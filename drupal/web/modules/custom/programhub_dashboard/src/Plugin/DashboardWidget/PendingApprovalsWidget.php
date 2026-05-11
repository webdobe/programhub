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
use Drupal\og\MembershipManagerInterface;
use Drupal\og\OgMembershipInterface;
use Drupal\programhub_dashboard\DashboardWidgetBase;
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
 * First reviewer-scoped widget. Only renders for users who have at
 * least one `approve community_content` permission grant (via an OG
 * role like instructor / manager / administrator on some program), AND
 * the listed nodes are filtered to ONLY the programs that user can
 * actually moderate.
 */
final class PendingApprovalsWidget extends DashboardWidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The 11 community content types — same list as MyDraftsWidget. If
   * you change one, change both. Living source of truth is ACCESS.md §2.
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
   * OG roles that confer reviewer authority on a program. Holding any
   * of these in at least one program lets the widget render.
   */
  private const REVIEWER_OG_ROLES = [
    'node-program-instructor',
    'node-program-manager',
    'node-program-administrator',
  ];

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly MembershipManagerInterface $membershipManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('og.membership_manager'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Allowed iff the user holds at least one reviewer OG role on at
   * least one program. Site admins always pass via their global perms,
   * but they hit this through the normal `access community_content
   * transition approve` permission which Drupal grants from elsewhere —
   * here we just check OG group membership.
   */
  public function access(AccountInterface $user): AccessResultInterface {
    $programIds = $this->reviewerProgramIds($user);
    return AccessResult::allowedIf(!empty($programIds))
      // Re-check when this user's OG memberships change.
      ->addCacheTags(["og_user_membership:{$user->id()}"])
      ->addCacheContexts(['user']);
  }

  public function build(AccountInterface $user): array {
    $programIds = $this->reviewerProgramIds($user);
    if (empty($programIds)) {
      return [];
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Same load-then-filter pattern as MyDraftsWidget — moderation_state
    // isn't directly queryable on the node. We also constrain by
    // og_audience to the user's reviewer-scope programs, so we never
    // surface a CITE submission to a GDES reviewer.
    $nids = $nodeStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', self::COMMUNITY_TYPES, 'IN')
      ->condition('og_audience', $programIds, 'IN')
      ->sort('changed', 'DESC')
      ->range(0, 100)
      ->execute();

    if (empty($nids)) {
      return [];
    }

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

  /**
   * Programs (node IDs) where this user holds a reviewer OG role.
   *
   * @return int[]
   */
  private function reviewerProgramIds(AccountInterface $user): array {
    $memberships = $this->membershipManager->getMemberships(
      (int) $user->id(),
      [OgMembershipInterface::STATE_ACTIVE],
    );

    $programIds = [];
    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if (!$group instanceof NodeInterface || $group->bundle() !== 'program') {
        continue;
      }
      foreach ($membership->getRoles() as $role) {
        if (in_array($role->id(), self::REVIEWER_OG_ROLES, TRUE)) {
          $programIds[(int) $group->id()] = (int) $group->id();
          break;
        }
      }
    }
    return array_values($programIds);
  }

}
