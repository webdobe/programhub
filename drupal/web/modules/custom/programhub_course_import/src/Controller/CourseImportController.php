<?php

declare(strict_types=1);

namespace Drupal\programhub_course_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;
use Drupal\programhub_course_import\Service\CatalogScraper;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Single-program "Import Courses" entry point. Wired up by hook_entity_operation.
 */
final class CourseImportController extends ControllerBase {

  /**
   * Run the import for one program group, then redirect to it.
   */
  public function importOne(GroupInterface $group): RedirectResponse {
    if (!in_array($group->bundle(), \Drupal\programhub_dashboard\Service\GroupContext::PROGRAM_GROUP_TYPES, TRUE)) {
      $this->messenger()->addError($this->t('Only program groups can be imported.'));
      return new RedirectResponse(Url::fromRoute('system.admin_content')->toString());
    }

    /** @var \Drupal\programhub_course_import\Service\CourseImporter $importer */
    $importer = \Drupal::service('programhub_course_import.importer');
    $result = $importer->importForProgram($group);

    if ($result['errors']) {
      foreach ($result['errors'] as $err) {
        $this->messenger()->addWarning((string) $err);
      }
    }

    $this->messenger()->addStatus($this->t(
      'Imported "@p" from @url — created: @c, updated: @u, unchanged: @x, flagged: @f.',
      [
        '@p' => $group->label(),
        '@url' => $result['url'],
        '@c' => $result['created'],
        '@u' => $result['updated'],
        '@x' => $result['unchanged'],
        '@f' => $result['flagged'],
      ],
    ));

    return new RedirectResponse(Url::fromRoute('entity.group.canonical', ['group' => $group->id()])->toString());
  }

  /**
   * Render the Courses tab on a program group.
   */
  public function coursesTab(GroupInterface $group): array {
    $abbr = $group->hasField('field_abbreviation') && !$group->get('field_abbreviation')->isEmpty()
      ? trim((string) $group->get('field_abbreviation')->value)
      : '';
    $catalogUrl = $abbr !== '' ? CatalogScraper::urlForPrefix($abbr) : NULL;

    $build = [];

    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['programhub-courses-header']],
    ];

    if ($catalogUrl) {
      $build['header']['source'] = [
        '#type' => 'item',
        '#title' => $this->t('Catalog source'),
        '#markup' => '<a href="' . $catalogUrl . '" target="_blank" rel="noopener">' . $catalogUrl . '</a>',
      ];
    }
    else {
      $build['header']['source'] = [
        '#markup' => '<p>' . $this->t('No abbreviation set on this program — add one to enable catalog imports.') . '</p>',
      ];
    }

    if ($catalogUrl && $this->currentUser()->hasPermission('import programhub courses')) {
      $build['header']['actions'] = [
        '#type' => 'actions',
        'import' => [
          '#type' => 'link',
          '#title' => $this->t('Import from catalog'),
          '#url' => Url::fromRoute('programhub_course_import.import_program', ['group' => $group->id()]),
          '#attributes' => [
            'class' => ['button', 'button--primary'],
          ],
        ],
      ];
    }

    $courses = $this->loadCourses($group);

    $rows = [];
    foreach ($courses as $course) {
      $title = $course->access('view') ? $course->toLink()->toString() : $course->label();
      $number = $course->hasField('field_course_number')
        ? $course->get('field_course_number')->value
        : '';
      $credits = $course->hasField('field_course_credits') && !$course->get('field_course_credits')->isEmpty()
        ? $course->get('field_course_credits')->value
        : '—';
      $offering = '—';
      if ($course->hasField('field_course_offering') && !$course->get('field_course_offering')->isEmpty()) {
        $names = [];
        foreach ($course->get('field_course_offering')->referencedEntities() as $term) {
          $names[] = $term->label();
        }
        if ($names) {
          $offering = implode(', ', $names);
        }
      }
      $yearCycle = '—';
      if ($course->hasField('field_course_year_cycle') && !$course->get('field_course_year_cycle')->isEmpty()) {
        $yearCycle = $course->get('field_course_year_cycle')->view(['label' => 'hidden'])[0]['#markup'] ?? $course->get('field_course_year_cycle')->value;
      }
      $prereqs = $this->refNumbers($course, 'field_course_prereqs');
      $rec = $this->refNumbers($course, 'field_course_rec_prereqs');
      $status = $course->isPublished() ? $this->t('Published') : $this->t('Unpublished');

      $ops = [];
      if ($course->access('update')) {
        $ops['edit'] = [
          'title' => $this->t('Edit'),
          'url' => $course->toUrl('edit-form'),
        ];
      }

      $rows[] = [
        'number' => $number,
        'title' => ['data' => $title],
        'credits' => $credits,
        'offering' => $offering,
        'year_cycle' => $yearCycle,
        'prereqs' => $prereqs !== '' ? $prereqs : '—',
        'rec_prereqs' => $rec !== '' ? $rec : '—',
        'status' => $status,
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => $ops,
          ],
        ],
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Number'),
        $this->t('Title'),
        $this->t('Credits'),
        $this->t('Offering'),
        $this->t('Cycle'),
        $this->t('Prereqs'),
        $this->t('Rec.'),
        $this->t('Status'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No courses are associated with this program yet. Run an import to pull them from the catalog.'),
    ];

    $build['#cache'] = [
      'tags' => array_merge(
        $group->getCacheTags(),
        ['node_list:course'],
      ),
      'contexts' => ['user.permissions'],
    ];

    return $build;
  }

  /**
   * Compact comma-separated list of referenced course numbers in a field.
   */
  private function refNumbers(NodeInterface $course, string $fieldName): string {
    if (!$course->hasField($fieldName) || $course->get($fieldName)->isEmpty()) {
      return '';
    }
    $numbers = [];
    foreach ($course->get($fieldName)->referencedEntities() as $referenced) {
      if ($referenced instanceof NodeInterface && $referenced->hasField('field_course_number')) {
        $n = trim((string) $referenced->get('field_course_number')->value);
        if ($n !== '') {
          $numbers[] = $n;
        }
      }
    }
    return implode(', ', $numbers);
  }

  /**
   * Course nodes related to a program group via gnode, sorted by number.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  private function loadCourses(GroupInterface $program): array {
    $nids = [];
    foreach ($program->getRelationships('group_node:course') as $relationship) {
      $nids[] = (int) $relationship->getEntityId();
    }
    if (!$nids) {
      return [];
    }
    $storage = $this->entityTypeManager()->getStorage('node');
    $sorted = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('nid', $nids, 'IN')
      ->sort('field_course_number.value', 'ASC')
      ->execute();
    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $storage->loadMultiple($sorted);
    return $nodes;
  }

}
