<?php

declare(strict_types=1);

namespace Drupal\programhub_certificate_import\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\programhub_course_import\Service\CourseImporter;
use Drupal\taxonomy\Entity\Term;

/**
 * Syncs the `gen_ed_category` vocabulary + course tagging from the catalog.
 *
 * Two side-effects per run:
 *
 *  1. Upsert one `gen_ed_category` taxonomy term per category section
 *     scraped from the degree-requirements pages. Idempotent: existing terms
 *     are matched by name and updated; missing terms are created.
 *
 *  2. For each course listed under a section, add the matching term to the
 *     course node's `field_fulfills_categories` — but only when it isn't
 *     already there. We never *remove* tags an editor (or an earlier run)
 *     already set; admins manage subtractions explicitly.
 *
 * Optional course spidering via `programhub_course_import.importer`. When
 * disabled, unknown course numbers are reported back as `missingCourses`.
 *
 * The canonical degree-requirements URLs are:
 *   - https://catalog.nic.edu/aa-as-degree-requirements/
 *   - https://catalog.nic.edu/aas-degree-requirements/
 *
 * Both pages overlap heavily but each contributes categories the other
 * lacks (aa-as has GEM 7i/7w; aas has AASID). The default `sync()` walks
 * both so a single command keeps everything aligned.
 */
final class GenEdImporter {

