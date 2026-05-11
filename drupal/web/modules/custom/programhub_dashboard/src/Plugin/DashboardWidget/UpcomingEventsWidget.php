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
 *   id = "upcoming_events",
 *   label = @Translation("Upcoming events"),
 *   description = @Translation("Events in your programs."),
 *   weight = -70,
 *   category = "universal"
 * )
 */
final class UpcomingEventsWidget extends DashboardWidgetBase implements ContainerFactoryPluginInterface {

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
    $nids = $this->groupContext->nidsInGroups($gids, ['event']);
    if (empty($nids)) {
      return [];
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $now = time();
    $items = [];
    foreach ($nodeStorage->loadMultiple($nids) as $node) {
      if (!$node instanceof NodeInterface || !$node->isPublished()) {
        continue;
      }
      if ($node->hasField('moderation_state')
        && $node->get('moderation_state')->value !== 'published') {
        continue;
      }
      $eventTs = $this->extractEventTimestamp($node);
      if ($eventTs === NULL || $eventTs < $now) {
        continue;
      }
      $items[] = [
        'ts' => $eventTs,
        'render' => [
          '#type' => 'inline_template',
          '#template' => '<a href="{{ url }}"><strong>{{ title }}</strong></a> — <span class="programhub-widget__meta">{{ when }}</span>',
          '#context' => [
            'url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString(),
            'title' => $node->label(),
            'when' => $this->dateFormatter->format($eventTs, 'medium'),
          ],
        ],
      ];
    }
    if (empty($items)) {
      return [];
    }
    usort($items, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);
    $items = array_slice($items, 0, 5);

    return [
      '#theme' => 'item_list',
      '#items' => array_column($items, 'render'),
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list:event'],
      ],
    ];
  }

  /**
   * Try a few common date field names to find the event timestamp.
   * Returns NULL if the event has no date — those get filtered out.
   */
  private function extractEventTimestamp(NodeInterface $node): ?int {
    foreach (['field_event_date', 'field_date', 'field_start'] as $name) {
      if (!$node->hasField($name) || $node->get($name)->isEmpty()) {
        continue;
      }
      $value = $node->get($name)->first()->getValue();
      $raw = $value['value'] ?? NULL;
      if (!$raw) {
        continue;
      }
      $ts = is_numeric($raw) ? (int) $raw : strtotime((string) $raw);
      if ($ts) {
        return $ts;
      }
    }
    return NULL;
  }

}
