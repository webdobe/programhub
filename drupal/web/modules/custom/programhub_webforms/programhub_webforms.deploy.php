<?php

/**
 * @file
 * Deploy hooks for programhub_webforms.
 *
 * Runs after `config:import`, so all webforms in `config/sync` (the
 * source templates) are guaranteed to exist before we try to clone.
 */

declare(strict_types=1);

use Drupal\group\Entity\Group;

/**
 * Backfill `{abbr}_request_info` and `{abbr}_subscribe` for every
 * existing group. New groups get them via hook_group_insert(); this
 * one-shot covers everything that predates the module.
 *
 * Idempotent — WebformProvisioner skips ids that already exist, so
 * safe to re-run after the initial deploy.
 */
function programhub_webforms_deploy_001_backfill_existing_groups(): string {
  /** @var \Drupal\programhub_webforms\Service\WebformProvisioner $provisioner */
  $provisioner = \Drupal::service('programhub_webforms.provisioner');
  $etm = \Drupal::entityTypeManager();

  $gids = $etm->getStorage('group')->getQuery()
    ->accessCheck(FALSE)
    ->execute();

  $createdTotal = 0;
  $touched = 0;
  foreach (Group::loadMultiple($gids) as $group) {
    $created = $provisioner->provisionForGroup($group);
    if ($created > 0) {
      $createdTotal += $created;
      $touched++;
    }
  }

  return sprintf(
    'Provisioned %d webforms across %d groups (%d groups scanned).',
    $createdTotal,
    $touched,
    count($gids),
  );
}
