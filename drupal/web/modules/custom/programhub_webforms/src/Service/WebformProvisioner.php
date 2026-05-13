<?php

declare(strict_types=1);

namespace Drupal\programhub_webforms\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\webform\Entity\Webform;

/**
 * Clones canonical "request info" and "subscribe" webforms into one
 * per-group instance keyed off the group's `field_abbreviation`.
 *
 * Naming: `{abbr}_request_info` and `{abbr}_subscribe`, both lowercase.
 * Category on each new webform is set to the abbreviation so the
 * webform admin UI groups them together.
 *
 * Idempotent: if either target id already exists we skip it, so this
 * service is safe to invoke from both the runtime hook (group insert)
 * and a one-shot deploy backfill across every existing group.
 */
final class WebformProvisioner {

  /**
   * Source webform id => target suffix.
   *
   * The source webforms are the existing canonical forms in
   * `config/sync` (cite_request_info / subscribe). Their elements,
   * handlers, and settings are cloned verbatim; only id, title, and
   * categories are overridden on the copy.
   */
  private const TEMPLATES = [
    'cite_request_info' => 'request_info',
    'subscribe' => 'subscribe',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Provision both webforms for a group. Returns the count created.
   *
   * Returns 0 when the group has no abbreviation or every target id
   * already exists — the latter is the steady state once a group has
   * been provisioned at least once.
   */
  public function provisionForGroup(GroupInterface $group): int {
    $abbr = $this->abbreviation($group);
    if ($abbr === NULL) {
      return 0;
    }
    $logger = $this->loggerFactory->get('programhub_webforms');
    $storage = $this->etm->getStorage('webform');
    $created = 0;

    foreach (self::TEMPLATES as $sourceId => $suffix) {
      $targetId = $abbr . '_' . $suffix;
      if ($storage->load($targetId)) {
        continue;
      }
      $source = $storage->load($sourceId);
      if (!$source instanceof Webform) {
        $logger->warning('Skipping @target — source template @source missing.', [
          '@target' => $targetId,
          '@source' => $sourceId,
        ]);
        continue;
      }

      $copy = $source->createDuplicate();
      $copy->set('id', $targetId);
      $copy->set('uuid', \Drupal::service('uuid')->generate());
      $copy->set('title', $this->title($group, $suffix));
      $copy->set('description', $source->get('description'));
      $copy->set('categories', [strtoupper($abbr)]);
      // Cloned webforms inherit `template: true/archive: true` from
      // their source; force back to a regular open form.
      $copy->set('template', FALSE);
      $copy->set('archive', FALSE);
      $copy->set('status', $source->get('status'));
      $copy->save();

      $created++;
      $logger->notice('Provisioned webform @id (category @cat) for group @gid.', [
        '@id' => $targetId,
        '@cat' => strtoupper($abbr),
        '@gid' => $group->id(),
      ]);
    }
    return $created;
  }

  /**
   * Lowercase, slugified abbreviation suitable for a webform id, or
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

  /**
   * Human-friendly title for the new webform. Groups the abbreviation
   * with the form's intent so the admin webform list reads cleanly.
   */
  private function title(GroupInterface $group, string $suffix): string {
    $label = (string) $group->label();
    return match ($suffix) {
      'request_info' => sprintf('Request Info · %s', $label),
      'subscribe' => sprintf('Subscribe · %s', $label),
      default => sprintf('%s · %s', ucfirst(str_replace('_', ' ', $suffix)), $label),
    };
  }

}
