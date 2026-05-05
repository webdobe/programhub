<?php

declare(strict_types=1);

namespace Drupal\programhub_profile_paths\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for profile path management.
 */
final class ProfilePathsCommands extends DrushCommands {

  /**
   * Backfill or rebuild field_path on every profile.
   */
  #[CLI\Command(name: 'programhub:profile-paths:rebuild', aliases: ['php-paths', 'profile-paths:rebuild'])]
  #[CLI\Option(name: 'bundle', description: 'Limit to a single bundle (graduate, instructor, tac_member).')]
  #[CLI\Option(name: 'force', description: 'Recompute even when current value already matches.')]
  #[CLI\Option(name: 'dry-run', description: 'Show what would change without saving.')]
  public function rebuild(array $options = ['bundle' => NULL, 'force' => FALSE, 'dry-run' => FALSE]): void {
    $storage = \Drupal::entityTypeManager()->getStorage('profile');

    $query = $storage->getQuery()->accessCheck(FALSE);
    if (!empty($options['bundle'])) {
      $query->condition('type', $options['bundle']);
    }
    $ids = $query->execute();

    $changed = 0;
    $skipped = 0;
    $missing = 0;

    foreach ($storage->loadMultiple($ids) as $profile) {
      if (!$profile->hasField('field_path')) {
        $missing++;
        continue;
      }
      $computed = programhub_profile_paths_compute($profile);
      if ($computed === NULL) {
        $skipped++;
        $this->io()->writeln(sprintf(
          '[skip] pid:%s (%s) — no derivable path',
          $profile->id(),
          $profile->bundle(),
        ));
        continue;
      }
      $current = $profile->get('field_path')->value;
      if ($current === $computed && !$options['force']) {
        continue;
      }
      $this->io()->writeln(sprintf(
        '[%s] pid:%s (%s)  %s  →  %s',
        $options['dry-run'] ? 'dry' : 'set',
        $profile->id(),
        $profile->bundle(),
        $current ?: '(empty)',
        $computed,
      ));
      if (!$options['dry-run']) {
        $profile->set('field_path', $computed);
        $profile->save();
      }
      $changed++;
    }

    $this->io()->success(sprintf(
      '%s %d profile path%s. %d skipped, %d without field.',
      $options['dry-run'] ? 'Would update' : 'Updated',
      $changed,
      $changed === 1 ? '' : 's',
      $skipped,
      $missing,
    ));
  }

}
