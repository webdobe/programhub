<?php

declare(strict_types=1);

namespace Drupal\programhub_course_import\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Fetches and parses NIC catalog course-description pages.
 *
 * Catalog URL pattern: https://catalog.nic.edu/course-descriptions/<prefix>/
 * Each course is rendered inside <div class="courseblock"> with:
 *   <p class="courseblocktitle">PREFIX-NUMBER Title<br/><em>N Credits</em></p>
 *   <p class="courseblockextra"> ... metadata ...
 *   <p class="courseblockdesc">description...</p>
 */
final class CatalogScraper {

  public const BASE_URL = 'https://catalog.nic.edu/course-descriptions/';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Build the catalog URL for a given prefix.
   */
  public static function urlForPrefix(string $prefix): string {
    return self::BASE_URL . strtolower(trim($prefix)) . '/';
  }

  /**
   * Fetch and parse all courses for a prefix.
   *
   * @return array<int, array{number:string,title:string,credits:?int,description:string,offering:string,semesters:array<int, string>,yearCycle:?string,prereqs:array<int,string>,recPrereqs:array<int,string>}>
   *   List of parsed course rows. Empty if the page returned non-200 or had no
   *   courseblocks.
   */
  public function fetchCourses(string $prefix): array {
    $url = self::urlForPrefix($prefix);
    $logger = $this->loggerFactory->get('programhub_course_import');

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'User-Agent' => 'ProgramHub/1.0 (course-import; +https://programhub)',
          'Accept' => 'text/html',
        ],
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $e) {
      $logger->error('Catalog fetch failed for @url: @msg', [
        '@url' => $url,
        '@msg' => $e->getMessage(),
      ]);
      return [];
    }

    if ($response->getStatusCode() !== 200) {
      $logger->error('Catalog fetch non-200 for @url: @code', [
        '@url' => $url,
        '@code' => $response->getStatusCode(),
      ]);
      return [];
    }

    $html = (string) $response->getBody();
    return $this->parseCourses($html);
  }

  /**
   * Extract courses from a catalog HTML string.
   *
   * Public for testing.
   *
   * @return array<int, array{number:string,title:string,credits:?int,description:string,offering:string,semesters:array<int, string>,yearCycle:?string,prereqs:array<int,string>,recPrereqs:array<int,string>}>
   */
  public function parseCourses(string $html): array {
    if (trim($html) === '') {
      return [];
    }

    $dom = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    // Force UTF-8 interpretation; the catalog pages declare it explicitly.
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new \DOMXPath($dom);
    $blocks = $xpath->query("//div[contains(concat(' ', normalize-space(@class), ' '), ' courseblock ')]");
    $courses = [];

    foreach ($blocks as $block) {
      $course = $this->parseBlock($xpath, $block);
      if ($course !== NULL) {
        $courses[] = $course;
      }
    }

    return $courses;
  }

  /**
   * @return array{number:string,title:string,credits:?int,description:string,offering:string,semesters:array<int, string>,yearCycle:?string,prereqs:array<int,string>,recPrereqs:array<int,string>}|null
   */
  private function parseBlock(\DOMXPath $xpath, \DOMNode $block): ?array {
    $titleNode = $xpath->query(".//p[contains(concat(' ', normalize-space(@class), ' '), ' courseblocktitle ')]", $block)->item(0);
    if ($titleNode === NULL) {
      return NULL;
    }

    // Title <p> looks like: <strong>CITE-104 Systems Administration I<br/>
    //   <em>3 Credits</em></strong>
    // Pull the credits out of <em>, then strip <em> and we have title-with-number.
    $creditsText = '';
    $emNodes = $xpath->query('.//em', $titleNode);
    if ($emNodes->length > 0) {
      $creditsText = trim($emNodes->item(0)->textContent);
      $emNodes->item(0)->parentNode?->removeChild($emNodes->item(0));
    }

    $titleText = trim(preg_replace('/\s+/', ' ', $titleNode->textContent ?? ''));
    if ($titleText === '') {
      return NULL;
    }

    // Extract bold-prefixed metadata from `.courseblockextra` paragraphs:
    //   <strong>Offering:</strong> Spring Only, All Years
    //   <strong>Corequisites:</strong> CITE-105
    //   <strong>Recommended Prerequisites:</strong> CITE-118 and CITE-119
    $extras = $this->extractExtras($xpath, $block);

    $offeringText = is_string($extras['offering'] ?? NULL) ? $extras['offering'] : '';
    $offering = $this->parseOffering($offeringText);

    $base = [
      'credits' => $this->parseCredits($creditsText),
      'description' => $this->extractDescription($xpath, $block),
      'offering' => $offeringText,
      'semesters' => $offering['semesters'],
      'yearCycle' => $offering['yearCycle'],
      'prereqs' => $this->mergeNumbers($extras, ['prerequisites', 'corequisites']),
      'recPrereqs' => $this->mergeNumbers($extras, [
        'recommended prerequisites',
        'recommended pre/corequisites',
        'recommended corequisites',
      ]),
    ];

    // Match "PREFIX-NUMBER " optionally with trailing letter (e.g. CITE-104A)
    if (!preg_match('/^([A-Z][A-Z]+[- ]\d+[A-Z]?)\s+(.+)$/u', $titleText, $m)) {
      return [
        'number' => '',
        'title' => $titleText,
      ] + $base;
    }

    return [
      // Normalize to "PREFIX-NUMBER".
      'number' => str_replace(' ', '-', $m[1]),
      'title' => trim($m[2]),
    ] + $base;
  }

  /**
   * Pull every `<strong>Label:</strong> value` row out of the courseblockextra
   * paragraphs, keyed by lower-cased label.
   *
   * For prereq-style labels the value is an array of normalized course numbers
   * (e.g. ["CITE-105", "CITE-116"]); other labels keep their plain-text value.
   *
   * @return array<string, string|array<int,string>>
   */
  private function extractExtras(\DOMXPath $xpath, \DOMNode $block): array {
    $extras = [];
    $nodes = $xpath->query(".//p[contains(concat(' ', normalize-space(@class), ' '), ' courseblockextra ')]", $block);
    foreach ($nodes as $p) {
      $strong = $xpath->query('.//strong', $p)->item(0);
      if ($strong === NULL) {
        continue;
      }
      $label = trim(rtrim(preg_replace('/\s+/', ' ', $strong->textContent ?? ''), ':'));
      if ($label === '') {
        continue;
      }
      $key = strtolower($label);

      // Prereq-style labels: extract referenced course numbers.
      if ($this->isReferenceLabel($key)) {
        $extras[$key] = $this->extractCourseNumbers($xpath, $p);
        continue;
      }

      // Plain text labels (Offering, Lecture, Lab, etc.).
      $full = trim(preg_replace('/\s+/', ' ', $p->textContent ?? ''));
      $value = preg_replace('/^' . preg_quote($label . ':', '/') . '\s*/u', '', $full);
      $value = trim($value ?? '');
      if ($value !== '') {
        $extras[$key] = $value;
      }
    }
    return $extras;
  }

  private function isReferenceLabel(string $key): bool {
    return in_array($key, [
      'prerequisites',
      'corequisites',
      'recommended prerequisites',
      'recommended corequisites',
      'recommended pre/corequisites',
    ], TRUE);
  }

  /**
   * Pull normalized course numbers ("CITE-105") from anchors in a paragraph,
   * falling back to a regex over the text content for un-linked references.
   *
   * @return array<int, string>
   */
  private function extractCourseNumbers(\DOMXPath $xpath, \DOMNode $paragraph): array {
    $numbers = [];

    // Anchor links are the most reliable source — text content is the number.
    foreach ($xpath->query('.//a', $paragraph) as $anchor) {
      $text = trim($anchor->textContent ?? '');
      if (preg_match('/^([A-Z]{2,5})[- ](\d+[A-Z]?)$/u', $text, $m)) {
        $numbers[strtoupper($m[1]) . '-' . $m[2]] = TRUE;
      }
    }

    // Fallback: scan the full text — catches un-linked numbers like "MATH 100".
    $text = $paragraph->textContent ?? '';
    if (preg_match_all('/\b([A-Z]{2,5})[- ](\d+[A-Z]?)\b/u', $text, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $m) {
        $numbers[strtoupper($m[1]) . '-' . $m[2]] = TRUE;
      }
    }

    return array_keys($numbers);
  }

  /**
   * Merge course-number lists across several extras keys (e.g. prereqs +
   * corequisites both go into the strict-required field).
   *
   * @param array<string, string|array<int,string>> $extras
   * @param array<int, string> $labels
   * @return array<int, string>
   */
  private function mergeNumbers(array $extras, array $labels): array {
    $set = [];
    foreach ($labels as $key) {
      $value = $extras[$key] ?? NULL;
      if (is_array($value)) {
        foreach ($value as $num) {
          $set[$num] = TRUE;
        }
      }
    }
    return array_keys($set);
  }

  /**
   * Split a catalog "Offering" string into semester tags + year-cycle key.
   *
   * Examples:
   *   "Spring Only, All Years"            → semesters=[Spring]      yearCycle=all
   *   "Fall and Spring Only, All Years"   → semesters=[Fall,Spring] yearCycle=all
   *   "Fall Only, Even Years"             → semesters=[Fall]        yearCycle=even
   *
   * Unrecognized inputs return empty arrays / NULL.
   *
   * @return array{semesters: array<int,string>, yearCycle: ?string}
   */
  public function parseOffering(string $text): array {
    $result = ['semesters' => [], 'yearCycle' => NULL];
    if ($text === '') {
      return $result;
    }

    $semesterTokens = ['Fall', 'Spring', 'Summer', 'Winter'];
    $semesters = [];
    foreach ($semesterTokens as $token) {
      if (preg_match('/\b' . preg_quote($token, '/') . '\b/i', $text)) {
        $semesters[$token] = TRUE;
      }
    }
    $result['semesters'] = array_keys($semesters);

    if (preg_match('/\beven\s+years?\b/i', $text)) {
      $result['yearCycle'] = 'even';
    }
    elseif (preg_match('/\bodd\s+years?\b/i', $text)) {
      $result['yearCycle'] = 'odd';
    }
    elseif (preg_match('/\ball\s+years?\b/i', $text)) {
      $result['yearCycle'] = 'all';
    }

    return $result;
  }

  private function parseCredits(string $text): ?int {
    if (preg_match('/(\d+)\s*Credit/i', $text, $m)) {
      return (int) $m[1];
    }
    return NULL;
  }

  private function extractDescription(\DOMXPath $xpath, \DOMNode $block): string {
    $descNode = $xpath->query(".//p[contains(concat(' ', normalize-space(@class), ' '), ' courseblockdesc ')]", $block)->item(0);
    if ($descNode === NULL) {
      return '';
    }
    return trim(preg_replace('/\s+/', ' ', $descNode->textContent ?? ''));
  }

}
