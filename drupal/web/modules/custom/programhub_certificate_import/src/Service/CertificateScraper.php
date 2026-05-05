<?php

declare(strict_types=1);

namespace Drupal\programhub_certificate_import\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Fetches and parses NIC catalog program-guidelines pages.
 *
 * Catalog URL pattern: https://catalog.nic.edu/program-guidelines/<slug>/
 *
 * Pages are organized into three tabbed containers we care about:
 *   #textcontainer            — overview / description
 *   #requirementstextcontainer — Plan of Study Grid (semester-grouped courses)
 *   #outcomestextcontainer    — Program Outcomes (intro + ordered list)
 */
final class CertificateScraper {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Fetch and parse the certificate page at $url.
   *
   * @return array{
   *   title:string,
   *   overviewHtml:string,
   *   outcomesHtml:string,
   *   requirementsHtml:string,
   *   totalCredits:?int,
   *   courses:array<int, array{number:string, semester:?int}>,
   * }|null
   *   NULL if fetch failed or page didn't look like a program-guidelines page.
   */
  public function scrape(string $url): ?array {
    $logger = $this->loggerFactory->get('programhub_certificate_import');

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'User-Agent' => 'ProgramHub/1.0 (certificate-import; +https://programhub)',
          'Accept' => 'text/html',
        ],
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $e) {
      $logger->error('Certificate fetch failed for @url: @msg', [
        '@url' => $url,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }

    if ($response->getStatusCode() !== 200) {
      $logger->error('Certificate fetch non-200 for @url: @code', [
        '@url' => $url,
        '@code' => $response->getStatusCode(),
      ]);
      return NULL;
    }

    return $this->parse((string) $response->getBody());
  }

  /**
   * Public for testing — feed in raw HTML and get back the parsed shape.
   */
  public function parse(string $html): ?array {
    if (trim($html) === '') {
      return NULL;
    }

    $dom = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new \DOMXPath($dom);

    $title = $this->extractTitle($xpath);

    $overviewHtml = $this->innerHtmlOfContainer($dom, $xpath, 'textcontainer');
    $outcomesHtml = $this->innerHtmlOfContainer($dom, $xpath, 'outcomestextcontainer');
    $requirementsHtml = $this->innerHtmlOfContainer($dom, $xpath, 'requirementstextcontainer');

    if ($overviewHtml === '' && $requirementsHtml === '' && $outcomesHtml === '') {
      // Doesn't look like a program-guidelines page.
      return NULL;
    }

    [$courses, $totalCredits] = $this->parseRequirements($xpath);

    return [
      'title' => $title,
      'overviewHtml' => $overviewHtml,
      'outcomesHtml' => $outcomesHtml,
      'requirementsHtml' => $requirementsHtml,
      'totalCredits' => $totalCredits,
      'courses' => $courses,
    ];
  }

  private function extractTitle(\DOMXPath $xpath): string {
    $node = $xpath->query("//h1[contains(concat(' ', normalize-space(@class), ' '), ' page-title ')]")->item(0);
    if ($node === NULL) {
      return '';
    }
    return trim(preg_replace('/\s+/', ' ', $node->textContent ?? '') ?? '');
  }

  /**
   * Return the inner HTML of an element whose id is $id, with any leading
   * <h2>/named-anchor stripped (those are tab labels we don't want in body
   * content). Returns empty string if not found.
   */
  private function innerHtmlOfContainer(\DOMDocument $dom, \DOMXPath $xpath, string $id): string {
    $container = $xpath->query("//*[@id='" . $id . "']")->item(0);
    if ($container === NULL) {
      return '';
    }

    $html = '';
    foreach ($container->childNodes as $child) {
      // Skip the <a name="..."></a> + <h2> tab label header.
      if ($child instanceof \DOMElement) {
        $tag = strtolower($child->tagName);
        if ($tag === 'a' && $child->getAttribute('name') !== '') {
          continue;
        }
        if ($tag === 'h2') {
          continue;
        }
      }
      $html .= $dom->saveHTML($child);
    }

    return trim($html);
  }

  /**
   * Walk the Plan of Study table, capturing course rows under their semester
   * heading and the trailing "Total Credits / Total Hours" row if present.
   *
   * @return array{0: array<int, array{number:string, semester:?int}>, 1: ?int}
   */
  private function parseRequirements(\DOMXPath $xpath): array {
    $courses = [];
    $totalCredits = NULL;

    $rows = $xpath->query("//table[contains(concat(' ', normalize-space(@class), ' '), ' sc_plangrid ')]//tr");
    if ($rows === FALSE) {
      return [$courses, $totalCredits];
    }

    $currentSemester = NULL;
    $seenNumbers = [];
    foreach ($rows as $tr) {
      $classAttr = $tr instanceof \DOMElement ? $tr->getAttribute('class') : '';

      // Semester heading row: <tr class="plangridterm">…<th>Semester N</th>
      if (str_contains(' ' . $classAttr . ' ', ' plangridterm ')) {
        $currentSemester = $this->parseSemester($tr->textContent ?? '');
        continue;
      }

      // Total Credits/Hours summary row.
      if (str_contains(' ' . $classAttr . ' ', ' plangridtotal ')
          || stripos($tr->textContent ?? '', 'Total Credits') !== FALSE
          || stripos($tr->textContent ?? '', 'Total Hours') !== FALSE) {
        $maybe = $this->parseTotalCredits($tr->textContent ?? '');
        if ($maybe !== NULL) {
          $totalCredits = $maybe;
        }
        continue;
      }

      // Skip "Select one of the following:" comment rows entirely — we still
      // pick up the indented courses below them as ordinary rows because the
      // <a> matching catches them on subsequent iterations.

      // Pull every course-number anchor found in this row. Multiple anchors
      // can appear (e.g. cross-listed options); each gets its own paragraph
      // entry under the current semester.
      $anchors = $xpath->query('.//a', $tr);
      foreach ($anchors as $a) {
        $text = trim($a->textContent ?? '');
        if (preg_match('/^([A-Z]{2,5})[- ](\d+[A-Z]?)$/u', $text, $m)) {
          $number = strtoupper($m[1]) . '-' . $m[2];
          $key = $number . '|' . ($currentSemester ?? '');
          if (isset($seenNumbers[$key])) {
            // Avoid duplicate within the same row/semester.
            continue;
          }
          $seenNumbers[$key] = TRUE;
          $courses[] = [
            'number' => $number,
            'semester' => $currentSemester,
          ];
        }
      }
    }

    return [$courses, $totalCredits];
  }

  private function parseSemester(string $text): ?int {
    if (preg_match('/Semester\s+(\d+)/iu', $text, $m)) {
      return (int) $m[1];
    }
    return NULL;
  }

  private function parseTotalCredits(string $text): ?int {
    // Catalog row textContent collapses cell whitespace, so the value can
    // be either "Total Credits 30" or "Total Credits30" or "Total Credits56-60".
    // Capture the first integer that follows; ranges (56-60) yield the lower bound.
    if (preg_match('/Total\s+(?:Credits?|Hours?)\s*[:\s]?\s*(\d+)/iu', $text, $m)) {
      return (int) $m[1];
    }
    return NULL;
  }

}
