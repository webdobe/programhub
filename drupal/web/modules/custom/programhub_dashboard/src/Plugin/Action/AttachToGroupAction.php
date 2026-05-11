<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bulk action: attach the selected nodes to a chosen group.
 *
 * Picks a target group up front, then for each selected node:
 *   - figures out the right `group_node:{bundle}` relationship type
 *     enabled on the target group's bundle,
 *   - creates a `group_relationship` row linking node ↔ group (skips
 *     when one already exists, so re-running is safe),
 *   - logs anything that can't be attached (no gnode plugin enabled on
 *     the target group for that bundle).
 *
 * The original use case: imported content (career outcomes, courses)
 * sometimes lands without a group; this lets an admin retroactively
 * scope a batch of orphans to the right program in one shot.
 */
#[Action(
  id: 'programhub_attach_to_group',
  label: new TranslatableMarkup('Attach to group (program/division)'),
  type: 'node'
)]
final class AttachToGroupAction extends ViewsBulkOperationsActionBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $etm,
    private readonly LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    /** @var LoggerChannelFactoryInterface $loggerFactory */
    $loggerFactory = $container->get('logger.factory');
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $loggerFactory->get('programhub_dashboard'),
    );
  }

  /**
   * Group picker shown in the VBO action-config step.
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $options = [];
    foreach (Group::loadMultiple() as $group) {
      assert($group instanceof GroupInterface);
      $options[$group->id()] = sprintf(
        '%s — %s',
        ucfirst($group->bundle()),
        $group->label(),
      );
    }
    asort($options);

    $form['gid'] = [
      '#type' => 'select',
      '#title' => $this->t('Target group'),
      '#description' => $this->t('Each selected node will be related to this group as gnode content. Already-related nodes are skipped.'),
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => $this->configuration['gid'] ?? NULL,
    ];
    return $form;
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['gid'] = (int) $form_state->getValue('gid');
  }

  /**
   * Per-node executor. Returns a short status string VBO surfaces on the
   * results page.
   */
  public function execute(?EntityInterface $entity = NULL): TranslatableMarkup {
    if (!$entity instanceof NodeInterface) {
      return $this->t('Skipped (not a node).');
    }
    $gid = (int) ($this->configuration['gid'] ?? 0);
    if ($gid === 0) {
      return $this->t('Skipped (no target group).');
    }
    /** @var \Drupal\group\Entity\GroupInterface|null $group */
    $group = $this->etm->getStorage('group')->load($gid);
    if (!$group) {
      return $this->t('Skipped (target group not found).');
    }

    $pluginId = 'group_node:' . $entity->bundle();

    // Verify the bundle's gnode plugin is enabled on this group type.
    // Without that, addRelationship() throws.
    $relTypes = $this->etm->getStorage('group_relationship_type')->loadByProperties([
      'group_type' => $group->bundle(),
      'content_plugin' => $pluginId,
    ]);
    if (!$relTypes) {
      $this->logger->warning(
        '@bundle node @nid not attached: no @plugin plugin on group @group_label (@gtype).',
        [
          '@bundle' => $entity->bundle(),
          '@nid' => $entity->id(),
          '@plugin' => $pluginId,
          '@group_label' => $group->label(),
          '@gtype' => $group->bundle(),
        ],
      );
      return $this->t('Skipped (plugin @p not enabled on @g).', [
        '@p' => $pluginId,
        '@g' => $group->bundle(),
      ]);
    }

    // Idempotency: skip if this node is already in this group via the
    // same plugin. (Multiple plugins can coexist on the same node, but
    // we never want duplicate rows for the same plugin.)
    $existing = $group->getRelationshipsByEntity($entity, $pluginId);
    if ($existing) {
      return $this->t('Already attached.');
    }

    $group->addRelationship($entity, $pluginId);
    return $this->t('Attached.');
  }

  /**
   * Site-admin only: this is a privileged content-scope operation,
   * and Group's per-group permissions don't have a coherent "may move
   * arbitrary content into this group" concept.
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $account ??= \Drupal::currentUser();
    $allowed = $account->hasPermission('administer group');
    if ($return_as_object) {
      return $allowed
        ? \Drupal\Core\Access\AccessResult::allowed()
        : \Drupal\Core\Access\AccessResult::forbidden();
    }
    return $allowed;
  }

}
