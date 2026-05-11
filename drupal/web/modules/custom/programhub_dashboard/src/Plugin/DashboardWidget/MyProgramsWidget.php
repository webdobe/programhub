<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Plugin\DashboardWidget;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupMembership;
use Drupal\programhub_dashboard\DashboardWidgetBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @DashboardWidget(
 *   id = "my_programs",
 *   label = @Translation("My programs"),
 *   description = @Translation("Programs you belong to and your role in each."),
 *   weight = -90,
 *   category = "universal"
 * )
 *
 * Lists the user's Group memberships scoped to the `program` group
 * bundle, with their roles in each — the orientation widget that
 * answers "where do I belong, and what am I here as?"
 */
final class MyProgramsWidget extends DashboardWidgetBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self($configuration, $plugin_id, $plugin_definition);
  }

  public function build(AccountInterface $user): array {
    $memberships = GroupMembership::loadByUser($user);
    if (!$memberships) {
      return [];
    }

    $items = [];
    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if (!$group || !in_array($group->bundle(), \Drupal\programhub_dashboard\Service\GroupContext::PROGRAM_GROUP_TYPES, TRUE)) {
        continue;
      }

      $roleLabels = [];
      foreach ($membership->getRoles() as $role) {
        $roleLabels[] = $role->label();
      }
      sort($roleLabels);

      $items[] = [
        '#type' => 'inline_template',
        '#template' => '<a href="{{ url }}"><strong>{{ title }}</strong></a>{% if roles %} — <span class="programhub-widget__meta">{{ roles|join(", ") }}</span>{% endif %}',
        '#context' => [
          'url' => Url::fromRoute('entity.group.canonical', ['group' => $group->id()])->toString(),
          'title' => $group->label(),
          'roles' => $roleLabels,
        ],
      ];
    }

    if (empty($items)) {
      return [];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['group_relationship_list:group_membership'],
      ],
    ];
  }

}
