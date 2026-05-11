<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Plugin\DashboardWidget;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\og\MembershipManagerInterface;
use Drupal\og\OgAccessInterface;
use Drupal\og\OgMembershipInterface;
use Drupal\programhub_dashboard\DashboardWidgetBase;
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
 * user has create access on in at least one program. Inspects OG perms
 * across the user's program memberships to know what to offer.
 *
 * If the user can create the same bundle in multiple programs, the link
 * goes to the generic add form — Drupal/OG will prompt for the group at
 * submit time.
 */
final class QuickActionsWidget extends DashboardWidgetBase implements ContainerFactoryPluginInterface {

  /**
   * Community types the user is most likely to want to create from a
   * dashboard quick-action. Excludes pure-metadata types (`high_score`,
   * `outcome`) and types only managers would create (`simplenews_issue`).
   * If a user has create perms for those, they can use the toolbar.
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
    private readonly MembershipManagerInterface $membershipManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly OgAccessInterface $ogAccess,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('og.membership_manager'),
      $container->get('entity_type.manager'),
      $container->get('og.access'),
    );
  }

  public function access(AccountInterface $user): AccessResultInterface {
    // Render only if there's at least one bundle the user can create
    // in at least one program — otherwise the widget is empty noise.
    return AccessResult::allowedIf(!empty($this->createableBundles($user)))
      ->addCacheContexts(['user'])
      ->addCacheTags(["og_user_membership:{$user->id()}"]);
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
   * Return the list of bundles the user has create access on in any
   * program they belong to. Deduplicated — a user who can create
   * `project` in two programs gets one button.
   *
   * @return string[]
   */
  private function createableBundles(AccountInterface $user): array {
    $memberships = $this->membershipManager->getMemberships(
      (int) $user->id(),
      [OgMembershipInterface::STATE_ACTIVE],
    );

    $createable = [];
    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if (!$group || $group->bundle() !== 'program') {
        continue;
      }
      foreach (self::QUICK_CREATE_TYPES as $bundle) {
        if (isset($createable[$bundle])) {
          continue;
        }
        // OG's group-content-access plugin uses permission names of the
        // form "create <bundle> content". Check via the og.access
        // service against this specific group.
        $access = $this->ogAccess->userAccess(
          $group,
          "create $bundle content",
          $user,
        );
        if ($access->isAllowed()) {
          $createable[$bundle] = $bundle;
        }
      }
    }

    return array_values($createable);
  }

}
