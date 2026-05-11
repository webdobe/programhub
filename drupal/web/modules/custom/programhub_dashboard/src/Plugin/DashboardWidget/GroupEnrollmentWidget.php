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
 *   id = "group_enrollment",
 *   label = @Translation("Manage members"),
 *   description = @Translation("Member counts and quick links to manage enrollment in programs you administer."),
 *   weight = -72,
 *   category = "manager"
 * )
 *
 * Manager/admin tier widget. For every program where the user holds
 * manager or administrator OG role, shows the member count and a link
 * to OG's people management page.
 */
final class GroupEnrollmentWidget extends DashboardWidgetBase implements ContainerFactoryPluginInterface {

  private const ADMIN_OG_ROLES = [
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
    return AccessResult::allowedIf(!empty($this->adminPrograms($user)))
      ->addCacheContexts(['user'])
      ->addCacheTags(["og_user_membership:{$user->id()}"]);
  }

  public function build(AccountInterface $user): array {
    $programs = $this->adminPrograms($user);
    if (empty($programs)) {
      return [];
    }

    $rows = [];
    foreach ($programs as $programId => $programLabel) {
      $count = $this->countActiveMembers($programId);
      $rows[] = [
        'label' => $programLabel,
        'count' => $count,
        // OG admin route — `entity.node.og_admin_routes.members` is what
        // contrib og_ui registers for the people-management page.
        'manage_url' => Url::fromRoute('entity.node.og_admin_routes.members', [
          'node' => $programId,
        ])->toString(),
      ];
    }

    return [
      '#type' => 'inline_template',
      '#template' => '
        <table class="programhub-widget__table">
          <thead>
            <tr>
              <th>{{ "Program"|t }}</th>
              <th class="programhub-widget__numeric">{{ "Members"|t }}</th>
              <th>{{ "Actions"|t }}</th>
            </tr>
          </thead>
          <tbody>
            {% for row in rows %}
              <tr>
                <td>{{ row.label }}</td>
                <td class="programhub-widget__numeric">{{ row.count }}</td>
                <td><a href="{{ row.manage_url }}" class="button button--small">{{ "Manage"|t }}</a></td>
              </tr>
            {% endfor %}
          </tbody>
        </table>
      ',
      '#context' => ['rows' => $rows],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['og_membership_list'],
      ],
    ];
  }

  /**
   * Count active members in a group.
   */
  private function countActiveMembers(int $groupNodeId): int {
    $group = $this->entityTypeManager->getStorage('node')->load($groupNodeId);
    if (!$group instanceof NodeInterface) {
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage('og_membership');
    $count = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('entity_type', 'node')
      ->condition('entity_id', $groupNodeId)
      ->condition('state', OgMembershipInterface::STATE_ACTIVE)
      ->count()
      ->execute();
    return (int) $count;
  }

  /**
   * @return array<int,string> programId => label
   */
  private function adminPrograms(AccountInterface $user): array {
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
        if (in_array($role->id(), self::ADMIN_OG_ROLES, TRUE)) {
          $programs[(int) $group->id()] = (string) $group->label();
          break;
        }
      }
    }
    return $programs;
  }

}
