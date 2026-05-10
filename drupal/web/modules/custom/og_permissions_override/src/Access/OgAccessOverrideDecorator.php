<?php

declare(strict_types=1);

namespace Drupal\og_permissions_override\Access;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\og\OgAccessInterface;
use Drupal\og_permissions_override\OgPermissionOverrideResolver;

/**
 * Decorates `og.access` to apply per-group permission overrides.
 *
 * Phase 1 ships this as a pass-through — every method delegates to the
 * inner service unchanged. The plumbing is in place; no permission
 * decisions are altered yet.
 *
 * Phase 4 will add the actual override logic in `userAccess()`:
 *
 *   1. Call the inner `$this->inner->userAccess($group, $operation, $user)`.
 *   2. Ask the resolver for any granted/revoked permissions for the
 *      user's OG role(s) on this group.
 *   3. If `$operation` is in `granted`, return `AccessResult::allowed()`.
 *   4. If `$operation` is in `revoked`, return `AccessResult::forbidden()`.
 *   5. Otherwise return the inner result.
 *
 * The decorator must implement every method of `OgAccessInterface`
 * verbatim — Drupal's service-decoration model requires the wrapper to
 * preserve the contract.
 */
final class OgAccessOverrideDecorator implements OgAccessInterface {

  public function __construct(
    private readonly OgAccessInterface $inner,
    // Resolver is injected for Phase 4; not yet consulted.
    private readonly OgPermissionOverrideResolver $resolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function userAccess(EntityInterface $group, string $operation, ?AccountInterface $user = NULL): AccessResultInterface {
    // Phase 1: pass-through. Phase 4: apply resolver delta on top.
    return $this->inner->userAccess($group, $operation, $user);
  }

  /**
   * {@inheritdoc}
   */
  public function userAccessEntity(string $operation, EntityInterface $entity, ?AccountInterface $user = NULL): AccessResultInterface {
    // Phase 1: pass-through. Phase 4: apply resolver delta on top.
    return $this->inner->userAccessEntity($operation, $entity, $user);
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
  public function userAccessGroupContentEntityOperation(string $operation, EntityInterface $group_content, ?AccountInterface $user = NULL): AccessResultInterface {
    return $this->inner->userAccessGroupContentEntityOperation($operation, $group_content, $user);
  }

  /**
   * Catch-all for any other method on `OgAccessInterface` that the OG
   * version we're depending on adds. Without this, a future OG release
   * adding a method to the interface would break the decorator on
   * `composer update`.
   *
   * Removed in Phase 4 once we override every method explicitly.
   *
   * @phpstan-ignore method.unused
   */
  public function __call(string $name, array $arguments): mixed {
    if (method_exists($this->inner, $name)) {
      return $this->inner->{$name}(...$arguments);
    }
    throw new \BadMethodCallException("Method {$name} does not exist on the decorated OG access service.");
  }

}
