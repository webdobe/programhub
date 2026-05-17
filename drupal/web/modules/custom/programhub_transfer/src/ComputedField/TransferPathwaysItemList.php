<?php

declare(strict_types=1);

namespace Drupal\programhub_transfer\ComputedField;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;

/**
 * Computed FieldItemList for `field_transfer_pathways`.
 *
 * Populated lazily from a reverse-reference query on transfer_pathway nodes.
 * The host entity (certificate or partner_institution) determines which
 * filter is run — see `queryPathwayIds()` for the branches.
 *
 * Sort: titles ascending, so the cert-page render order is deterministic.
 */
final class TransferPathwaysItemList extends EntityReferenceFieldItemList {

  use ComputedItemListTrait;

  protected function computeValue(): void {
    $entity = $this->getEntity();
    $storage = \Drupal::entityTypeManager()->getStorage('node');

    $ids = $this->queryPathwayIds($entity, $storage);
    foreach ($ids as $delta => $id) {
      $this->list[$delta] = $this->createItem($delta, ['target_id' => (int) $id]);
    }
  }

  /**
   * @return array<int,int|string>
   *   Pathway node ids, ordered by title.
   */
  private function queryPathwayIds(EntityInterface $entity, EntityStorageInterface $storage): array {
    if ($entity->getEntityTypeId() !== 'node') {
      return [];
    }
    $bundle = $entity->bundle();

    if ($bundle === 'partner_institution') {
      // Pathways pointing at this partner. One query, straight equality.
      return array_values($storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'transfer_pathway')
        ->condition('field_partner', $entity->id())
        ->sort('title')
        ->execute());
    }

    if ($bundle === 'certificate') {
      // Two ways a pathway can apply to this cert:
      //   1. It explicitly lists the cert in field_source_certificates.
      //   2. It lists the cert's program group in field_programs (program-
      //      level pathway that covers every cert under that program).
      $query = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'transfer_pathway');

      $or = $query->orConditionGroup()
        ->condition('field_source_certificates', $entity->id());

      $programGids = $this->programGidsForCertificate($entity);
      if ($programGids) {
        $or->condition('field_programs', $programGids, 'IN');
      }
      $query->condition($or);

      return array_values($query->sort('title')->execute());
    }

    return [];
  }

  /**
   * Find every program group this certificate is related to via gnode.
   *
   * A cert can sit under multiple programs (rare in practice but allowed by
   * the schema). We include them all so a pathway scoped to any one of those
   * programs surfaces on the cert.
   *
   * @return array<int,int>
   */
  private function programGidsForCertificate(EntityInterface $cert): array {
    $relStorage = \Drupal::entityTypeManager()->getStorage('group_relationship');
    $rels = $relStorage->loadByProperties([
      'entity_id' => $cert->id(),
      'plugin_id' => 'group_node:certificate',
    ]);
    $gids = [];
    foreach ($rels as $rel) {
      $gid = (int) $rel->getGroupId();
      if ($gid) {
        $gids[] = $gid;
      }
    }
    return array_values(array_unique($gids));
  }

}
