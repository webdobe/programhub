<?php

/**
 * @file
 * Deploy hooks for programhub_media.
 *
 * Migrates legacy image fields to media reference fields:
 *   - node.{project,article,student_spotlight}.field_main_image
 *       → field_featured_media (entity_reference → media:image)
 *   - node.project.field_project_gallery
 *       → field_media_gallery (entity_reference → media:image, multi)
 *
 * Files are reused as-is; new Media entities wrap existing File entities and
 * carry the alt text forward. Existing Media entities that already point at a
 * given File are reused (no duplicate media per file across the run).
 *
 * Lives in *deploy* (not post_update) because the new fields are added by
 * the same config import — they don't exist yet when post_update runs.
 *
 * Idempotent. A second run will:
 *   - skip nodes that already have the new field populated,
 *   - reuse media that the previous run created.
 */

declare(strict_types=1);

use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Migrate field_main_image → field_featured_media on project, article,
 * student_spotlight.
 */
function programhub_media_deploy_01_main_image_to_featured_media(array &$sandbox): string {
  $bundles = ['project', 'article', 'student_spotlight'];
  $report = [];
  foreach ($bundles as $bundle) {
    [$migrated, $skipped] = _programhub_media_migrate_single(
      $bundle,
      'field_main_image',
      'field_featured_media',
    );
    $report[] = sprintf('%s: migrated %d, skipped %d', $bundle, $migrated, $skipped);
  }
  return 'field_main_image → field_featured_media — ' . implode(' · ', $report);
}

/**
 * Migrate project.field_project_gallery → field_media_gallery.
 */
function programhub_media_deploy_02_project_gallery_to_media_gallery(array &$sandbox): string {
  [$migrated, $skipped] = _programhub_media_migrate_multi(
    'project',
    'field_project_gallery',
    'field_media_gallery',
  );
  return sprintf(
    'project.field_project_gallery → field_media_gallery — migrated %d, skipped %d',
    $migrated,
    $skipped,
  );
}

/**
 * Migrate a single-cardinality image field on $bundle to a media reference.
 *
 * @return array{0:int,1:int} [migrated, skipped]
 */
function _programhub_media_migrate_single(
  string $bundle,
  string $sourceField,
  string $targetField,
): array {
  $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
  $nids = $nodeStorage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', $bundle)
    ->exists($sourceField)
    ->execute();

  $migrated = 0;
  $skipped = 0;
  foreach ($nodeStorage->loadMultiple($nids) as $node) {
    /** @var NodeInterface $node */
    if (!$node->hasField($targetField) || !$node->get($targetField)->isEmpty()) {
      $skipped++;
      continue;
    }
    $item = $node->get($sourceField)->first();
    if (!$item) {
      $skipped++;
      continue;
    }
    $fid = (int) $item->target_id;
    $alt = (string) ($item->alt ?? '');
    $media = _programhub_media_ensure_image_media($fid, $alt, (string) $node->label());
    if (!$media) {
      $skipped++;
      continue;
    }
    $node->set($targetField, ['target_id' => $media->id()]);
    // Don't bump moderation state or create a new revision noisily.
    $node->setNewRevision(FALSE);
    $node->save();
    $migrated++;
  }
  return [$migrated, $skipped];
}

/**
 * Migrate a multi-cardinality image field to a media reference list.
 *
 * @return array{0:int,1:int} [migrated, skipped]
 */
function _programhub_media_migrate_multi(
  string $bundle,
  string $sourceField,
  string $targetField,
): array {
  $nodeStorage = \Drupal::entityTypeManager()->getStorage('node');
  $nids = $nodeStorage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', $bundle)
    ->exists($sourceField)
    ->execute();

  $migrated = 0;
  $skipped = 0;
  foreach ($nodeStorage->loadMultiple($nids) as $node) {
    /** @var NodeInterface $node */
    if (!$node->hasField($targetField) || !$node->get($targetField)->isEmpty()) {
      $skipped++;
      continue;
    }
    $refs = [];
    foreach ($node->get($sourceField) as $item) {
      $fid = (int) $item->get('target_id')->getValue();
      $alt = (string) ($item->get('alt')->getValue() ?? '');
      $media = _programhub_media_ensure_image_media($fid, $alt, (string) $node->label());
      if ($media) {
        $refs[] = ['target_id' => $media->id()];
      }
    }
    if (!$refs) {
      $skipped++;
      continue;
    }
    $node->set($targetField, $refs);
    $node->setNewRevision(FALSE);
    $node->save();
    $migrated++;
  }
  return [$migrated, $skipped];
}

/**
 * Return a media:image entity that wraps $fid, creating it if needed.
 *
 * Dedupe is by File id: if any existing media:image already references this
 * file, reuse it. Otherwise create a new one carrying the supplied alt and
 * a sensible name (alt → node label → filename).
 */
function _programhub_media_ensure_image_media(int $fid, string $alt, string $nodeLabel): ?MediaInterface {
  static $byFid = [];
  if (isset($byFid[$fid])) {
    return $byFid[$fid];
  }

  $mediaStorage = \Drupal::entityTypeManager()->getStorage('media');
  $fileStorage = \Drupal::entityTypeManager()->getStorage('file');

  /** @var FileInterface|null $file */
  $file = $fileStorage->load($fid);
  if (!$file) {
    return $byFid[$fid] = NULL;
  }

  $existing = $mediaStorage->loadByProperties([
    'bundle' => 'image',
    'field_media_image' => $fid,
  ]);
  if ($existing) {
    /** @var MediaInterface $media */
    $media = reset($existing);
    return $byFid[$fid] = $media;
  }

  $name = $alt !== '' ? $alt : ($nodeLabel !== '' ? $nodeLabel : $file->getFilename());
  // media.name has a 255-char limit.
  if (strlen($name) > 250) {
    $name = substr($name, 0, 250);
  }

  $media = Media::create([
    'bundle' => 'image',
    'name' => $name,
    'status' => 1,
    'uid' => $file->getOwnerId(),
    'field_media_image' => [
      'target_id' => $fid,
      'alt' => $alt,
    ],
  ]);
  $media->save();
  return $byFid[$fid] = $media;
}
