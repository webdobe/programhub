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

  /**
   * {@inheritdoc}
   *
   * Only show if the user belongs to at least one program. Otherwise
   * "upcoming events" is meaningless noise.
   */
  public function access(AccountInterface $user): AccessResultInterface {
    $programIds = $this->programIds($user);
    return AccessResult::allowedIf(!empty($programIds))
      ->addCacheContexts(['user'])
      ->addCacheTags(["og_user_membership:{$user->id()}"]);
  }

  public function build(AccountInterface $user): array {
    $programIds = $this->programIds($user);
    if (empty($programIds)) {
      return [];
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');

    // Pull recent events in user's programs; filter to published +
    // future-dated in PHP. Event date field name varies in practice
    // (`field_date`, `field_event_date`, etc.) — defensive lookup.
    $nids = $nodeStorage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('og_audience', $programIds, 'IN')
      ->sort('created', 'DESC')
      ->range(0, 50)
      ->execute();

    if (empty($nids)) {
      return [];
    }

    $now = time();
    $items = [];
    foreach ($nodeStorage->loadMultiple($nids) as $node) {
      if (!$node instanceof NodeInterface) {
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
      if (count($items) >= 5) {
        break;
      }
    }
    if (empty($items)) {
      return [];
    }
    usort($items, static fn(array $a, array $b): int => $a['ts'] <=> $b['ts']);

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
      // Datetime fields store ISO strings; daterange stores 'value' for start.
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
