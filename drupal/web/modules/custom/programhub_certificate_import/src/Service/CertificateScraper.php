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
   * Each row in `courses` is a single requirement slot. Exactly one of
   * `number` / `categoryLabel` is set per row:
   *
   *   - `number` set, `categoryLabel` null:
   *       a normal course requirement (e.g. "BOAA-110").
   *       `alternativeNote` may carry inline "or X" text the catalog mentions
   *       without a course code.
   *
   *   - `number` null, `categoryLabel` set:
   *       a category placeholder ("GEM 3 — Mathematical Ways of Knowing").
   *       The importer resolves this to a `gen_ed_category` taxonomy term.
   *
   * @return array{
   *   title:string,
   *   typeAbbr:?string,
   *   overview:string,
   *   outcomesHtml:string,
   *   totalCredits:?int,
   *   courses:array<int, array{
   *     number:?string,
   *     semester:?int,
   *     credits:string,
   *     alternativeNote:?string,
   *     categoryLabel:?string,
   *   }>,
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
    $typeAbbr = $this->extractTypeAbbr($title);

    $overview = $this->extractProgramDescriptionText($xpath);
    $outcomesHtml = $this->innerHtmlOfContainer($dom, $xpath, 'outcomestextcontainer');

    if ($overview === '' && $outcomesHtml === '') {
      // Doesn't look like a program-guidelines page.
      return NULL;
    }

    [$courses, $totalCredits] = $this->parseRequirements($xpath);

    return [
      'title' => $title,
      'typeAbbr' => $typeAbbr,
      'overview' => $overview,
      'outcomesHtml' => $outcomesHtml,
      'totalCredits' => $totalCredits,
      'courses' => $courses,
    ];
  }

  /**
   * Pull the parenthesised credential abbreviation out of "Foo Bar (XYZ)".
   * Returns NULL if no parens found.
   */
  private function extractTypeAbbr(string $title): ?string {
    if (preg_match('/\(([A-Z]{2,5})\)\s*$/u', $title, $m)) {
      return $m[1];
    }
    return NULL;
  }

  /**
   * Plain-text overview from #programdescriptions, paragraph-by-paragraph.
   *
   * DOM structure on catalog.nic.edu places the prose `<p>` elements inside
   * `#programdescriptions`, while `#requirementstextcontainer` and
   * `#outcomestextcontainer` are siblings of the wrapping `#textcontainer`
   * (i.e. not descendants of `#programdescriptions`) — so a plain `//p`
   * query under #programdescriptions safely catches only the overview prose.
   *
   * The catalog sometimes wraps `<p>` inside another `<p>`; DOMDocument
   * normalizes those into split siblings, so we just iterate and skip empty
   * results. Each paragraph is run through `cleanText()` (entity decode +
   * whitespace collapse) and joined with `\n\n`.
   */
  private function extractProgramDescriptionText(\DOMXPath $xpath): string {
    $ps = $xpath->query("//*[@id='programdescriptions']//p");
    if ($ps === FALSE) {
      return '';
    }

    $paragraphs = [];
    foreach ($ps as $p) {
      // Skip <p> elements that contain another <p> — the inner ones surface
      // in their own iteration (DOM may or may not nest them depending on
      // the parser's quirks-mode handling of `<p><p>...</p></p>`).
      if ($xpath->query('.//p', $p)->length > 0) {
        continue;
      }
      $text = $this->cleanText($p->textContent ?? '');
      if ($text !== '') {
        $paragraphs[] = $text;
      }
    }

    return implode("\n\n", $paragraphs);
  }

  /**
   * Decode HTML entities and collapse runs of whitespace to a single space.
   * Used for paragraph-level plain-text extraction.
   */
  private function cleanText(string $text): string {
    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $collapsed = preg_replace('/\s+/u', ' ', $decoded);
    return trim($collapsed ?? '');
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
   * Walk the requirements table, capturing course rows and the trailing
   * "Total Credits / Total Hours" row if present.
   *
   * Two table layouts in the wild:
   *
   *  - `sc_plangrid` — degree programs (AAS, AS): rows are grouped by
   *    `tr.plangridterm` semester headers, last row is `tr.plangridtotal`.
   *  - `sc_courselist` — basic/intermediate/advanced technical certificates
   *    (BTC/ITC/ATC): flat list, no semester groupings, total row is
   *    `tr.listsum`.
   *
   * We try plangrid first, then fall back to courselist when none found.
   * No semester is reported for courselist rows.
   *
   * @return array{0: array<int, array{number:string, semester:?int, credits:string}>, 1: ?int}
   */
  private function parseRequirements(\DOMXPath $xpath): array {
    [$courses, $totalCredits] = $this->parsePlangrid($xpath);
    if (!$courses) {
      [$courses, $totalCredits] = $this->parseCourselist($xpath);
    }
    return [$courses, $totalCredits];
  }

  /**
   * @return array{0: array<int, array{number:?string, semester:?int, credits:string, alternativeNote:?string, categoryLabel:?string}>, 1: ?int}
   */
  private function parsePlangrid(\DOMXPath $xpath): array {
    $courses = [];
    $totalCredits = NULL;

    $rows = $xpath->query("//table[contains(concat(' ', normalize-space(@class), ' '), ' sc_plangrid ')]//tr");
    if ($rows === FALSE) {
      return [$courses, $totalCredits];
    }

    $currentSemester = NULL;
    $pendingCredits = '';
    $seenNumbers = [];
    foreach ($rows as $tr) {
      $classAttr = $tr instanceof \DOMElement ? $tr->getAttribute('class') : '';

      // Semester heading row: <tr class="plangridterm">…<th>Semester N</th>
      if (str_contains(' ' . $classAttr . ' ', ' plangridterm ')) {
        $currentSemester = $this->parseSemester($tr->textContent ?? '');
        $pendingCredits = '';
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

      // Credits cell: usually the last <td class="hourscol">. Empty string if
      // the row doesn't carry its own credits — e.g. an indented option under
      // a "Select one of the following: 3-5" header. In that case, an earlier
      // row supplied the credits in $pendingCredits.
      $rowCredits = $this->extractRowCredits($xpath, $tr);

      // Comment row: either "Select one of the following:" (no anchor, has
      // credits to pass to the next indented row) or a category placeholder
      // like "GEM 3 - Mathematical Ways of Knowing" (carries a category label
      // and its own credits). Distinguishable by the comment text shape.
      $isCommentRow = $xpath->query(".//span[contains(concat(' ', normalize-space(@class), ' '), ' comment ')]", $tr)->length > 0;
      if ($isCommentRow) {
        $commentText = $this->cleanText($tr->textContent ?? '');
        $categoryLabel = $this->extractCategoryLabel($commentText);
        if ($categoryLabel !== NULL) {
          // First-class category requirement — emit a row with no course code.
          $courses[] = [
            'number' => NULL,
            'semester' => $currentSemester,
            'credits' => $rowCredits !== '' ? $rowCredits : $pendingCredits,
            'alternativeNote' => NULL,
            'categoryLabel' => $categoryLabel,
          ];
          $pendingCredits = '';
          continue;
        }
        // Plain "Select one of the following:" — stash credits for next row.
        if ($rowCredits !== '') {
          $pendingCredits = $rowCredits;
        }
        continue;
      }

      // Pull every course-number anchor found in this row.
      $anchors = $xpath->query('.//a', $tr);
      $alternativeNote = $this->extractInlineAlternativeNote($xpath, $tr);
      $foundAnchorInRow = FALSE;
      foreach ($anchors as $a) {
        $text = trim($a->textContent ?? '');
        if (preg_match('/^([A-Z]{2,5})[- ](\d+[A-Z]?)$/u', $text, $m)) {
          $foundAnchorInRow = TRUE;
          $number = strtoupper($m[1]) . '-' . $m[2];
          $key = $number . '|' . ($currentSemester ?? '');
          if (isset($seenNumbers[$key])) {
            continue;
          }
          $seenNumbers[$key] = TRUE;
          $credits = $rowCredits !== '' ? $rowCredits : $pendingCredits;
          $courses[] = [
            'number' => $number,
            'semester' => $currentSemester,
            'credits' => $credits,
            // Only attach to the first anchor in the row — subsequent anchors
            // (rare) get the same note via their own row, not this one.
            'alternativeNote' => $alternativeNote,
            'categoryLabel' => NULL,
          ];
          $alternativeNote = NULL;
        }
      }

      // Once a real anchor row has consumed the pending credits, clear it so
      // it doesn't bleed into unrelated rows further down.
      if ($foundAnchorInRow) {
        $pendingCredits = '';
      }
    }

    return [$courses, $totalCredits];
  }

  /**
   * Flat-list course table (`sc_courselist`) used by BTC/ITC/ATC certs.
   *
   * Each `<tbody><tr>` is either:
   *   - a course row: `td.codecol > a` carries "BLDR-132", `td.hourscol` the
   *     credits, middle `<td>` the title (ignored — we keep the course node
   *     as the source of truth for titles)
   *   - a totals row (`tr.listsum`): `td.hourscol` holds the total
   *
   * @return array{0: array<int, array{number:?string, semester:?int, credits:string, alternativeNote:?string, categoryLabel:?string}>, 1: ?int}
   */
  private function parseCourselist(\DOMXPath $xpath): array {
    $courses = [];
    $totalCredits = NULL;

    $rows = $xpath->query("//table[contains(concat(' ', normalize-space(@class), ' '), ' sc_courselist ')]/tbody/tr");
    if ($rows === FALSE || $rows->length === 0) {
      return [$courses, $totalCredits];
    }

    $seen = [];
    foreach ($rows as $tr) {
      $classAttr = $tr instanceof \DOMElement ? $tr->getAttribute('class') : '';

      if (str_contains(' ' . $classAttr . ' ', ' listsum ')) {
        $maybe = $this->parseTotalCredits($tr->textContent ?? '');
        if ($maybe !== NULL) {
          $totalCredits = $maybe;
        }
        continue;
      }

      $rowCredits = $this->extractRowCredits($xpath, $tr);
      $alternativeNote = $this->extractInlineAlternativeNote($xpath, $tr);
      $anchors = $xpath->query(".//td[contains(concat(' ', normalize-space(@class), ' '), ' codecol ')]//a", $tr);
      foreach ($anchors as $a) {
        $text = trim($a->textContent ?? '');
        if (!preg_match('/^([A-Z]{2,5})[- ](\d+[A-Z]?)$/u', $text, $m)) {
          continue;
        }
        $number = strtoupper($m[1]) . '-' . $m[2];
        if (isset($seen[$number])) {
          continue;
        }
        $seen[$number] = TRUE;
        $courses[] = [
          'number' => $number,
          'semester' => NULL,
          'credits' => $rowCredits,
          'alternativeNote' => $alternativeNote,
          'categoryLabel' => NULL,
        ];
        $alternativeNote = NULL;
      }
    }

    return [$courses, $totalCredits];
  }

  /**
   * Pull an inline "or X" alternative out of a row's title cell.
   *
   * The catalog markup looks like:
   *   <td class="titlecol">
   *     Small Business Accounting
   *     <br/>
   *     <div class="blockindent">or Principles of Accounting</div>
   *   </td>
   *
   * We capture the text inside `.blockindent` (with the leading "or "
   * stripped) so the importer can stash it as a free-text alternative note
   * on the row — useful when the alternative course isn't linked to a code
   * we can resolve to a course node.
   *
   * Returns NULL when no `.blockindent` is found in the row.
   */
  private function extractInlineAlternativeNote(\DOMXPath $xpath, \DOMNode $tr): ?string {
    $nodes = $xpath->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' blockindent ')]", $tr);
    if ($nodes === FALSE || $nodes->length === 0) {
      return NULL;
    }
    $text = $this->cleanText($nodes->item(0)->textContent ?? '');
    // Drop the literal "or " / "OR " prefix the catalog uses.
    $text = preg_replace('/^or\s+/iu', '', $text) ?? $text;
    return $text !== '' ? $text : NULL;
  }

  /**
   * Pull a category label (e.g. "GEM 3") out of a comment row's text.
   *
   * Catalog comment-row text comes through like:
   *   "GEM 3 - Mathematical Ways of Knowing"
   *   "GEM 4 — Scientific Ways of Knowing"
   *
   * Returns the canonical short label ("GEM 3") if matched; NULL otherwise
   * — which means the row is the other kind of comment ("Select one of the
   * following:") and should pass through to credit-stash handling.
   */
  private function extractCategoryLabel(string $commentText): ?string {
    // Match "GEM 3", "GEM 7i", "GEM 7w" — keeps the suffix letter for the
    // institutional-designation variants on /aa-as-degree-requirements/.
    if (preg_match('/\bGEM\s+(\d+[a-z]?)\b/iu', $commentText, $m)) {
      return 'GEM ' . strtolower($m[1]);
    }
    return NULL;
  }

  /**
   * Read the credits cell of a plan-grid row (the last `.hourscol` td). Returns
   * the trimmed text — typically "3", "2", or a range "3-5"; "" if absent.
   */
  private function extractRowCredits(\DOMXPath $xpath, \DOMNode $tr): string {
    $cells = $xpath->query(".//td[contains(concat(' ', normalize-space(@class), ' '), ' hourscol ')]", $tr);
    if ($cells === FALSE || $cells->length === 0) {
      return '';
    }
    $last = $cells->item($cells->length - 1);
    return trim($last->textContent ?? '');
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
