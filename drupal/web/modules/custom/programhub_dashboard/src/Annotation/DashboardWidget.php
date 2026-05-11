<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Annotation for dashboard widget plugins.
 *
 * Example:
 *
 *   /**
 *    * @DashboardWidget(
 *    *   id = "my_widget",
 *    *   label = @Translation("My widget"),
 *    *   description = @Translation("Shows my thing."),
 *    *   weight = 10,
 *    *   category = "universal"
 *    * )
 *    *\/
 *   class MyWidget extends DashboardWidgetBase { ... }
 *
 * @Annotation
 */
final class DashboardWidget extends Plugin {

  /**
   * Machine name. Unique across all installed widgets.
   */
  public string $id;

  /**
   * Short human-readable name shown as the widget heading.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $label;

  /**
   * One-line description of what the widget shows. Optional.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $description = '';

  /**
   * Sort order on the dashboard. Lower numbers render first.
   */
  public int $weight = 0;

  /**
   * Loose grouping label — e.g. "universal", "student", "instructor".
   * Not enforced; used for organising widget admin and analytics.
   */
  public string $category = 'general';

}
