<?php

/**
 * @file
 * Deploy hooks for programhub_webform_simplenews.
 */

declare(strict_types=1);

/**
 * Backfill the simplenews subscribe handler across every existing
 * `{abbr}_subscribe` webform. New webforms get it via
 * hook_webform_insert(); this one-shot covers everything that
 * predates the module. Idempotent.
 */
function programhub_webform_simplenews_deploy_001_wire_existing_subscribe_forms(): string {
  /** @var \Drupal\programhub_webform_simplenews\Service\SubscribeHandlerWiring $wiring */
  $wiring = \Drupal::service('programhub_webform_simplenews.wiring');
  $count = $wiring->wireAll();
  return sprintf('Wired %d subscribe webforms.', $count);
}
