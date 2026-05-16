<?php

namespace Drupal\programhub_careers\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Parses the BLS OEWS combined master XLSX from a fixed cache path.
 *
 * The expected file is:
 *   private://programhub_careers/bls_master.xlsx
 *
 * Editors upload it via the admin form (or drop it manually). The file is
 * what BLS calls the "All data" master — typically named
 * all_data_M_<YYYY>.xlsx — containing national + state + MSA rows in one
 * sheet, distinguished by AREA_TYPE + AREA columns.
 *
 * Streaming reader. The combined master is ~80 MB / ~414 000 rows; loading it
 * into memory (PhpSpreadsheet, etc.) OOMs the container even with row filters
 * because the shared-strings table alone is huge. OpenSpout walks the sheet
 * one row at a time without materialising everything.
 */
class BlsLoader {

  public const CANONICAL_PATH = 'private://programhub_careers/bls_master.xlsx';

  /** AREA_TYPE codes in the OEWS master file. */
  private const AREA_TYPE_NATIONAL = '1';
  private const AREA_TYPE_STATE = '2';
  private const AREA_TYPE_MSA = '4';

  /** Codes BLS uses for "data suppressed" — treat as null. */
  private const SUPPRESSED = ['*', '**', '#', ''];

  /** Columns we care about — referenced by name in the header row.
   *  A_* are annual wages, H_* are hourly. OEWS always publishes both;
   *  hourly is suppressed (* / **) for any SOC that's annually-rated only
   *  (teachers, pilots, etc.), in which case parseDecimal returns NULL and
   *  the hourly fields stay empty on the node. */
  private const NEEDED_COLUMNS = [
    'OCC_CODE', 'OCC_TITLE', 'AREA_TYPE', 'AREA',
    'A_PCT25', 'A_MEDIAN', 'A_PCT75',
    'H_PCT25', 'H_MEDIAN', 'H_PCT75',
  ];

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns whether the master XLSX is present at the canonical path.
   */
  public function hasFile(): bool {
    $path = $this->fileSystem->realpath(self::CANONICAL_PATH);
    return $path !== FALSE && file_exists($path);
  }

  /**
   * Resolve the canonical file path or throw.
   */
  public function realPathOrThrow(): string {
    $path = $this->fileSystem->realpath(self::CANONICAL_PATH);
    if ($path === FALSE || !file_exists($path)) {
      throw new \RuntimeException(
        'BLS master XLSX not found. Upload it on the careers refresh page.'
      );
    }
    return $path;
  }

  /**
   * Parse the master XLSX into three SOC-keyed maps. Streams the workbook
   * row-by-row via OpenSpout; memory stays around 16 MB regardless of file
   * size, but on the 414k-row master this still takes ~100 s. Run from a
   * Drupal Batch op so the proxy/PHP timeout is the only bound.
   *
   * @return array{national: array, state: array, msa: array}
   */
  public function load(string $stateCode, string $msaCode): array {
    $path = $this->realPathOrThrow();
    $out = ['national' => [], 'state' => [], 'msa' => []];
    $this->stream($path, $stateCode, $msaCode, $out);
    $this->loggerFactory->get('programhub_careers')->info(
      'BLS master parsed: @nat national, @st state, @msa MSA rows',
      ['@nat' => count($out['national']), '@st' => count($out['state']), '@msa' => count($out['msa'])],
    );
    return $out;
  }

  /**
   * Pick the most-local non-suppressed wage row for a SOC.
   *
   * Returns ['row' => ..., 'source' => 'msa'|'state'|'national'] or null.
   */
  public static function chooseWage(string $soc, array $data): ?array {
    foreach (['msa', 'state', 'national'] as $source) {
      $row = $data[$source][$soc] ?? NULL;
      if (!$row) {
        continue;
      }
      // Accept the row if any of the six wage cells survived suppression.
      foreach (['pay_low', 'pay_median', 'pay_high', 'hourly_low', 'hourly_median', 'hourly_high'] as $k) {
        if ($row[$k] !== NULL) {
          return ['row' => $row, 'source' => $source];
        }
      }
    }
    return NULL;
  }

