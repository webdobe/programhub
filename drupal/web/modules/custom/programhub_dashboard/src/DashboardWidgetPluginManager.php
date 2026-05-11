<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\programhub_dashboard\Annotation\DashboardWidget;

/**
 * Plugin manager for `@DashboardWidget` plugins.
 *
 * Discovers any class annotated with `@DashboardWidget` under
 * `Drupal\*\Plugin\DashboardWidget` in every enabled module.
 */
final class DashboardWidgetPluginManager extends DefaultPluginManager {

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cacheBackend,
    ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct(
      'Plugin/DashboardWidget',
      $namespaces,
      $moduleHandler,
      DashboardWidgetInterface::class,
      DashboardWidget::class,
    );
    $this->alterInfo('programhub_dashboard_widget_info');
    $this->setCacheBackend($cacheBackend, 'programhub_dashboard_widget_plugins');
  }

  /**
   * Convenience: instantiate every widget in weight order.
   *
   * @return DashboardWidgetInterface[]
   *   Widgets keyed by plugin ID, sorted ascending by weight.
   */
  public function getAllSorted(): array {
    $definitions = $this->getDefinitions();
    uasort($definitions, static function (array $a, array $b): int {
      return ((int) ($a['weight'] ?? 0)) <=> ((int) ($b['weight'] ?? 0));
    });
    $widgets = [];
    foreach (array_keys($definitions) as $id) {
      try {
        $widget = $this->createInstance($id);
        if ($widget instanceof DashboardWidgetInterface) {
          $widgets[$id] = $widget;
        }
      }
      catch (PluginException) {
        // Skip widgets whose dependencies aren't available.
        continue;
      }
    }
    return $widgets;
  }

}
