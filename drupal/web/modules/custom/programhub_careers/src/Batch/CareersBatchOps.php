<?php

namespace Drupal\programhub_careers\Batch;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\programhub_careers\Service\BlsLoader;

/**
 * Static callbacks for the careers refresh Batch.
 *
 * Drupal Batch resolves operations via [class, method] callables, so these
 * have to be static and pull services from the container at call time.
 *
 * State carried across calls:
 *   $context['sandbox']  — per-op working state (only the upsert op uses it)
 *   $context['results']  — accumulated output visible to later ops + finish:
 *     ['bls']            ['national'=>..., 'state'=>..., 'msa'=>...]
 *     ['onet']           SOC → string[]
 *     ['programsBySoc']  SOC → int[] of program nids
 *     ['stats']          ['created'=>int, 'updated'=>int, 'skipped'=>int, 'missing'=>string[]]
 *
 * Why most ops aren't resumable:
 *   The XLSX/TSV streaming readers can't be persisted between PHP processes —
 *   resuming would require seeking back to the cursor row from byte 0, which
 *   is O(n) per chunk and turns the whole pass into O(n²). Single-pass per op
 *   is the right shape; the cost is that the *web* request running parseBls
 *   needs ≥ ~150 s of proxy/PHP time. The admin form route should be configured
 *   accordingly (see programhub_careers.routing.yml or your nginx rules).
 */
final class CareersBatchOps {

  /**
   * Phase 1 — full BLS sheet stream into national/state/MSA buckets.
   * Runs in a single HTTP request; lifts time limits defensively.
   */
  public static function parseBls(
    string $path,
    string $stateCode,
    string $msaCode,
    array &$context,
  ): void {
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    $loader = \Drupal::service('programhub_careers.bls_loader');
    assert($loader instanceof BlsLoader);

    $context['results']['bls'] = $loader->load($stateCode, $msaCode);
    $bls = $context['results']['bls'];
    $context['message'] = t('BLS parsed: @nat national, @st state, @msa MSA rows', [
      '@nat' => count($bls['national']),
      '@st' => count($bls['state']),
      '@msa' => count($bls['msa']),
    ]);
    $context['finished'] = 1;
  }

  /**
   * Phase 2 — full O*NET TSV read into SOC → tasks map.
   * Small file (~6 MB), runs in one shot.
   */
  public static function parseOnet(string $path, array &$context): void {
    @set_time_limit(0);

    $loader = \Drupal::service('programhub_careers.onet_loader');
    $context['results']['onet'] = $loader->load();
    $context['message'] = t('O*NET parsed: @n SOCs', ['@n' => count($context['results']['onet'])]);
    $context['finished'] = 1;
  }

  /**
   * Phase 3 — collect SOC → program-nid map. Single pass, fast.
   */
  public static function collectSocs(array &$context): void {
    $importer = \Drupal::service('programhub_careers.importer');
    [, $programsBySoc] = $importer->collectSocs();
    $context['results']['programsBySoc'] = $programsBySoc;
    $count = count($programsBySoc);
    $context['message'] = t('Collected @n SOCs from program nodes.', ['@n' => $count]);
    $context['finished'] = 1;
  }

  /**
   * Phase 4 — chunked upsert of career_outcome nodes. Resumable.
   */
  public static function upsert(
    int $chunkSize,
    string $year,
    bool $dryRun,
    array &$context,
  ): void {
    $sandbox = &$context['sandbox'];
    if (!isset($sandbox['upsert_index'])) {
      $sandbox['upsert_index'] = 0;
      $sandbox['upsert_socs'] = array_keys($context['results']['programsBySoc'] ?? []);
      $sandbox['upsert_total'] = count($sandbox['upsert_socs']);
      $context['results']['stats'] = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'missing' => [],
      ];
    }

    $importer = \Drupal::service('programhub_careers.importer');
    $bls = $context['results']['bls'] ?? ['national' => [], 'state' => [], 'msa' => []];
    $tasks = $context['results']['onet'] ?? [];
    $programsBySoc = $context['results']['programsBySoc'] ?? [];
    $stats = &$context['results']['stats'];

    $end = min((int) $sandbox['upsert_index'] + $chunkSize, (int) $sandbox['upsert_total']);
    for ($i = (int) $sandbox['upsert_index']; $i < $end; $i++) {
      $soc = $sandbox['upsert_socs'][$i];
      $wage = BlsLoader::chooseWage($soc, $bls);
      if (!$wage) {
        $stats['missing'][] = $soc;
        continue;
      }
      $action = $importer->upsertOne(
        $soc,
        $wage,
        $tasks[$soc] ?? [],
        $programsBySoc[$soc] ?? [],
        $year,
        $dryRun,
      );
      $stats[$action]++;
    }
    $sandbox['upsert_index'] = $end;

    $context['message'] = t('Upserted @done of @total SOCs', [
      '@done' => $end,
      '@total' => $sandbox['upsert_total'],
    ]);
    $context['finished'] = $sandbox['upsert_total'] === 0
      ? 1
      : $end / $sandbox['upsert_total'];
  }

  /**
   * Final callback. Drupal calls this once after all ops finish (or on error).
   */
  public static function finished(bool $success, array $results, array $operations): void {
    /** @var MessengerInterface $messenger */
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addError(t('Careers refresh did not complete. Check the recent log messages for details.'));
      return;
    }

    $stats = $results['stats'] ?? ['created' => 0, 'updated' => 0, 'skipped' => 0, 'missing' => []];
    $messenger->addStatus(t(
      'Careers refresh done. Created: @c · Updated: @u · Skipped: @s · No data: @n',
      [
        '@c' => $stats['created'],
        '@u' => $stats['updated'],
        '@s' => $stats['skipped'],
        '@n' => count($stats['missing']),
      ],
    ));
    if (!empty($stats['missing'])) {
      $messenger->addWarning(t('SOCs with no BLS data: @list', [
        '@list' => implode(', ', $stats['missing']),
      ]));
    }
  }

}
