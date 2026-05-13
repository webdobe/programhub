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
 * Builds the "Programs" tray for the Gin admin toolbar.
 *
 * Programs the user belongs to are nested under their parent division
 * (Subgroup tree: division → program). The resulting tray is four
 * levels deep — Programs > Division > Program > Bundle deep-link —
 * which mirrors the org chart so a user with seats across multiple
 * divisions can scan by division first.
 */
final class GroupsNavigation implements ContainerInjectionInterface {
  use StringTranslationTrait;

  /**
   * Bundles surfaced in each program's submenu for browsing.
   */
  private const MANAGED_BUNDLES = [
    'article' => 'Articles',
    'award' => 'Awards',
    'event' => 'Events',
    'project' => 'Projects',
    'student_spotlight' => 'Student spotlights',
  ];

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Build the Groups tray render array, or `#access: FALSE` when empty.
   */
  public function build(): array {
    $items = $this->buildGroupItems();
    if (!$items) {
      return ['#access' => FALSE];
    }

    return [
      // Suggestion array: prefer our 4-level template, fall back to
      // Gin's 3-level if the module template isn't registered yet.
      '#theme' => ['menu_region__middle__programs', 'menu_region__middle'],
      '#menu_name' => 'programs',
      '#title' => $this->t('Programs'),
      '#items' => [
        'programs' => [
          'title' => $this->t('Programs'),
          'url' => Url::fromUserInput('/group'),
          'class' => 'programs',
          'attributes' => new Attribute(['class' => ['toolbar-menu__item--programs']]),
          'below' => $items,
        ],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['group_relationship_list:group_membership'],
      ],
    ];
  }

  /**
   * Build top-level entries: one per division (with nested programs),
   * plus any orphan programs (not attached to a division tree).
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

    $subgroupHandler = $this->entityTypeManager->getHandler('group', 'subgroup');

    // Map: divisionGid => [GroupInterface programs ...]. Add divisions
    // surfaced via membership AND any pulled in as a program's parent.
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
      $items[] = $this->divisionItem($division, $divisionPrograms[$gid] ?? []);
    }
    foreach ($orphanPrograms as $program) {
      $items[] = $this->programItem($program);
    }

    usort($items, fn(array $a, array $b) => strnatcasecmp((string) $a['title'], (string) $b['title']));
    return $items;
  }

  /**
   * Render one division as a top-level entry, with its programs nested
   * underneath. The division's own canonical link is included as the
   * first child so it stays reachable now that it's no longer a leaf.
   */
  private function divisionItem(GroupInterface $division, array $programs): array {
    $programItems = array_map(fn(GroupInterface $p) => $this->programItem($p), $programs);
    usort($programItems, fn(array $a, array $b) => strnatcasecmp((string) $a['title'], (string) $b['title']));

    $below = [
      [
        'title' => $this->t('Overview'),
        'url' => Url::fromRoute('entity.group.canonical', ['group' => $division->id()]),
        'attributes' => new Attribute(['class' => ['programhub-division-overview']]),
        'below' => [],
      ],
      ...$programItems,
    ];

    return [
      'title' => $division->label(),
      'url' => Url::fromRoute('entity.group.canonical', ['group' => $division->id()]),
      'attributes' => new Attribute(['class' => ['programhub-division']]),
      'below' => $below,
      '#cache' => ['tags' => ['group:' . $division->id()]],
    ];
  }

  /**
   * Render one program with the bundle deep-links as its submenu.
   */
  private function programItem(GroupInterface $program): array {
    return [
      'title' => $program->label(),
      'url' => Url::fromRoute('entity.group.canonical', ['group' => $program->id()]),
      'attributes' => new Attribute(['class' => ['programhub-group']]),
      'below' => $this->buildBundleLinks((int) $program->id()),
      '#cache' => ['tags' => ['group:' . $program->id()]],
    ];
  }

  /**
   * Per-bundle deep-links into gnode's built-in /group/{gid}/nodes view,
   * with its exposed `type` filter constrained to one bundle.
   */
  private function buildBundleLinks(int $gid): array {
    $links = [];
    foreach (self::MANAGED_BUNDLES as $bundle => $label) {
      $links[] = [
        'title' => $this->t($label),
        'url' => Url::fromRoute('view.group_nodes.page_1', ['group' => $gid], [
          'query' => ['type' => $bundle],
        ]),
        'attributes' => new Attribute(['class' => ['programhub-group-bundle']]),
        'below' => [],
      ];
    }
    return $links;
  }

}
