<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Default base class for dashboard widgets.
 *
 * Widgets that need DI should extend `ContainerFactoryPluginInterface`
 * and implement `create()` themselves. Most widgets won't.
 *
 * `StringTranslationTrait` is mixed in so plugins can use `$this->t()`
 * without re-importing the trait per widget.
 */
abstract class DashboardWidgetBase extends PluginBase implements DashboardWidgetInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   *
   * Default: allow for any authenticated user. Override to restrict by
   * permission, role, or OG membership.
   */
  public function access(AccountInterface $user): AccessResultInterface {
    return AccessResult::allowedIf($user->isAuthenticated())
      ->addCacheContexts(['user.roles:authenticated']);
  }

  /**
   * {@inheritdoc}
   */
  abstract public function build(AccountInterface $user): array;

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function weight(): int {
    return (int) ($this->pluginDefinition['weight'] ?? 0);
  }

  /**
   * {@inheritdoc}
   */
  public function category(): string {
    return (string) ($this->pluginDefinition['category'] ?? 'general');
  }

}
