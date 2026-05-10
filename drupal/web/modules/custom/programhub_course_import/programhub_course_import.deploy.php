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
 * Course aliases depend on the pathauto.pattern.courses config landing in
 * step 2 (and its scrape-time field_course_number values), so this hook
 * runs in step 4.
 */

declare(strict_types=1);

/**
 * Resave every course so hook_node_presave (in
 * programhub_course_import.module) computes and writes its canonical
 * `/courses/<course-number>` alias. Drops any stale aliases pointing at
 * each course (e.g. previous incarnations under a different scheme).
 *
 * Idempotent — when alias is already correct the presave hook short-
 * circuits and the save is a no-op write.
 */
function programhub_course_import_deploy_01_rebuild_course_aliases(array &$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
  $courses = $storage->loadByProperties(['type' => 'course']);
  $touched = 0;
  foreach ($courses as $course) {
    $expected = programhub_course_import_compute_course_alias($course);
    if ($expected === '' || !$course->hasField('field_path')) {
      continue;
    }
    $current = (string) ($course->get('field_path')->value ?? '');
    $hasStale = FALSE;
    foreach ($aliasStorage->loadByProperties(['path' => '/node/' . $course->id()]) as $a) {
      if ($a->getAlias() !== $expected) {
        $a->delete();
        $hasStale = TRUE;
      }
    }
    if ($current !== $expected || $hasStale) {
      $course->save();
      $touched++;
    }
  }
  return sprintf('Course aliases — touched: %d (presave handled the writes).', $touched);
}
