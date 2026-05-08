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
    $numbers = array_values(array_unique(array_map(
      static fn(array $c) => $c['number'],
      $scraped['courses'],
    )));

    $resolution = $this->courseImporter->ensureCoursesByNumbers($numbers);
    $result['spidered'] = $resolution['spidered'];
    $result['coursesMissing'] = $resolution['missing'];

    // ---- Compute new paragraph plan (course node id + semester + credits) ---
    /** @var array<int, array{nodeId:int, semester:?int, credits:string}> $plan */
    $plan = [];
    foreach ($scraped['courses'] as $row) {
      $node = $resolution['nodes'][$row['number']] ?? NULL;
      if ($node === NULL) {
        continue;
      }
      $result['coursesResolved']++;
      $plan[] = [
        'nodeId' => (int) $node->id(),
        'semester' => $row['semester'] !== NULL ? (int) $row['semester'] : NULL,
        'credits' => (string) ($row['credits'] ?? ''),
      ];
    }
    $result['paragraphsBuilt'] = count($plan);

    // Compare to currently-attached paragraphs.
    $currentPlan = [];
    foreach ($certificate->get('field_courses')->referencedEntities() as $existing) {
      $courseRef = $existing->get('field_course')->target_id;
      $sem = $existing->get('field_semester')->value;
      $credits = $existing->hasField('field_credits') ? (string) ($existing->get('field_credits')->value ?? '') : '';
      $currentPlan[] = [
        'nodeId' => $courseRef !== NULL ? (int) $courseRef : 0,
        'semester' => $sem !== NULL ? (int) $sem : NULL,
        'credits' => $credits,
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
          'field_course' => ['target_id' => $entry['nodeId']],
          'field_semester' => $entry['semester'],
          'field_credits' => $entry['credits'] !== '' ? $entry['credits'] : NULL,
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
