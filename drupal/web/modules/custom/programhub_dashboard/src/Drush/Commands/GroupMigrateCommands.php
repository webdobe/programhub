<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Drush\Commands;

use Drupal\programhub_dashboard\Migration\OgToGroupMigrator;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Thin CLI wrapper around `programhub_dashboard.og_to_group_migrator`.
 *
 * The migration also runs automatically via
 * `programhub_dashboard_deploy_001_migrate_og_to_group()` during
 * `drush deploy`. This command is only for manual runs or `--dry-run`
 * previews.
 *
 *   drush phmog --dry-run
 *   drush phmog
 */
final class GroupMigrateCommands extends DrushCommands {

  public function __construct(
    private readonly OgToGroupMigrator $migrator,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('programhub_dashboard.og_to_group_migrator'),
    );
  }

  #[CLI\Command(name: 'programhub:migrate-og-to-group', aliases: ['phmog'])]
  #[CLI\Option(name: 'dry-run', description: 'Print planned writes; save nothing.')]
  #[CLI\Usage(name: 'drush phmog --dry-run', description: 'Preview the migration.')]
  #[CLI\Usage(name: 'drush phmog', description: 'Migrate OG data into Group entities.')]
  public function migrate(array $options = ['dry-run' => FALSE]): void {
    $dry = (bool) $options['dry-run'];
    $this->logger()->notice($dry ? 'DRY RUN — nothing will be written.' : 'Migrating OG → Group…');
    $result = $this->migrator->run($dry, $this->logger());
    $this->logger()->notice(sprintf(
      '✓ Groups: %d, members: %d, content rows: %d.',
      $result['groups'],
      $result['members'],
      $result['content'],
    ));
  }

}
