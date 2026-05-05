<?php

declare(strict_types=1);

namespace Drupal\programhub_certificate_import\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * - certificatesTab(): renders the "Certificates" local task on a program node.
 * - importOne(): runs the catalog import for a single certificate, redirects.
 */
final class CertificateController extends ControllerBase {

  /**
   * Run the import for one certificate, then redirect back to it.
   */
  public function importOne(NodeInterface $node): RedirectResponse {
    if ($node->bundle() !== 'certificate') {
      $this->messenger()->addError($this->t('Only certificate nodes can be imported.'));
      return new RedirectResponse(Url::fromRoute('system.admin_content')->toString());
    }

    /** @var \Drupal\programhub_certificate_import\Service\CertificateImporter $importer */
    $importer = \Drupal::service('programhub_certificate_import.importer');
    $result = $importer->importForCertificate($node);

    if ($result['errors']) {
      foreach ($result['errors'] as $err) {
        $this->messenger()->addWarning((string) $err);
      }
    }

    if ($result['url']) {
      $missing = $result['coursesMissing'];
      $this->messenger()->addStatus($this->t(
        'Imported "@p" from @url — courses: @c, spidered: @s, missing: @m, total credits: @t.',
        [
          '@p' => $node->label(),
          '@url' => $result['url'],
          '@c' => $result['coursesResolved'],
          '@s' => $result['spidered'],
          '@m' => count($missing),
          '@t' => $result['totalCredits'] ?? '—',
        ],
      ));
      if ($missing) {
        $this->messenger()->addWarning($this->t(
          'Could not resolve these course numbers: @list',
          ['@list' => implode(', ', $missing)],
        ));
      }
    }

    return new RedirectResponse(Url::fromRoute('entity.node.canonical', ['node' => $node->id()])->toString());
  }

  /**
   * Render the Certificates tab on a program node.
   */
  public function certificatesTab(NodeInterface $node): array {
    $build = [];

    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['programhub-certificates-header']],
    ];

    if ($node->access('update')) {
      $build['header']['actions'] = [
        '#type' => 'actions',
        'add' => [
          '#type' => 'link',
          '#title' => $this->t('Add Certificate'),
          '#url' => Url::fromRoute('node.add', ['node_type' => 'certificate'], [
            'query' => ['og_audience' => $node->id()],
          ]),
          '#attributes' => [
            'class' => ['button', 'button--primary'],
          ],
        ],
      ];
    }

    $certificates = $this->loadCertificates($node);

    $rows = [];
    foreach ($certificates as $cert) {
      $title = $cert->access('view') ? $cert->toLink()->toString() : $cert->label();
      $totalCredits = $cert->hasField('field_total_credits') && !$cert->get('field_total_credits')->isEmpty()
        ? $cert->get('field_total_credits')->value
        : '—';
      $courseCount = $cert->hasField('field_courses') ? $cert->get('field_courses')->count() : 0;
      $hasUrl = $cert->hasField('field_certificate_url') && !$cert->get('field_certificate_url')->isEmpty();
      $catalogUrl = $hasUrl ? $cert->get('field_certificate_url')->uri : NULL;
      $status = $cert->isPublished() ? $this->t('Published') : $this->t('Unpublished');

      $ops = [];
      if ($cert->access('update')) {
        $ops['edit'] = [
          'title' => $this->t('Edit'),
          'url' => $cert->toUrl('edit-form'),
        ];
      }
      if ($hasUrl && $this->currentUser()->hasPermission('import programhub certificates')) {
        $ops['import'] = [
          'title' => $this->t('Import from catalog'),
          'url' => Url::fromRoute('programhub_certificate_import.import_one', ['node' => $cert->id()]),
        ];
      }

      $rows[] = [
        'title' => ['data' => $title],
        'credits' => $totalCredits,
        'courses' => $courseCount,
        'source' => $catalogUrl
          ? ['data' => ['#markup' => '<a href="' . htmlspecialchars($catalogUrl) . '" target="_blank" rel="noopener">' . $this->t('Catalog page')->__toString() . '</a>']]
          : '—',
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
        $this->t('Title'),
        $this->t('Total Credits'),
        $this->t('Courses'),
        $this->t('Catalog'),
        $this->t('Status'),
        $this->t('Operations'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No certificates yet for this program. Use "Add Certificate" to create one.'),
    ];

    $build['#cache'] = [
      'tags' => array_merge(
        $node->getCacheTags(),
        ['node_list:certificate'],
      ),
      'contexts' => ['user.permissions'],
    ];

    return $build;
  }

  /**
   * @return \Drupal\node\NodeInterface[]
   */
  private function loadCertificates(NodeInterface $program): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'certificate')
      ->condition('og_audience', $program->id())
      ->sort('title', 'ASC')
      ->execute();
    if (!$ids) {
      return [];
    }
    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $storage->loadMultiple($ids);
    return $nodes;
  }

}
