<?php

declare(strict_types=1);

namespace Drupal\programhub_course_import\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Action\Plugin\Action\Derivative\EntityChangedActionDeriver;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\programhub_course_import\Service\CourseImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bulk-action: scrape and import the catalog courses for selected programs.
 *
 * Shows up in Views Bulk Operations on any view of nodes (filter to bundle =
 * program for it to be useful). Runs once per selected program node.
 */
#[Action(
  id: 'programhub_import_courses',
  label: new \Drupal\Core\StringTranslation\TranslatableMarkup('Import courses from NIC catalog'),
  type: 'node',
  category: new \Drupal\Core\StringTranslation\TranslatableMarkup('ProgramHub'),
)]
final class ImportCoursesAction extends ActionBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly CourseImporter $importer,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('programhub_course_import.importer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'program') {
      return;
    }
    $result = $this->importer->importForProgram($entity);
    \Drupal::messenger()->addStatus(t(
      'Imported courses for @p — created: @c, updated: @u, unchanged: @x, flagged: @f.',
      [
        '@p' => $entity->label(),
        '@c' => $result['created'],
        '@u' => $result['updated'],
        '@x' => $result['unchanged'],
        '@f' => $result['flagged'],
      ],
    ));
    foreach ($result['errors'] as $err) {
      \Drupal::messenger()->addWarning((string) $err);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var \Drupal\node\NodeInterface $object */
    $access = $object->access('update', $account, TRUE)
      ->andIf(\Drupal\Core\Access\AccessResult::allowedIfHasPermission($account ?? \Drupal::currentUser(), 'import programhub courses'));
    return $return_as_object ? $access : $access->isAllowed();
  }

}
