<?php

declare(strict_types=1);

namespace Drupal\programhub_careers\Drush\Commands;

use Drupal\programhub_careers\Service\CareersBatchBuilder;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush wrapper around the careers refresh.
 *
 * Always runs through the Drupal Batch API (same code path as the admin form)
 * because the BLS master XLSX is too big to parse in one process tick without
 * either a proxy timeout (web) or PHP memory pressure (CLI).
 *
 * Usage:
 *   drush programhub:careers:refresh              # live
 *   drush programhub:careers:refresh --dry-run    # preview
 */
final class ProgramhubCareersCommands extends DrushCommands {

  /**
   * Refresh career_outcome nodes from the uploaded BLS + O*NET source files.
   */
  #[CLI\Command(name: 'programhub:careers:refresh', aliases: ['phcr', 'careers:refresh'])]
  #[CLI\Option(name: 'dry-run', description: 'Log planned writes without saving.')]
  #[CLI\Usage(name: 'drush programhub:careers:refresh', description: 'Run the import with current settings.')]
  #[CLI\Usage(name: 'drush programhub:careers:refresh --dry-run', description: 'Preview without writing.')]
  public function refresh(array $options = ['dry-run' => FALSE]): void {
    $dry = (bool) $options['dry-run'];
    $this->logger()->notice($dry ? 'Dry run — nothing will be saved.' : 'Refreshing career outcomes…');

    /** @var CareersBatchBuilder $builder */
    $builder = \Drupal::service('programhub_careers.batch_builder');
    $batch = $builder->build($dry);
    batch_set($batch);
    drush_backend_batch_process();
  }

}
