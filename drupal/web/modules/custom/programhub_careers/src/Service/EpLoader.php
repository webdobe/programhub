<?php

namespace Drupal\programhub_careers\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Parses the BLS Employment Projections "Occupational Projections and Worker
 * Characteristics" XLSX from a fixed cache path.
 *
 * Source file (editor download, manual upload):
 *   https://www.bls.gov/emp/tables/occupational-projections-and-characteristics.htm
 *   → "Occupation.xlsx" (occupational projections + entry-level requirements)
 *
 * Canonical local path:
 *   private://programhub_careers/ep_master.xlsx
 *
 * Why this file (vs. one of the other EP tables): occupation.xlsx is the
 * single workbook that carries BOTH the projection numbers (2023 →
 * 2033 employment, change, percent, openings) AND the worker-characteristics
 * columns (typical entry-level education, work experience in a related
 * occupation, typical on-the-job training). Pulling them from one file means
 * one editor upload + one streaming pass per refresh.
 *
 * Column-name brittleness: BLS suffixes the year on each release (2022-32 →
 * 2023-33 → 2024-34 …). We match headers with case-insensitive substring
 * tests instead of exact strings so a year rollover doesn't break the
 * importer — the editor just uploads the new file.
 */
class EpLoader {

  public const CANONICAL_PATH = 'private://programhub_careers/ep_master.xlsx';

  /** Cells BLS uses for "data suppressed" / "not applicable" — treat as null. */
  private const NULLISH = ['', '—', '-', '--', 'N/A', 'NA', '*'];

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public function hasFile(): bool {
    $path = $this->fileSystem->realpath(self::CANONICAL_PATH);
    return $path !== FALSE && file_exists($path);
  }

  public function realPathOrThrow(): string {
    $path = $this->fileSystem->realpath(self::CANONICAL_PATH);
    if ($path === FALSE || !file_exists($path)) {
      throw new \RuntimeException(
        'EP master XLSX not found. Upload it on the careers refresh page.'
      );
    }
    return $path;
  }

  /**
   * Parse the workbook into a SOC-keyed map of projection + characteristics.
   *
   * @return array<string, array{
   *   title: string,
   *   employment_current: ?int,
   *   employment_projected: ?int,
   *   employment_change: ?int,
   *   outlook_percent: ?float,
   *   education_typical: ?string,
   *   work_experience: ?string,
   *   on_job_training: ?string,
   *   projection_period: ?string,
   * }>
   */
  public function load(): array {
    $path = $this->realPathOrThrow();
    $out = [];
    $this->stream($path, $out);
    $this->loggerFactory->get('programhub_careers')->info(
      'EP master parsed: @n SOC rows',
      ['@n' => count($out)],
    );
    return $out;
  }

