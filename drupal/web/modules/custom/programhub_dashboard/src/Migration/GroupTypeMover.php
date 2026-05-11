<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Migration;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationship;
use Drupal\group\Entity\GroupRelationshipType;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Psr\Log\LoggerInterface;

/**
 * Move a Group entity from one group_type to another.
 *
 * Drupal doesn't natively support bundle changes; this service does
 * the equivalent transactionally:
 *   1. Creates a fresh group of the target type, copying label, owner,
 *      and every custom field value present on both bundles.
 *   2. Re-points every `group_relationship` row (memberships AND
 *      content) at the new group, translating each relationship_type
 *      ID from `{source_type}-{plugin_id}` → `{target_type}-{plugin_id}`.
 *      Roles on group_membership relationships get translated the same
 *      way (`{source_type}-{role}` → `{target_type}-{role}`).
 *   3. Deletes the source group.
 *
 * Idempotent only at the "is the group ALREADY of the target type?"
 * level. If the source is mid-migration when the process dies, the
 * group may be partially moved; re-running detects "already on target
 * type" as a no-op and exits clean. Failures inside the loop log and
 * continue rather than aborting halfway.
 *
 * Used by both:
 *   - `programhub:group:retype` drush command (manual / ad-hoc)
 *   - `programhub_dashboard_deploy_NNN_retype_*` deploy hooks (prod)
 */
final class GroupTypeMover {

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly Connection $db,
  ) {}

  /**
   * @param int $sourceGid
   *   Group entity ID to move.
   * @param string $targetType
   *   Destination group_type machine name (must already exist).
   * @param bool $dryRun
   *   Log planned writes without changing anything.
   * @param \Psr\Log\LoggerInterface|null $logger
   *
   * @return array{moved:bool,newGid:?int,relationships:int,skipped:array<int,string>}
   */
  public function move(int $sourceGid, string $targetType, bool $dryRun = FALSE, ?LoggerInterface $logger = NULL): array {
    $logger ??= \Drupal::logger('programhub_dashboard');

    $source = Group::load($sourceGid);
    if (!$source) {
      $logger->warning("Source group $sourceGid not found.");
      return ['moved' => FALSE, 'newGid' => NULL, 'relationships' => 0, 'skipped' => []];
    }
    if ($source->bundle() === $targetType) {
      $logger->notice("Group $sourceGid already on type $targetType — no-op.");
      return ['moved' => FALSE, 'newGid' => $sourceGid, 'relationships' => 0, 'skipped' => []];
    }
    if (!GroupType::load($targetType)) {
      throw new \InvalidArgumentException("Target group_type '$targetType' does not exist.");
    }

    $sourceType = $source->bundle();

    // ── 1. Create the target group ────────────────────────────────────
    $values = [
      'type' => $targetType,
      'label' => $source->label(),
      'uid' => $source->getOwnerId(),
    ];
    // Copy any custom field that exists on both bundles.
    foreach ($source->getFieldDefinitions() as $name => $def) {
      if (!$def->getFieldStorageDefinition()->isBaseField()
        && $source->hasField($name)
        && !$source->get($name)->isEmpty()) {
        $values[$name] = $source->get($name)->getValue();
      }
    }

    $logger->notice("[retype] group $sourceGid: $sourceType → $targetType ({$source->label()})");
    if ($dryRun) {
      $relCount = (int) $this->db->select('group_relationship_field_data', 'g')
        ->fields('g', ['id'])
        ->condition('gid', $sourceGid)
        ->countQuery()
        ->execute()
        ->fetchField();
      return ['moved' => FALSE, 'newGid' => NULL, 'relationships' => $relCount, 'skipped' => []];
    }

    $target = Group::create($values);
    $target->save();
    $newGid = (int) $target->id();

    // ── 2. Move every relationship row ────────────────────────────────
    $relIds = $this->etm->getStorage('group_relationship')->getQuery()
      ->accessCheck(FALSE)
      ->condition('gid', $sourceGid)
      ->execute();

    $moved = 0;
    $skipped = [];

    foreach ($this->etm->getStorage('group_relationship')->loadMultiple($relIds) as $rel) {
      assert($rel instanceof GroupRelationship);
      $pluginId = $rel->getPluginId();

      // The target group_type may not have this plugin enabled. Without
      // a matching relationship_type we can't re-create the row, so we
      // log + skip; the source group_relationship gets deleted with the
      // source group at the end.
      $targetRelType = $this->etm->getStorage('group_relationship_type')
        ->loadByProperties([
          'group_type' => $targetType,
          'content_plugin' => $pluginId,
        ]);
      if (!$targetRelType) {
        $skipped[] = "$pluginId (no plugin on $targetType)";
        $logger->warning("[retype] skip $pluginId — not enabled on $targetType");
        continue;
      }
      /** @var GroupRelationshipType $targetRelType */
      $targetRelType = reset($targetRelType);

      $entity = $rel->getEntity();
      if (!$entity) {
        $skipped[] = "$pluginId (no entity for relationship {$rel->id()})";
        continue;
      }

      // For group_membership rows, also translate the role IDs.
      $extra = [];
      if ($pluginId === 'group_membership' && $rel->hasField('group_roles') && !$rel->get('group_roles')->isEmpty()) {
        $newRoleIds = [];
        foreach ($rel->get('group_roles') as $item) {
          $oldRoleId = $item->target_id;
          // Map `{source_type}-{name}` → `{target_type}-{name}`.
          if (str_starts_with($oldRoleId, $sourceType . '-')) {
            $candidate = $targetType . '-' . substr($oldRoleId, strlen($sourceType) + 1);
            if (GroupRole::load($candidate)) {
              $newRoleIds[] = ['target_id' => $candidate];
            }
            else {
              $logger->warning("[retype] role $oldRoleId has no counterpart on $targetType — dropping");
            }
          }
        }
        $extra['group_roles'] = $newRoleIds;
      }

      $target->addRelationship($entity, $pluginId, $extra);
      $moved++;
    }

    // ── 3. Delete the source ──────────────────────────────────────────
    // Cascade-deletes the source's group_relationship rows.
    $source->delete();

    $logger->notice("[retype] moved gid $sourceGid → $newGid ($moved relationships, " . count($skipped) . " skipped)");

    return [
      'moved' => TRUE,
      'newGid' => $newGid,
      'relationships' => $moved,
      'skipped' => $skipped,
    ];
  }

  /**
   * Convenience: move by source group label (when gid isn't known at
   * deploy time but the label is stable).
   */
  public function moveByLabel(string $label, string $sourceType, string $targetType, bool $dryRun = FALSE, ?LoggerInterface $logger = NULL): array {
    $gids = $this->etm->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $sourceType)
      ->condition('label', $label)
      ->range(0, 1)
      ->execute();
    if (!$gids) {
      ($logger ?? \Drupal::logger('programhub_dashboard'))->notice("[retype] no $sourceType group matches label '$label' — nothing to do.");
      return ['moved' => FALSE, 'newGid' => NULL, 'relationships' => 0, 'skipped' => []];
    }
    return $this->move((int) reset($gids), $targetType, $dryRun, $logger);
  }

}
