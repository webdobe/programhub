<?php

declare(strict_types=1);

namespace Drupal\programhub_certificate_import\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\programhub_course_import\Service\CourseImporter;

/**
 * Pulls a certificate's overview / outcomes / requirements from its catalog
 * URL and writes them onto the certificate node, rebuilding the
 * `field_courses` paragraphs from the Plan of Study Grid.
 *
 * Each import creates a new revision of the certificate node. Any course
 * referenced in the requirements that doesn't yet exist in Drupal is fetched
 * via the course-import spider, attaching to a matching program by
 * abbreviation (or staying orphaned if no program matches).
 */
final class CertificateImporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CertificateScraper $scraper,
    private readonly CourseImporter $courseImporter,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Run the import for one certificate node.
   *
   * @return array{
   *   url:string,
   *   changed:bool,
   *   overviewChanged:bool,
   *   outcomesChanged:bool,
   *   typeChanged:bool,
   *   totalCredits:?int,
   *   typeAbbr:?string,
   *   coursesResolved:int,
   *   coursesMissing:array<int,string>,
   *   categoriesResolved:int,
   *   categoriesMissing:array<int,string>,
   *   spidered:int,
   *   paragraphsBuilt:int,
   *   errors:array<int,string>,
   * }
   */
  public function importForCertificate(NodeInterface $certificate, bool $dryRun = FALSE): array {
    $logger = $this->loggerFactory->get('programhub_certificate_import');
    $result = [
      'url' => '',
      'changed' => FALSE,
      'overviewChanged' => FALSE,
      'outcomesChanged' => FALSE,
      'typeChanged' => FALSE,
      'totalCredits' => NULL,
      'typeAbbr' => NULL,
      'coursesResolved' => 0,
      'coursesMissing' => [],
      'categoriesResolved' => 0,
      'categoriesMissing' => [],
      'spidered' => 0,
      'paragraphsBuilt' => 0,
      'errors' => [],
    ];

    if ($certificate->bundle() !== 'certificate') {
      $result['errors'][] = sprintf('Node %d is not a certificate (bundle: %s)', $certificate->id(), $certificate->bundle());
      return $result;
    }

    if (!$certificate->hasField('field_certificate_url') || $certificate->get('field_certificate_url')->isEmpty()) {
      $result['errors'][] = sprintf('Certificate "%s" has no field_certificate_url — cannot import.', $certificate->label());
      return $result;
    }

    $url = (string) $certificate->get('field_certificate_url')->uri;
    $result['url'] = $url;

    $scraped = $this->scraper->scrape($url);
    if ($scraped === NULL) {
      $result['errors'][] = sprintf('Scrape returned no usable content from %s.', $url);
      return $result;
    }

    $result['totalCredits'] = $scraped['totalCredits'];
    $result['typeAbbr'] = $scraped['typeAbbr'];

    // ---- Resolve course nodes (spidering missing ones) -----------------------
    // Only resolve rows that actually reference a course code. Category-only
    // rows (`number === NULL`) skip the spider entirely.
    $numbers = array_values(array_unique(array_filter(array_map(
      static fn(array $c) => $c['number'],
      $scraped['courses'],
    ))));

    $resolution = $this->courseImporter->ensureCoursesByNumbers($numbers);
    $result['spidered'] = $resolution['spidered'];
    $result['coursesMissing'] = $resolution['missing'];

    // Pre-resolve category labels → taxonomy term ids. Unknown labels stay
    // null and get reported back so editors can seed missing terms once.
    $categoryLabels = array_values(array_unique(array_filter(array_map(
      static fn(array $c) => $c['categoryLabel'] ?? NULL,
      $scraped['courses'],
    ))));
    $categoryTids = $this->resolveCategoryLabels($categoryLabels);
    foreach ($categoryLabels as $label) {
      if (!isset($categoryTids[$label])) {
        $result['categoriesMissing'][] = $label;
      }
    }

    // ---- Compute new paragraph plan ------------------------------------------
    // Each plan entry mirrors one row in the catalog. Either `nodeId` is set
    // (course row) or `categoryTid` is set (category placeholder). Both
    // course and category rows can carry an alternativeNote (course-only in
    // practice, but the schema is symmetric).
    /** @var array<int, array{nodeId:int, categoryTid:int, semester:?int, credits:string, altNote:string}> $plan */
    $plan = [];
    foreach ($scraped['courses'] as $row) {
      $nodeId = 0;
      $categoryTid = 0;
      if ($row['number'] !== NULL) {
        $node = $resolution['nodes'][$row['number']] ?? NULL;
        if ($node === NULL) {
          continue;
        }
        $nodeId = (int) $node->id();
        $result['coursesResolved']++;
      }
      elseif ($row['categoryLabel'] !== NULL && isset($categoryTids[$row['categoryLabel']])) {
        $categoryTid = $categoryTids[$row['categoryLabel']];
        $result['categoriesResolved']++;
      }
      else {
        // Unresolved row — neither a known course nor a known category.
        continue;
      }
      $plan[] = [
        'nodeId' => $nodeId,
        'categoryTid' => $categoryTid,
        'semester' => $row['semester'] !== NULL ? (int) $row['semester'] : NULL,
        'credits' => (string) ($row['credits'] ?? ''),
        'altNote' => (string) ($row['alternativeNote'] ?? ''),
      ];
    }
    $result['paragraphsBuilt'] = count($plan);

    // Compare to currently-attached paragraphs.
    $currentPlan = [];
    foreach ($certificate->get('field_courses')->referencedEntities() as $existing) {
      $courseRef = $existing->get('field_course')->target_id;
      $categoryRef = $existing->hasField('field_category') ? $existing->get('field_category')->target_id : NULL;
      $sem = $existing->get('field_semester')->value;
      $credits = $existing->hasField('field_credits') ? (string) ($existing->get('field_credits')->value ?? '') : '';
      $altNote = $existing->hasField('field_alternative_note') ? (string) ($existing->get('field_alternative_note')->value ?? '') : '';
      $currentPlan[] = [
        'nodeId' => $courseRef !== NULL ? (int) $courseRef : 0,
        'categoryTid' => $categoryRef !== NULL ? (int) $categoryRef : 0,
        'semester' => $sem !== NULL ? (int) $sem : NULL,
        'credits' => $credits,
        'altNote' => $altNote,
      ];
    }
    $paragraphsChanged = $currentPlan !== $plan;

    // ---- Diff scalar fields --------------------------------------------------
    $newOverview = $scraped['overview'];
    $newOutcomes = $scraped['outcomesHtml'];

    if ($this->textValueDiffers($certificate, 'field_overview', $newOverview, 'plain_text')) {
      $result['overviewChanged'] = TRUE;
    }
    if ($this->textValueDiffers($certificate, 'field_outcomes', $newOutcomes, 'html')) {
      $result['outcomesChanged'] = TRUE;
    }

    $totalCreditsChanged = FALSE;
    if ($certificate->hasField('field_total_credits')) {
      $current = $certificate->get('field_total_credits')->value;
      $current = $current === NULL ? NULL : (int) $current;
      if ($current !== $scraped['totalCredits']) {
        $totalCreditsChanged = TRUE;
      }
    }

    // Resolve certificate type (taxonomy reference) from "(XYZ)" abbr in title.
    $newTypeTid = $this->resolveCertificateType($scraped['typeAbbr']);
    $currentTypeTid = NULL;
    if ($certificate->hasField('field_certificate_type') && !$certificate->get('field_certificate_type')->isEmpty()) {
      $currentTypeTid = (int) $certificate->get('field_certificate_type')->target_id;
    }
    if ($newTypeTid !== NULL && $newTypeTid !== $currentTypeTid) {
      $result['typeChanged'] = TRUE;
    }

    $result['changed'] =
      $result['overviewChanged']
      || $result['outcomesChanged']
      || $result['typeChanged']
      || $totalCreditsChanged
      || $paragraphsChanged;

    if ($dryRun) {
      return $result;
    }

    // ---- Write back ----------------------------------------------------------
    if ($result['overviewChanged']) {
      $certificate->set('field_overview', $newOverview === '' ? NULL : ['value' => $newOverview, 'format' => 'plain_text']);
    }
    if ($result['outcomesChanged']) {
      $certificate->set('field_outcomes', $newOutcomes === '' ? NULL : ['value' => $newOutcomes, 'format' => 'html']);
    }
    if ($totalCreditsChanged) {
      $certificate->set('field_total_credits', $scraped['totalCredits']);
    }
    if ($result['typeChanged']) {
      $certificate->set('field_certificate_type', ['target_id' => $newTypeTid]);
    }

    if ($paragraphsChanged) {
      foreach ($certificate->get('field_courses')->referencedEntities() as $oldParagraph) {
        $oldParagraph->delete();
      }
      $newParagraphRefs = [];
      foreach ($plan as $entry) {
        $paragraph = Paragraph::create([
          'type' => 'certificate_course',
          'field_course' => $entry['nodeId'] !== 0 ? ['target_id' => $entry['nodeId']] : NULL,
          'field_category' => $entry['categoryTid'] !== 0 ? ['target_id' => $entry['categoryTid']] : NULL,
          'field_semester' => $entry['semester'],
          'field_credits' => $entry['credits'] !== '' ? $entry['credits'] : NULL,
          'field_alternative_note' => $entry['altNote'] !== '' ? $entry['altNote'] : NULL,
        ]);
        $paragraph->save();
        $newParagraphRefs[] = [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ];
      }
      $certificate->set('field_courses', $newParagraphRefs);
    }

    if ($result['changed']) {
      $certificate->setNewRevision(TRUE);
      $certificate->setRevisionUserId((int) ($this->currentUser->id() ?: 1));
      $certificate->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $certificate->setRevisionLogMessage('Imported from NIC catalog (' . $url . ')');
      $certificate->save();
    }

    $logger->notice(
      'Certificate import for "@p" — courses=@c missing=@m spidered=@s',
      [
        '@p' => $certificate->label(),
        '@c' => $result['coursesResolved'],
        '@m' => count($result['coursesMissing']),
        '@s' => $result['spidered'],
      ],
    );

    return $result;
  }

  /**
   * Resolve gen_ed_category term ids by exact name in a single batched query.
   *
   * Editors seed the vocabulary (the deploy hook ships GEM 1-6); unknown
   * labels are silently omitted from the returned map so the caller can
   * report them as missing without aborting the whole import.
   *
   * @param array<int,string> $labels
   * @return array<string,int>  label → tid for every label that resolved
   */
  private function resolveCategoryLabels(array $labels): array {
    if (!$labels) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties([
      'vid' => 'gen_ed_category',
      'name' => $labels,
    ]);
    $map = [];
    foreach ($terms as $term) {
      $map[$term->label()] = (int) $term->id();
    }
    return $map;
  }

  /**
   * Look up a certificate_type term by exact name (e.g. "AAS"). Does NOT
   * auto-create — admins seed the vocabulary; the importer only resolves.
   */
  private function resolveCertificateType(?string $abbr): ?int {
    if ($abbr === NULL || $abbr === '') {
      return NULL;
    }
    $existing = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([
      'vid' => 'certificate_type',
      'name' => $abbr,
    ]);
    if (!$existing) {
      return NULL;
    }
    $term = reset($existing);
    return (int) $term->id();
  }

  private function textValueDiffers(
    NodeInterface $node,
    string $field,
    string $newValue,
    string $expectedFormat = 'html',
  ): bool {
    if (!$node->hasField($field)) {
      return FALSE;
    }
    $current = trim((string) ($node->get($field)->value ?? ''));
    $currentFormat = (string) ($node->get($field)->format ?? '');
    $new = trim($newValue);
    if ($current !== $new) {
      return TRUE;
    }
    // If we'd write a non-empty value, also flag mismatched format so legacy
    // values get migrated (e.g. 'basic_html' → 'html', or 'html' → 'plain_text'
    // for the overview field that switched formats).
    if ($new !== '' && $currentFormat !== $expectedFormat) {
      return TRUE;
    }
    return FALSE;
  }

}
