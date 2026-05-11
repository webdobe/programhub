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
 *   id = "program_summary",
 *   label = @Translation("Program at a glance"),
 *   description = @Translation("Counts of projects, pending reviews, and events for programs you manage."),
 *   weight = -75,
 *   category = "manager"
 * )
 *
 * Manager-tier widget. Visible only if the user holds `manager` or
 * `administrator` Group role on at least one program. Shows per-program
 * counts of common content types.
 */
final class ProgramSummaryWidget extends DashboardWidgetBase implements ContainerFactoryPluginInterface {

  private const MANAGER_ROLES = [
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
    $programs = $this->groupContext->userProgramsByLabel($user, self::MANAGER_ROLES);
    return AccessResult::allowedIf(!empty($programs))
      ->addCacheContexts(['user'])
      ->addCacheTags(['group_relationship_list:group_membership']);
  }

  public function build(AccountInterface $user): array {
    $programs = $this->groupContext->userProgramsByLabel($user, self::MANAGER_ROLES);
    if (empty($programs)) {
      return [];
    }

    $rows = [];
    foreach ($programs as $gid => $label) {
      $rows[] = [
        'label' => $label,
        'url' => Url::fromRoute('entity.group.canonical', ['group' => $gid])->toString(),
        'counts' => [
          'projects' => $this->countByBundle($gid, ['project']),
          'pending' => $this->countPending($gid),
          'events' => $this->countByBundle($gid, ['event']),
        ],
      ];
    }

    return [
      '#type' => 'inline_template',
      '#template' => '
        <table class="programhub-widget__table">
          <thead>
            <tr>
              <th>{{ "Program"|t }}</th>
              <th class="programhub-widget__numeric">{{ "Projects"|t }}</th>
              <th class="programhub-widget__numeric">{{ "Pending"|t }}</th>
              <th class="programhub-widget__numeric">{{ "Events"|t }}</th>
            </tr>
          </thead>
          <tbody>
            {% for row in rows %}
              <tr>
                <td><a href="{{ row.url }}">{{ row.label }}</a></td>
                <td class="programhub-widget__numeric">{{ row.counts.projects }}</td>
                <td class="programhub-widget__numeric">{{ row.counts.pending }}</td>
                <td class="programhub-widget__numeric">{{ row.counts.events }}</td>
              </tr>
            {% endfor %}
          </tbody>
        </table>
      ',
      '#context' => ['rows' => $rows],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['node_list:project', 'node_list:event'],
      ],
    ];
  }

  private function countByBundle(int $gid, array $bundles): int {
    $nids = $this->groupContext->nidsInGroups([$gid], $bundles);
    if (!$nids) {
      return 0;
    }
    return (int) $this->entityTypeManager->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('nid', $nids, 'IN')
      ->condition('status', 1)
      ->count()
      ->execute();
  }

  /**
   * Count nodes pending review across all community types in a program.
   * `moderation_state` isn't directly queryable, so load-then-filter;
   * the scan is capped at 200 since managers have a finite scope.
   */
  private function countPending(int $gid): int {
    $nids = array_slice($this->groupContext->nidsInGroups([$gid]), 0, 200);
    if (empty($nids)) {
      return 0;
    }
    $count = 0;
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($nids) as $node) {
      if ($node instanceof NodeInterface
        && $node->hasField('moderation_state')
        && $node->get('moderation_state')->value === 'pending_review') {
        $count++;
      }
    }
    return $count;
  }

}
