<?php

declare(strict_types=1);

namespace Drupal\programhub_certificate_import\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\programhub_certificate_import\Service\CertificateImporter;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for certificate import.
 */
final class CertificateImportCommands extends DrushCommands {

  /**
   * Import one or more certificates from their configured catalog URLs.
   */
  #[CLI\Command(name: 'programhub:certificates:import', aliases: ['phcert', 'certs:import'])]
  #[CLI\Option(name: 'cert', description: 'Limit to a single certificate by node ID.')]
  #[CLI\Option(name: 'program', description: 'Limit to certificates of one program (by abbreviation, e.g. CITE).')]
  #[CLI\Option(name: 'dry-run', description: 'Preview changes without writing.')]
  public function import(array $options = ['cert' => NULL, 'program' => NULL, 'dry-run' => FALSE]): void {
    /** @var EntityTypeManagerInterface $etm */
    $etm = \Drupal::service('entity_type.manager');
    /** @var CertificateImporter $importer */
    $importer = \Drupal::service('programhub_certificate_import.importer');

    $storage = $etm->getStorage('node');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'certificate');

    if (!empty($options['cert'])) {
      $query->condition('nid', (int) $options['cert']);
    }
    elseif (!empty($options['program'])) {
      $programIds = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'program')
        ->condition('field_abbreviation', $options['program'])
        ->execute();
      if (!$programIds) {
        $this->io()->warning(sprintf('No program with abbreviation "%s".', $options['program']));
        return;
      }
      $query->condition('og_audience', array_values($programIds), 'IN');
    }

    $ids = $query->execute();
    if (!$ids) {
      $this->io()->warning('No matching certificates.');
      return;
    }

    $totals = ['changed' => 0, 'unchanged' => 0, 'spidered' => 0, 'missing' => 0];

    foreach ($storage->loadMultiple($ids) as $cert) {
      $this->io()->section(sprintf('Certificate: %s (nid %d)', $cert->label(), $cert->id()));
      $result = $importer->importForCertificate($cert, (bool) $options['dry-run']);

      if ($result['errors']) {
        foreach ($result['errors'] as $err) {
          $this->io()->error((string) $err);
        }
        continue;
      }

      $this->io()->writeln(sprintf(
        '  url=%s  courses=%d missing=%d spidered=%d totalCredits=%s changed=%s',
        $result['url'],
        $result['coursesResolved'],
        count($result['coursesMissing']),
        $result['spidered'],
        $result['totalCredits'] ?? '—',
        $result['changed'] ? 'yes' : 'no',
      ));

      if ($result['coursesMissing']) {
        $this->io()->writeln('  missing: ' . implode(', ', $result['coursesMissing']));
      }

      $totals['changed'] += $result['changed'] ? 1 : 0;
      $totals['unchanged'] += $result['changed'] ? 0 : 1;
      $totals['spidered'] += $result['spidered'];
      $totals['missing'] += count($result['coursesMissing']);
    }

    $this->io()->success(sprintf(
      '%s — changed=%d unchanged=%d spidered=%d missing=%d',
      $options['dry-run'] ? 'DRY RUN' : 'Done',
      $totals['changed'],
      $totals['unchanged'],
      $totals['spidered'],
      $totals['missing'],
    ));
  }

}
