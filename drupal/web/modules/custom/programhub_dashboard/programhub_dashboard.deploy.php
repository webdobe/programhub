<?php

/**
 * @file
 * Deploy hooks for ProgramHub dashboard.
 *
 * The big OG → Group migration moved out of this file and into
 * `programhub_careers.post_update.php` because it has to run BEFORE
 * `config:import` — by the time `deploy:hook` fires, og has been
 * uninstalled and the og tables we need to read are gone. This file
 * carries the *post-cim* tasks: anything that needs Group/Subgroup
 * config already in place (relationship-type plugins, role inheritance,
 * etc.) to do its work.
 */

declare(strict_types=1);

use Drupal\group\Entity\Group;
use Drupal\programhub_dashboard\Service\GroupContext;

/**
 * Attach every program-shaped group to the CTE division as a subgroup.
 *
 * Runs after `config:import` so the `subgroup:program`,
 * `subgroup:program_design`, and `subgroup:program_culinary`
 * relationship-type plugins are installed on `division`. Walks every
 * program / program_design / program_culinary group, finds the single
 * `division` group (CTE), and creates a subgroup relationship.
 *
 * Idempotent: skips any program already related to the division via
 * the same plugin. Safe to re-run on a snapshot or a partially-completed
 * deploy.
 */
function programhub_dashboard_deploy_001_attach_programs_to_division(): string {
  $etm = \Drupal::entityTypeManager();
  $logger = \Drupal::logger('programhub_dashboard');

  $divisionIds = $etm->getStorage('group')->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'division')
    ->range(0, 1)
    ->execute();
  if (!$divisionIds) {
    return 'No division group found; nothing to attach.';
  }
  $cte = Group::load((int) reset($divisionIds));

  $programGids = $etm->getStorage('group')->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', GroupContext::PROGRAM_GROUP_TYPES, 'IN')
    ->execute();

  $attached = 0;
  $skipped = 0;
  foreach (Group::loadMultiple($programGids) as $program) {
    $pluginId = 'subgroup:' . $program->bundle();
    if ($cte->getRelationshipsByEntity($program, $pluginId)) {
      $skipped++;
      continue;
    }
    $cte->addRelationship($program, $pluginId);
    $attached++;
    $logger->notice(
      'Attached @label (gid @gid, @type) under @cte as subgroup.',
      [
        '@label' => $program->label(),
        '@gid' => $program->id(),
        '@type' => $program->bundle(),
        '@cte' => $cte->label(),
      ],
    );
  }

  return sprintf('Attached %d programs to CTE; %d already attached.', $attached, $skipped);
}
