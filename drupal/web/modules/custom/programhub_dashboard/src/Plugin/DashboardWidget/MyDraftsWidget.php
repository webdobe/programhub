<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Plugin\DashboardWidget;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\programhub_dashboard\DashboardWidgetBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @DashboardWidget(
 *   id = "my_drafts",
 *   label = @Translation("My drafts"),
 *   description = @Translation("Community-content nodes you've authored that aren't published yet."),
 *   weight = -50,
 *   category = "universal"
 * )
 */
final class MyDraftsWidget extends DashboardWidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The 11 content types under the community_content workflow. Mirrors
   * ACCESS.md §2. If a type is added to or removed from that workflow,
   * update this list too — keep doc and code in sync.
   */
  private const COMMUNITY_TYPES = [
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

  /**
   * Moderation states considered "in progress" (i.e. not yet live, not
   * archived). Drives the list shown to the author.
   */
  private const IN_PROGRESS_STATES = ['draft', 'pending_review', 'rejected'];

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  public function build(AccountInterface $user): array {
    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Two-step instead of conditioning on `moderation_state` directly:
    // entity_query can't always join through the content_moderation_state
    // table cleanly (it's a computed pseudo-field on the node), so we
    // pull the user's recent nodes and filter in PHP. Cheap — a user has
    // dozens of nodes at most.
    $nids = $nodeStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('uid', $user->id())
      ->condition('type', self::COMMUNITY_TYPES, 'IN')
      ->sort('changed', 'DESC')
      ->range(0, 50)
      ->execute();

    if (empty($nids)) {
      return [];
    }

    $items = [];
    foreach ($nodeStorage->loadMultiple($nids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      // Skip nodes that aren't yet in the workflow (no moderation_state
      // field yet, or computed value isn't one of the in-progress states).
      if (!$node->hasField('moderation_state')) {
        continue;
      }
      $state = $node->get('moderation_state')->value;
      if (!in_array($state, self::IN_PROGRESS_STATES, TRUE)) {
        continue;
      }
      $items[] = [
        '#type' => 'link',
        '#title' => $this->t('@title — @state', [
          '@title' => $node->label(),
          '@state' => $this->stateLabel($state),
        ]),
        '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()]),
        '#attributes' => [
          'class' => ['programhub-widget__item'],
          'data-state' => $state,
          'data-bundle' => $node->bundle(),
        ],
      ];
      // Cap the rendered list at 10.
      if (count($items) >= 10) {
        break;
      }
    }

    if (empty($items)) {
      return [];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#cache' => [
        // Invalidate when any node owned by this user changes. Cheaper
        // than tagging every individual node — node_list:<bundle> tags
        // cover the same surface for our query.
        'tags' => array_map(
          static fn(string $type): string => "node_list:$type",
          self::COMMUNITY_TYPES,
        ),
        'contexts' => ['user'],
      ],
    ];
  }

  private function stateLabel(string $state): string {
    return match ($state) {
      'draft' => (string) $this->t('Draft'),
      'pending_review' => (string) $this->t('In review'),
      'rejected' => (string) $this->t('Needs revision'),
      default => $state,
    };
  }

}
