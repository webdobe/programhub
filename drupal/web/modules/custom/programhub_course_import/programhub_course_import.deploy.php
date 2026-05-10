<?php

/**
 * @file
 * Deploy hooks for programhub_course_import.
 *
 * `drush deploy` runs hooks in this order:
 *   1. updatedb        — hook_update_N + hook_post_update_NAME
 *   2. config:import   — applies YAML in config/sync/
 *   3. cache:rebuild
 *   4. deploy:hook     — runs hook_deploy_NAME (this file)
 *
 * Course aliases are handled by pathauto (pattern in
 * config/sync/pathauto.pattern.courses.yml). This hook just nudges every
 * existing course to regenerate its alias against the current pattern — the
 * actual slugification happens in pathauto, the actual write happens in
 * Drupal's path module, and the fieldable_path field type then mirrors the
 * value into `field_path`. We don't need a presave hook because course
 * titles don't have the special characters (`+`, parentheticals) that
 * forced the certificate path to be hand-managed.
 */

declare(strict_types=1);

/**
 * Strip leading "{COURSE-NUMBER} " prefixes from course titles.
 *
 * Earlier importer runs concatenated the catalog number into the title
 * ("CITE-118 Computer Information Technology Essentials"); the number
 * already lives in field_course_number, so the title now carries only the
 * descriptive part. The importer self-cleans courses it touches, but
 * cross-listed support courses (e.g. ATEC-117, COMM-101) live under
 * department prefixes the importer doesn't scrape, so this hook handles
 * the legacy cleanup for them. Idempotent.
 */
function programhub_course_import_deploy_00_strip_number_from_course_titles(array &$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $stripped = 0;
  foreach ($storage->loadByProperties(['type' => 'course']) as $course) {
    if (!$course->hasField('field_course_number')) {
      continue;
    }
    $number = trim((string) $course->get('field_course_number')->value);
    $title = trim((string) $course->getTitle());
    if ($number === '' || $title === '') {
      continue;
    }
    $cleaned = preg_replace(
      '/^' . preg_quote($number, '/') . '\s*[-:]?\s+/iu',
      '',
      $title,
    );
    $cleaned = trim((string) $cleaned);
    if ($cleaned !== '' && $cleaned !== $title) {
      $course->setTitle($cleaned);
      $course->save();
      $stripped++;
    }
  }
  return sprintf('Course titles — stripped number prefix from: %d.', $stripped);
}

/**
 * Force pathauto to (re)generate every course's alias against the current
 * pattern. Drops any stale aliases still pointing at each course (e.g.
 * leftovers from earlier slug schemes).
 *
 * Idempotent — pathauto's update-action setting determines whether the
 * write is a no-op when the alias hasn't changed.
 */
function programhub_course_import_deploy_01_rebuild_course_aliases(array &$sandbox): string {
  $aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
  $courses = \Drupal::entityTypeManager()->getStorage('node')
    ->loadByProperties(['type' => 'course']);
  $touched = 0;
  foreach ($courses as $course) {
    // Drop any existing aliases — pathauto will generate a fresh one on save.
    foreach ($aliasStorage->loadByProperties(['path' => '/node/' . $course->id()]) as $a) {
      $a->delete();
    }
    // Re-enable pathauto on this node and resave; pathauto's
    // hook_entity_update fires and writes the new alias.
    $course->path->pathauto = 1;
    $course->save();
    $touched++;
  }
  return sprintf('Course aliases — touched: %d.', $touched);
}
