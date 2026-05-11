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
 *   id = "program_summary",
 *   label = @Translation("Program at a glance"),
 *   description = @Translation("Counts of projects, pending reviews, and events for programs you manage."),
 *   weight = -75,
 *   category = "manager"
 * )
 *
 * Manager-tier widget. Visible only if the user holds `manager` or
 * `administrator` OG roles on at least one program. Shows per-program
 * counts of common content types.
 */
final class ProgramSummaryWidget extends DashboardWidgetBase implements ContainerFactoryPluginInterface {

  private const MANAGER_OG_ROLES = [
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

  public function access(AccountInterface $user): AccessResultInterface {
    $programIds = $this->managedProgramIds($user);
    return AccessResult::allowedIf(!empty($programIds))
      ->addCacheContexts(['user'])
      ->addCacheTags(["og_user_membership:{$user->id()}"]);
  }

  public function build(AccountInterface $user): array {
    $programs = $this->managedProgramIds($user);
    if (empty($programs)) {
      return [];
    }

    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $rows = [];

    foreach ($programs as $programId => $programLabel) {
      $rows[] = [
        'label' => $programLabel,
        'url' => Url::fromRoute('entity.node.canonical', ['node' => $programId])->toString(),
        'counts' => [
          'projects' => $this->count($nodeStorage, $programId, ['project']),
          'pending' => $this->countPending($nodeStorage, $programId),
          'events' => $this->count($nodeStorage, $programId, ['event']),
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

  /**
   * @return array<int,string> programId => label
   */
  private function managedProgramIds(AccountInterface $user): array {
    $memberships = $this->membershipManager->getMemberships(
      (int) $user->id(),
      [OgMembershipInterface::STATE_ACTIVE],
    );
    $programs = [];
    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if (!$group instanceof NodeInterface || $group->bundle() !== 'program') {
        continue;
      }
      foreach ($membership->getRoles() as $role) {
        if (in_array($role->id(), self::MANAGER_OG_ROLES, TRUE)) {
          $programs[(int) $group->id()] = (string) $group->label();
          break;
        }
      }
    }
    return $programs;
  }

  /**
   * Count published nodes of given bundles in a single program.
   */
  private function count($nodeStorage, int $programId, array $bundles): int {
    $count = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundles, 'IN')
      ->condition('og_audience', $programId)
      ->condition('status', 1)
      ->count()
      ->execute();
    return (int) $count;
  }

  /**
   * Count nodes pending review across all community types in a program.
   * Falls back to load-then-filter since `moderation_state` isn't
   * directly queryable on the node — but we cap the scan at 200 nodes
   * per program; this widget is for managers, who have a finite scope.
   */
  private function countPending($nodeStorage, int $programId): int {
    $nids = $nodeStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('og_audience', $programId)
      ->range(0, 200)
      ->execute();
    if (empty($nids)) {
      return 0;
    }
    $count = 0;
    foreach ($nodeStorage->loadMultiple($nids) as $node) {
      if ($node instanceof NodeInterface
        && $node->hasField('moderation_state')
        && $node->get('moderation_state')->value === 'pending_review') {
        $count++;
      }
    }
    return $count;
  }

}
