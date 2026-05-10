<?php

declare(strict_types=1);

namespace Drupal\og_permissions_override;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\og_permissions_override\Entity\OgPermissionOverride;

/**
 * Resolves which permission deltas apply for a given (OG role × group).
 *
 * Phase 1 stub: loads matching `OgPermissionOverride` config entities and
 * returns their granted/revoked arrays. With no overrides configured this
 * is always an empty delta, so behavior matches bundle-default OG roles.
 *
 * Phase 4 work: add a per-request memoize cache; consider invalidating on
 * config save events; potentially front-load all overrides into a single
 * keyed array (today's volume is small enough that a per-call load works).
 */
final class OgPermissionOverrideResolver {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns the permission delta for a specific OG role on a specific group.
   *
   * @return array{granted: string[], revoked: string[]}
   *   `granted` is permissions to add on top of the bundle role; `revoked`
   *   is permissions to subtract. Both empty means "no override; use
   *   bundle defaults".
   */
  public function getDelta(string $ogRoleId, string $groupEntityType, int $groupId): array {
    $storage = $this->entityTypeManager->getStorage('og_permission_override');
    $matches = $storage->loadByProperties([
      'og_role' => $ogRoleId,
      'group_entity_type' => $groupEntityType,
      'group_id' => $groupId,
    ]);

    if (empty($matches)) {
      return ['granted' => [], 'revoked' => []];
    }

    $granted = [];
    $revoked = [];
    foreach ($matches as $override) {
      if (!$override instanceof OgPermissionOverride) {
        continue;
      }
      $granted = array_merge($granted, $override->getGranted());
      $revoked = array_merge($revoked, $override->getRevoked());
    }

    return [
      'granted' => array_values(array_unique($granted)),
      'revoked' => array_values(array_unique($revoked)),
    ];
  }

}
