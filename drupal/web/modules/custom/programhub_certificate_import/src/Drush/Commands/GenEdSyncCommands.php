<?php

declare(strict_types=1);

namespace Drupal\programhub_certificate_import\Drush\Commands;

use Drupal\programhub_certificate_import\Service\GenEdImporter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush command for the gen-ed catalog sync.
 *
 * Pulls /aa-as-degree-requirements/ + /aas-degree-requirements/, upserts
 * `gen_ed_category` terms, and tags every course node found under each
 * category with `field_fulfills_categories`. Idempotent; safe to re-run.
 */
final class GenEdSyncCommands extends DrushCommands {

  /**
   * Sync gen-ed categories + course tagging from the NIC catalog.
   */
  #[CLI\Command(name: 'programhub:gen-ed:sync', aliases: ['pgesync'])]
  #[CLI\Option(name: 'url', description: 'Override source URL — repeat to scan multiple pages. Defaults to the two NIC degree-requirements pages.')]
  #[CLI\Option(name: 'no-spider', description: 'Skip fetching unknown course nodes via the course-import spider. Unknown numbers are reported as missing instead.')]
  #[CLI\Option(name: 'dry-run', description: 'Compute the plan and report counts without writing.')]
  public function sync(array $options = ['url' => [], 'no-spider' => FALSE, 'dry-run' => FALSE]): void {
    /** @var GenEdImporter $importer */
    $importer = \Drupal::service('programhub_certificate_import.gen_ed_importer');

    $urls = $options['url'] ?? [];
    if (!is_array($urls)) {
      $urls = [$urls];
    }
    $urls = array_values(array_filter($urls));

    $result = $importer->sync(
      $urls ?: NULL,
      !$options['no-spider'],
      (bool) $options['dry-run'],
    );

    $this->io()->section('Source URLs');
    foreach ($result['urls'] as $url) {
      $this->io()->writeln('  ' . $url);
    }

    if ($result['errors']) {
      foreach ($result['errors'] as $err) {
        $this->io()->error((string) $err);
      }
    }

    $this->io()->writeln(sprintf(
      'Terms — seen: %d, created: %d',
      $result['termsSeen'],
      $result['termsCreated'],
    ));
    $this->io()->writeln(sprintf(
      'Courses — tagged: %d (tags added: %d), spidered: %d, missing: %d',
      $result['coursesTagged'],
      $result['tagsAdded'],
      $result['spidered'],
      count($result['missingCourses']),
    ));

    if ($result['missingCourses']) {
      $this->io()->writeln('  missing course numbers: ' . implode(', ', $result['missingCourses']));
    }

    $this->io()->success($options['dry-run'] ? 'DRY RUN — no writes.' : 'Done.');
  }

}
