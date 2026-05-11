<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Navigation;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupMembership;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Replaces Gin's flat "Create" tray with a program-scoped picker.
 *
 * Gin's default Create dropdown lists every node bundle the user has
 * "create X content" on — but in our Group-scoped world those orphan
 * any new node off any program audience. This service emits a tray
 * structured as:
 *
 *   Create ▾
 *     Graphic & Web Design ▸
 *       + Article
 *       + Award
 *       + Event
 *       …
 *
 * Each leaf links straight to gnode's `create-form` route, which both
 * creates the node and attaches it to the chosen group in one save.
 *
 * Only bundles the user has `create group_node:BUNDLE entity` permission
 * on for that specific group appear under it. If the user can't create
 * anything anywhere, the tray returns `#access: FALSE` and the entry
 * is dropped — keeps junior accounts from staring at an empty menu.
 */
final class CreateNavigation implements ContainerInjectionInterface {
  use StringTranslationTrait;

  /**
   * Bundles a contributor might want to create inside a program.
   *
   * Bundle → menu label. Strictly user-creatable content; admin-driven
   * bundles (course, certificate, career_outcome, venue, menu) are
   * imported via drush commands, not created from the toolbar.
   */
  private const CREATEABLE_BUNDLES = [
    'article' => 'Article',
    'award' => 'Award',
    'event' => 'Event',
    'project' => 'Project',
    'portfolio_show' => 'Portfolio Show',
    'student_spotlight' => 'Student spotlight',
    'simplenews_issue' => 'Newsletter Issue',
  ];

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $etm,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Build the Create tray render array, or `#access: FALSE` when empty.
   */
  public function build(): array {
    $items = $this->buildProgramItems();
    if (!$items) {
      return ['#access' => FALSE];
    }

    // Gin renders the Create dropdown out of `#menu_top` (separate
    // region from `#menu_middle` where Content/Programs live), so this
    // tray uses the top-region theme. Same item shape — `title`, `url`,
    // `below` — the template handles 3 levels regardless of region.
    return [
      '#theme' => 'menu_region__middle',
      '#menu_name' => 'create',
      '#title' => $this->t('Create'),
      '#items' => [
        'create' => [
          'title' => $this->t('Create'),
          'url' => Url::fromUserInput('/group'),
          'class' => 'create',
          'attributes' => new Attribute(['class' => ['toolbar-menu__item--create']]),
          'below' => $items,
        ],
      ],
      '#cache' => [
        'contexts' => ['user', 'user.group_permissions'],
        'tags' => ['group_relationship_list:group_membership'],
      ],
    ];
  }

  /**
   * For each program the user belongs to, build a submenu of
   * createable bundle links.
   */
  private function buildProgramItems(): array {
    $memberships = GroupMembership::loadByUser($this->currentUser->getAccount());
    if (!$memberships) {
      return [];
    }

    $seen = [];
    $items = [];
    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if (!$group || isset($seen[$group->id()])) {
        continue;
      }
      if (!in_array($group->bundle(), [...\Drupal\programhub_dashboard\Service\GroupContext::PROGRAM_GROUP_TYPES, 'division'], TRUE)) {
        continue;
      }
      $seen[$group->id()] = TRUE;

      $bundleLinks = $this->buildBundleLinks($group);
      if (!$bundleLinks) {
        continue;
      }
      $items[] = [
        'title' => $group->label(),
        'url' => Url::fromRoute('entity.group_relationship.group_node_create_page', ['group' => $group->id()]),
        'attributes' => new Attribute(['class' => ['programhub-create-program']]),
        'below' => $bundleLinks,
        '#cache' => ['tags' => ['group:' . $group->id()]],
      ];
    }

    usort($items, fn(array $a, array $b) => strnatcasecmp((string) $a['title'], (string) $b['title']));
    return $items;
  }

  /**
   * Per-bundle "+ Article", "+ Award" etc. links for one program, scoped
   * to what the user can actually create there.
   *
   * Drupal admins (`administer group` + `bypass node access`) bypass the
   * per-bundle Group permission check — Group's own permission checker
   * doesn't honor those globals for content-plugin perms, so we layer
   * the bypass explicitly.
   */
  private function buildBundleLinks(\Drupal\group\Entity\GroupInterface $group): array {
    $isAdmin = $this->currentUser->hasPermission('administer group')
      || $this->currentUser->hasPermission('bypass node access');

    // Which gnode plugins are actually enabled on this group bundle. Division
    // (for example) only ships article/award/event; surfacing "+ Project"
    // there would lead to a 404 on click.
    $enabledPlugins = [];
    $rels = $this->etm->getStorage('group_relationship_type')
      ->loadByProperties(['group_type' => $group->bundle()]);
    foreach ($rels as $rel) {
      $enabledPlugins[$rel->getPluginId()] = TRUE;
    }

    $links = [];
    foreach (self::CREATEABLE_BUNDLES as $bundle => $label) {
      $plugin = "group_node:$bundle";
      if (!isset($enabledPlugins[$plugin])) {
        continue;
      }
      if (!$isAdmin && !$group->hasPermission("create $plugin entity", $this->currentUser)) {
        continue;
      }
      $links[] = [
        'title' => $this->t('+ @label', ['@label' => $label]),
        'url' => Url::fromRoute('entity.group_relationship.create_form', [
          'group' => $group->id(),
          'plugin_id' => $plugin,
        ]),
        'attributes' => new Attribute(['class' => ['programhub-create-bundle']]),
        'below' => [],
      ];
    }
    return $links;
  }

}