  /**
   * Pick the richest sheet in the workbook and ingest it.
   *
   * The BLS workbook ships multiple projection tables. Table 1.1 is
   * projection-only (title/SOC/employment), Table 1.7 adds the worker-
   * characteristics columns (education, work experience, on-the-job
   * training) we need for the OOH Quick-Facts fields. Both parse as valid
   * headers, so "first match wins" picks the wrong one.
   *
   * Strategy: score every sheet's header by how many of our target columns
   * it maps, ingest the highest-scoring one. The five "rich" columns
   * (employment_change, outlook_percent, education_typical, work_experience,
   * on_job_training) each count for 1 — Table 1.7 wins by a large margin
   * over Table 1.1, regardless of sheet order.
   *
   * @param array<string, array> $out
   */
  private function stream(string $path, array &$out): void {
    $logger = $this->loggerFactory->get('programhub_careers');

    // Two-pass: pass 1 scores every sheet's header without ingesting; pass 2
    // re-opens the file and ingests just the winner. OpenSpout's reader can't
    // rewind, so we have to reopen. That's cheap — header detection only
    // needs the first ~30 rows of each sheet.
    [$bestSheetName, $bestRowIndex, $bestIdx, $bestPeriod, $bestHeaderCells] = $this->scoreSheets($path, $logger);
    if ($bestSheetName === NULL) {
      $logger->warning('EP file had no sheet with a recognizable header. Upload BLS "Occupation.xlsx" / "Occupational Projections and Worker Characteristics".');
      return;
    }
    $logger->notice('EP header matched on sheet "@s" row @r — mapped: @cols, period: @p', [
      '@s' => $bestSheetName,
      '@r' => $bestRowIndex,
      '@cols' => implode(', ', array_map(static fn($k, $v) => "$k=col$v", array_keys($bestIdx), $bestIdx)),
      '@p' => $bestPeriod ?? '(unresolved)',
    ]);
    // Dump every non-empty header cell so unmapped columns are visible —
    // makes it obvious when BLS uses a phrasing my matcher doesn't catch.
    $unmapped = [];
    $mappedCols = array_flip($bestIdx);
    foreach ($bestHeaderCells as $col => $text) {
      if (!isset($mappedCols[$col])) {
        $unmapped[] = sprintf('col%d="%s"', $col, $text);
      }
    }
    if ($unmapped) {
      $logger->notice('EP unmapped header cells (if a Quick-Facts field is missing, expand the matcher in EpLoader::resolveIndices to recognise one of these): @list', [
        '@list' => implode(' | ', $unmapped),
      ]);
    }

    $reader = new Reader();
    $reader->open($path);
    try {
      foreach ($reader->getSheetIterator() as $sheet) {
        $sheetName = method_exists($sheet, 'getName') ? $sheet->getName() : '(unnamed)';
        if ($sheetName !== $bestSheetName) {
          continue;
        }
        foreach ($sheet->getRowIterator() as $rowIndex => $row) {
          if ($rowIndex <= $bestRowIndex) {
            continue; // Skip up to and including the header row.
          }
          $this->capture($row->toArray(), $bestIdx, $bestPeriod, $out);
        }
        return;
      }
    }
    finally {
      $reader->close();
    }
  }

  /**
   * Walk every sheet and score the best candidate header.
   *
   * @return array{0:?string,1:int,2:?array<string,int>,3:?string,4:array<int,string>}
   *   [sheetName, rowIndex, idx, projectionPeriod, headerCells]. headerCells
   *   is the full row of non-empty cells (for the diagnostic dump).
   */
  private function scoreSheets(string $path, $logger): array {
    /** @var string[] $richCols Columns that distinguish Table 1.7 from 1.1. */
    $richCols = ['employment_change', 'outlook_percent', 'education_typical', 'work_experience', 'on_job_training'];

    $bestName = NULL;
    $bestRow = -1;
    $bestIdx = NULL;
    $bestPeriod = NULL;
    $bestHeader = [];
    $bestScore = -1;

    $reader = new Reader();
    $reader->open($path);
    try {
      foreach ($reader->getSheetIterator() as $sheet) {
        $sheetName = method_exists($sheet, 'getName') ? $sheet->getName() : '(unnamed)';
        $sniffed = [];
        $matched = FALSE;
        foreach ($sheet->getRowIterator() as $rowIndex => $row) {
          if ($rowIndex > 30) {
            break;
          }
          $cells = $row->toArray();
          if (count($sniffed) < 6 && array_filter($cells, static fn($v) => trim((string) $v) !== '')) {
            $sniffed[] = array_map(static fn($v) => trim((string) $v), $cells);
          }
          $candidate = $this->resolveIndices($cells);
          if ($candidate === NULL) {
            continue;
          }
          $score = count(array_intersect_key(array_flip($richCols), $candidate));
          if ($score > $bestScore) {
            $bestScore = $score;
            $bestName = $sheetName;
            $bestRow = $rowIndex;
            $bestIdx = $candidate;
            $bestPeriod = $this->inferPeriod($cells, $candidate);
            $bestHeader = [];
            foreach ($cells as $col => $v) {
              $text = trim((string) $v);
              if ($text !== '') {
                $bestHeader[$col] = preg_replace('/\s+/u', ' ', $text) ?? $text;
              }
            }
          }
          $matched = TRUE;
          break;
        }
        if (!$matched) {
          $logger->info('EP sheet "@s": no header row found in first 30 rows. Sniffed: @rows', [
            '@s' => $sheetName,
            '@rows' => json_encode($sniffed),
          ]);
        }
      }
    }
    finally {
      $reader->close();
    }
    return [$bestName, $bestRow, $bestIdx, $bestPeriod, $bestHeader];
  }

