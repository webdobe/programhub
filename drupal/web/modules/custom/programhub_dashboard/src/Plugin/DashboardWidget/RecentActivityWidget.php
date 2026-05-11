<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Plugin\DashboardWidget;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
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
 *   id = "recent_activity",
 *   label = @Translation("Recent activity"),
 *   description = @Translation("Latest changes in programs you belong to."),
 *   weight = -60,
 *   category = "program"
 * )
 *
 * Universal-but-program-scoped: any user in a program sees changes to
 * content in THAT program. A GDES student doesn't see CITE activity.
 */
final class RecentActivityWidget extends DashboardWidgetBase implements ContainerFactoryPluginInterface {

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

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly MembershipManagerInterface $membershipManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
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
      $container->get('date.formatter'),
    );
  }

  public function access(AccountInterface $user): AccessResultInterface {
    return AccessResult::allowedIf(!empty($this->programIds($user)))
      ->addCacheContexts(['user'])
      ->addCacheTags(["og_user_membership:{$user->id()}"]);
  }

  public function build(AccountInterface $user): array {
    $programIds = $this->programIds($user);
    if (empty($programIds)) {
      return [];
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');

    $nids = $nodeStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', self::COMMUNITY_TYPES, 'IN')
      ->condition('og_audience', $programIds, 'IN')
      ->sort('changed', 'DESC')
      ->range(0, 10)
      ->execute();

    if (empty($nids)) {
      return [];
    }

    $items = [];
    foreach ($nodeStorage->loadMultiple($nids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $items[] = [
        '#type' => 'inline_template',
        '#template' => '<a href="{{ url }}"><strong>{{ title }}</strong></a> <span class="programhub-widget__meta">— {{ bundle }} · {{ ago }}</span>',
        '#context' => [
          'url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString(),
          'title' => $node->label(),
          'bundle' => $node->bundle(),
          'ago' => $this->dateFormatter->formatTimeDiffSince($node->getChangedTime(), ['granularity' => 1]) . ' ago',
        ],
      ];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => array_map(static fn(string $t): string => "node_list:$t", self::COMMUNITY_TYPES),
      ],
    ];
  }

  /**
   * @return int[]
   */
  private function programIds(AccountInterface $user): array {
    $memberships = $this->membershipManager->getMemberships(
      (int) $user->id(),
      [OgMembershipInterface::STATE_ACTIVE],
    );
    $ids = [];
    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if ($group instanceof NodeInterface && $group->bundle() === 'program') {
        $ids[(int) $group->id()] = (int) $group->id();
      }
    }
    return array_values($ids);
  }

}
