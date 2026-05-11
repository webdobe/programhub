<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Migration;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRelationshipType;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * One-shot OG → Group module migration.
 *
 * Pure data migration: assumes the Group module + its config (group
 * types, roles, gnode plugins) are already imported. Idempotent —
 * every write checks if the destination row already exists, so this
 * service is safe to call from both `drush phmog` and a deploy hook.
 *
 * Run order on each pass:
 *
 *   1. Programs/divisions: each `node:program` and `node:division` is
 *      mirrored into a matching `group:program` / `group:division` with
 *      the same title and custom field values.
 *   2. Memberships: every `og_membership` row becomes a `group_membership`
 *      relationship with the equivalent Group role.
 *   3. Group content: every node with an `og_audience` reference becomes
 *      a `group_node:{bundle}` relationship pointing back to the new
 *      group.
 *
 * The node→group ID map lives in state (`programhub.og_migration.map`)
 * so subsequent runs see the existing groups and skip recreation.
 */
final class OgToGroupMigrator {

  public const STATE_KEY = 'programhub.og_migration.node_to_group_id';

  /**
   * OG role machine-name → Group role machine-name.
   *
   * `*-member` and `*-non-member` aren't mapped: Group ships synthetic
   * `member` / `outsider` roles, so explicit recreation isn't needed.
   */
  private const ROLE_MAP = [
    'node-program-administrator' => 'program-administrator',
    'node-program-manager' => 'program-manager',
    'node-program-instructor' => 'program-instructor',
    'node-program-student' => 'program-student',
    'node-program-graduate' => 'program-graduate',
    'node-program-tac_member' => 'program-tac_member',
    'node-division-administrator' => 'division-administrator',
  ];

  /**
   * Custom fields to copy from node:{program,division} → group:{type}.
   */
  private const COPY_FIELDS = [
    'program' => ['field_abbreviation', 'field_path', 'field_website', 'field_soc_codes'],
    'division' => ['field_abbreviation', 'field_path', 'field_website'],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly StateInterface $state,
    private readonly Connection $db,
  ) {}

  /**
   * Run the migration end-to-end and return a summary.
   *
   * @param bool $dryRun
   *   When TRUE, log planned writes but make no changes.
   * @param \Psr\Log\LoggerInterface|null $logger
   *   Optional logger; used for per-row notices. Falls back to the
   *   `programhub_dashboard` channel.
   *
   * @return array{groups:int,members:int,content:int}
   */
  public function run(bool $dryRun = FALSE, ?LoggerInterface $logger = NULL): array {
    $logger ??= \Drupal::logger('programhub_dashboard');

    // Once Phase 4 ships, the OG tables and node:program bundle are gone
    // — every fresh-env replay of this hook becomes a clean no-op.
    if (!$this->db->schema()->tableExists('og_membership')
      || !$this->db->schema()->tableExists('node__og_audience')) {
      $logger->notice('OG tables not present; nothing to migrate.');
      return ['groups' => 0, 'members' => 0, 'content' => 0];
    }

    $map = $this->state->get(self::STATE_KEY, []);
    $groups = $this->migrateGroups($map, $dryRun, $logger);
    if (!$dryRun) {
      $this->state->set(self::STATE_KEY, $map);
    }
    $members = $this->migrateMemberships($map, $dryRun, $logger);
    $content = $this->migrateGroupContent($map, $dryRun, $logger);

    return ['groups' => $groups, 'members' => $members, 'content' => $content];
  }

  private function migrateGroups(array &$map, bool $dry, LoggerInterface $logger): int {
    $created = 0;
    // Bundles disappear once Phase 4 lands.
    $bundleInfo = \Drupal::service('entity_type.bundle.info')->getBundleInfo('node');
    $bundles = array_filter(
      array_keys(self::COPY_FIELDS),
      static fn(string $b): bool => isset($bundleInfo[$b]),
    );
    if (!$bundles) {
      return 0;
    }
    $storage = $this->etm->getStorage('node');
    $nids = $storage->getQuery()
      ->condition('type', $bundles, 'IN')
      ->accessCheck(FALSE)
      ->execute();

    foreach ($storage->loadMultiple($nids) as $node) {
      assert($node instanceof NodeInterface);
      if (isset($map[$node->id()])) {
        continue;
      }
      $type = $node->bundle();
      $logger->notice("[group] $type:{$node->id()} {$node->label()}");
      if ($dry) {
        $map[(int) $node->id()] = 'DRY';
        $created++;
        continue;
      }
      $values = [
        'type' => $type,
        'label' => $node->label(),
        'uid' => $node->getOwnerId(),
      ];
      foreach (self::COPY_FIELDS[$type] as $f) {
        if ($node->hasField($f) && !$node->get($f)->isEmpty()) {
          $values[$f] = $node->get($f)->getValue();
        }
      }
      $group = Group::create($values);
      $group->save();
      $map[(int) $node->id()] = (int) $group->id();
      $created++;
    }
    return $created;
  }

