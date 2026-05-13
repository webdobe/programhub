<?php

declare(strict_types=1);

namespace Drupal\programhub_webform_simplenews\Drush\Commands;

use Drupal\programhub_webform_simplenews\Service\SubscribeHandlerWiring;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Backfill / re-wire the simplenews subscribe handler across every
 * `{abbr}_subscribe` webform.
 *
 * Use after a `drush cim` purges per-group webforms (and the
 * `phwfr` recreates them without their handlers), or after manually
 * spinning up new subscribe forms.
 *
 *   drush programhub:webform-simplenews:wire
 *   drush phwsw
 */
final class ProgramhubWebformSimplenewsCommands extends DrushCommands {

  public function __construct(
    private readonly SubscribeHandlerWiring $wiring,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self($container->get('programhub_webform_simplenews.wiring'));
  }

  #[CLI\Command(name: 'programhub:webform-simplenews:wire', aliases: ['phwsw'])]
  #[CLI\Usage(name: 'drush programhub:webform-simplenews:wire', description: 'Attach the simplenews handler to any {abbr}_subscribe webform that does not already carry one.')]
  public function wire(): void {
    $count = $this->wiring->wireAll();
    $this->logger()->success(sprintf('Wired %d subscribe webforms.', $count));
  }

}
