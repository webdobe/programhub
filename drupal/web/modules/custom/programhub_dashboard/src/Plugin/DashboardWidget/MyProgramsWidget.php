<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Plugin\DashboardWidget;

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
 *   id = "my_programs",
 *   label = @Translation("My programs"),
 *   description = @Translation("Programs you belong to and your role in each."),
 *   weight = -90,
 *   category = "universal"
 * )
 *
 * First OG-aware widget. Lists the user's active OG memberships scoped
 * to the `program` group bundle, with their OG roles in each — the
 * orientation widget that answers "where do I belong, and what am I
 * here as?"
 */
final class MyProgramsWidget extends DashboardWidgetBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly MembershipManagerInterface $membershipManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('og.membership_manager'),
    );
  }

  public function build(AccountInterface $user): array {
    $memberships = $this->membershipManager->getMemberships(
      (int) $user->id(),
      [OgMembershipInterface::STATE_ACTIVE],
    );

    if (empty($memberships)) {
      return [];
    }

    $items = [];
    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if (!$group instanceof NodeInterface || $group->bundle() !== 'program') {
        // Skip non-program memberships (e.g., divisions); a separate
        // widget could show those.
        continue;
      }

      // Format OG role labels — strip the `node-program-` prefix so the
      // user sees "Instructor" instead of "node-program-instructor".
      $roleLabels = [];
      foreach ($membership->getRoles() as $role) {
        $roleLabels[] = $role->label();
      }
      sort($roleLabels);

      $items[] = [
        '#type' => 'inline_template',
        '#template' => '<a href="{{ url }}"><strong>{{ title }}</strong></a>{% if roles %} — <span class="programhub-widget__meta">{{ roles|join(", ") }}</span>{% endif %}',
        '#context' => [
          'url' => Url::fromRoute('entity.node.canonical', ['node' => $group->id()])->toString(),
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
        // Refresh whenever this user's OG memberships change.
        'contexts' => ['user'],
        'tags' => ["og_user_membership:{$user->id()}"],
      ],
    ];
  }

}