  /**
   * Try to interpret a row as the header. Returns the column-index map or
   * NULL if this row clearly isn't the header (no SOC column).
   *
   * Header detection is lenient — BLS phrases columns differently across
   * releases ("Numeric change, 2023–33" vs "Employment change, number" vs
   * "Change, numeric"). We match by combinations of substrings so a fresh
   * EP workbook drops in without code edits.
   *
   * Required to be considered a header at all: an SOC column. Employment +
   * change columns are strongly desired but the loader will still capture
   * SOC + characteristics-only rows if BLS ever splits the workbook.
   *
   * @param array<int, mixed> $row
   * @return array<string, int>|null
   */
  private function resolveIndices(array $row): ?array {
    // Lower-cased trimmed cell strings for substring matching. Collapse
    // run-of-whitespace so wrapped headers compare cleanly.
    $headers = [];
    foreach ($row as $i => $v) {
      if (is_string($v) || is_numeric($v)) {
        $h = strtolower(trim((string) $v));
        $h = preg_replace('/\s+/u', ' ', $h) ?? $h;
        if ($h !== '') {
          $headers[$i] = $h;
        }
      }
    }

    $idx = [];
    // Track every "Employment, YEAR" column we see so we can assign the
    // smaller year to current and the larger to projected — column order
    // varies across releases and shouldn't be load-bearing.
    /** @var array<int, array{col:int, year:int}> $employmentCols */
    $employmentCols = [];

    // Order matters: the change-column matchers come BEFORE the strict
    // "Employment, YEAR" matcher so headers like "Employment change,
    // numeric, 2024-34" and "Employment distribution, percent, 2024" claim
    // the right slot instead of being miscategorised as employment-year
    // columns by a too-loose regex. The strict employment regex below
    // requires the cell to be `Employment, YEAR` exactly (optionally
    // followed by a `[N]` footnote marker) — anything richer falls through.
    foreach ($headers as $i => $h) {
      if (!isset($idx['soc']) && $this->matchesSoc($h)) {
        $idx['soc'] = $i;
      }
      elseif (!isset($idx['title']) && $this->matchesTitle($h)) {
        $idx['title'] = $i;
      }
      elseif (!isset($idx['employment_change']) && $this->matchesNumericChange($h)) {
        $idx['employment_change'] = $i;
      }
      elseif (!isset($idx['outlook_percent']) && $this->matchesPercentChange($h)) {
        $idx['outlook_percent'] = $i;
      }
      elseif (preg_match('/^employment,\s*(20\d{2})\s*(?:\[\d+\])?\s*$/u', $h, $m)) {
        $employmentCols[] = ['col' => $i, 'year' => (int) $m[1]];
      }
      elseif (!isset($idx['education_typical']) && str_contains($h, 'education') && !str_contains($h, 'attainment')) {
        // "Typical entry-level education" OR "Typical education needed for
        // entry". Excludes "Educational attainment" tables.
        $idx['education_typical'] = $i;
      }
      elseif (!isset($idx['work_experience']) && str_contains($h, 'work experience')) {
        $idx['work_experience'] = $i;
      }
      elseif (!isset($idx['on_job_training']) && (str_contains($h, 'on-the-job training') || str_contains($h, 'on the job training'))) {
        $idx['on_job_training'] = $i;
      }
    }

    // Sort employment columns by year and assign smallest → current, next →
    // projected. If there's only one, treat it as current. More than two is
    // unexpected (workbook layout change) — log it and take the lowest +
    // highest.
    if ($employmentCols) {
      usort($employmentCols, static fn($a, $b) => $a['year'] <=> $b['year']);
      $idx['employment_current'] = $employmentCols[0]['col'];
      if (count($employmentCols) >= 2) {
        $idx['employment_projected'] = end($employmentCols)['col'];
      }
    }

    // Header requires at least an SOC column. Without it we can't key data.
    return isset($idx['soc']) ? $idx : NULL;
  }

