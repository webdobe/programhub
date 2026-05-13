<?php

declare(strict_types=1);

namespace Drupal\programhub_webforms\Drush\Commands;

use Drupal\group\Entity\Group;
use Drupal\programhub_webforms\Service\WebformProvisioner;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Re-provision the per-group webforms after a `drush cim` purges them.
 *
 * The provisioned `{abbr}_request_info` / `{abbr}_subscribe` webforms
 * live only in active config (deliberately — they're admin-customized
 * per group). A full `drush cim` deletes anything missing from
 * `config/sync`, so this command exists to rebuild them on demand.
 * Idempotent.
 *
 *   drush programhub:webforms:refresh
 *   drush phwfr
 */
final class ProgramhubWebformsCommands extends DrushCommands {

  public function __construct(
    private readonly WebformProvisioner $provisioner,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self($container->get('programhub_webforms.provisioner'));
  }

  #[CLI\Command(name: 'programhub:webforms:refresh', aliases: ['phwfr'])]
  #[CLI\Usage(name: 'drush programhub:webforms:refresh', description: 'Re-provision missing per-group webforms.')]
  public function refresh(): void {
    $etm = \Drupal::entityTypeManager();
    $gids = $etm->getStorage('group')->getQuery()->accessCheck(FALSE)->execute();

    $created = 0;
    foreach (Group::loadMultiple($gids) as $group) {
      $created += $this->provisioner->provisionForGroup($group);
    }
    $this->logger()->success(sprintf(
      'Provisioned %d webforms across %d groups scanned.',
      $created,
      count($gids),
    ));
  }

}
