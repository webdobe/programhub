<?php

declare(strict_types=1);

namespace Drupal\og_permissions_override\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\og\MembershipManagerInterface;
use Drupal\og\OgAccess;
use Drupal\og\OgAccessInterface;
use Drupal\og_permissions_override\OgPermissionOverrideResolver;

/**
 * Decorates `og.access` to apply per-group permission overrides.
 *
 * Extends the concrete `OgAccess` class rather than just implementing
 * `OgAccessInterface` because contrib OG type-hints the CONCRETE class
 * on several consumer constructor params (e.g. `OgMembershipAccessControlHandler`).
 * Decoration would TypeError on first request if the decorator weren't
 * an `OgAccess` instance.
 *
 * We deliberately do NOT call `parent::__construct()` — the parent's
 * 8 readonly properties stay uninitialized. That's safe because:
 *   1. OG's public surface (4 methods on OgAccessInterface) is fully
 *      overridden here; nothing ever invokes the parent's logic.
 *   2. The parent's readonly props are only used internally by the
 *      parent's own public methods, which we no longer call.
 *
 * Override semantics:
 *   - The decorator first asks the inner (real) `og.access` for the
 *     base access decision.
 *   - It then consults the resolver for permission deltas on every
 *     OG role the user holds in the target group.
 *   - **Revoke wins over grant.** If any matching override revokes the
 *     permission, the decision becomes forbidden. Otherwise, if any
 *     matching override grants the permission and the base was not
 *     already allowed, the decision flips to allowed.
 *   - Cacheability: result inherits all cache tags/contexts from both
 *     the inner result and the resolver. If overrides are added or
 *     removed, the resolver's tags invalidate this decision.
 *
 * Only `userAccess()` consults overrides today. The other three
 * interface methods (`userAccessEntity()`, `userAccessEntityOperation()`,
 * `userAccessGroupContentEntityOperation()`) ultimately delegate to
 * `userAccess()` inside OG's own implementation, so overrides propagate
 * automatically.
 */
final class OgAccessOverrideDecorator extends OgAccess implements OgAccessInterface {

  public function __construct(
    private readonly OgAccessInterface $inner,
    private readonly OgPermissionOverrideResolver $resolver,
    // Renamed from `membershipManager` to avoid colliding with the
    // protected property of the same name on parent OgAccess. PHP
    // forbids narrowing visibility (protected → private = fatal).
    private readonly MembershipManagerInterface $ogMembershipManager,
  ) {
    // No parent::__construct() — see class docblock.
  }

  /**
   * {@inheritdoc}
   */
  public function userAccess(EntityInterface $group, string $permission, ?AccountInterface $user = NULL, bool $skip_alter = FALSE): AccessResultInterface {
    $base = $this->inner->userAccess($group, $permission, $user, $skip_alter);

    // Anonymous / no user context — nothing to override. Most OG checks
    // pass a user; the null fallback is defensive.
    if ($user === NULL) {
      return $base;
    }

    // Look up the user's OG roles in THIS specific group. If they're
    // not a member, no per-group override applies.
    $membership = $this->ogMembershipManager->getMembership($group, (int) $user->id());
    if ($membership === NULL) {
      return $base;
    }

    $hasGrant = FALSE;
    $hasRevoke = FALSE;
    foreach ($membership->getRoles() as $role) {
      $delta = $this->resolver->getDelta(
        $role->id(),
        $group->getEntityTypeId(),
        (int) $group->id(),
      );
      if (in_array($permission, $delta['granted'], TRUE)) {
        $hasGrant = TRUE;
      }
      if (in_array($permission, $delta['revoked'], TRUE)) {
        $hasRevoke = TRUE;
      }
    }

    // Revoke always wins: even if base allowed, an explicit revoke
    // takes precedence. Carry through base's cacheability so cache
    // invalidation still works when bundle-level perms change.
    if ($hasRevoke) {
      return AccessResult::forbidden('Revoked by og_permissions_override')
        ->addCacheableDependency($base)
        ->addCacheTags(['config:og_permissions_override.override.list'])
        ->addCacheContexts(['user']);
    }

    // Grant flips a forbidden base to allowed. Doesn't touch a base
    // that's already allowed (no-op) or neutral.
    if ($hasGrant && !$base->isAllowed()) {
      return AccessResult::allowed()
        ->addCacheableDependency($base)
        ->addCacheTags(['config:og_permissions_override.override.list'])
        ->addCacheContexts(['user']);
    }

    // No override applies — return the base decision, but include
    // override cache tags so adding an override later invalidates this.
    return $base
      ->addCacheTags(['config:og_permissions_override.override.list'])
      ->addCacheContexts(['user']);
  }

  /**
   * {@inheritdoc}
   */
  public function userAccessEntity(string $permission, EntityInterface $entity, ?AccountInterface $user = NULL): AccessResultInterface {
    return $this->inner->userAccessEntity($permission, $entity, $user);
  }

  /**
   * {@inheritdoc}
   */
  public function userAccessEntityOperation(string $operation, EntityInterface $entity, ?AccountInterface $user = NULL): AccessResultInterface {
    return $this->inner->userAccessEntityOperation($operation, $entity, $user);
  }

  /**
   * {@inheritdoc}
   */
  public function userAccessGroupContentEntityOperation(string $operation, EntityInterface $group_entity, EntityInterface $group_content_entity, ?AccountInterface $user = NULL): AccessResultInterface {
    return $this->inner->userAccessGroupContentEntityOperation($operation, $group_entity, $group_content_entity, $user);
  }

}
