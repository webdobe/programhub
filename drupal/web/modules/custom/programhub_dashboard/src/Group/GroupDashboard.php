<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Group;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\programhub_dashboard\Service\GroupContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the content-overview sections for a group's canonical page.
 *
 * Sections (top to bottom):
 *
 *   1. Stats strip — counts per surfaced bundle, each linking to the
 *      filtered group_nodes view.
 *   2. Latest content — the 3 most recent published nodes per bundle,
 *      grouped into per-bundle cards.
 *   3. Career outcomes — every `career_outcome` node attached to the
 *      group, with SOC code and median pay.
 *   4. Subgroups — only on `division`, lists the program children with
 *      their own quick stats.
 *
 * Hung off `hook_group_view_alter()` rather than a custom view mode so
 * the existing `default` field display (label, abbreviation, website,
 * SOCs) keeps rendering verbatim above us. Easier to maintain than
 * mirroring every group bundle's display config.
 */
final class GroupDashboard implements ContainerInjectionInterface {
  use StringTranslationTrait;

  /**
   * Bundles surfaced in the stats strip and "latest content" cards.
   *
   * Excludes admin-driven bundles (course, certificate, career_outcome,
   * venue, menu) — those have their own sections or are import-only.
   */
  private const SURFACED_BUNDLES = [
    'article' => 'Articles',
    'award' => 'Awards',
    'event' => 'Events',
    'project' => 'Projects',
    'portfolio_show' => 'Portfolio Shows',
    'student_spotlight' => 'Student spotlights',
  ];

  private const LATEST_PER_BUNDLE = 3;

