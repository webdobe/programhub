<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Plugin\DashboardWidget;

use Drupal\Core\Session\AccountInterface;
use Drupal\programhub_dashboard\DashboardWidgetBase;

/**
 * @DashboardWidget(
 *   id = "profile",
 *   label = @Translation("Your profile"),
 *   description = @Translation("Name and roles."),
 *   weight = -100,
 *   category = "universal"
 * )
 */
final class ProfileWidget extends DashboardWidgetBase {

  /**
   * {@inheritdoc}
   *
   * Inherited default: any authenticated user. No override needed.
   */
  public function build(AccountInterface $user): array {
    return [
      '#type' => 'container',
      'name' => [
        '#markup' => '<p><strong>' . $user->getDisplayName() . '</strong></p>',
      ],
      'roles' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Roles'),
        '#items' => array_diff(
          $user->getRoles(),
          ['authenticated', 'anonymous'],
        ),
        '#empty' => $this->t('No additional roles yet.'),
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ["user:{$user->id()}"],
      ],
    ];
  }

}
