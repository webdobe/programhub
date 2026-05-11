<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Plugin\DashboardWidget;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\Group;
use Drupal\programhub_dashboard\DashboardWidgetBase;
use Drupal\programhub_dashboard\Service\GroupContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @DashboardWidget(
 *   id = "quick_actions",
 *   label = @Translation("Create"),
 *   description = @Translation("Quick links to create content you have permission for."),
 *   weight = -95,
 *   category = "universal"
 * )
 *
 * Shows a row of "Create X" links for every community content type the
 * user has create access on in at least one program. Checks Group
 * permissions across the user's program memberships to know what to
 * offer.
 *
 * If the user can create the same bundle in multiple programs, the link
 * goes to the generic add form — Drupal/Group will prompt for the group
 * at submit time.
 */
final class QuickActionsWidget extends DashboardWidgetBase implements ContainerFactoryPluginInterface {

  /**
   * Community types most likely to be created from a dashboard quick-
   * action. Excludes pure-metadata types and types only managers create.
   */
  private const QUICK_CREATE_TYPES = [
    'project',
    'award',
    'event',
    'student_spotlight',
    'article',
    'portfolio_show',
    'menu',
  ];

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly GroupContext $groupContext,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('programhub_dashboard.group_context'),
      $container->get('entity_type.manager'),
    );
  }

  public function access(AccountInterface $user): AccessResultInterface {
    return AccessResult::allowedIf(!empty($this->createableBundles($user)))
      ->addCacheContexts(['user'])
      ->addCacheTags(['group_relationship_list:group_membership']);
  }

  public function build(AccountInterface $user): array {
    $bundles = $this->createableBundles($user);
    if (empty($bundles)) {
      return [];
    }

    $nodeTypeStorage = $this->entityTypeManager->getStorage('node_type');
    $items = [];
    foreach ($bundles as $bundle) {
      $type = $nodeTypeStorage->load($bundle);
      $label = $type ? $type->label() : $bundle;
      $items[] = [
        '#type' => 'link',
        '#title' => $this->t('+ @label', ['@label' => $label]),
        '#url' => Url::fromRoute('node.add', ['node_type' => $bundle]),
        '#attributes' => [
          'class' => ['button', 'button--small'],
          'data-bundle' => $bundle,
        ],
      ];
    }

    return [
      '#type' => 'inline_template',
      '#template' => '<div class="programhub-widget__buttons">{% for item in items %}{{ item }}{% endfor %}</div>',
      '#context' => ['items' => $items],
      '#cache' => ['contexts' => ['user']],
    ];
  }

  /**
   * Bundles the user has create access on in any program they belong to.
   *
   * @return string[]
   */
  private function createableBundles(AccountInterface $user): array {
    $gids = $this->groupContext->userProgramGroupIds($user);
    if (!$gids) {
      return [];
    }
    $createable = [];
    foreach (Group::loadMultiple($gids) as $group) {
      foreach (self::QUICK_CREATE_TYPES as $bundle) {
        if (isset($createable[$bundle])) {
          continue;
        }
        if ($group->hasPermission("create group_node:$bundle entity", $user)) {
          $createable[$bundle] = $bundle;
        }
      }
    }
    return array_values($createable);
  }

}
