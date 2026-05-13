<?php

/**
 * One-shot bootstrap for the subgroup hierarchy:
 *
 *   division (root)
 *     ├── program (leaf)
 *     ├── program_design (leaf)
 *     └── program_culinary (leaf)
 *
 * Three things have to happen, in order:
 *
 *   1. Mark `division` as a tree ROOT and each program subtype as a
 *      LEAF in that tree (subgroup_handler API).
 *   2. Once those flags are set, Subgroup's deriver auto-generates
 *      `subgroup:{leaf_type}` group-relation plugins. We then install
 *      them on the `division` group type via the relation-type
 *      storage's `createFromPlugin()`.
 *   3. (Future) RoleInheritance entities to cascade
 *      `division-administrator` → `program-administrator` perms.
 *      Skipped here — handle role inheritance when the requirements
 *      are clear; structural nesting first.
 *
 * Run:  ddev drush scr /var/www/html/drupal/scripts/setup_subgroups.php
 * Then: ddev drush cex -y
 */

declare(strict_types=1);

use Drupal\group\Entity\GroupType;

$handler = \Drupal::entityTypeManager()->getHandler('group_type', 'subgroup');

$rootId = 'division';
$leafIds = ['program', 'program_design', 'program_culinary'];

$root = GroupType::load($rootId);
if (!$root) {
  echo "group_type:$rootId missing — aborting\n";
  exit(1);
}

// 1. Init tree.
if (!$handler->isRoot($root)) {
  $handler->initTree($root);
  echo "Initialized tree on group_type:$rootId\n";
}
else {
  echo "group_type:$rootId already a tree root\n";
}

// 2. Add each program subtype as a leaf under division.
foreach ($leafIds as $leafId) {
  $leaf = GroupType::load($leafId);
  if (!$leaf) {
    echo "group_type:$leafId missing — skipping\n";
    continue;
  }
  if ($handler->isLeaf($leaf)) {
    echo "group_type:$leafId already a leaf\n";
    continue;
  }
  $handler->addLeaf($root, $leaf);
  echo "Added group_type:$leafId as leaf under $rootId\n";
}

// 3. Install the auto-derived subgroup:{leaf} plugins on the division.
//    The deriver only exposes the derivative AFTER the leaf flag is set,
//    so this must run after step 2.
$relStorage = \Drupal::entityTypeManager()->getStorage('group_relationship_type');
foreach ($leafIds as $leafId) {
  $pluginId = "subgroup:$leafId";
  $existing = $relStorage->loadByProperties([
    'group_type' => $rootId,
    'content_plugin' => $pluginId,
  ]);
  if ($existing) {
    echo "$pluginId already installed on $rootId\n";
    continue;
  }
  $relStorage->createFromPlugin($root, $pluginId)->save();
  echo "Installed $pluginId on $rootId\n";
}

echo "\n✓ Done. Run: ddev drush cex -y\n";
