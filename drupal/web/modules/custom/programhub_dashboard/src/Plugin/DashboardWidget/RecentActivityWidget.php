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
use Drupal\programhub_dashboard\DashboardWidgetBase;
use Drupal\programhub_dashboard\Service\GroupContext;
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
    private readonly GroupContext $groupContext,
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
      $container->get('programhub_dashboard.group_context'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  public function access(AccountInterface $user): AccessResultInterface {
    return AccessResult::allowedIf(!empty($this->groupContext->userProgramGroupIds($user)))
      ->addCacheContexts(['user'])
      ->addCacheTags(['group_relationship_list:group_membership']);
  }

  public function build(AccountInterface $user): array {
    $gids = $this->groupContext->userProgramGroupIds($user);
    if (empty($gids)) {
      return [];
    }

    $nids = $this->groupContext->nidsInGroups($gids, self::COMMUNITY_TYPES);
    if (empty($nids)) {
      return [];
    }

    // Sort by changed desc; cap at 10. Use entity query for the sort —
    // group_relationship doesn't carry node.changed.
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $sortedNids = $nodeStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('nid', $nids, 'IN')
      ->sort('changed', 'DESC')
      ->range(0, 10)
      ->execute();

    $items = [];
    foreach ($nodeStorage->loadMultiple($sortedNids) as $node) {
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

}
