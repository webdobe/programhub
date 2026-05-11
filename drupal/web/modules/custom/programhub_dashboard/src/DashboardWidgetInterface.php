<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Contract every dashboard widget implements.
 *
 * Two responsibilities:
 *   1. `access($user)` — decide whether THIS user gets to see the widget.
 *      Combine global Drupal permissions, OG memberships, and any
 *      content-level checks here. The dashboard controller calls this
 *      before rendering. If it returns AccessResult::forbidden(), the
 *      widget is silently skipped.
 *   2. `build($user)` — return the render array for the widget body when
 *      access is allowed. The dashboard wraps it in the widget chrome
 *      (heading, container) — the plugin should NOT render its own
 *      heading.
 */
interface DashboardWidgetInterface extends PluginInspectionInterface {

  /**
   * Whether this user can see the widget on their dashboard.
   *
   * Cacheability metadata on the returned AccessResult is preserved by
   * the dashboard render pipeline (so e.g. a widget that depends on
   * `user.permissions` and `user.og_membership` should add both cache
   * contexts here).
   */
  public function access(AccountInterface $user): AccessResultInterface;

  /**
   * Render array for the widget body.
   *
   * @return array
   *   A render array. Empty array if the widget has nothing to show.
   */
  public function build(AccountInterface $user): array;

  /**
   * Human-readable label, as declared in the annotation.
   */
  public function label(): string;

  /**
   * Widget weight; lower = renders earlier.
   */
  public function weight(): int;

  /**
   * Category label from the annotation.
   */
  public function category(): string;

}