  private function matchesSoc(string $h): bool {
    // "SOC code", "Occupation code", "2023 National Employment Matrix code".
    // Avoid matching "Occupational projections code" or anything not
    // identifying a SOC. Loose check: contains "code" + (matrix|soc|occupation).
    if (!str_contains($h, 'code')) {
      return FALSE;
    }
    return str_contains($h, 'matrix')
      || str_contains($h, 'soc')
      || str_contains($h, 'occupation');
  }

  private function matchesTitle(string $h): bool {
    return str_contains($h, 'occupation title')
      || str_contains($h, 'matrix title')
      || $h === 'title';
  }

  private function matchesNumericChange(string $h): bool {
    // "Numeric change, 2023-33", "Employment change, numeric, 2023-33",
    // "Change, number". All include both "change" and a number-ish word.
    if (!str_contains($h, 'change')) {
      return FALSE;
    }
    if (str_contains($h, 'percent') || str_contains($h, '%')) {
      return FALSE; // Don't grab percent here.
    }
    return str_contains($h, 'numeric')
      || str_contains($h, 'number');
  }

  private function matchesPercentChange(string $h): bool {
    if (!str_contains($h, 'change')) {
      return FALSE;
    }
    return str_contains($h, 'percent') || str_contains($h, '%');
  }

  /**
   * Extract a "2023-33"-style projection-period string from the header.
   *
   * Two extraction paths:
   *  1. Direct: a change/percent header like "Numeric change, 2023-33"
   *     contains the period inline.
   *  2. Inferred: when there's no change column (e.g. Table 1.1),
   *     reconstruct from the two "Employment, YEAR" headers we already
   *     identified — e.g. "Employment, 2023" + "Employment, 2033" → 2023-33.
   *
   * @param array<int, mixed> $headerRow
   * @param array<string, int> $idx
   */
  private function inferPeriod(array $headerRow, array $idx): ?string {
    foreach (['employment_change', 'outlook_percent'] as $key) {
      $col = $idx[$key] ?? NULL;
      $cell = $col !== NULL ? ($headerRow[$col] ?? NULL) : NULL;
      if (is_string($cell) && preg_match('/(20\d{2})\s*[–\-]\s*(\d{2,4})/u', $cell, $m)) {
        return $m[1] . '-' . substr($m[2], -2);
      }
    }
    // Fall back to the two employment-year columns.
    $currentCol = $idx['employment_current'] ?? NULL;
    $projectedCol = $idx['employment_projected'] ?? NULL;
    if ($currentCol === NULL || $projectedCol === NULL) {
      return NULL;
    }
    $currentYear = $this->yearFromHeader((string) ($headerRow[$currentCol] ?? ''));
    $projectedYear = $this->yearFromHeader((string) ($headerRow[$projectedCol] ?? ''));
    if ($currentYear !== NULL && $projectedYear !== NULL) {
      return $currentYear . '-' . substr((string) $projectedYear, -2);
    }
    return NULL;
  }

  private function yearFromHeader(string $h): ?int {
    return preg_match('/(20\d{2})/u', $h, $m) ? (int) $m[1] : NULL;
  }

