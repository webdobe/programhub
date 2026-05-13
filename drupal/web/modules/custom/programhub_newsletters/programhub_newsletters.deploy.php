<?php

/**
 * @file
 * Deploy hooks for programhub_newsletters.
 *
 * Runs after `config:import`, so the source template
 * (`simplenews.newsletter.default`) is guaranteed to exist before
 * we try to clone it.
 */

declare(strict_types=1);

use Drupal\group\Entity\Group;

/**
 * Backfill `{abbr}_newsletter` for every existing group. New groups
 * get theirs via hook_group_insert(); this one-shot covers everything
 * that predates the module.
 *
 * Idempotent — NewsletterProvisioner skips ids that already exist.
 */
function programhub_newsletters_deploy_001_backfill_existing_groups(): string {
  /** @var \Drupal\programhub_newsletters\Service\NewsletterProvisioner $provisioner */
  $provisioner = \Drupal::service('programhub_newsletters.provisioner');
  $etm = \Drupal::entityTypeManager();

  $gids = $etm->getStorage('group')->getQuery()
    ->accessCheck(FALSE)
    ->execute();

  $created = 0;
  foreach (Group::loadMultiple($gids) as $group) {
    $created += $provisioner->provisionForGroup($group);
  }

  return sprintf(
    'Provisioned %d newsletters across %d groups scanned.',
    $created,
    count($gids),
  );
}
