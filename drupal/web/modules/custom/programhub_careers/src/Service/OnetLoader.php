<?php

namespace Drupal\programhub_careers\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Parses the O*NET Task Statements TSV from a fixed cache path.
 *
 * The expected file is:
 *   private://programhub_careers/onet_tasks.tsv
 *
 * Editors upload it via the admin form. Source download:
 *   https://www.onetcenter.org/database.html
 *   → "Download O*NET database files" → "Tab-delimited text" → Tasks
 *
 * The file is small (~6 MB) and streams in seconds; we don't bother chunking.
 */
class OnetLoader {

  public const CANONICAL_PATH = 'private://programhub_careers/onet_tasks.tsv';

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

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
        'O*NET tasks TSV not found. Upload it on the careers refresh page.'
      );
    }
    return $path;
  }

  /**
   * Load and return SOC → list of task strings.
   *
   * @return array<string, string[]>
   */
  public function load(): array {
    $path = $this->realPathOrThrow();
    $fh = fopen($path, 'r');
    if (!$fh) {
      throw new \RuntimeException("Could not open $path");
    }
    try {
      $header = fgetcsv($fh, 0, "\t");
      if (!$header) {
        return [];
      }
      $idx = array_flip(array_map('trim', $header));
      // Some releases use BOM-prefixed column names; guard.
      $socIdx = $idx['O*NET-SOC Code'] ?? $idx["\u{FEFF}O*NET-SOC Code"] ?? NULL;
      $taskIdx = $idx['Task'] ?? NULL;
      if ($socIdx === NULL || $taskIdx === NULL) {
        throw new \RuntimeException('O*NET tasks file missing expected columns — is this the Task Statements TSV?');
      }

      $out = [];
      while (($row = fgetcsv($fh, 0, "\t")) !== FALSE) {
        $onetSoc = trim((string) ($row[$socIdx] ?? ''));
        $task = trim((string) ($row[$taskIdx] ?? ''));
        if ($onetSoc === '' || $task === '') {
          continue;
        }
        // "15-1212.00" → "15-1212". Top-level SOC matches BLS.
        $soc = preg_replace('/\.\d+$/', '', $onetSoc);
        $out[$soc][] = $task;
      }
      $this->loggerFactory->get('programhub_careers')->info(
        'O*NET tasks parsed: @count SOCs',
        ['@count' => count($out)],
      );
      return $out;
    }
    finally {
      fclose($fh);
    }
  }

}