  /**
   * Pull one data row into the output map.
   *
   * @param array<int, mixed> $row
   * @param array<string, int> $idx
   * @param array<string, array> $out
   */
  private function capture(array $row, array $idx, ?string $period, array &$out): void {
    $soc = $this->normalizeSoc($row[$idx['soc']] ?? '');
    if ($soc === '' || $soc === '00-0000') {
      return;
    }
    if (!preg_match('/^\d{2}-\d{4}$/', $soc)) {
      // Aggregate / parent rows (e.g. major group "11-0000") and footers.
      return;
    }

    // Pull each optional column safely — `??` on the array access alone
    // doesn't suppress the inner $idx[...] read on PHP 8.1+. Resolve the
    // column index to NULL once, then short-circuit if absent.
    $cell = static fn(?int $col) => $col !== NULL ? ($row[$col] ?? NULL) : NULL;

    $employmentCurrent = $this->parseInt($cell($idx['employment_current'] ?? NULL));
    $employmentProjected = $this->parseInt($cell($idx['employment_projected'] ?? NULL));
    $employmentChange = $this->parseInt($cell($idx['employment_change'] ?? NULL));
    $outlookPercent = $this->parseDecimal($cell($idx['outlook_percent'] ?? NULL));

    $out[$soc] = [
      'title' => trim((string) ($cell($idx['title'] ?? NULL) ?? '')),
      // BLS publishes employment in thousands. Multiply up so the field
      // matches the "Number of Jobs" the OOH headline shows. parseInt
      // handles the comma stripping.
      'employment_current' => $employmentCurrent !== NULL ? $employmentCurrent * 1000 : NULL,
      'employment_projected' => $employmentProjected !== NULL ? $employmentProjected * 1000 : NULL,
      'employment_change' => $employmentChange !== NULL ? $employmentChange * 1000 : NULL,
      'outlook_percent' => $outlookPercent,
      'education_typical' => $this->stringOrNull($cell($idx['education_typical'] ?? NULL)),
      'work_experience' => $this->stringOrNull($cell($idx['work_experience'] ?? NULL)),
      'on_job_training' => $this->stringOrNull($cell($idx['on_job_training'] ?? NULL)),
      'projection_period' => $period,
    ];
  }

  /**
   * Normalize an SOC cell value. BLS sometimes stores codes without the
   * hyphen ("119032" → "11-9032"). We coerce both forms so callers can key
   * on the canonical hyphenated shape used by OEWS / O*NET.
   */
  private function normalizeSoc(mixed $v): string {
    $s = trim((string) $v);
    if ($s === '') {
      return '';
    }
    if (preg_match('/^(\d{2})-?(\d{4})$/', $s, $m)) {
      return $m[1] . '-' . $m[2];
    }
    return $s;
  }

  private function parseInt(mixed $v): ?int {
    if ($v === NULL) {
      return NULL;
    }
    $s = trim(str_replace([',', '$'], '', (string) $v));
    if (in_array($s, self::NULLISH, TRUE)) {
      return NULL;
    }
    return is_numeric($s) ? (int) $s : NULL;
  }

  private function parseDecimal(mixed $v): ?float {
    if ($v === NULL) {
      return NULL;
    }
    $s = trim(str_replace([',', '$', '%'], '', (string) $v));
    if (in_array($s, self::NULLISH, TRUE)) {
      return NULL;
    }
    return is_numeric($s) ? (float) $s : NULL;
  }

  private function stringOrNull(mixed $v): ?string {
    $s = trim((string) ($v ?? ''));
    if ($s === '' || in_array($s, self::NULLISH, TRUE)) {
      return NULL;
    }
    return $s;
  }

  /**
   * Translate a percent-change value into the canonical BLS outlook label.
   *
   * BLS Occupational Outlook Handbook bands (per their methodology page):
   *   ≥ 8%        Much faster than average
   *    5–7%       Faster than average
   *    3–4%       As fast as average
   *   -1 to 2%    Slower than average
   *   ≤ -2%       Decline
   *
   * Public so callers (importer, tests) can derive consistently without
   * embedding the table everywhere.
   */
  public static function outlookLabel(?float $percent): ?string {
    if ($percent === NULL) {
      return NULL;
    }
    return match (TRUE) {
      $percent >= 8.0 => 'Much faster than average',
      $percent >= 5.0 => 'Faster than average',
      $percent >= 3.0 => 'As fast as average',
      $percent >= -1.0 => 'Slower than average',
      default => 'Decline',
    };
  }

}
