#!/usr/bin/env drush
<?php

/**
 * Import the WeLearnDesign sticker PNGs into graduate profiles' field_sticker.
 *
 * Usage (env-var form — recommended, drush won't eat them):
 *   DRY_RUN=1 STICKER_SOURCE=/path/to/stickers drush scr scripts/import-stickers.php
 *
 * Usage (CLI args — must come AFTER `--` so drush doesn't consume them):
 *   drush scr scripts/import-stickers.php -- --dry-run --source=/path/to/stickers
 *
 *   Default source: /var/www/html/drupal/web/sites/default/files/stickers
 *
 * Filename pattern (best effort):
 *   GDES_<COURSE>_<TERM>_[<TYPE>_]STICKER_[<SUBJECT>_]<FIRST>_<LAST>.png
 *
 * The script extracts the trailing FIRST_LAST tokens (immediately before .png),
 * looks up a profile:graduate whose field_name matches, copies the file into
 * Drupal's public stickers directory, and assigns it to field_sticker.
 */

// ── Args ────────────────────────────────────────────────────────────────────
$source = getenv('STICKER_SOURCE') ?: '/var/www/html/drupal/web/sites/default/files/stickers';
$dryRun = (bool) getenv('DRY_RUN');

// Also accept --source= / --dry-run after `--` (drush passes them via $extra)
$cliArgs = $extra ?? [];
foreach ($cliArgs as $arg) {
  if (str_starts_with($arg, '--source=')) {
    $source = substr($arg, strlen('--source='));
  } elseif ($arg === '--dry-run') {
    $dryRun = true;
  }
}
if (!is_dir($source)) {
  fwrite(STDERR, "ERROR: source directory not found: $source\n");
  exit(1);
}

echo "Source: $source" . PHP_EOL;
echo "Mode:   " . ($dryRun ? 'DRY RUN' : 'WRITE') . PHP_EOL;
echo str_repeat('─', 70) . PHP_EOL;

// ── Pre-build a lookup of graduate profiles by "First Last" ────────────────
$profileStorage = \Drupal::entityTypeManager()->getStorage('profile');
$ids = $profileStorage->getQuery()
  ->accessCheck(false)
  ->condition('type', 'graduate')
  ->execute();

$byName = [];
foreach ($profileStorage->loadMultiple($ids) as $profile) {
  /** @var \Drupal\profile\Entity\ProfileInterface $profile */
  $name = $profile->get('field_name')->getValue();
  if (!$name) {
    continue;
  }
  $given = strtolower(trim($name[0]['given'] ?? ''));
  $family = strtolower(trim($name[0]['family'] ?? ''));
  if ($given && $family) {
    $byName["$given $family"] = $profile;
  }
}
echo "Loaded " . count($byName) . " graduate profiles" . PHP_EOL;

// ── Walk the source directory ──────────────────────────────────────────────
$matched = 0;
$skipped = 0;
$errors = 0;

foreach (new DirectoryIterator($source) as $entry) {
  if ($entry->isDot() || !$entry->isFile()) {
    continue;
  }
  $filename = $entry->getFilename();
  if (!preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', $filename)) {
    continue;
  }

  // Strip extension, then split on underscores. The last two tokens that look
  // like names (alpha-only) are FIRST and LAST. Capture the rest as "kind".
  $base = preg_replace('/\.[^.]+$/', '', $filename);
  $tokens = preg_split('/[_ ]+/', $base);

  // Find the rightmost two alphabetic tokens — those are FIRST and LAST.
  $alphaPositions = [];
  foreach ($tokens as $i => $tok) {
    if (preg_match('/^[A-Za-z]+$/', $tok)) {
      $alphaPositions[] = $i;
    }
  }
  if (count($alphaPositions) < 2) {
    fprintf(STDERR, "[skip] cannot parse name from: $filename\n");
    $skipped++;
    continue;
  }
  $lastIdx = end($alphaPositions);
  $firstIdx = prev($alphaPositions);

  $first = strtolower($tokens[$firstIdx]);
  $last = strtolower($tokens[$lastIdx]);
  $key = "$first $last";

  if (!isset($byName[$key])) {
    fprintf(STDERR, "[no match] $filename (looked for: $first $last)\n");
    $skipped++;
    continue;
  }

  /** @var \Drupal\profile\Entity\ProfileInterface $profile */
  $profile = $byName[$key];
  $matched++;

  echo "[match] $filename → " . ucwords("$first $last")
    . " (pid:" . $profile->id() . ")" . PHP_EOL;

  if ($dryRun) {
    continue;
  }

  // Copy the file into Drupal's managed file system.
  $destDir = 'public://stickers';
  \Drupal::service('file_system')->prepareDirectory(
    $destDir,
    \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY |
    \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS,
  );
  $destination = "$destDir/$filename";

  try {
    $managed = \Drupal::service('file.repository')->writeData(
      file_get_contents($entry->getPathname()),
      $destination,
      \Drupal\Core\File\FileExists::Replace,
    );
  } catch (\Throwable $e) {
    fprintf(STDERR, "[error] copy failed for $filename: " . $e->getMessage() . "\n");
    $errors++;
    continue;
  }

  $alt = sprintf('Sticker by %s %s', ucwords($first), ucwords($last));
  $profile->set('field_sticker', [
    'target_id' => $managed->id(),
    'alt' => $alt,
    'title' => '',
  ]);
  $profile->save();
}

echo str_repeat('─', 70) . PHP_EOL;
echo "Matched: $matched" . PHP_EOL;
echo "Skipped: $skipped" . PHP_EOL;
echo "Errors:  $errors" . PHP_EOL;
