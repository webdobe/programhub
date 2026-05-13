<?php

declare(strict_types=1);

namespace Drupal\programhub_newsletters\Drush\Commands;

use Drupal\group\Entity\Group;
use Drupal\programhub_newsletters\Service\NewsletterProvisioner;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Re-provision the per-group simplenews newsletters after a
 * `drush cim` purges them.
 *
 * The provisioned `{abbr}_newsletter` config entities live only in
 * active config (deliberately — they're admin-customized per group).
 * A full `drush cim` deletes anything missing from `config/sync`, so
 * this command exists to rebuild them on demand. Idempotent.
 *
 *   drush programhub:newsletters:refresh
 *   drush phnlr
 */
final class ProgramhubNewslettersCommands extends DrushCommands {

  public function __construct(
    private readonly NewsletterProvisioner $provisioner,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self($container->get('programhub_newsletters.provisioner'));
  }

  #[CLI\Command(name: 'programhub:newsletters:refresh', aliases: ['phnlr'])]
  #[CLI\Usage(name: 'drush programhub:newsletters:refresh', description: 'Re-provision missing per-group newsletters.')]
  public function refresh(): void {
    $etm = \Drupal::entityTypeManager();
    $gids = $etm->getStorage('group')->getQuery()->accessCheck(FALSE)->execute();

    $created = 0;
    foreach (Group::loadMultiple($gids) as $group) {
      $created += $this->provisioner->provisionForGroup($group);
    }
    $this->logger()->success(sprintf(
      'Provisioned %d newsletters across %d groups scanned.',
      $created,
      count($gids),
    ));
  }

}
