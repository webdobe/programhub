<?php

declare(strict_types=1);

namespace Drupal\programhub_course_import\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\programhub_course_import\Service\CourseImporter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for course import.
 */
final class CourseImportCommands extends DrushCommands {

  /**
   * Import courses for one or all programs.
   */
  #[CLI\Command(name: 'programhub:courses:import', aliases: ['phci', 'courses:import'])]
  #[CLI\Option(name: 'program', description: 'Limit to a single program (by abbreviation, e.g. CITE).')]
  #[CLI\Option(name: 'dry-run', description: 'Preview changes without writing.')]
  public function import(array $options = ['program' => NULL, 'dry-run' => FALSE]): void {
    /** @var EntityTypeManagerInterface $etm */
    $etm = \Drupal::service('entity_type.manager');
    /** @var CourseImporter $importer */
    $importer = \Drupal::service('programhub_course_import.importer');

    $storage = $etm->getStorage('node');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'program');
    if (!empty($options['program'])) {
      $query->condition('field_abbreviation', $options['program']);
    }
    $ids = $query->execute();

    if (!$ids) {
      $this->io()->warning('No matching programs found.');
      return;
    }

    $totals = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'flagged' => 0];

    foreach ($storage->loadMultiple($ids) as $program) {
      $this->io()->section(sprintf('Program: %s', $program->label()));
      $result = $importer->importForProgram($program, (bool) $options['dry-run']);

      if ($result['errors']) {
        foreach ($result['errors'] as $err) {
          $this->io()->error((string) $err);
        }
        continue;
      }

      $this->io()->writeln(sprintf(
        '  url=%s  created=%d updated=%d unchanged=%d flagged=%d',
        $result['url'],
        $result['created'],
        $result['updated'],
        $result['unchanged'],
        $result['flagged'],
      ));

      foreach (['created', 'updated', 'unchanged', 'flagged'] as $k) {
        $totals[$k] += $result[$k];
      }
    }

    $this->io()->success(sprintf(
      '%s — created=%d updated=%d unchanged=%d flagged=%d',
      $options['dry-run'] ? 'DRY RUN' : 'Done',
      $totals['created'],
      $totals['updated'],
      $totals['unchanged'],
      $totals['flagged'],
    ));
  }

}
