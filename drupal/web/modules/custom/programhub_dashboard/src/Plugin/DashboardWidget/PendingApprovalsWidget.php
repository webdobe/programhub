<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Plugin\DashboardWidget;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\programhub_dashboard\DashboardWidgetBase;
use Drupal\programhub_dashboard\Service\GroupContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @DashboardWidget(
 *   id = "pending_approvals",
 *   label = @Translation("Pending approvals"),
 *   description = @Translation("Community content awaiting review in programs you moderate."),
 *   weight = -80,
 *   category = "reviewer"
 * )
 *
 * Reviewer-scoped widget. Only renders for users who hold a reviewer
 * Group role (instructor / manager / administrator) on at least one
 * program, AND the listed nodes are filtered to only the programs that
 * user can actually moderate.
 */
final class PendingApprovalsWidget extends DashboardWidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The 11 community content types. Living source of truth is ACCESS.md §2.
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
   * Group role name SUFFIXES that confer reviewer authority. Matched
   * across every program subtype (program, program_design, …) via
   * GroupContext::userProgramGroupIds().
   */
  private const REVIEWER_ROLES = [
    'instructor',
    'manager',
    'administrator',
  ];

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly GroupContext $groupContext,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('programhub_dashboard.group_context'),
      $container->get('entity_type.manager'),
    );
  }

  public function access(AccountInterface $user): AccessResultInterface {
    $gids = $this->groupContext->userProgramGroupIds($user, self::REVIEWER_ROLES);
    return AccessResult::allowedIf(!empty($gids))
      ->addCacheTags(['group_relationship_list:group_membership'])
      ->addCacheContexts(['user']);
  }

  public function build(AccountInterface $user): array {
    $gids = $this->groupContext->userProgramGroupIds($user, self::REVIEWER_ROLES);
    if (empty($gids)) {
      return [];
    }

    $nids = $this->groupContext->nidsInGroups($gids, self::COMMUNITY_TYPES);
    if (empty($nids)) {
      return [];
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $items = [];
    foreach ($nodeStorage->loadMultiple($nids) as $node) {
      if (!$node instanceof NodeInterface || !$node->hasField('moderation_state')) {
        continue;
      }
      if ($node->get('moderation_state')->value !== 'pending_review') {
        continue;
      }
      $author = $node->getOwner();
      $items[] = [
        '#type' => 'inline_template',
        '#template' => '<a href="{{ url }}"><strong>{{ title }}</strong></a> — <span class="programhub-widget__meta">{{ bundle }}{% if author %} · by {{ author }}{% endif %}</span>',
        '#context' => [
          'url' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])->toString(),
          'title' => $node->label(),
          'bundle' => $node->bundle(),
          'author' => $author ? $author->getDisplayName() : NULL,
        ],
      ];
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
        'contexts' => ['user'],
        'tags' => array_map(
          static fn(string $type): string => "node_list:$type",
          self::COMMUNITY_TYPES,
        ),
      ],
    ];
  }

}