  public const DEFAULT_SOURCE_URLS = [
    'https://catalog.nic.edu/aa-as-degree-requirements/',
    'https://catalog.nic.edu/aas-degree-requirements/',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly GenEdScraper $scraper,
    private readonly CourseImporter $courseImporter,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Sync vocabulary + course tagging from a list of catalog URLs.
   *
   * @param array<int,string>|null $urls
   *   Source pages. Defaults to {@see self::DEFAULT_SOURCE_URLS}.
   * @param bool $spider
   *   When TRUE, unknown course numbers are fetched + created via the
   *   course-import spider. When FALSE, they're reported in `missingCourses`.
   * @param bool $dryRun
   *   When TRUE, computes the plan but writes nothing.
   *
   * @return array{
   *   urls:array<int,string>,
   *   termsSeen:int,
   *   termsCreated:int,
   *   coursesTagged:int,
   *   tagsAdded:int,
   *   spidered:int,
   *   missingCourses:array<int,string>,
   *   errors:array<int,string>,
   * }
   */
  public function sync(?array $urls = NULL, bool $spider = TRUE, bool $dryRun = FALSE): array {
    $logger = $this->loggerFactory->get('programhub_certificate_import');
    $urls = $urls ?? self::DEFAULT_SOURCE_URLS;

    $result = [
      'urls' => $urls,
      'termsSeen' => 0,
      'termsCreated' => 0,
      'coursesTagged' => 0,
      'tagsAdded' => 0,
      'spidered' => 0,
      'missingCourses' => [],
      'errors' => [],
    ];

    // ---- 1. Scrape all source URLs and merge by category label ------------
    // Same category may appear on multiple pages with overlapping but
    // non-identical course lists. We union the course-number sets per label.
    /** @var array<string, array{label:string, fullName:string, anchor:string, courseNumbers:array<int,string>}> $merged */
    $merged = [];
    foreach ($urls as $url) {
      $sections = $this->scraper->scrape($url);
      if ($sections === NULL) {
        $result['errors'][] = sprintf('Scrape failed: %s', $url);
        continue;
      }
      foreach ($sections as $section) {
        $label = $section['label'];
        if (!isset($merged[$label])) {
          $merged[$label] = $section;
          continue;
        }
        $merged[$label]['courseNumbers'] = array_values(array_unique(array_merge(
          $merged[$label]['courseNumbers'],
          $section['courseNumbers'],
        )));
        // Prefer the longer/more descriptive fullName if either is empty.
        if ($merged[$label]['fullName'] === '' && $section['fullName'] !== '') {
          $merged[$label]['fullName'] = $section['fullName'];
        }
      }
    }
    $result['termsSeen'] = count($merged);
    if (!$merged) {
      return $result;
    }

    // ---- 2. Resolve / upsert taxonomy terms by label ----------------------
    // `ensureCategoryTerm` returns [tid|null, created?]. In dry-run mode,
    // missing terms come back as tid=null but still flag "would create".
    /** @var array<string, int> $termIds  label → tid (0 = dry-run-would-create) */
    $termIds = [];
    foreach ($merged as $label => $section) {
      [$tid, $created] = $this->ensureCategoryTerm($label, $section['fullName'], $dryRun);
      if ($created) {
        $result['termsCreated']++;
      }
      $termIds[$label] = $tid ?? 0;
    }

    // ---- 3. Optional spider — fetch unknown course numbers --------------
    $allNumbers = [];
    foreach ($merged as $section) {
      foreach ($section['courseNumbers'] as $num) {
        $allNumbers[$num] = TRUE;
      }
    }
    $allNumbers = array_keys($allNumbers);

    if ($spider && !$dryRun) {
      $resolution = $this->courseImporter->ensureCoursesByNumbers($allNumbers);
      $result['spidered'] = $resolution['spidered'];
      $result['missingCourses'] = $resolution['missing'];
      $courseNodes = $resolution['nodes'];
    }
    else {
      $courseNodes = $this->loadCoursesByNumbers($allNumbers);
      $missing = array_values(array_diff($allNumbers, array_keys($courseNodes)));
      $result['missingCourses'] = $missing;
    }

    // ---- 4. Tag each course with its categories ---------------------------
    foreach ($merged as $label => $section) {
      $tid = $termIds[$label] ?? 0;
      if ($tid === 0 && !$dryRun) {
        // Term creation must have failed in step 2 — skip rather than
        // attribute zero tag-additions to a phantom term.
        continue;
      }
      foreach ($section['courseNumbers'] as $number) {
        $node = $courseNodes[$number] ?? NULL;
        if ($node === NULL) {
          continue;
        }
        if (!$node->hasField('field_fulfills_categories')) {
          continue;
        }
        $currentTids = array_map(
          static fn(array $v) => (int) $v['target_id'],
          $node->get('field_fulfills_categories')->getValue(),
        );
        if (in_array($tid, $currentTids, TRUE)) {
          continue;
        }
        $currentTids[] = $tid;
        $result['tagsAdded']++;
        if (!$dryRun) {
          $node->set('field_fulfills_categories', array_map(
            static fn(int $t) => ['target_id' => $t],
            $currentTids,
          ));
          $node->save();
        }
      }
    }
    // coursesTagged: distinct course count touched (rough — tagsAdded
    // overcounts when one course gains multiple categories in one run).
    // Walk the merged plan once more for a clean distinct count.
    $touched = [];
    foreach ($merged as $section) {
      foreach ($section['courseNumbers'] as $number) {
        if (isset($courseNodes[$number])) {
          $touched[$number] = TRUE;
        }
      }
    }
    $result['coursesTagged'] = count($touched);

    $logger->notice(
      'Gen-ed sync — terms seen=@s created=@c, courses tagged=@t (tags added=@a), spidered=@sp, missing=@m',
      [
        '@s' => $result['termsSeen'],
        '@c' => $result['termsCreated'],
        '@t' => $result['coursesTagged'],
        '@a' => $result['tagsAdded'],
        '@sp' => $result['spidered'],
        '@m' => count($result['missingCourses']),
      ],
    );

    return $result;
  }

  /**
   * Ensure a `gen_ed_category` term exists for $label. Backfills the
   * description from the scraped full heading only when the existing term's
   * description is empty — never clobbers editor edits.
   *
   * @return array{0:?int, 1:bool}
   *   [tid, created?]. tid is NULL only in dry-run when the term doesn't yet
   *   exist (creation skipped). `created` is TRUE when this run would create
   *   the term, regardless of dry-run state.
   */
  private function ensureCategoryTerm(string $label, string $fullName, bool $dryRun): array {
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $existing = $storage->loadByProperties([
      'vid' => 'gen_ed_category',
      'name' => $label,
    ]);
    if ($existing) {
      /** @var \Drupal\taxonomy\TermInterface $term */
      $term = reset($existing);
      if (!$dryRun && $fullName !== '' && trim((string) ($term->getDescription() ?? '')) === '') {
        $term->setDescription($fullName);
        $term->save();
      }
      return [(int) $term->id(), FALSE];
    }
    if ($dryRun) {
      return [NULL, TRUE];
    }
    $term = Term::create([
      'vid' => 'gen_ed_category',
      'name' => $label,
      'description' => [
        'value' => $fullName,
        'format' => 'plain_text',
      ],
    ]);
    $term->save();
    return [(int) $term->id(), TRUE];
  }

  /**
   * Load existing course nodes keyed by course number. Used in dry-run /
   * no-spider mode so the planner can still distinguish present vs missing
   * courses without triggering the spider's side-effects.
   *
   * @param array<int,string> $numbers
   * @return array<string, \Drupal\node\NodeInterface>
   */
  private function loadCoursesByNumbers(array $numbers): array {
    if (!$numbers) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course')
      ->condition('field_course_number', $numbers, 'IN')
      ->execute();
    $out = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $num = (string) $node->get('field_course_number')->value;
      if ($num !== '') {
        $out[$num] = $node;
      }
    }
    return $out;
  }

}
