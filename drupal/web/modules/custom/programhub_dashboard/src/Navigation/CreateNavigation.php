<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Navigation;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\programhub_dashboard\Service\GroupContext;
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
 *     CTE ▸                          (division)
 *       Graphic & Web Design ▸       (program)
 *         + Article
 *         + Award
 *         + Event
 *         …
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
    $items = $this->buildGroupItems();
    if (!$items) {
      return ['#access' => FALSE];
    }

    return [
      // Suggestion array: prefer our 4-level template, fall back to
      // Gin's 3-level if the module template isn't registered yet.
      '#theme' => ['menu_region__middle__create', 'menu_region__middle'],
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
   * Top-level entries: one per division (with nested programs), plus
   * orphan programs (not attached to a division tree).
   */
  private function buildGroupItems(): array {
    $memberships = GroupMembership::loadByUser($this->currentUser->getAccount());
    if (!$memberships) {
      return [];
    }

    /** @var \Drupal\group\Entity\GroupInterface[] $divisions */
    $divisions = [];
    /** @var \Drupal\group\Entity\GroupInterface[] $programs */
    $programs = [];
    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if (!$group) {
        continue;
      }
      $bundle = $group->bundle();
      if ($bundle === 'division') {
        $divisions[(int) $group->id()] = $group;
      }
      elseif (in_array($bundle, GroupContext::PROGRAM_GROUP_TYPES, TRUE)) {
        $programs[(int) $group->id()] = $group;
      }
    }

    $subgroupHandler = $this->etm->getHandler('group', 'subgroup');

    $divisionPrograms = [];
    $orphanPrograms = [];
    foreach ($divisions as $gid => $division) {
      $divisionPrograms[$gid] = [];
    }
    foreach ($programs as $program) {
      if (!$subgroupHandler->isLeaf($program) || $subgroupHandler->isRoot($program)) {
        $orphanPrograms[] = $program;
        continue;
      }
      $parent = $subgroupHandler->getParent($program);
      if (!$parent instanceof GroupInterface) {
        $orphanPrograms[] = $program;
        continue;
      }
      $pid = (int) $parent->id();
      if (!isset($divisions[$pid])) {
        $divisions[$pid] = $parent;
        $divisionPrograms[$pid] = [];
      }
      $divisionPrograms[$pid][] = $program;
    }

    $items = [];
    foreach ($divisions as $gid => $division) {
      $entry = $this->divisionItem($division, $divisionPrograms[$gid] ?? []);
      if ($entry !== NULL) {
        $items[] = $entry;
      }
    }
    foreach ($orphanPrograms as $program) {
      $entry = $this->programItem($program);
      if ($entry !== NULL) {
        $items[] = $entry;
      }
    }

    usort($items, fn(array $a, array $b) => strnatcasecmp((string) $a['title'], (string) $b['title']));
    return $items;
  }

  /**
   * Render a division as a top-level entry. The division's own
   * createable bundles are inlined into the submenu (no wrapper),
   * followed by one entry per program. Returns NULL if nothing under
   * the division is reachable.
   */
  private function divisionItem(GroupInterface $division, array $programs): ?array {
    $bundleLinks = $this->buildBundleLinks($division);

    $programItems = [];
    foreach ($programs as $program) {
      $programItem = $this->programItem($program);
      if ($programItem !== NULL) {
        $programItems[] = $programItem;
      }
    }
    usort($programItems, fn(array $a, array $b) => strnatcasecmp((string) $a['title'], (string) $b['title']));

    // Bundle "+ X" links first, then programs. Both groups stay in
    // their own deterministic orders (CREATEABLE_BUNDLES order, then
    // alphabetical programs).
    $below = [...$bundleLinks, ...$programItems];
    if (!$below) {
      return NULL;
    }

    return [
      'title' => $division->label(),
      'url' => Url::fromRoute('entity.group_relationship.group_node_create_page', ['group' => $division->id()]),
      'attributes' => new Attribute(['class' => ['programhub-create-division']]),
      'below' => $below,
      '#cache' => ['tags' => ['group:' . $division->id()]],
    ];
  }

  /**
   * Render one program with its createable bundle links underneath.
   * Returns NULL when the user can't create anything in this program.
   */
  private function programItem(GroupInterface $program): ?array {
    $bundleLinks = $this->buildBundleLinks($program);
    if (!$bundleLinks) {
      return NULL;
    }
    return [
      'title' => $program->label(),
      'url' => Url::fromRoute('entity.group_relationship.group_node_create_page', ['group' => $program->id()]),
      'attributes' => new Attribute(['class' => ['programhub-create-program']]),
      'below' => $bundleLinks,
      '#cache' => ['tags' => ['group:' . $program->id()]],
    ];
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
  private function buildBundleLinks(GroupInterface $group): array {
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