  public function __construct(
    private readonly Connection $db,
    private readonly EntityTypeManagerInterface $etm,
    private readonly GroupContext $groupContext,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('programhub_dashboard.group_context'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Build the dashboard sections for a group, or [] if the group bundle
   * isn't one we surface.
   */
  public function build(GroupInterface $group): array {
    $bundle = $group->bundle();
    if ($bundle !== 'division' && !in_array($bundle, GroupContext::PROGRAM_GROUP_TYPES, TRUE)) {
      return [];
    }

    $enabledBundles = $this->enabledNodeBundles($bundle);
    $surfaced = array_intersect_key(self::SURFACED_BUNDLES, $enabledBundles);

    $sections = [];

    if ($surfaced) {
      $sections['stats'] = $this->statsCard($group, $surfaced);
      $sections['latest'] = $this->latestCard($group, $surfaced);
    }

    if (isset($enabledBundles['career_outcome'])) {
      $sections['careers'] = $this->careersCard($group);
    }

    if ($bundle === 'division') {
      $sections['subgroups'] = $this->subgroupsCard($group);
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['programhub-group-dashboard']],
      '#attached' => ['library' => ['programhub_dashboard/group_dashboard']],
      '#cache' => [
        'tags' => array_merge(
          ['group:' . $group->id(), 'group_relationship_list:group_membership'],
          array_map(static fn(string $b): string => "node_list:$b", array_keys($enabledBundles)),
        ),
      ],
      'sections' => array_filter($sections),
    ];
  }

  /**
   * Bundles enabled on this group bundle via gnode plugins.
   *
   * @return array<string, true>
   *   Keyed by bundle name for fast `isset()` checks.
   */
  private function enabledNodeBundles(string $groupBundle): array {
    $rels = $this->etm->getStorage('group_relationship_type')
      ->loadByProperties(['group_type' => $groupBundle]);
    $out = [];
    foreach ($rels as $rel) {
      $plugin = $rel->getPluginId();
      if (str_starts_with($plugin, 'group_node:')) {
        $out[substr($plugin, strlen('group_node:'))] = TRUE;
      }
    }
    return $out;
  }

  private function statsCard(GroupInterface $group, array $surfaced): array {
    $gid = (int) $group->id();
    $items = [];
    foreach ($surfaced as $bundle => $label) {
      $items[] = [
        'label' => $this->t($label),
        'count' => $this->countByBundle($gid, $bundle),
        'url' => Url::fromRoute('view.group_nodes.page_1', ['group' => $gid], [
          'query' => ['type' => $bundle],
        ])->toString(),
      ];
    }

    return $this->card($this->t('At a glance'), [
      '#type' => 'inline_template',
      '#template' => '
        <ul class="programhub-group-dashboard__stats">
          {% for item in items %}
            <li class="programhub-group-dashboard__stat">
              <a href="{{ item.url }}">
                <span class="programhub-group-dashboard__stat-count">{{ item.count }}</span>
                <span class="programhub-group-dashboard__stat-label">{{ item.label }}</span>
              </a>
            </li>
          {% endfor %}
        </ul>
      ',
      '#context' => ['items' => $items],
    ]);
  }

  private function latestCard(GroupInterface $group, array $surfaced): array {
    $gid = (int) $group->id();
    $columns = [];
    foreach ($surfaced as $bundle => $label) {
      $nodes = $this->latestNodes($gid, $bundle, self::LATEST_PER_BUNDLE);
      if (!$nodes) {
        continue;
      }
      $columns[] = [
        'label' => $this->t($label),
        'all_url' => Url::fromRoute('view.group_nodes.page_1', ['group' => $gid], [
          'query' => ['type' => $bundle],
        ])->toString(),
        'nodes' => array_map(fn(NodeInterface $n) => [
          'title' => $n->label(),
          'url' => $n->toUrl()->toString(),
          'changed' => $this->dateFormatter->format($n->getChangedTime(), 'short'),
        ], $nodes),
      ];
    }

    if (!$columns) {
      return [];
    }

    return $this->card($this->t('Latest content'), [
      '#type' => 'inline_template',
      '#template' => '
        <div class="programhub-group-dashboard__latest">
          {% for col in columns %}
            <section class="programhub-group-dashboard__latest-col">
              <header class="programhub-group-dashboard__latest-head">
                <h3>{{ col.label }}</h3>
                <a class="programhub-group-dashboard__latest-all" href="{{ col.all_url }}">{{ "View all"|t }}</a>
              </header>
              <ul class="programhub-group-dashboard__latest-list">
                {% for node in col.nodes %}
                  <li>
                    <a href="{{ node.url }}">{{ node.title }}</a>
                    <span class="programhub-group-dashboard__latest-meta">{{ node.changed }}</span>
                  </li>
                {% endfor %}
              </ul>
            </section>
          {% endfor %}
        </div>
      ',
      '#context' => ['columns' => $columns],
    ]);
  }

  private function careersCard(GroupInterface $group): array {
    $gid = (int) $group->id();
    $nids = $this->groupContext->nidsInGroups([$gid], ['career_outcome']);
    if (!$nids) {
      return [];
    }

    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $this->etm->getStorage('node')->loadMultiple($nids);
    $rows = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface || !$node->isPublished()) {
        continue;
      }
      $rows[] = [
        'soc' => $node->hasField('field_soc_code') ? (string) $node->get('field_soc_code')->value : '',
        'title' => $node->label(),
        'url' => $node->toUrl()->toString(),
        'pay_median' => $this->formatPay($node, 'field_pay_median'),
        'pay_range' => $this->payRange($node),
      ];
    }
    if (!$rows) {
      return [];
    }
    usort($rows, fn(array $a, array $b) => strnatcasecmp((string) $a['title'], (string) $b['title']));

