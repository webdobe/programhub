<?php

namespace Drupal\programhub_careers\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\programhub_careers\Batch\CareersBatchOps;

/**
 * Builds the Drupal Batch definition that runs the careers import.
 *
 * The careers import is too heavy for one HTTP request (BLS master XLSX is
 * ~400k rows, ~30 columns; the full pipeline blows the proxy timeout). Each
 * operation here is *resumable* — Drupal calls it repeatedly until
 * $context['finished'] reaches 1.0, so a single phase can fan out across as
 * many sub-requests as it needs.
 *
 * Phases:
 *   1. parseBls   — chunked row-range XLSX read into national/state/msa map
 *   2. parseOnet  — chunked TSV line read into SOC → tasks map
 *   3. collectSocs — single fast pass over program nodes
 *   4. upsert     — chunked walk of SOCs; create/update career_outcome nodes
 *   5. finish     — flash messages, write log
 */
class CareersBatchBuilder {

  /** SOCs upserted per request. Each upsert is a node save — keep modest. */
  private const UPSERT_CHUNK = 25;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly BlsLoader $blsLoader,
    private readonly OnetLoader $onetLoader,
    private readonly EpLoader $epLoader,
  ) {}

  /**
   * Build a Drupal Batch definition. The caller passes it to batch_set().
   *
   * @return array{
   *   title: \Drupal\Core\StringTranslation\TranslatableMarkup,
   *   operations: array<int, array{0: callable, 1: array<int, mixed>}>,
   *   finished: callable,
   *   init_message: \Drupal\Core\StringTranslation\TranslatableMarkup,
   *   progress_message: \Drupal\Core\StringTranslation\TranslatableMarkup,
   * }
   */
  public function build(bool $dryRun): array {
    $config = $this->configFactory->get('programhub_careers.settings');
    $blsPath = $this->blsLoader->realPathOrThrow();
    $onetPath = $this->onetLoader->realPathOrThrow();
    // EP is optional. NULL signals the parseEp op to skip parsing and leave
    // an empty results['ep'] map; the upsert phase tolerates that.
    $epPath = $this->epLoader->hasFile() ? $this->epLoader->realPathOrThrow() : NULL;
    $year = '20' . (string) $config->get('bls_year');
    $stateCode = (string) $config->get('bls_state_code');
    $msaCode = (string) $config->get('bls_msa_code');

    // BLS + O*NET + EP parses are each one HTTP request. The BLS pass is the
    // long pole (~90–120 s on the 414k-row master); we can't break it into
    // smaller pieces without paying an O(n²) skip-from-start cost on every
    // chunk (OpenSpout can't persist its reader state between PHP processes).
    // EP is smaller (~830 rows) — single pass is plenty. The upsert phase IS
    // resumable — it's the only place where chunking helps.
    $operations = [
      [
        [CareersBatchOps::class, 'parseBls'],
        [$blsPath, $stateCode, $msaCode],
      ],
      [
        [CareersBatchOps::class, 'parseOnet'],
        [$onetPath],
      ],
      [
        [CareersBatchOps::class, 'parseEp'],
        [$epPath],
      ],
      [
        [CareersBatchOps::class, 'collectSocs'],
        [],
      ],
      [
        [CareersBatchOps::class, 'upsert'],
        [self::UPSERT_CHUNK, $year, $dryRun],
      ],
    ];

    return [
      'title' => t('Refreshing career outcomes…'),
      'operations' => $operations,
      'finished' => [CareersBatchOps::class, 'finished'],
      'init_message' => t('Starting…'),
      'progress_message' => t('Step @current of @total.'),
    ];
  }

}
