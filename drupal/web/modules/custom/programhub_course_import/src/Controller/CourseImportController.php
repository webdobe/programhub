<?php

declare(strict_types=1);

namespace Drupal\programhub_course_import\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\programhub_course_import\Service\CatalogScraper;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Single-program "Import Courses" entry point. Wired up by hook_entity_operation.
 */
final class CourseImportController extends ControllerBase {

  /**
   * Run the import for one program node, then redirect to its admin canonical.
   */
  public function importOne(NodeInterface $node): RedirectResponse {
    if ($node->bundle() !== 'program') {
      $this->messenger()->addError($this->t('Only program nodes can be imported.'));
      return new RedirectResponse(Url::fromRoute('system.admin_content')->toString());
    }

    /** @var \Drupal\programhub_course_import\Service\CourseImporter $importer */
    $importer = \Drupal::service('programhub_course_import.importer');
    $result = $importer->importForProgram($node);

    if ($result['errors']) {
      foreach ($result['errors'] as $err) {
        $this->messenger()->addWarning((string) $err);
      }
    }

    $this->messenger()->addStatus($this->t(
      'Imported "@p" from @url — created: @c, updated: @u, unchanged: @x, flagged: @f.',
      [
        '@p' => $node->label(),
        '@url' => $result['url'],
        '@c' => $result['created'],
        '@u' => $result['updated'],
        '@x' => $result['unchanged'],
        '@f' => $result['flagged'],
      ],
    ));

    return new RedirectResponse(Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString());
  }

  /**
   * Render the Courses tab on a program node.
   */
  public function coursesTab(NodeInterface $node): array {
    $abbr = $node->hasField('field_abbreviation')
      ? trim((string) $node->get('field_abbreviation')->value)
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
          '#url' => Url::fromRoute('programhub_course_import.import_program', ['node' => $node->id()]),
          '#attributes' => [
            'class' => ['button', 'button--primary'],
          ],
        ],
      ];
    }

    $courses = $this->loadCourses($node);

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
        $node->getCacheTags(),
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
   * Load course nodes that belong to a program (via og_audience).
   *
   * @return \Drupal\node\NodeInterface[]
   */
  private function loadCourses(NodeInterface $program): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'course')
      ->condition('og_audience', $program->id())
      ->sort('field_course_number.value', 'ASC')
      ->execute();

    if (!$ids) {
      return [];
    }

    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $storage->loadMultiple($ids);
    return $nodes;
  }

}
