<?php

declare(strict_types=1);

namespace Drupal\programhub_newsletters\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\simplenews\Entity\Newsletter;

/**
 * Clones a canonical simplenews newsletter into one per-group instance
 * keyed off the group's `field_abbreviation`.
 *
 * Naming: `{abbr}_newsletter` (lowercase). The clone inherits the
 * template's format/priority/handlers/etc.; only id, name,
 * description, and from_name are overridden so the new list reads as
 * the group's own.
 *
 * Idempotent — skips when the target id already exists, so safe from
 * both the runtime hook (group insert) and the deploy backfill.
 */
final class NewsletterProvisioner {

  private const SOURCE_TEMPLATE = 'default';

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Provision the per-group newsletter. Returns 1 if created, 0 if
   * skipped (no abbreviation, or target already exists).
   */
  public function provisionForGroup(GroupInterface $group): int {
    $abbr = $this->abbreviation($group);
    if ($abbr === NULL) {
      return 0;
    }
    $logger = $this->loggerFactory->get('programhub_newsletters');
    $storage = $this->etm->getStorage('simplenews_newsletter');
    $targetId = $abbr . '_newsletter';

    if ($storage->load($targetId)) {
      return 0;
    }
    $source = $storage->load(self::SOURCE_TEMPLATE);
    if (!$source instanceof Newsletter) {
      $logger->warning('Skipping @target — source template @source missing.', [
        '@target' => $targetId,
        '@source' => self::SOURCE_TEMPLATE,
      ]);
      return 0;
    }

    $copy = $source->createDuplicate();
    $copy->set('id', $targetId);
    $copy->set('uuid', \Drupal::service('uuid')->generate());
    $copy->set('name', sprintf('%s Newsletter', $group->label()));
    $copy->set('description', sprintf('Subscribers for %s.', $group->label()));
    $copy->set('from_name', (string) $group->label());
    $copy->save();

    $logger->notice('Provisioned newsletter @id for group @gid.', [
      '@id' => $targetId,
      '@gid' => $group->id(),
    ]);
    return 1;
  }

  /**
   * Lowercase, slugified abbreviation suitable for a config id, or
   * NULL when the group lacks a non-empty abbreviation.
   */
  private function abbreviation(GroupInterface $group): ?string {
    if (!$group->hasField('field_abbreviation') || $group->get('field_abbreviation')->isEmpty()) {
      return NULL;
    }
    $raw = (string) $group->get('field_abbreviation')->value;
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $raw) ?? '');
    $slug = trim($slug, '_');
    return $slug === '' ? NULL : $slug;
  }

}
