<?php

/**
 * Bootstrap the subgroup hierarchy by writing the type-level tree
 * state DIRECTLY into group_type third_party_settings + creating the
 * relationship-type + role-inheritance configs. We bypass Subgroup's
 * `addLeaf()` handler API because it refuses to mark a group_type as
 * a leaf when groups of that type already exist — a safety net that
 * trips here in dev (programs were created before subgroup was
 * installed). On prod's clean deploy the constraint isn't a problem,
 * since this all ships via config import BEFORE any groups exist; the
 * direct writes here just stage the configs locally so `drush cex`
 * captures them.
 *
 * Resulting tree (nested set values calculated for 1 root + 3 leaves):
 *
 *   division          depth=0  left=1  right=8  tree=division
 *     ├── program            depth=1  left=2  right=3
 *     ├── program_design     depth=1  left=4  right=5
 *     └── program_culinary   depth=1  left=6  right=7
 *
 * Run:  ddev drush scr /var/www/html/drupal/scripts/setup_subgroup_tree.php
 * Then: ddev drush cex -y
 */

declare(strict_types=1);

use Drupal\group\Entity\GroupType;

// ─────────────────────────────────────────────────────────────────────
// 1. Nested-set values on each group_type's `third_party_settings.subgroup`.
// ─────────────────────────────────────────────────────────────────────
$treeId = 'division';
$tree = [
  // [type_id, depth, left, right]
  ['division', 0, 1, 8],
  ['program', 1, 2, 3],
  ['program_design', 1, 4, 5],
  ['program_culinary', 1, 6, 7],
];

foreach ($tree as [$typeId, $depth, $left, $right]) {
  $gt = GroupType::load($typeId);
  if (!$gt) {
    echo "group_type:$typeId missing — skipping\n";
    continue;
  }
  $gt->setThirdPartySetting('subgroup', 'depth', $depth)
    ->setThirdPartySetting('subgroup', 'left', $left)
    ->setThirdPartySetting('subgroup', 'right', $right)
    ->setThirdPartySetting('subgroup', 'tree', $treeId)
    ->save();
  echo "Tree state set: $typeId (depth=$depth, left=$left, right=$right)\n";
}

// ─────────────────────────────────────────────────────────────────────
// 2. Install subgroup:{leaf} relationship plugins on the root.
// ─────────────────────────────────────────────────────────────────────
$leafTypes = ['program', 'program_design', 'program_culinary'];
$relStorage = \Drupal::entityTypeManager()->getStorage('group_relationship_type');
$root = GroupType::load($treeId);

foreach ($leafTypes as $leafId) {
  $pluginId = "subgroup:$leafId";
  $existing = $relStorage->loadByProperties([
    'group_type' => $treeId,
    'content_plugin' => $pluginId,
  ]);
  if ($existing) {
    echo "$pluginId already on $treeId — skipping\n";
    continue;
  }
  $relStorage->createFromPlugin($root, $pluginId)->save();
  echo "Installed $pluginId on $treeId\n";
}

// ─────────────────────────────────────────────────────────────────────
// 3. Role inheritance — division-administrator cascades to the admin
//    role on every nested program type. is_admin roles auto-grant every
//    perm on that group type, so this is enough; no per-perm mapping.
// ─────────────────────────────────────────────────────────────────────
$inheritances = [
  // [machine_name, source role, target role]
  ['division_admin_to_program_admin', 'division-administrator', 'program-administrator'],
  ['division_admin_to_program_design_admin', 'division-administrator', 'program_design-administrator'],
  ['division_admin_to_program_culinary_admin', 'division-administrator', 'program_culinary-administrator'],
];

$inhStorage = \Drupal::entityTypeManager()->getStorage('subgroup_role_inheritance');
foreach ($inheritances as [$id, $source, $target]) {
  if ($inhStorage->load($id)) {
    echo "role_inheritance:$id exists — skipping\n";
    continue;
  }
  $inhStorage->create([
    'id' => $id,
    'source' => $source,
    'target' => $target,
    'tree' => $treeId,
  ])->save();
  echo "Created role_inheritance:$id ($source → $target)\n";
}

echo "\n✓ Done. Run: ddev drush cex -y\n";
