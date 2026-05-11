<?php

/**
 * @file
 * Deploy hooks for ProgramHub governance.
 *
 * Runs AFTER `drush config:import` (per CONVENTIONS.md), so the
 * `community_content` workflow and the `content_moderation_state` field
 * on each community-content type both exist by the time these run.
 *
 * Every hook in here MUST be idempotent. Re-runs (DB restore, recovery,
 * snapshot, fresh build) re-execute every deploy hook.
 */

declare(strict_types=1);

use Drupal\node\Entity\Node;

/**
 * Backfill content_moderation_state for existing community-content nodes.
 *
 * When the `community_content` workflow is applied to an existing content
 * type, Drupal does NOT retroactively create moderation state records for
 * the nodes already in that bundle. They show up as "Not in workflow" and
 * never appear in the moderation queue until they're saved again.
 *
 * This hook fixes that by creating a `content_moderation_state` entity
 * for every node of the 11 community content types, setting:
 *   - `moderation_state` = "published" if the node is published, else "draft"
 *   - referencing the node's current default revision
 *
 * Skips any node that already has a moderation state — re-runs are safe.
 */
function programhub_governance_deploy_01_backfill_moderation_states(?array &$sandbox = NULL): string {
  $logger = \Drupal::logger('programhub_governance');
  $entityTypeManager = \Drupal::entityTypeManager();
  $nodeStorage = $entityTypeManager->getStorage('node');
  $stateStorage = $entityTypeManager->getStorage('content_moderation_state');

  $communityTypes = [
    'article',
    'award',
    'event',
    'game',
    'high_score',
    'menu',
    'simplenews_issue',
    'outcome',
    'portfolio_show',
    'project',
    'student_spotlight',
  ];

  // Build the worklist on the first call. Drupal batches deploy hooks via
  // the `$sandbox` mechanism; we use it so a few thousand nodes don't
  // exhaust memory in one pass.
  if ($sandbox === NULL || !isset($sandbox['nids'])) {
    $sandbox ??= [];
    $sandbox['nids'] = array_values(
      $nodeStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $communityTypes, 'IN')
        ->execute()
    );
    $sandbox['total'] = count($sandbox['nids']);
    $sandbox['cursor'] = 0;
    $sandbox['created'] = 0;
    $sandbox['skipped'] = 0;
    $sandbox['#finished'] = $sandbox['total'] === 0 ? 1 : 0;
    if ($sandbox['total'] === 0) {
      return 'No community-content nodes found; nothing to backfill.';
    }
  }

  // Process in chunks of 50 to keep peak memory predictable.
  $batchSize = 50;
  $end = min($sandbox['cursor'] + $batchSize, $sandbox['total']);

  for ($i = $sandbox['cursor']; $i < $end; $i++) {
    $nid = $sandbox['nids'][$i];
    $node = $nodeStorage->load($nid);
    if (!$node instanceof Node) {
      continue;
    }

    // Idempotency: skip if a state already exists for this node under the
    // community_content workflow.
    $existing = $stateStorage->loadByProperties([
      'content_entity_type_id' => 'node',
      'content_entity_id' => $nid,
      'workflow' => 'community_content',
    ]);
    if (!empty($existing)) {
      $sandbox['skipped']++;
      continue;
    }

    // Map node.status to a workflow state. Existing published nodes
    // become "published"; unpublished ones become "draft" (closest
    // analogue — they weren't reviewed under this workflow).
    $targetState = $node->isPublished() ? 'published' : 'draft';

    try {
      $moderationState = $stateStorage->create([
        'content_entity_type_id' => 'node',
        'content_entity_id' => $nid,
        'content_entity_revision_id' => $node->getRevisionId(),
        'workflow' => 'community_content',
        'moderation_state' => $targetState,
      ]);
      $moderationState->save();
      $sandbox['created']++;
    }
    catch (\Throwable $e) {
      $logger->error(
        'Failed to backfill moderation state for node @nid (@bundle): @msg',
        [
          '@nid' => $nid,
          '@bundle' => $node->bundle(),
          '@msg' => $e->getMessage(),
        ],
      );
    }
  }

  $sandbox['cursor'] = $end;
  $sandbox['#finished'] = $sandbox['cursor'] / max(1, $sandbox['total']);

  if ($sandbox['#finished'] >= 1) {
    return sprintf(
      'Backfilled %d moderation states (skipped %d pre-existing) across %d nodes.',
      $sandbox['created'],
      $sandbox['skipped'],
      $sandbox['total'],
    );
  }

  return sprintf(
    'Backfilling… %d/%d nodes processed (%d created so far).',
    $sandbox['cursor'],
    $sandbox['total'],
    $sandbox['created'],
  );
}
