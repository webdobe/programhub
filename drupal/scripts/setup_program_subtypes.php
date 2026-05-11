<?php

/**
 * One-shot bootstrap for two new group types specialized off `program`:
 *
 *   - program_design   — GDES + future design/visual programs.
 *                        Inherits the base program plugin set + adds
 *                        portfolio_show (already in program; surfaced
 *                        here for clarity).
 *   - program_culinary — future CULA + similar food-service programs.
 *                        Inherits base plugins + menu, venue.
 *
 * The base `program` type stays unchanged. After running this + the
 * `programhub:group:retype` drush command (next commit), GDES can be
 * moved from program → program_design without data loss.
 *
 * Each new type gets:
 *   - The same custom fields as `program` (abbreviation, path, website, soc_codes)
 *   - Its tailored gnode plugin set
 *   - All the program-X roles cloned to {new_type}-X with identical permissions
 *   - Synthetic roles (insider, outsider, anonymous) granting view access
 *
 * Run:  ddev drush scr /var/www/html/drupal/scripts/setup_program_subtypes.php
 * Then: ddev drush cex -y
 */

declare(strict_types=1);

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\GroupRelationshipType;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;

// ─────────────────────────────────────────────────────────────────────────
// Type definitions.
// ─────────────────────────────────────────────────────────────────────────

/**
 * Plugins shared across every program subtype (the safe baseline).
 */
$baseProgramBundles = [
  'article', 'award', 'event', 'career_outcome', 'certificate', 'course',
  'project', 'simplenews_issue', 'student_spotlight',
];

$subtypes = [
  'program_design' => [
    'label' => 'Program (design)',
    'description' => 'Design and visual-arts programs (GDES, future Animation, Web Design). Adds portfolio_show on top of the program baseline.',
    'bundles' => [...$baseProgramBundles, 'portfolio_show'],
  ],
  'program_culinary' => [
    'label' => 'Program (culinary)',
    'description' => 'Food-service programs (future CULA). Adds menu + venue on top of the program baseline.',
    'bundles' => [...$baseProgramBundles, 'menu', 'venue'],
  ],
];

/**
 * Custom fields to mirror from the base program type.
 */
$copyFields = ['field_abbreviation', 'field_path', 'field_website', 'field_soc_codes'];

// Source-of-truth role definitions live on `program` already; clone each
// role's permissions list, swapping the `program-` prefix on the role ID.
$srcRoles = [
  'program-administrator',
  'program-manager',
  'program-instructor',
  'program-student',
  'program-graduate',
  'program-tac_member',
];

// ─────────────────────────────────────────────────────────────────────────
// Group types.
// ─────────────────────────────────────────────────────────────────────────
foreach ($subtypes as $id => $spec) {
  if (!GroupType::load($id)) {
    GroupType::create([
      'id' => $id,
      'label' => $spec['label'],
      'description' => $spec['description'],
      'new_revision' => TRUE,
      'creator_membership' => TRUE,
      'creator_wizard' => FALSE,
      'creator_roles' => [],
    ])->save();
    echo "Created group_type:$id\n";
  }
}

// ─────────────────────────────────────────────────────────────────────────
// Gnode plugins per type.
// ─────────────────────────────────────────────────────────────────────────
$storage = \Drupal::entityTypeManager()->getStorage('group_relationship_type');
foreach ($subtypes as $typeId => $spec) {
  foreach ($spec['bundles'] as $bundle) {
    $existing = $storage->loadByProperties([
      'group_type' => $typeId,
      'content_plugin' => "group_node:$bundle",
    ]);
    if ($existing) {
      continue;
    }
    $storage->createFromPlugin(GroupType::load($typeId), "group_node:$bundle")->save();
    echo "Enabled group_node:$bundle on $typeId\n";
  }
}

