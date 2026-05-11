<?php

declare(strict_types=1);

namespace Drupal\programhub_dashboard\Drush\Commands;

use Drupal\programhub_dashboard\Migration\GroupTypeMover;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Move a Group entity between group_types.
 *
 *   drush programhub:group:retype --gid=2 --type=program_design
 *   drush programhub:group:retype --label="Graphic & Web Design" --source-type=program --type=program_design
 *   drush programhub:group:retype --gid=2 --type=program_design --dry-run
 */
final class GroupTypeRetypeCommands extends DrushCommands {

  public function __construct(
    private readonly GroupTypeMover $mover,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self($container->get('programhub_dashboard.group_type_mover'));
  }

  #[CLI\Command(name: 'programhub:group:retype', aliases: ['phgrt'])]
  #[CLI\Option(name: 'gid', description: 'Source group entity ID.')]
  #[CLI\Option(name: 'label', description: 'Source group label (alternative to --gid).')]
  #[CLI\Option(name: 'source-type', description: 'Required with --label: the current group_type.')]
  #[CLI\Option(name: 'type', description: 'Destination group_type machine name.')]
  #[CLI\Option(name: 'dry-run', description: 'Log planned writes; save nothing.')]
  #[CLI\Usage(name: 'drush phgrt --gid=2 --type=program_design', description: 'Move group 2 to program_design.')]
  public function retype(array $options = [
    'gid' => NULL,
    'label' => NULL,
    'source-type' => NULL,
    'type' => NULL,
    'dry-run' => FALSE,
  ]): void {
    $targetType = $options['type'];
    if (!$targetType) {
      throw new \InvalidArgumentException('--type is required.');
    }
    $dry = (bool) $options['dry-run'];

    if ($options['gid']) {
      $result = $this->mover->move((int) $options['gid'], $targetType, $dry, $this->logger());
    }
    elseif ($options['label']) {
      if (!$options['source-type']) {
        throw new \InvalidArgumentException('--source-type is required when using --label.');
      }
      $result = $this->mover->moveByLabel(
        (string) $options['label'],
        (string) $options['source-type'],
        $targetType,
        $dry,
        $this->logger(),
      );
    }
    else {
      throw new \InvalidArgumentException('Pass either --gid or --label.');
    }

    if ($result['moved']) {
      $this->logger()->notice(sprintf(
        '✓ Moved to gid=%d (%d relationships, %d skipped).',
        $result['newGid'],
        $result['relationships'],
        count($result['skipped']),
      ));
    }
    elseif ($dry) {
      $this->logger()->notice(sprintf(
        'DRY RUN — would move %d relationships.',
        $result['relationships'],
      ));
    }
  }

}
