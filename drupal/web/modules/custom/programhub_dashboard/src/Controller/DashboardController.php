<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\programhub_dashboard\DashboardWidgetPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * `/dashboard` route handler.
 *
 * Iterates every registered widget plugin in weight order, runs the
 * widget's own `access()` check against the current user, and renders the
 * accessible ones. The widget's access result's cache metadata is folded
 * into the page so the dashboard auto-invalidates whenever a widget's
 * visibility would change.
 */
final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly DashboardWidgetPluginManager $widgetManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('plugin.manager.programhub_dashboard_widget'),
    );
  }

  /**
   * Render the dashboard for the current user.
   */
  public function view(): array {
    $user = $this->currentUser();
    $widgets = $this->widgetManager->getAllSorted();

    $items = [];
    $cacheMeta = [
      'contexts' => ['user'],
      'tags' => ['programhub_dashboard'],
    ];

    foreach ($widgets as $id => $widget) {
      $access = $widget->access($user);
      $cacheMeta['contexts'] = array_merge(
        $cacheMeta['contexts'],
        $access->getCacheContexts(),
      );
      $cacheMeta['tags'] = array_merge(
        $cacheMeta['tags'],
        $access->getCacheTags(),
      );

      if (!$access->isAllowed()) {
        continue;
      }

      $body = $widget->build($user);
      if (empty($body)) {
        continue;
      }

      $items[$id] = [
        '#theme' => 'programhub_dashboard_widget',
        '#widget_id' => $id,
        '#label' => $widget->label(),
        '#category' => $widget->category(),
        '#body' => $body,
      ];
    }

    return [
      '#theme' => 'programhub_dashboard',
      '#widgets' => $items,
      '#user' => [
        'id' => (int) $user->id(),
        'name' => $user->getDisplayName(),
      ],
      '#attached' => [
        'library' => ['programhub_dashboard/dashboard'],
      ],
      '#cache' => [
        'contexts' => array_values(array_unique($cacheMeta['contexts'])),
        'tags' => array_values(array_unique($cacheMeta['tags'])),
      ],
    ];
  }

}
