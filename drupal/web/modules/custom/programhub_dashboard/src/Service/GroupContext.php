<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupMembership;

/**
 * Centralizes Group queries the dashboard widgets keep repeating.
 *
 * Every widget needs the same two questions answered:
 *
 *   1. Which programs (or divisions) does this user belong to, optionally
 *      filtered by their role? — `userGroupIds()`.
 *   2. Which content nodes are scoped to those groups? — `nidsInGroups()`.
 *
 * Centralizing here keeps the widgets thin and ensures the queries stay
 * consistent across the dashboard.
 */
final class GroupContext {

  /**
   * Every group_type that represents a program in ProgramHub.
   *
   * Single source of truth for code that needs to query across all
   * program-shaped group types (importers, dashboards, navigation).
   * Add new program subtypes here when introducing them.
   */
  public const PROGRAM_GROUP_TYPES = [
    'program',
    'program_design',
    'program_culinary',
  ];

  public function __construct(
    private readonly Connection $db,
  ) {}

  /**
   * Group IDs (gids) the user is a member of, scoped to a group bundle.
   *
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user to inspect.
   * @param string|null $bundle
   *   Optional group bundle filter — typically `program` or `division`.
   *   Pass NULL to include all group bundles.
   * @param string[]|null $roles
   *   Optional list of Group role IDs (e.g. `program-manager`); a
   *   membership matches if it carries at least one of these roles.
   *   Pass NULL to skip the role filter.
   *
   * @return int[]
   *   Group IDs, deduplicated.
   */
  public function userGroupIds(AccountInterface $user, ?string $bundle = NULL, ?array $roles = NULL): array {
    $memberships = GroupMembership::loadByUser($user);
    if (!$memberships) {
      return [];
    }
    $gids = [];
    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if (!$group) {
        continue;
      }
      if ($bundle !== NULL && $group->bundle() !== $bundle) {
        continue;
      }
      if ($roles !== NULL) {
        $hasRole = FALSE;
        foreach ($membership->getRoles() as $role) {
          if (in_array($role->id(), $roles, TRUE)) {
            $hasRole = TRUE;
            break;
          }
        }
        if (!$hasRole) {
          continue;
        }
      }
      $gids[(int) $group->id()] = (int) $group->id();
    }
    return array_values($gids);
  }

  /**
   * Group IDs of every program-shaped group the user is in.
   *
   * Covers `program` AND every subtype in PROGRAM_GROUP_TYPES.
   * Optional role filter matches by ROLE NAME SUFFIX, so callers don't
   * have to enumerate every per-subtype role ID:
   *
   *   ['instructor'] → matches program-instructor,
   *                            program_design-instructor,
   *                            program_culinary-instructor.
   *
   * @param string[]|null $roleSuffixes
   *
   * @return int[]
   */
  public function userProgramGroupIds(AccountInterface $user, ?array $roleSuffixes = NULL): array {
    $memberships = GroupMembership::loadByUser($user);
    if (!$memberships) {
      return [];
    }
    $allowedRoles = NULL;
    if ($roleSuffixes !== NULL) {
      $allowedRoles = [];
      foreach (self::PROGRAM_GROUP_TYPES as $type) {
        foreach ($roleSuffixes as $suffix) {
          $allowedRoles["$type-$suffix"] = TRUE;
        }
      }
    }
    $gids = [];
    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if (!$group || !in_array($group->bundle(), self::PROGRAM_GROUP_TYPES, TRUE)) {
        continue;
      }
      if ($allowedRoles !== NULL) {
        $match = FALSE;
        foreach ($membership->getRoles() as $role) {
          if (isset($allowedRoles[$role->id()])) {
            $match = TRUE;
            break;
          }
        }
        if (!$match) {
          continue;
        }
      }
      $gids[(int) $group->id()] = (int) $group->id();
    }
    return array_values($gids);
  }

  /**
   * Same shape as userProgramGroupIds() but returns gid → label.
   * For widgets rendering per-program tables.
   *
   * @return array<int,string>
   */
  public function userProgramsByLabel(AccountInterface $user, ?array $roleSuffixes = NULL): array {
    $gids = $this->userProgramGroupIds($user, $roleSuffixes);
    if (!$gids) {
      return [];
    }
    $out = [];
    foreach (\Drupal::entityTypeManager()->getStorage('group')->loadMultiple($gids) as $group) {
      $out[(int) $group->id()] = (string) $group->label();
    }
    return $out;
  }

  /**
   * Programs (gid → label) the user can manage, with role filtering.
   *
   * @deprecated Use userProgramsByLabel() — it handles all program
   * subtypes (program, program_design, program_culinary).
   *
   * @return array<int,string>
   */
  public function userPrograms(AccountInterface $user, ?array $roles = NULL): array {
    $memberships = GroupMembership::loadByUser($user);
    if (!$memberships) {
      return [];
    }
    $out = [];
    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if (!$group || !in_array($group->bundle(), self::PROGRAM_GROUP_TYPES, TRUE)) {
        continue;
      }
      if ($roles !== NULL) {
        $hasRole = FALSE;
        foreach ($membership->getRoles() as $role) {
          if (in_array($role->id(), $roles, TRUE)) {
            $hasRole = TRUE;
            break;
          }
        }
        if (!$hasRole) {
          continue;
        }
      }
      $out[(int) $group->id()] = (string) $group->label();
    }
    return $out;
  }

  /**
   * Node IDs of content scoped to one or more groups.
   *
   * Drupal's entity query can't directly traverse the group_relationship
   * table without a Views relationship, so we hit it as a raw SELECT —
   * faster too, since we only need IDs.
   *
   * @param int[] $groupIds
   * @param string[]|null $bundles
   *   Optional list of node bundles. NULL matches every gnode bundle.
   *
   * @return int[]
   */
  public function nidsInGroups(array $groupIds, ?array $bundles = NULL): array {
    if (!$groupIds) {
      return [];
    }
    $q = $this->db->select('group_relationship_field_data', 'g')
      ->fields('g', ['entity_id'])
      ->condition('gid', $groupIds, 'IN');
    if ($bundles !== NULL) {
      $plugins = array_map(static fn(string $b): string => "group_node:$b", $bundles);
      $q->condition('plugin_id', $plugins, 'IN');
    }
    else {
      $q->condition('plugin_id', 'group_node:%', 'LIKE');
    }
    return array_map('intval', $q->execute()->fetchCol());
  }

}
