<?php

namespace Drupal\programhub_careers\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\NodeInterface;

/**
 * Joins BLS OEWS + O*NET data and upserts career_outcome nodes.
 *
 * Idempotent: re-running produces the same nodes. Editorial fields
 * (field_pay_range, field_description, field_career_track) are never
 * overwritten on existing nodes.
 *
 * Two entry points:
 *   - run(): one-shot, used as a fallback / by tests.
 *   - collectSocs() + upsertOne(): pieces called by the Drupal Batch ops
 *     so a refresh can spread across many HTTP requests.
 */
class CareersImporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly BlsLoader $blsLoader,
    private readonly OnetLoader $onetLoader,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Run the full import in one shot. Use the Batch API for production runs.
   *
   * @return array{created: int, updated: int, skipped: int, missing: string[]}
   */
  public function run(bool $dryRun = FALSE): array {
    $log = $this->loggerFactory->get('programhub_careers');
    $config = $this->configFactory->get('programhub_careers.settings');

    [, $programsBySoc] = $this->collectSocs();
    $allSocs = array_keys($programsBySoc);
    if (empty($allSocs)) {
      $log->warning('No SOC codes tagged on any program — nothing to import.');
      return ['created' => 0, 'updated' => 0, 'skipped' => 0, 'missing' => []];
    }

    $bls = $this->blsLoader->load(
      (string) $config->get('bls_state_code'),
      (string) $config->get('bls_msa_code'),
    );
    $tasks = $this->onetLoader->load();

    $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'missing' => []];
    $year = '20' . (string) $config->get('bls_year');

    foreach ($allSocs as $soc) {
      $wage = BlsLoader::chooseWage($soc, $bls);
      if (!$wage) {
        $stats['missing'][] = $soc;
        $log->warning('No BLS data for SOC @soc — skipping', ['@soc' => $soc]);
        continue;
      }
      $action = $this->upsertOne(
        $soc,
        $wage,
        $tasks[$soc] ?? [],
        $programsBySoc[$soc] ?? [],
        $year,
        $dryRun,
      );
      $stats[$action]++;
    }

    return $stats;
  }

  /**
   * Walk every program group and collect:
   *   - socsByProgram: group gid → string[]
   *   - programsBySoc: SOC code → group gid[]
   *
   * Public so the batch ops can call it directly.
   *
   * @return array{0: array<int, string[]>, 1: array<string, int[]>}
   */
  public function collectSocs(): array {
    // Sweep every program subtype — `program`, `program_design`,
    // `program_culinary`, etc. — so retyped programs (e.g. GDES under
    // program_design) still contribute their SOC codes.
    $gids = $this->entityTypeManager->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', \Drupal\programhub_dashboard\Service\GroupContext::PROGRAM_GROUP_TYPES, 'IN')
      ->execute();
    $programs = $this->entityTypeManager->getStorage('group')->loadMultiple($gids);

    $socsByProgram = [];
    $programsBySoc = [];
    foreach ($programs as $program) {
      $codes = [];
      if ($program->hasField('field_soc_codes')) {
        foreach ($program->get('field_soc_codes') as $item) {
          $code = trim((string) $item->getString());
          if ($code !== '') {
            $codes[] = $code;
          }
        }
      }
      $socsByProgram[$program->id()] = $codes;
      foreach ($codes as $code) {
        $programsBySoc[$code][] = (int) $program->id();
      }
    }
    return [$socsByProgram, $programsBySoc];
  }

  /**
   * Upsert a single career_outcome from a chosen wage row + tasks.
   *
   * @param array{row: array, source: string} $wage
   * @param string[] $tasks
   * @param int[] $programIds
   * @return 'created'|'updated'|'skipped'
   */
  public function upsertOne(
    string $soc,
    array $wage,
    array $tasks,
    array $programIds,
    string $year,
    bool $dryRun,
  ): string {
    $payload = [
      'soc_code' => $soc,
      'title' => $wage['row']['title'],
      'pay_low' => $wage['row']['pay_low'],
      'pay_median' => $wage['row']['pay_median'],
      'pay_high' => $wage['row']['pay_high'],
      'pay_source' => $this->paySourceLabel($wage['source'], $year),
      'tasks' => $tasks,
      'program_ids' => $programIds,
    ];
    return $this->upsert($payload, $dryRun);
  }

  /**
   * Find or create the career_outcome node for a SOC, then update verbatim
   * fields. Returns 'created', 'updated', or 'skipped' (dry run).
   */
  private function upsert(array $p, bool $dryRun): string {
    $storage = $this->entityTypeManager->getStorage('node');
    $existing = $storage->loadByProperties([
      'type' => 'career_outcome',
      'field_soc_code' => $p['soc_code'],
    ]);
    /** @var \Drupal\node\NodeInterface|null $node */
    $node = $existing ? reset($existing) : NULL;

    if ($dryRun) {
      $verb = $node ? 'PATCH' : 'POST';
      $this->loggerFactory->get('programhub_careers')->info(
        '@verb @soc @title',
        ['@verb' => $verb, '@soc' => $p['soc_code'], '@title' => $p['title']],
      );
      return 'skipped';
    }

    if (!$node) {
      $node = $storage->create([
        'type' => 'career_outcome',
        'title' => $p['title'],
        'field_soc_code' => $p['soc_code'],
        'status' => 1,
      ]);
      $action = 'created';
    }
    else {
      $action = 'updated';
    }

    // Verbatim fields — always overwritten.
    $node->set('field_pay_low', $p['pay_low']);
    $node->set('field_pay_median', $p['pay_median']);
    $node->set('field_pay_high', $p['pay_high']);
    $node->set('field_pay_source', $p['pay_source']);
    $node->set('field_tasks', array_map(fn($t) => ['value' => $t], $p['tasks']));

    $node->save();

    // Group memberships — union with whatever's already there. Attach to
    // each program group that claims this SOC; existing relationships
    // are preserved (we never remove a group an editor has added).
    $existingGroupIds = [];
    foreach (\Drupal\group\Entity\GroupRelationship::loadByEntity($node) as $rel) {
      $existingGroupIds[(int) $rel->getGroupId()] = TRUE;
    }
    $groupStorage = $this->entityTypeManager->getStorage('group');
    foreach ($p['program_ids'] as $gid) {
      if (isset($existingGroupIds[(int) $gid])) {
        continue;
      }
      $group = $groupStorage->load($gid);
      if ($group) {
        $group->addRelationship($node, 'group_node:career_outcome');
      }
    }
    return $action;
  }

  /**
   * Build the citation string written to field_pay_source. The fallback level
   * (MSA → state → national) shows up in the label so editors can see why a
   * given outcome's pay isn't local.
   */
  private function paySourceLabel(string $source, string $year): string {
    return match ($source) {
      'msa' => "BLS OEWS May $year · Coeur d'Alene MSA",
      'state' => "BLS OEWS May $year · Idaho",
      default => "BLS OEWS May $year · National",
    };
  }

}
