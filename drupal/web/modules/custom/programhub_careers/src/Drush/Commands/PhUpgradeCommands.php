<?php

declare(strict_types=1);

namespace Drupal\programhub_careers\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRelationship;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * One-shot orchestrator for the OG → Group + Subgroup upgrade.
 *
 * This is the upgrade entry point for a prod box that's still on OG.
 * It runs all the steps in the order that DOESN'T break: install Group
 * modules first, import their staged configs from `config/sync` so the
 * new group_types + roles + relationship plugins exist, run the OG
 * data migration (which still has og_membership + node__og_audience
 * to read from at this point), retype GDES to `program_design`, attach
 * the program groups to the CTE division as subgroups, then clean up
 * the OG residue.
 *
 * Lives in `programhub_careers` only because that module is already
 * enabled in the pre-upgrade prod snapshot — the command itself has
 * nothing to do with careers; the module is just a stable launch
 * point. Once this has run, regular `drush deploy` works.
 *
 *   drush phupgrade            # full run
 *   drush phupgrade --dry-run  # no-op preview (not implemented yet —
 *                                use a DB snapshot to dry-run instead)
 */
final class PhUpgradeCommands extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly ModuleInstallerInterface $moduleInstaller,
    private readonly Connection $db,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('module_installer'),
      $container->get('database'),
    );
  }

  #[CLI\Command(name: 'programhub:upgrade-to-group', aliases: ['phupgrade'])]
  #[CLI\Usage(name: 'drush phupgrade', description: 'Migrate OG → Group + Subgroup in one go.')]
  public function run(): void {
    $logger = $this->logger();

    // 1. Install Group + Subgroup modules. module_installer pulls
    //    initial config from each module's config/install/ AND respects
    //    config/sync overrides — meaning our custom group_type configs
    //    in sync get applied as part of the install. After this call
    //    the container is rebuilt so we can resolve services from the
    //    new modules.
    $modules = [
      'flexible_permissions',
      'group',
      'gnode',
      'subgroup',
      'programhub_dashboard',
      'programhub_display',
      'programhub_governance',
    ];
    $toInstall = array_filter(
      $modules,
      static fn(string $m): bool => !\Drupal::moduleHandler()->moduleExists($m),
    );
    if ($toInstall) {
      $this->moduleInstaller->install($toInstall);
      $logger->success(sprintf('Installed: %s', implode(', ', $toInstall)));
    }

    // 2. Selectively stage the Group-related configs from sync to
    //    active. A full `cim` here would fail validation — OG content
    //    still exists at this point, so any config that would remove
    //    a content bundle (program/division node types, og_membership
    //    bundle) is rejected. Instead we copy ONLY the configs we need
    //    for `Group::create()` calls in step 3 to succeed: group_types,
    //    group roles, group-entity field configs, and the gnode +
    //    subgroup relationship plugins. The full cim runs at the end
    //    once OG data is gone.
    $logger->notice('Staging Group configs from sync …');
    $this->copyGroupConfigsFromSync();
    drupal_flush_all_caches();

    // 3. Migrate OG data → Group entities + relationships.
    /** @var \Drupal\programhub_dashboard\Migration\OgToGroupMigrator $migrator */
    $migrator = \Drupal::service('programhub_dashboard.og_to_group_migrator');
    $migrate = $migrator->run(FALSE, $logger);
    $logger->success(sprintf(
      'Migrated %d groups, %d memberships, %d content rows.',
      $migrate['groups'], $migrate['members'], $migrate['content'],
    ));

    // 4. Retype GDES → program_design.
    /** @var \Drupal\programhub_dashboard\Migration\GroupTypeMover $mover */
    $mover = \Drupal::service('programhub_dashboard.group_type_mover');
    $retype = $mover->moveByLabel('Graphic & Web Design', 'program', 'program_design', FALSE, $logger);
    if ($retype['moved']) {
      $logger->success(sprintf('Retyped GDES → program_design (new gid=%d).', $retype['newGid']));
    }

    // 5. Attach each program-shaped group to CTE as a subgroup.
    $attached = $this->attachProgramsToDivision();
    if ($attached !== NULL) {
      $logger->success(sprintf('Attached %d programs to CTE as subgroups.', $attached));
    }

    // 5b. Backfill group-Administrator role on every existing membership
    //     held by a Drupal global Administrator user. The OG → Group
    //     migrator preserves memberships but assigns the base group role
    //     only, which means even global admins lose edit/members access
    //     on the migrated groups until they're upgraded. (Going forward,
    //     the `*-site_admin` synchronized group roles in config/sync
    //     handle this for any *new* user-group combination — this step
    //     is the one-shot catch-up for migrated memberships.)
    $upgraded = $this->ensureAdminMemberships($logger);
    if ($upgraded !== NULL) {
      $logger->success(sprintf('Upgraded %d existing memberships to include the group-administrator role.', $upgraded));
    }

    // 6. Drop stranded program/division node entities.
    $etm = \Drupal::entityTypeManager();
    $nodes = $etm->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', ['program', 'division'], 'IN')
      ->execute();
    if ($nodes) {
      $etm->getStorage('node')->delete(
        $etm->getStorage('node')->loadMultiple($nodes),
      );
      $logger->success(sprintf('Deleted %d stranded program/division nodes.', count($nodes)));
    }

    // 7. Remove og_audience fields. Pre-emptively fix the simplenews_issue
    //    form display so the field-instance deletion doesn't choke on a
    //    stale `simplenews_issue_widget` widget reference.
    $simplenewsFd = $etm->getStorage('entity_form_display')->load('node.simplenews_issue.default');
    if ($simplenewsFd) {
      $simplenewsFd->removeComponent('og_audience');
      $comp = $simplenewsFd->getComponent('simplenews_issue');
      if ($comp && ($comp['type'] ?? '') === 'simplenews_issue_widget') {
        $comp['type'] = 'simplenews_issue';
        $simplenewsFd->setComponent('simplenews_issue', $comp);
      }
      $simplenewsFd->save();
    }
    $fieldsRemoved = 0;
    foreach (FieldConfig::loadMultiple() as $fc) {
      if ($fc->getName() === 'og_audience') {
        $fc->delete();
        $fieldsRemoved++;
      }
    }
    foreach (['node', 'profile'] as $entityType) {
      $storage = FieldStorageConfig::loadByName($entityType, 'og_audience');
      if ($storage) {
        $storage->delete();
      }
    }
    if ($fieldsRemoved) {
      $logger->success(sprintf('Removed og_audience from %d bundles.', $fieldsRemoved));
    }
    // Field deletion happens in two stages — flush the purge queue so
    // og's tables actually get dropped before we uninstall. Keep going
    // until field_purge_batch stops finding anything to purge (one
    // pass is not enough when many bundles share a field).
    /** @var \Drupal\field\FieldStorageConfigStorage $fsc */
    $fsc = $etm->getStorage('field_storage_config');
    $passes = 0;
    do {
      $passes++;
      field_purge_batch(500);
      $pending = $fsc->loadByProperties(['deleted' => TRUE])
        + $etm->getStorage('field_config')->loadByProperties(['deleted' => TRUE]);
    } while (!empty($pending) && $passes < 50);
    if (!empty($pending)) {
      $this->logger()->warning(sprintf('Still %d fields pending purge after %d passes.', count($pending), $passes));
    }

    // 8a. Uninstall the og_permissions_override stub (vestigial — see
    //    its info.yml). Does nothing if already gone.
    if (\Drupal::moduleHandler()->moduleExists('og_permissions_override')) {
      $this->moduleInstaller->uninstall(['og_permissions_override']);
    }

    // 8. Delete og_membership entities + uninstall the modules.
    $omIds = $etm->getStorage('og_membership')->getQuery()
      ->accessCheck(FALSE)->execute();
    if ($omIds) {
      $etm->getStorage('og_membership')->delete(
        $etm->getStorage('og_membership')->loadMultiple($omIds),
      );
    }
    if (\Drupal::moduleHandler()->moduleExists('og_ui')) {
      $this->moduleInstaller->uninstall(['og_ui']);
    }
    if (\Drupal::moduleHandler()->moduleExists('og')) {
      $this->moduleInstaller->uninstall(['og']);
    }
    $logger->success('Uninstalled OG.');

    // 9. Final cim pass to catch anything that depended on og being gone.
    $logger->notice('Final config import pass …');
    $this->runConfigImport();

    $logger->success('phupgrade complete.');
  }

  /**
   * Walk every group and ensure each member who holds the Drupal global
   * Administrator role also has the per-group `{type}-administrator`
   * role attached to their group_membership row.
   *
   * The OG → Group migrator preserves memberships but only assigns the
   * base "member" role, leaving global admins unable to edit/admin the
   * migrated groups. The `*-site_admin` group roles in config (scope:
   * outsider, global_role: administrator) handle this declaratively for
   * any future user-group combination — but they don't retroactively
   * upgrade rows the migrator already inserted with the base role only.
   * This catch-up loop closes that gap.
   *
   * Idempotent: skips memberships that already carry the admin role,
   * skips group types whose `{type}-administrator` role doesn't exist.
   *
   * @return int|null
   *   Count of memberships upgraded, or NULL if no global admins exist.
   */
  private function ensureAdminMemberships($logger): ?int {
    $etm = \Drupal::entityTypeManager();

    $adminIds = $etm->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('roles', 'administrator')
      ->execute();
    // uid=1 doesn't always carry an explicit roles row but is always admin.
    $adminIds[1] = 1;
    if (!$adminIds) {
      return NULL;
    }
    $admins = $etm->getStorage('user')->loadMultiple($adminIds);

    $upgraded = 0;
    foreach ($etm->getStorage('group')->loadMultiple() as $group) {
      $roleId = $group->bundle() . '-administrator';
      $role = \Drupal\group\Entity\GroupRole::load($roleId);
      if (!$role) {
        continue;
      }
      foreach ($admins as $admin) {
        $member = $group->getMember($admin);
        if (!$member) {
          continue;
        }
        $rel = $member->getGroupRelationship();
        $current = array_map(
          static fn(array $v) => $v['target_id'],
          $rel->get('group_roles')->getValue(),
        );
        if (in_array($roleId, $current, TRUE)) {
          continue;
        }
        $current[] = $roleId;
        $rel->set('group_roles', array_map(
          static fn(string $r) => ['target_id' => $r],
          $current,
        ));
        $rel->save();
        $upgraded++;
      }
    }
    return $upgraded;
  }

  /**
   * Attach every program-shaped group to the (single) CTE division.
   *
   * @return int|null Count attached, or NULL if no division was found.
   */
  private function attachProgramsToDivision(): ?int {
    // Resolve via the global — the injected $this->etm was built before
    // group/subgroup were installed and won't know about those entity
    // types.
    $etm = \Drupal::entityTypeManager();
    $divisionIds = $etm->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'division')
      ->range(0, 1)
      ->execute();
    if (!$divisionIds) {
      return NULL;
    }
    $cte = Group::load((int) reset($divisionIds));
    $programGids = $etm->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', \Drupal\programhub_dashboard\Service\GroupContext::PROGRAM_GROUP_TYPES, 'IN')
      ->execute();
    $attached = 0;
    foreach (Group::loadMultiple($programGids) as $program) {
      $pluginId = 'subgroup:' . $program->bundle();
      if ($cte->getRelationshipsByEntity($program, $pluginId)) {
        continue;
      }
      $cte->addRelationship($program, $pluginId);
      $attached++;
    }
    return $attached;
  }

  /**
   * Selectively import Group-related configs from `config/sync`.
   *
   * Used when a full `cim` would fail validation (e.g. OG content still
   * exists and would block bundle deletions). Walks specific config
   * prefixes and creates / updates them via the entity API — which is
   * critical, because going through entity storage triggers the
   * schema-install events that field_storage_config relies on for its
   * DB tables. A raw `$active->write()` skips those events.
   *
   * Order matters: group types before fields-on-groups, fields before
   * displays, etc. — matches Drupal's own config dep ordering. Within
   * a prefix, we sort by name so child configs follow parents.
   */
  private function copyGroupConfigsFromSync(): void {
    $sync = \Drupal::service('config.storage.sync');

    // Three-phase order matters. Subgroup's plugin deriver scans
    // group_types for the `third_party_settings.subgroup` flag to
    // expose `subgroup:{leaf}` plugins — but that scan is cached, so
    // we have to flush the plugin manager AFTER group_types land and
    // BEFORE we create relationship_types that reference those plugins.
    $phases = [
      // Phase 1 — bundles, fields, roles. No plugin-derivative deps.
      [
        'group.type.' => 'group_type',
        'field.storage.group.' => 'field_storage_config',
        'field.field.group.' => 'field_config',
        'group.role.' => 'group_role',
      ],
      // Phase 2 — relationship types. `subgroup:*` plugins must be
      // discoverable now, so flush the plugin manager first.
      [
        'group.relationship_type.' => 'group_relationship_type',
        'field.field.group_relationship.' => 'field_config',
      ],
      // Phase 3 — everything that depends on phase 2.
      [
        'core.entity_form_display.group.' => 'entity_form_display',
        'core.entity_view_display.group.' => 'entity_view_display',
        'core.entity_form_display.group_relationship.' => 'entity_form_display',
        'core.entity_view_display.group_relationship.' => 'entity_view_display',
        'subgroup.subgroup_role_inheritance.' => 'subgroup_role_inheritance',
      ],
    ];

    $applied = 0;
    foreach ($phases as $phaseIndex => $prefixToEntityType) {
      if ($phaseIndex === 1) {
        // Between phase 1 (group_types) and phase 2 (relationship_types):
        // 1) Subgroup's GROUP_TYPE_LEAF_IMPORT event only clears the
        //    plugin-manager cache — it does NOT install the
        //    `subgroup_depth/left/right/tree` field instances. We have
        //    to do that ourselves for every leaf group_type, otherwise
        //    Group::load() blows up later with "Field subgroup_depth
        //    is unknown."
        // 2) Re-derive subgroup plugins so phase 2's `subgroup:*`
        //    relationship-types resolve.
        $this->installSubgroupFieldsOnLeafGroupTypes();
        \Drupal::service('group_relation_type.manager')->clearCachedDefinitions();
        \Drupal::service('entity_type.manager')->clearCachedDefinitions();
      }
      elseif ($phaseIndex > 1) {
        \Drupal::service('group_relation_type.manager')->clearCachedDefinitions();
        \Drupal::service('entity_type.manager')->clearCachedDefinitions();
      }
      foreach ($prefixToEntityType as $prefix => $entityType) {
        $names = $sync->listAll($prefix);
        sort($names);
        foreach ($names as $name) {
          $data = $sync->read($name);
          if (!$data) {
            continue;
          }
          try {
            // Always resolve via the global so we get the rebuilt container
            // after module_installer->install() — `$this->etm` was injected
            // at command construction and is stale once new modules land.
            $storage = \Drupal::entityTypeManager()->getStorage($entityType);
          }
          catch (\Exception $e) {
            $this->logger()->warning("Storage for $entityType not available — skipping $name");
            continue;
          }
          $id = $data['id'] ?? NULL;
          if (!$id) {
            continue;
          }
          $existing = $storage->load($id);
          if ($existing) {
            // If sync has a different UUID, the final cim will see a
            // delete+create instead of an update and refuse if content
            // exists. Drop the auto-created config and recreate with
            // sync's UUID. Safe here — copyGroupConfigsFromSync runs
            // BEFORE the OG→Group data migration, so no
            // `group_relationship` content references these yet.
            if (!empty($data['uuid']) && $existing->uuid() !== $data['uuid']) {
              $existing->delete();
              $storage->create($data)->save();
            }
            else {
              // Same UUID — straight update. Skip `_core` and `uuid`
              // because Drupal config entities refuse mid-save UUID
              // changes.
              foreach ($data as $key => $value) {
                if (in_array($key, ['_core', 'uuid'], TRUE)) {
                  continue;
                }
                $existing->set($key, $value);
              }
              $existing->save();
            }
          }
          else {
            $storage->create($data)->save();
          }
          $applied++;
        }
      }
    }
    $this->logger()->notice("Applied $applied Group-related configs from sync.");
  }

  /**
   * Install the four subgroup_* fields on every leaf group_type.
   *
   * Subgroup ships only the field.storage configs in its config/install
   * directory; the per-bundle field.field instances are added at runtime
   * by SubgroupFieldManager::installFields(). That call normally happens
   * via the LEAF_ADD event when a group_type is flipped to a leaf
   * through the entity API — but config-import-driven leaf creation
   * dispatches LEAF_IMPORT instead, which doesn't install fields.
   * So we do it ourselves for any leaf that's missing them.
   */
  private function installSubgroupFieldsOnLeafGroupTypes(): void {
    $etm = \Drupal::entityTypeManager();
    $handler = $etm->getHandler('group_type', 'subgroup');
    $fieldManager = \Drupal::service('subgroup.field_manager');
    $fcStorage = $etm->getStorage('field_config');
    foreach ($etm->getStorage('group_type')->loadMultiple() as $groupType) {
      if (!$handler->isLeaf($groupType)) {
        continue;
      }
      // Skip if already installed (rerun safety).
      if ($fcStorage->load('group.' . $groupType->id() . '.subgroup_depth')) {
        continue;
      }
      $fieldManager->installFields($groupType->id());
      $this->logger()->notice("Installed subgroup fields on group.{$groupType->id()}.");
    }
  }

  /**
   * Invoke the configured config importer programmatically — same code
   * path as `drush cim`, executed inline so we don't have to shell out.
   */
  private function runConfigImport(): void {
    $syncStorage = \Drupal::service('config.storage.sync');
    $activeStorage = \Drupal::service('config.storage');
    $configManager = \Drupal::service('config.manager');

    $storageComparer = new \Drupal\Core\Config\StorageComparer(
      $syncStorage,
      $activeStorage,
    );
    if (!$storageComparer->createChangelist()->hasChanges()) {
      $this->logger()->notice('No config changes to import.');
      return;
    }

    $importer = new \Drupal\Core\Config\ConfigImporter(
      $storageComparer,
      \Drupal::service('event_dispatcher'),
      $configManager,
      \Drupal::service('lock'),
      \Drupal::service('config.typed'),
      \Drupal::service('module_handler'),
      $this->moduleInstaller,
      \Drupal::service('theme_handler'),
      \Drupal::service('string_translation'),
      \Drupal::service('extension.list.module'),
      \Drupal::service('extension.list.theme'),
    );
    if ($importer->alreadyImporting()) {
      $this->logger()->warning('Another config import already running.');
      return;
    }
    try {
      $importer->import();
      $this->logger()->success('Config import complete.');
    }
    catch (\Drupal\Core\Config\ConfigImporterException $e) {
      foreach ($importer->getErrors() as $error) {
        $this->logger()->error($error);
      }
      throw $e;
    }
  }

}
