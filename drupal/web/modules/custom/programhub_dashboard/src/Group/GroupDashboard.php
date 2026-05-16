<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Group;

use Drupal\Component\Datetime\TimeInterface;
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
    private readonly TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('programhub_dashboard.group_context'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
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

    $sections['header'] = $this->headerStrip($group);

    if ($surfaced) {
      $sections['stats'] = $this->statsCard($group, $surfaced);
      $sections['latest'] = $this->latestCard($group, $surfaced);
    }

    if (isset($enabledBundles['career_outcome'])) {
      $sections['careers'] = $this->careersCard($group);
    }

    $sections['forms'] = $this->formsCard($group);

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

  /**
   * Compact strip above the cards: abbreviation chip + website link.
   * Reskins the two pieces of info we just hid from the default field
   * display so the page header still tells you what program you're
   * looking at, without the labeled-row noise.
   */
  private function headerStrip(GroupInterface $group): array {
    $abbr = NULL;
    if ($group->hasField('field_abbreviation') && !$group->get('field_abbreviation')->isEmpty()) {
      $abbr = (string) $group->get('field_abbreviation')->value;
    }
    $websiteUrl = NULL;
    if ($group->hasField('field_website') && !$group->get('field_website')->isEmpty()) {
      $websiteUrl = (string) $group->get('field_website')->first()->getUrl()->toString();
    }

    if ($abbr === NULL && $websiteUrl === NULL) {
      return [];
    }

    return [
      '#type' => 'inline_template',
      '#template' => '
        <div class="programhub-group-dashboard__header">
          {% if abbr %}<span class="programhub-group-dashboard__chip">{{ abbr }}</span>{% endif %}
          {% if website %}<a class="programhub-group-dashboard__website" href="{{ website }}" target="_blank" rel="noopener">{{ "Visit website ↗"|t }}</a>{% endif %}
        </div>
      ',
      '#context' => ['abbr' => $abbr, 'website' => $websiteUrl],
    ];
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

    // Per-group forms + newsletter tiles, appended to the same strip
    // so admins see content + funnel metrics side-by-side. Each tile
    // is only rendered when the underlying entity actually exists,
    // so a partially-provisioned group doesn't show empty "0" tiles
    // that imply something is missing.
    $abbr = $this->groupAbbreviation($group);
    if ($abbr !== NULL) {
      foreach (['request_info' => 'Info requests', 'subscribe' => 'Subscribe submissions'] as $suffix => $label) {
        $webformId = $abbr . '_' . $suffix;
        if (!$this->etm->getStorage('webform')->load($webformId)) {
          continue;
        }
        $items[] = [
          'label' => $this->t($label),
          'count' => $this->countWebformSubmissions($webformId),
          'url' => Url::fromRoute('entity.webform.results_submissions', ['webform' => $webformId])->toString(),
        ];
      }

      $newsletterId = $abbr . '_newsletter';
      if ($this->etm->getStorage('simplenews_newsletter')->load($newsletterId)) {
        $items[] = [
          'label' => $this->t('Subscribers'),
          'count' => $this->countActiveSubscribers($newsletterId),
          'url' => Url::fromRoute('view.simplenews_subscribers.page_1', [], [
            'query' => ['subscriptions_target_id' => $newsletterId],
          ])->toString(),
        ];
      }
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

  /**
   * Submission counts for the per-group `{abbr}_request_info` /
   * `{abbr}_subscribe` webforms, plus active subscribers on the
   * matching `{abbr}_newsletter`. Skips silently if the group has
   * no abbreviation or none of the expected entities exist —
   * brand-new groups won't show this section until provisioning
   * runs.
   */
  private function formsCard(GroupInterface $group): array {
    $abbr = $this->groupAbbreviation($group);
    if ($abbr === NULL) {
      return [];
    }
    $rows = [];

    foreach (['request_info', 'subscribe'] as $suffix) {
      $webformId = $abbr . '_' . $suffix;
      $webform = $this->etm->getStorage('webform')->load($webformId);
      if (!$webform) {
        continue;
      }
      $rows[] = [
        'kind' => 'webform',
        'label' => $webform->label(),
        'url' => Url::fromRoute('entity.webform.results_submissions', ['webform' => $webformId])->toString(),
        'total' => $this->countWebformSubmissions($webformId),
        'recent' => $this->countWebformSubmissions($webformId, 30),
      ];
    }

    $newsletterId = $abbr . '_newsletter';
    $newsletter = $this->etm->getStorage('simplenews_newsletter')->load($newsletterId);
    if ($newsletter) {
      $rows[] = [
        'kind' => 'newsletter',
        'label' => $this->t('@name (subscribers)', ['@name' => $newsletter->label()]),
        // Newsletter admin doesn't have a per-list subscribers route;
        // link to the subscriber listing filtered by this newsletter.
        'url' => Url::fromRoute('view.simplenews_subscribers.page_1', [], [
          'query' => ['subscriptions_target_id' => $newsletterId],
        ])->toString(),
        'total' => $this->countActiveSubscribers($newsletterId),
        'recent' => $this->countActiveSubscribers($newsletterId, 30),
      ];
    }

    if (!$rows) {
      return [];
    }

    return $this->card($this->t('Forms & subscribers'), [
      '#type' => 'inline_template',
      '#template' => '
        <table class="programhub-group-dashboard__forms">
          <thead>
            <tr>
              <th>{{ "Form / list"|t }}</th>
              <th class="programhub-group-dashboard__numeric">{{ "Total"|t }}</th>
              <th class="programhub-group-dashboard__numeric">{{ "Last 30 days"|t }}</th>
            </tr>
          </thead>
          <tbody>
            {% for row in rows %}
              <tr>
                <td><a href="{{ row.url }}">{{ row.label }}</a></td>
                <td class="programhub-group-dashboard__numeric">{{ row.total }}</td>
                <td class="programhub-group-dashboard__numeric">{{ row.recent }}</td>
              </tr>
            {% endfor %}
          </tbody>
        </table>
      ',
      '#context' => ['rows' => $rows],
      '#cache' => [
        'tags' => [
          'config:webform.webform.' . $abbr . '_request_info',
          'config:webform.webform.' . $abbr . '_subscribe',
          'config:simplenews.newsletter.' . $abbr . '_newsletter',
          'webform_submission_list',
          'simplenews_subscriber_list',
        ],
      ],
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

  /**
   * Submission count for a webform, optionally bounded to the last N
   * days. Counts only completed (non-draft) submissions.
   */
  private function countWebformSubmissions(string $webformId, ?int $sinceDays = NULL): int {
    $q = $this->db->select('webform_submission', 'ws')
      ->condition('ws.webform_id', $webformId)
      ->condition('ws.in_draft', 0);
    if ($sinceDays !== NULL) {
      $q->condition('ws.created', $this->time->getRequestTime() - ($sinceDays * 86400), '>=');
    }
    return (int) $q->countQuery()->execute()->fetchField();
  }

  /**
   * Active (status = 1) subscriber count for a simplenews newsletter,
   * optionally bounded to subscribers created in the last N days.
   * Joins the subscriptions field-data table to the subscriber base
   * table so we filter by status without loading every row.
   */
  private function countActiveSubscribers(string $newsletterId, ?int $sinceDays = NULL): int {
    $q = $this->db->select('simplenews_subscriber__subscriptions', 'subs');
    $q->join('simplenews_subscriber', 's', 's.id = subs.entity_id');
    $q->condition('subs.subscriptions_target_id', $newsletterId)
      ->condition('s.status', 1);
    if ($sinceDays !== NULL) {
      $q->condition('s.created', $this->time->getRequestTime() - ($sinceDays * 86400), '>=');
    }
    return (int) $q->countQuery()->execute()->fetchField();
  }

  /**
   * Lowercase, slugified `field_abbreviation` for the group, or NULL
   * when the field is empty. Matches the slug rules used by the
   * provisioners (programhub_webforms / programhub_newsletters) so
   * the ids line up.
   */
  private function groupAbbreviation(GroupInterface $group): ?string {
    if (!$group->hasField('field_abbreviation') || $group->get('field_abbreviation')->isEmpty()) {
      return NULL;
    }
    $raw = (string) $group->get('field_abbreviation')->value;
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $raw) ?? '');
    $slug = trim($slug, '_');
    return $slug === '' ? NULL : $slug;
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