  /**
   * Core streaming pass over the active sheet.
   *
   * @param array{national: array, state: array, msa: array} $out
   */
  private function stream(
    string $path,
    string $stateCode,
    string $msaCode,
    array &$out,
  ): void {
    $reader = new Reader();
    $reader->open($path);
    try {
      foreach ($reader->getSheetIterator() as $sheet) {
        $idx = NULL;
        foreach ($sheet->getRowIterator() as $rowIndex => $row) {
          if ($rowIndex === 1) {
            $idx = $this->resolveIndices($row->toArray());
            continue;
          }
          if ($idx === NULL) {
            continue;
          }
          $this->categorize($row->toArray(), $idx, $stateCode, $msaCode, $out);
        }
        // Only the first sheet has data; ignore any others.
        break;
      }
    }
    finally {
      $reader->close();
    }
  }

  /**
   * Resolve and validate column indices from a header row.
   *
   * @return array<string, int>
   */
  private function resolveIndices(array $header): array {
    $idx = [];
    foreach ($header as $i => $v) {
      if (is_string($v)) {
        $idx[trim($v)] = $i;
      }
    }
    foreach (self::NEEDED_COLUMNS as $col) {
      if (!isset($idx[$col])) {
        throw new \RuntimeException("BLS master XLSX missing column $col — is this the all_data_M_<year>.xlsx file?");
      }
    }
    return $idx;
  }

  /**
   * Place a single row into the right bucket (or skip).
   *
   * @param array{national: array, state: array, msa: array} $out
   */
  private function categorize(array $row, array $idx, string $stateCode, string $msaCode, array &$out): void {
    $occ = trim((string) ($row[$idx['OCC_CODE']] ?? ''));
    if ($occ === '' || $occ === '00-0000') {
      return;
    }
    $areaType = trim((string) ($row[$idx['AREA_TYPE']] ?? ''));
    $area = trim((string) ($row[$idx['AREA']] ?? ''));

    $bucket = match (TRUE) {
      $areaType === self::AREA_TYPE_NATIONAL => 'national',
      $areaType === self::AREA_TYPE_STATE && $area === $stateCode => 'state',
      $areaType === self::AREA_TYPE_MSA && $area === $msaCode => 'msa',
      default => NULL,
    };
    if ($bucket === NULL) {
      return;
    }

    $out[$bucket][$occ] = [
      'title' => trim((string) ($row[$idx['OCC_TITLE']] ?? '')),
      'pay_low' => $this->parseInt($row[$idx['A_PCT25']] ?? NULL),
      'pay_median' => $this->parseInt($row[$idx['A_MEDIAN']] ?? NULL),
      'pay_high' => $this->parseInt($row[$idx['A_PCT75']] ?? NULL),
      'hourly_low' => $this->parseDecimal($row[$idx['H_PCT25']] ?? NULL),
      'hourly_median' => $this->parseDecimal($row[$idx['H_MEDIAN']] ?? NULL),
      'hourly_high' => $this->parseDecimal($row[$idx['H_PCT75']] ?? NULL),
    ];
  }

  private function parseInt(mixed $v): ?int {
    if ($v === NULL) {
      return NULL;
    }
    $s = trim(str_replace([',', '$'], '', (string) $v));
    if (in_array($s, self::SUPPRESSED, TRUE)) {
      return NULL;
    }
    return is_numeric($s) ? (int) $s : NULL;
  }

  /**
   * Parse a decimal wage (hourly H_* columns). Same suppression rules as
   * {@see parseInt} but preserves cents — BLS publishes hourly to two
   * decimal places ("24.38").
   */
  private function parseDecimal(mixed $v): ?float {
    if ($v === NULL) {
      return NULL;
    }
    $s = trim(str_replace([',', '$'], '', (string) $v));
    if (in_array($s, self::SUPPRESSED, TRUE)) {
      return NULL;
    }
    return is_numeric($s) ? (float) $s : NULL;
  }

}