// ─────────────────────────────────────────────────────────────────────────
// Custom fields — clone each program field instance to the new bundles.
// ─────────────────────────────────────────────────────────────────────────
foreach ($subtypes as $typeId => $_) {
  foreach ($copyFields as $fieldName) {
    $src = FieldConfig::loadByName('group', 'program', $fieldName);
    if (!$src) {
      continue;
    }
    if (FieldConfig::loadByName('group', $typeId, $fieldName)) {
      continue;
    }
    $storageEntity = FieldStorageConfig::loadByName('group', $fieldName);
    FieldConfig::create([
      'field_storage' => $storageEntity,
      'bundle' => $typeId,
      'label' => $src->getLabel(),
      'required' => $src->isRequired(),
    ])->save();
    echo "Cloned field group.$typeId.$fieldName\n";
  }
}

// ─────────────────────────────────────────────────────────────────────────
// Roles — clone every program-X role to {new_type}-X with same perms.
// ─────────────────────────────────────────────────────────────────────────
foreach ($subtypes as $typeId => $_) {
  foreach ($srcRoles as $srcRoleId) {
    $src = GroupRole::load($srcRoleId);
    if (!$src) {
      continue;
    }
    $name = substr($srcRoleId, strlen('program-'));
    $newId = "$typeId-$name";
    if (GroupRole::load($newId)) {
      continue;
    }
    // Translate plugin-scoped perms: `… group_node:X entity` is identical
    // across group types, so the perm strings copy 1:1.
    GroupRole::create([
      'id' => $newId,
      'label' => $src->label(),
      'group_type' => $typeId,
      'admin' => $src->isAdmin(),
      'permissions' => $src->getPermissions(),
      'scope' => 'individual',
    ])->save();
    echo "Cloned role $srcRoleId → $newId\n";
  }
  // Synthetic outsider/insider/anonymous, mirroring `program-*`.
  foreach (['outsider', 'insider', 'anonymous'] as $scope) {
    $src = GroupRole::load("program-$scope");
    $newId = "$typeId-$scope";
    if (!$src || GroupRole::load($newId)) {
      continue;
    }
    GroupRole::create([
      'id' => $newId,
      'label' => $src->label(),
      'group_type' => $typeId,
      'scope' => $src->getScope(),
      'global_role' => $src->get('global_role'),
      'permissions' => $src->getPermissions(),
    ])->save();
    echo "Cloned synthetic role program-$scope → $newId\n";
  }
}

// ─────────────────────────────────────────────────────────────────────────
// Form + view displays — mirror the program defaults.
// ─────────────────────────────────────────────────────────────────────────
foreach ($subtypes as $typeId => $_) {
  $fd = EntityFormDisplay::load("group.$typeId.default");
  if (!$fd) {
    $fd = EntityFormDisplay::create([
      'targetEntityType' => 'group',
      'bundle' => $typeId,
      'mode' => 'default',
      'status' => TRUE,
    ]);
  }
  $weight = 0;
  foreach ($copyFields as $fieldName) {
    $type = FieldConfig::loadByName('group', $typeId, $fieldName)?->getType();
    $fd->setComponent($fieldName, [
      'type' => $type === 'link' ? 'link_default' : 'string_textfield',
      'weight' => $weight++,
    ]);
  }
  $fd->save();

  $vd = EntityViewDisplay::load("group.$typeId.default");
  if (!$vd) {
    $vd = EntityViewDisplay::create([
      'targetEntityType' => 'group',
      'bundle' => $typeId,
      'mode' => 'default',
      'status' => TRUE,
    ]);
  }
  $weight = 0;
  foreach ($copyFields as $fieldName) {
    $type = FieldConfig::loadByName('group', $typeId, $fieldName)?->getType();
    $vd->setComponent($fieldName, [
      'label' => 'inline',
      'type' => $type === 'link' ? 'link' : 'string',
      'weight' => $weight++,
    ]);
  }
  $vd->save();
  echo "Built form + view displays for group.$typeId\n";
}

echo "\n✓ Done. Run: ddev drush cex -y\n";
