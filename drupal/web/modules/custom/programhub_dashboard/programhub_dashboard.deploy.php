<?php

/**
 * @file
 * Deploy hooks for ProgramHub dashboard.
 *
 * Run AFTER `drush config:import` (per CONVENTIONS.md). By then the
 * Group types, roles, and gnode plugin configs are in place, so the
 * one-shot OG → Group migration can populate them from the legacy OG
 * tables.
 *
 * Every hook MUST be idempotent — re-runs (DB restore, snapshot, fresh
 * build) re-execute every deploy hook.
 */

declare(strict_types=1);

/**
 * Migrate Organic Groups data → Group module entities.
 *
 * Pulls the legacy OG data forward in three passes:
 *   1. `node:program` / `node:division` → matching `group:program` /
 *      `group:division` with the same title and custom field values.
 *   2. `og_membership` rows → `group_membership` relationships with the
 *      mapped Group role.
 *   3. Every node with an `og_audience` value → `group_node:{bundle}`
 *      relationship to the new group.
 *
 * Subsequent rows are skipped if a corresponding Group row already
 * exists, so re-running this deploy hook on a snapshot that's already
 * migrated is a no-op.
 *
 * Phase 4 of the migration plan deletes the OG fields/configs in a
 * later commit; that's gated behind running this hook successfully.
 */
function programhub_dashboard_deploy_001_migrate_og_to_group(): string {
  /** @var \Drupal\programhub_dashboard\Migration\OgToGroupMigrator $migrator */
  $migrator = \Drupal::service('programhub_dashboard.og_to_group_migrator');
  $result = $migrator->run(FALSE, \Drupal::logger('programhub_dashboard'));

  return sprintf(
    'OG → Group migrated: %d groups, %d memberships, %d content rows.',
    $result['groups'],
    $result['members'],
    $result['content'],
  );
}

/**
 * Move known programs to their specialized group_types.
 *
 * Runs after `_001` so the base `program` groups exist by the time we
 * try to retype them. Idempotent — moveByLabel() returns a no-op if the
 * group is already on the target type (or no source group with that
 * label exists, which is the steady state once it has been moved).
 *
 * Mapping rationale:
 *   - Graphic & Web Design → program_design (portfolio_show emphasis).
 *
 * Future programs can be appended to this map without renumbering the
 * deploy hook (the moves themselves are idempotent), or — when more
 * cleanly — a fresh `_NNN_retype_*` hook can be added.
 */
function programhub_dashboard_deploy_002_retype_specialized_programs(): string {
  $map = [
    // [source label, current type, target type]
    ['Graphic & Web Design', 'program', 'program_design'],
  ];

  /** @var \Drupal\programhub_dashboard\Migration\GroupTypeMover $mover */
  $mover = \Drupal::service('programhub_dashboard.group_type_mover');
  $logger = \Drupal::logger('programhub_dashboard');

  $moved = 0;
  $skippedNotFound = 0;
  foreach ($map as [$label, $sourceType, $targetType]) {
    $result = $mover->moveByLabel($label, $sourceType, $targetType, FALSE, $logger);
    if ($result['moved']) {
      $moved++;
    }
    elseif ($result['newGid'] === NULL) {
      $skippedNotFound++;
    }
  }

  return sprintf(
    'Specialized program retype: %d moved, %d already-on-target / not-found.',
    $moved,
    count($map) - $moved,
  );
}
