<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Navigation;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupMembership;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the "Programs" tray for the Gin admin toolbar.
 *
 * Each program or division the current user belongs to becomes a
 * dropdown entry; under it are deep-links into the admin content view
 * filtered to that group, one per content bundle a program/division
 * manager actually touches day-to-day (articles, awards, events,
 * projects, student spotlights).
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
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
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
      '#theme' => 'menu_region__middle',
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
   * Build one submenu entry per group the user belongs to.
   */
  private function buildGroupItems(): array {
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
      $items[] = [
        'title' => $group->label(),
        'url' => Url::fromRoute('entity.group.canonical', ['group' => $group->id()]),
        'attributes' => new Attribute(['class' => ['programhub-group']]),
        'below' => $this->buildBundleLinks((int) $group->id()),
        '#cache' => ['tags' => ['group:' . $group->id()]],
      ];
    }

    usort($items, fn(array $a, array $b) => strnatcasecmp((string) $a['title'], (string) $b['title']));
    return $items;
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
