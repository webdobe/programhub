<?php

declare(strict_types=1);

namespace Drupal\programhub_course_import\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * Imports catalog-scraped courses into Drupal as `course` nodes.
 *
 * Behavior:
 *  - Existing course (matched by field_course_number + og_audience=program):
 *    saved as a NEW REVISION with updated fields.
 *  - Missing course: a new published `course` node is created.
 *  - Course in Drupal but NOT in the scraped list: unpublished + revisioned
 *    with a "[Removed from catalog]" log message.
 *  - Prerequisites/corequisites referenced in the catalog are resolved as
 *    entity references. Missing referenced courses are spidered: we fetch
 *    their owning catalog prefix, create the course node (attaching to a
 *    matching `program` if its abbreviation matches), and recurse until
 *    every reachable course exists in Drupal.
 */
final class CourseImporter {

  /**
   * Cap on transitive spider depth (defensive — catalog isn't actually that deep).
   */
  private const MAX_SPIDER_DEPTH = 6;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CatalogScraper $scraper,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * Run the import for one program node.
   *
   * @return array{created:int,updated:int,unchanged:int,flagged:int,spidered:int,errors:array<int,string>,prefix:string,url:string}
   */
  /**
   * Public spider entry point — make sure each given course number exists in
   * Drupal (creating + recursively resolving prereqs as needed). Used by other
   * modules (e.g. the certificate importer) that have a list of course numbers
   * and need real course nodes back.
   *
   * @param array<int, string> $numbers
   * @return array{nodes: array<string, NodeInterface>, missing: array<int, string>, spidered: int}
   *   - nodes: map of number → resolved course node (only successfully-resolved entries)
   *   - missing: numbers we couldn't find or scrape
   *   - spidered: count of courses created during this call
   */
  public function ensureCoursesByNumbers(array $numbers): array {
    $logger = $this->loggerFactory->get('programhub_course_import');
    $courseCache = [];
    $rowCache = [];
    $counters = [
      'created' => 0,
      'updated' => 0,
      'unchanged' => 0,
      'flagged' => 0,
      'spidered' => 0,
      'errors' => [],
      'prefix' => '',
      'url' => '',
    ];
    $missing = [];

    // ensureCourseByNumber needs a "primary program" for context; we don't
    // have one in this entry point, so synthesize a sentinel that isn't used
    // beyond identity.
    $sentinel = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'program',
      'title' => '__spider_sentinel__',
    ]);

    $resolved = [];
    foreach (array_unique($numbers) as $number) {
      $node = $this->ensureCourseByNumber(
        $number,
        $sentinel,
        $courseCache,
        $rowCache,
        $counters,
        FALSE,
        $logger,
        0,
      );
      if ($node !== NULL) {
        $resolved[$number] = $node;
      }
      else {
        $missing[] = $number;
      }
    }

    return [
      'nodes' => $resolved,
      'missing' => $missing,
      'spidered' => $counters['spidered'],
    ];
  }

  public function importForProgram(NodeInterface $program, bool $dryRun = FALSE): array {
    $logger = $this->loggerFactory->get('programhub_course_import');
    $result = [
      'created' => 0,
      'updated' => 0,
      'unchanged' => 0,
      'flagged' => 0,
      'spidered' => 0,
      'errors' => [],
      'notices' => [],
      'prefix' => '',
      'url' => '',
    ];

    if ($program->bundle() !== 'program') {
      $result['errors'][] = sprintf('Node %d is not a program (bundle: %s)', $program->id(), $program->bundle());
      return $result;
    }

    $prefix = $this->prefixForProgram($program);
    if ($prefix === NULL) {
      $result['errors'][] = sprintf('Program "%s" has no field_abbreviation — cannot derive catalog prefix.', $program->label());
      return $result;
    }
    $result['prefix'] = $prefix;
    $result['url'] = CatalogScraper::urlForPrefix($prefix);

    /** @var array<string, array<int, array>> $rowCache rows keyed by prefix */
    $rowCache = [];
    /** @var array<string, NodeInterface> $courseCache loaded courses keyed by number */
    $courseCache = [];

    $rows = $this->fetchPrefix($prefix, $rowCache);
    if ($rows === []) {
      // Two reasons we can land here, both benign for cross-program runs:
      //   1. The prefix has no own catalog page (404). E.g. CYBER courses are
      //      catalogued under CITE; CYBER's "/course-descriptions/cyber/" 404s.
      //   2. The page exists but contains no courseblocks (catalog reorg).
      // Surface as a notice — caller decides whether to treat it as an error.
      $result['notices'][] = sprintf(
        'No courses to import for "%s" — no catalog page at %s. (If this program reuses another department\'s prefix, that\'s expected.)',
        $program->label(),
        $result['url'],
      );
      return $result;
    }

    $primaryNumbers = [];
    foreach ($rows as $row) {
      if ($row['number'] !== '') {
        $primaryNumbers[$row['number']] = TRUE;
      }
    }

    // Pass 1: create-or-update every course in the primary prefix WITHOUT
    // resolving references yet. Tally counts as we go.
    foreach ($rows as $row) {
      $this->upsertWithoutRefs($row, $program, $courseCache, $result, $rowCache, $dryRun, $logger);
    }

    // Pass 2: resolve references. This may spider into other prefixes;
    // ensureCourseByNumber recurses (depth-bounded) for transitive prereqs.
    foreach ($rows as $row) {
      if ($row['number'] === '' || !isset($courseCache[$row['number']])) {
        continue;
      }
      $this->setReferences(
        $courseCache[$row['number']],
        $row,
        $program,
        $courseCache,
        $rowCache,
        $result,
        $dryRun,
        $logger,
      );
    }

    // Flag any existing course in this program that wasn't in the scrape.
    $existing = $this->loadExistingCourses($program);
    foreach ($existing as $number => $node) {
      if (!isset($primaryNumbers[$number])) {
        $this->flagMissingCourse($node, $dryRun);
        $result['flagged']++;
      }
    }

    $logger->notice(
      'Course import for "@p" (@prefix): created=@c updated=@u unchanged=@x flagged=@f spidered=@s',
      [
        '@p' => $program->label(),
        '@prefix' => $prefix,
        '@c' => $result['created'],
        '@u' => $result['updated'],
        '@x' => $result['unchanged'],
        '@f' => $result['flagged'],
        '@s' => $result['spidered'],
      ],
    );

    return $result;
  }

  // ---------------------------------------------------------------------------
  // Pass 1: upsert without references.
  // ---------------------------------------------------------------------------

  private function upsertWithoutRefs(
    array $row,
    NodeInterface $program,
    array &$courseCache,
    array &$result,
    array &$rowCache,
    bool $dryRun,
    $logger,
  ): void {
    $number = $row['number'];
    if ($number === '') {
      return;
    }

    try {
      $existing = $this->loadCourseByNumber($number, $program);
      if ($existing !== NULL) {
        if ($this->updateCourseFields($existing, $row, $dryRun)) {
          $result['updated']++;
        }
        else {
          $result['unchanged']++;
        }
        $courseCache[$number] = $existing;
      }
      else {
        $node = $this->createCourse($program, $row, $dryRun);
        if ($node !== NULL) {
          $courseCache[$number] = $node;
          $result['created']++;
        }
      }
    }
    catch (\Throwable $e) {
      $result['errors'][] = sprintf('Course "%s": %s', $number, $e->getMessage());
      $logger->error('Course import error: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  // ---------------------------------------------------------------------------
  // Pass 2: resolve prereq/recommended references, spidering missing courses.
  // ---------------------------------------------------------------------------

  private function setReferences(
    NodeInterface $node,
    array $row,
    NodeInterface $program,
    array &$courseCache,
    array &$rowCache,
    array &$result,
    bool $dryRun,
    $logger,
  ): void {
    $prereqIds = $this->resolveRefs($row['prereqs'], $program, $courseCache, $rowCache, $result, $dryRun, $logger, 0);
    $recIds = $this->resolveRefs($row['recPrereqs'], $program, $courseCache, $rowCache, $result, $dryRun, $logger, 0);

    $changed = $this->setReferenceField($node, 'field_course_prereqs', $prereqIds);
    $changed = $this->setReferenceField($node, 'field_course_rec_prereqs', $recIds) || $changed;

    if (!$changed || $dryRun) {
      return;
    }

    // setReferenceField only marks the field — bump revision once.
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) ($this->currentUser->id() ?: 1));
    $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $node->setRevisionLogMessage('Updated prerequisite references from NIC catalog');
    $node->save();

    // Saving here counts as a content update — but we already counted this row
    // in pass 1 (created or updated). To avoid double-counting, we don't bump
    // the result counters here.
  }

  /**
   * @param array<int, string> $numbers
   * @return array<int, int> entity ids
   */
  private function resolveRefs(
    array $numbers,
    NodeInterface $primaryProgram,
    array &$courseCache,
    array &$rowCache,
    array &$result,
    bool $dryRun,
    $logger,
    int $depth,
  ): array {
    $ids = [];
    foreach ($numbers as $number) {
      $course = $this->ensureCourseByNumber($number, $primaryProgram, $courseCache, $rowCache, $result, $dryRun, $logger, $depth);
      if ($course !== NULL && $course->id() !== NULL) {
        $ids[] = (int) $course->id();
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Spider entry point: look up a course by number; if missing, scrape its
   * prefix, create it, and recursively resolve its own prereqs.
   */
  private function ensureCourseByNumber(
    string $number,
    NodeInterface $primaryProgram,
    array &$courseCache,
    array &$rowCache,
    array &$result,
    bool $dryRun,
    $logger,
    int $depth,
  ): ?NodeInterface {
    if (isset($courseCache[$number])) {
      return $courseCache[$number];
    }

    // Check if it already exists anywhere in the system.
    $existing = $this->loadCourseByNumberAnywhere($number);
    if ($existing !== NULL) {
      $courseCache[$number] = $existing;
      return $existing;
    }

    if ($depth >= self::MAX_SPIDER_DEPTH) {
      $logger->warning('Spider depth cap reached at @num (depth @d) — skipping deeper resolution.', [
        '@num' => $number,
        '@d' => $depth,
      ]);
      return NULL;
    }

    // Spider: derive the prefix, fetch its catalog page, find the matching row.
    $prefix = $this->prefixFromCourseNumber($number);
    if ($prefix === NULL) {
      $logger->warning('Cannot derive catalog prefix from course number "@num".', ['@num' => $number]);
      return NULL;
    }

    $rows = $this->fetchPrefix($prefix, $rowCache);
    $row = $this->findRowByNumber($rows, $number);
    if ($row === NULL) {
      $logger->warning('Spider: course "@num" not found at @url.', [
        '@num' => $number,
        '@url' => CatalogScraper::urlForPrefix($prefix),
      ]);
      return NULL;
    }

    if ($dryRun) {
      // We can't create — return null and let counters reflect "would have been".
      $result['spidered']++;
      return NULL;
    }

    // Find a program to attach this course to (matched by field_abbreviation).
    $owningProgram = $this->findProgramByPrefix($prefix) ?? NULL;
    $node = $this->createCourse($owningProgram, $row, $dryRun);
    if ($node === NULL) {
      return NULL;
    }
    $courseCache[$number] = $node;
    $result['spidered']++;

    // Recursively resolve THIS course's references too — full spider.
    $prereqIds = $this->resolveRefs($row['prereqs'], $primaryProgram, $courseCache, $rowCache, $result, $dryRun, $logger, $depth + 1);
    $recIds = $this->resolveRefs($row['recPrereqs'], $primaryProgram, $courseCache, $rowCache, $result, $dryRun, $logger, $depth + 1);

    $changed = $this->setReferenceField($node, 'field_course_prereqs', $prereqIds);
    $changed = $this->setReferenceField($node, 'field_course_rec_prereqs', $recIds) || $changed;
    if ($changed) {
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId((int) ($this->currentUser->id() ?: 1));
      $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $node->setRevisionLogMessage('Spidered from NIC catalog with prerequisite references');
      $node->save();
    }

    return $node;
  }

  // ---------------------------------------------------------------------------
  // Loaders / lookups.
  // ---------------------------------------------------------------------------

  private function loadCourseByNumber(string $number, NodeInterface $program): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course')
      ->condition('og_audience', $program->id())
      ->condition('field_course_number', $number)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    $id = (int) reset($ids);
    /** @var NodeInterface|null $n */
    $n = $storage->load($id);
    return $n;
  }

  /**
   * Find a course by number across ALL programs (used during spider).
   */
  private function loadCourseByNumberAnywhere(string $number): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course')
      ->condition('field_course_number', $number)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    /** @var NodeInterface|null $n */
    $n = $storage->load((int) reset($ids));
    return $n;
  }

  /**
   * @return array<string, NodeInterface>
   */
  private function loadExistingCourses(NodeInterface $program): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course')
      ->condition('og_audience', $program->id())
      ->execute();
    if (!$ids) {
      return [];
    }
    $byNumber = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $num = $node->hasField('field_course_number')
        ? trim((string) $node->get('field_course_number')->value)
        : '';
      if ($num !== '') {
        $byNumber[$num] = $node;
      }
    }
    return $byNumber;
  }

  private function findProgramByPrefix(string $prefix): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'program')
      ->condition('field_abbreviation', strtoupper($prefix))
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }
    /** @var NodeInterface|null $n */
    $n = $storage->load((int) reset($ids));
    return $n;
  }

  /**
   * Fetch a prefix's rows from the catalog, caching across the import run.
   *
   * @return array<int, array>
   */
  private function fetchPrefix(string $prefix, array &$rowCache): array {
    if (isset($rowCache[$prefix])) {
      return $rowCache[$prefix];
    }
    $rows = $this->scraper->fetchCourses($prefix);
    $rowCache[$prefix] = $rows;
    return $rows;
  }

  private function findRowByNumber(array $rows, string $number): ?array {
    foreach ($rows as $row) {
      if (($row['number'] ?? '') === $number) {
        return $row;
      }
    }
    return NULL;
  }

  private function prefixForProgram(NodeInterface $program): ?string {
    if (!$program->hasField('field_abbreviation')) {
      return NULL;
    }
    $value = trim((string) $program->get('field_abbreviation')->value);
    if ($value === '') {
      return NULL;
    }
    return strtolower($value);
  }

  private function prefixFromCourseNumber(string $number): ?string {
    if (!preg_match('/^([A-Z]{2,5})-/u', $number, $m)) {
      return NULL;
    }
    return strtolower($m[1]);
  }

  // ---------------------------------------------------------------------------
  // Mutators.
  // ---------------------------------------------------------------------------

  private function createCourse(?NodeInterface $owningProgram, array $row, bool $dryRun): ?NodeInterface {
    if ($dryRun) {
      return NULL;
    }
    $semesterTids = $this->ensureSemesterTerms($row['semesters'] ?? []);
    $values = [
      'type' => 'course',
      'title' => sprintf('%s %s', $row['number'], $row['title']),
      'status' => 1,
      'uid' => $this->currentUser->id() ?: 1,
      'field_course_number' => $row['number'],
      'field_description' => $row['description'] !== '' ? [
        'value' => $row['description'],
        'format' => 'html',
      ] : NULL,
      'field_course_credits' => $row['credits'],
      'field_course_offering' => array_map(fn(int $tid) => ['target_id' => $tid], $semesterTids),
      'field_course_year_cycle' => ($row['yearCycle'] ?? NULL) ?: NULL,
      'revision_log' => $owningProgram
        ? 'Imported from NIC catalog'
        : 'Spidered from NIC catalog (no matching program for ' . substr($row['number'], 0, 5) . ')',
    ];
    if ($owningProgram !== NULL) {
      $values['og_audience'] = [['target_id' => $owningProgram->id()]];
    }

    $node = Node::create($values);
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) ($this->currentUser->id() ?: 1));
    $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $node->save();
    return $node;
  }

  /**
   * Returns TRUE if any scalar field changed.
   */
  private function updateCourseFields(NodeInterface $node, array $row, bool $dryRun): bool {
    $newTitle = sprintf('%s %s', $row['number'], $row['title']);
    $newDesc = $row['description'];
    $newCredits = $row['credits'];

    $changed = FALSE;

    if ($node->label() !== $newTitle) {
      $node->setTitle($newTitle);
      $changed = TRUE;
    }

    $currentDesc = $node->hasField('field_description')
      ? trim((string) $node->get('field_description')->value)
      : '';
    $currentDescFormat = $node->hasField('field_description')
      ? (string) $node->get('field_description')->format
      : '';
    $needsDescRewrite = $currentDesc !== $newDesc
      || ($newDesc !== '' && $currentDescFormat !== 'html');
    if ($needsDescRewrite) {
      $node->set('field_description', $newDesc !== '' ? [
        'value' => $newDesc,
        'format' => 'html',
      ] : NULL);
      $changed = TRUE;
    }

    if ($node->hasField('field_course_credits')) {
      $current = $node->get('field_course_credits')->value;
      $current = $current === NULL ? NULL : (int) $current;
      if ($current !== $newCredits) {
        $node->set('field_course_credits', $newCredits);
        $changed = TRUE;
      }
    }

    if ($node->hasField('field_course_offering')) {
      $currentTids = [];
      foreach ($node->get('field_course_offering') as $item) {
        if ($item->target_id !== NULL) {
          $currentTids[] = (int) $item->target_id;
        }
      }
      $newTids = $this->ensureSemesterTerms($row['semesters'] ?? []);

      $a = $currentTids;
      $b = $newTids;
      sort($a);
      sort($b);
      if ($a !== $b) {
        $node->set('field_course_offering', array_map(fn(int $tid) => ['target_id' => $tid], $newTids));
        $changed = TRUE;
      }
    }

    if ($node->hasField('field_course_year_cycle')) {
      $current = $node->get('field_course_year_cycle')->value;
      $new = ($row['yearCycle'] ?? NULL) ?: NULL;
      if ($current !== $new) {
        $node->set('field_course_year_cycle', $new);
        $changed = TRUE;
      }
    }

    // Re-publish if something brought it back from being flagged.
    if (!$node->isPublished()) {
      $node->setPublished();
      $changed = TRUE;
    }

    if (!$changed) {
      return FALSE;
    }
    if ($dryRun) {
      return TRUE;
    }

    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) ($this->currentUser->id() ?: 1));
    $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $node->setRevisionLogMessage('Updated from NIC catalog');
    $node->save();
    return TRUE;
  }

  /**
   * Set an entity-reference field if the target id list differs from current.
   * Only mutates the in-memory node; caller saves with a single revision.
   *
   * @param array<int, int> $ids
   */
  private function setReferenceField(NodeInterface $node, string $fieldName, array $ids): bool {
    if (!$node->hasField($fieldName)) {
      return FALSE;
    }

    $currentIds = [];
    foreach ($node->get($fieldName) as $item) {
      $tid = $item->target_id;
      if ($tid !== NULL) {
        $currentIds[] = (int) $tid;
      }
    }

    sort($currentIds);
    $newIds = $ids;
    sort($newIds);

    if ($currentIds === $newIds) {
      return FALSE;
    }

    $node->set($fieldName, array_map(fn(int $id) => ['target_id' => $id], $ids));
    return TRUE;
  }

  /**
   * Find-or-create course_offering terms for a list of semester names.
   *
   * The form widget is options_select (no auto-create), so this importer is
   * the only place that mints new offering terms.
   *
   * @param array<int, string> $names
   * @return array<int, int> term ids in input order, deduped.
   */
  private function ensureSemesterTerms(array $names): array {
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $tids = [];
    foreach ($names as $rawName) {
      $name = trim($rawName);
      if ($name === '') {
        continue;
      }
      $existing = $termStorage->loadByProperties([
        'vid' => 'course_offering',
        'name' => $name,
      ]);
      if ($existing) {
        $term = reset($existing);
      }
      else {
        $term = $termStorage->create([
          'vid' => 'course_offering',
          'name' => $name,
        ]);
        $term->save();
      }
      $tids[(int) $term->id()] = TRUE;
    }
    return array_keys($tids);
  }

  private function flagMissingCourse(NodeInterface $node, bool $dryRun): void {
    if (!$node->isPublished()) {
      return;
    }
    if ($dryRun) {
      return;
    }
    $node->setUnpublished();
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId((int) ($this->currentUser->id() ?: 1));
    $node->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $node->setRevisionLogMessage('[Removed from catalog] Auto-unpublished — no longer in NIC catalog scrape. Review and delete or restore.');
    $node->save();
  }

}