    return $this->card($this->t('Career outcomes'), [
      '#type' => 'inline_template',
      '#template' => '
        <table class="programhub-group-dashboard__careers">
          <thead>
            <tr>
              <th>{{ "SOC"|t }}</th>
              <th>{{ "Occupation"|t }}</th>
              <th class="programhub-group-dashboard__numeric">{{ "Median pay"|t }}</th>
              <th class="programhub-group-dashboard__numeric">{{ "Range"|t }}</th>
            </tr>
          </thead>
          <tbody>
            {% for row in rows %}
              <tr>
                <td><code>{{ row.soc }}</code></td>
                <td><a href="{{ row.url }}">{{ row.title }}</a></td>
                <td class="programhub-group-dashboard__numeric">{{ row.pay_median }}</td>
                <td class="programhub-group-dashboard__numeric">{{ row.pay_range }}</td>
              </tr>
            {% endfor %}
          </tbody>
        </table>
      ',
      '#context' => ['rows' => $rows],
    ]);
  }

  private function subgroupsCard(GroupInterface $division): array {
    $subgroupHandler = $this->etm->getHandler('group', 'subgroup');
    if (!$subgroupHandler->isLeaf($division)) {
      return [];
    }
    $children = $subgroupHandler->getChildren($division);
    if (!$children) {
      return [];
    }

    $rows = [];
    foreach ($children as $child) {
      if (!$child instanceof GroupInterface) {
        continue;
      }
      $gid = (int) $child->id();
      $rows[] = [
        'label' => $child->label(),
        'url' => $child->toUrl()->toString(),
        'articles' => $this->countByBundle($gid, 'article'),
        'events' => $this->countByBundle($gid, 'event'),
        'projects' => $this->countByBundle($gid, 'project'),
      ];
    }
    if (!$rows) {
      return [];
    }
    usort($rows, fn(array $a, array $b) => strnatcasecmp((string) $a['label'], (string) $b['label']));

    return $this->card($this->t('Programs in this division'), [
      '#type' => 'inline_template',
      '#template' => '
        <table class="programhub-group-dashboard__subgroups">
          <thead>
            <tr>
              <th>{{ "Program"|t }}</th>
              <th class="programhub-group-dashboard__numeric">{{ "Articles"|t }}</th>
              <th class="programhub-group-dashboard__numeric">{{ "Events"|t }}</th>
              <th class="programhub-group-dashboard__numeric">{{ "Projects"|t }}</th>
            </tr>
          </thead>
          <tbody>
            {% for row in rows %}
              <tr>
                <td><a href="{{ row.url }}">{{ row.label }}</a></td>
                <td class="programhub-group-dashboard__numeric">{{ row.articles }}</td>
                <td class="programhub-group-dashboard__numeric">{{ row.events }}</td>
                <td class="programhub-group-dashboard__numeric">{{ row.projects }}</td>
              </tr>
            {% endfor %}
          </tbody>
        </table>
      ',
      '#context' => ['rows' => $rows],
    ]);
  }

  /**
   * Wrap a section body in the same `claro-details` chrome the
   * /dashboard widgets use, so visual treatment stays consistent.
   */
  private function card($title, array $body): array {
    return [
      '#type' => 'details',
      '#title' => $title,
      '#open' => TRUE,
      '#attributes' => ['class' => ['claro-details', 'programhub-widget']],
      'body' => $body,
    ];
  }

  /**
   * Most recent published nodes of one bundle in a group.
   *
   * Joins group_relationship_field_data + node_field_data so we can
   * order by `changed` and slice in SQL — avoids loading every nid.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  private function latestNodes(int $gid, string $bundle, int $limit): array {
    $nids = $this->db->select('group_relationship_field_data', 'g')
      ->fields('n', ['nid'])
      ->condition('g.gid', $gid)
      ->condition('g.plugin_id', "group_node:$bundle")
      ->condition('n.status', 1)
      ->orderBy('n.changed', 'DESC')
      ->range(0, $limit);
    $nids->join('node_field_data', 'n', 'n.nid = g.entity_id');
    $ids = array_map('intval', $nids->execute()->fetchCol());
    if (!$ids) {
      return [];
    }
    $nodes = $this->etm->getStorage('node')->loadMultiple($ids);
    // Preserve the SQL order (loadMultiple sorts by id).
    $ordered = [];
    foreach ($ids as $id) {
      if (isset($nodes[$id])) {
        $ordered[$id] = $nodes[$id];
      }
    }
    return $ordered;
  }

  private function countByBundle(int $gid, string $bundle): int {
    return (int) $this->db->select('group_relationship_field_data', 'g')
      ->condition('g.gid', $gid)
      ->condition('g.plugin_id', "group_node:$bundle")
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  private function formatPay(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '—';
    }
    $value = (int) $node->get($field)->value;
    return '$' . number_format($value);
  }

  private function payRange(NodeInterface $node): string {
    $low = $node->hasField('field_pay_low') && !$node->get('field_pay_low')->isEmpty()
      ? (int) $node->get('field_pay_low')->value : null;
    $high = $node->hasField('field_pay_high') && !$node->get('field_pay_high')->isEmpty()
      ? (int) $node->get('field_pay_high')->value : null;
    if ($low === null && $high === null) {
      if ($node->hasField('field_pay_range') && !$node->get('field_pay_range')->isEmpty()) {
        return (string) $node->get('field_pay_range')->value;
      }
      return '—';
    }
    return '$' . number_format($low ?? 0) . '–$' . number_format($high ?? 0);
  }

}
