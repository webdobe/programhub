<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Plugin\DashboardWidget;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\programhub_dashboard\DashboardWidgetBase;
use Drupal\programhub_dashboard\Service\GroupContext;
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
 * manager or administrator Group role, shows the member count and a link
 * to Group's members management view.
 */
final class GroupEnrollmentWidget extends DashboardWidgetBase implements ContainerFactoryPluginInterface {

  private const ADMIN_ROLES = [
    'manager',
    'administrator',
  ];

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly GroupContext $groupContext,
    private readonly Connection $db,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('programhub_dashboard.group_context'),
      $container->get('database'),
    );
  }

  public function access(AccountInterface $user): AccessResultInterface {
    $programs = $this->groupContext->userProgramsByLabel($user, self::ADMIN_ROLES);
    return AccessResult::allowedIf(!empty($programs))
      ->addCacheContexts(['user'])
      ->addCacheTags(['group_relationship_list:group_membership']);
  }

  public function build(AccountInterface $user): array {
    $programs = $this->groupContext->userProgramsByLabel($user, self::ADMIN_ROLES);
    if (empty($programs)) {
      return [];
    }

    $rows = [];
    foreach ($programs as $gid => $label) {
      $rows[] = [
        'label' => $label,
        'count' => $this->countMembers($gid),
        // Group ships a `/group/{gid}/members` Views page (Phase 1 brought
        // it in as `views.view.group_members`).
        'manage_url' => Url::fromRoute('view.group_members.page_1', ['group' => $gid])->toString(),
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
        'tags' => ['group_relationship_list:group_membership'],
      ],
    ];
  }

  private function countMembers(int $gid): int {
    return (int) $this->db->select('group_relationship_field_data', 'g')
      ->fields('g', ['id'])
      ->condition('gid', $gid)
      ->condition('plugin_id', 'group_membership')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