  private function migrateMemberships(array $map, bool $dry, LoggerInterface $logger): int {
    $created = 0;
    $rows = $this->db->select('og_membership', 'm')
      ->fields('m', ['id', 'uid', 'entity_id', 'entity_type'])
      ->condition('m.entity_type', 'node')
      ->execute()
      ->fetchAll();

    // Bulk-load roles to avoid N+1.
    $rolesByMembership = [];
    if ($rows) {
      $roleRows = $this->db->select('og_membership__roles', 'r')
        ->fields('r', ['entity_id', 'roles_target_id'])
        ->execute()
        ->fetchAll();
      foreach ($roleRows as $r) {
        $rolesByMembership[$r->entity_id][] = $r->roles_target_id;
      }
    }

    foreach ($rows as $row) {
      $nid = (int) $row->entity_id;
      $uid = (int) $row->uid;
      if (!isset($map[$nid])) {
        $logger->warning("Skip og_membership {$row->id} — no group for nid $nid");
        continue;
      }
      $user = User::load($uid);
      if (!$user) {
        $logger->warning("Skip og_membership {$row->id} — user $uid not found");
        continue;
      }

      // Idempotency: skip if user is already a member of this group.
      if (!$dry) {
        $group = Group::load($map[$nid]);
        $exists = $this->db->select('group_relationship_field_data', 'g')
          ->fields('g', ['id'])
          ->condition('gid', $group->id())
          ->condition('entity_id', $uid)
          ->condition('plugin_id', 'group_membership')
          ->execute()
          ->fetchField();
        if ($exists) {
          continue;
        }
      }

      $roleIds = [];
      foreach ($rolesByMembership[$row->id] ?? [] as $ogRole) {
        if (isset(self::ROLE_MAP[$ogRole])) {
          $roleIds[] = self::ROLE_MAP[$ogRole];
        }
      }
      $logger->notice("[member] gid {$map[$nid]} ← uid $uid (roles: " . implode(',', $roleIds) . ')');
      if (!$dry) {
        $group->addMember($user, ['group_roles' => $roleIds]);
      }
      $created++;
    }
    return $created;
  }

  private function migrateGroupContent(array $map, bool $dry, LoggerInterface $logger): int {
    $created = 0;
    // content_plugin → relationship_type ID. Some IDs are auto-hashed
    // when the plugin name is long, so we resolve at runtime.
    $relTypeIds = [];
    foreach (GroupRelationshipType::loadMultiple() as $rt) {
      $relTypeIds[$rt->get('group_type') . '|' . $rt->getPluginId()] = $rt->id();
    }

    $rows = $this->db->select('node__og_audience', 'a')
      ->fields('a', ['entity_id', 'bundle', 'og_audience_target_id'])
      ->execute()
      ->fetchAll();

    foreach ($rows as $row) {
      $bundle = $row->bundle;
      $nid = (int) $row->entity_id;
      $target = (int) $row->og_audience_target_id;

      // Programs/divisions became groups themselves — skip.
      if (in_array($bundle, array_keys(self::COPY_FIELDS), TRUE)) {
        continue;
      }
      if (!isset($map[$target])) {
        $logger->warning("Skip $bundle:$nid — audience nid $target not migrated.");
        continue;
      }
      $node = $this->etm->getStorage('node')->load($nid);
      if (!$node) {
        continue;
      }

      $pluginId = "group_node:$bundle";
      $groupId = $map[$target];

      if (!$dry) {
        $group = Group::load($groupId);
        $relTypeKey = $group->bundle() . '|' . $pluginId;
        $relTypeId = $relTypeIds[$relTypeKey] ?? NULL;
        if (!$relTypeId) {
          $logger->warning("Skip $bundle:$nid — no relationship type for $relTypeKey on group $groupId.");
          continue;
        }
        $exists = $this->db->select('group_relationship_field_data', 'g')
          ->fields('g', ['id'])
          ->condition('gid', $groupId)
          ->condition('entity_id', $nid)
          ->condition('type', $relTypeId)
          ->execute()
          ->fetchField();
        if ($exists) {
          continue;
        }
        $group->addRelationship($node, $pluginId);
      }

      $logger->notice("[content] $bundle:$nid → gid $groupId");
      $created++;
    }
    return $created;
  }

}
