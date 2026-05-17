<?php

declare(strict_types=1);

namespace Drupal\programhub_certificate_import\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Parses NIC degree-requirements pages for general-education category data.
 *
 * Catalog URLs (both contribute):
 *   - https://catalog.nic.edu/aa-as-degree-requirements/   (GEM 1-6, GEM 7i/7w)
 *   - https://catalog.nic.edu/aas-degree-requirements/     (subset + AASID)
 *
 * The shape of each section is identical across pages:
 *
 *   <h2><a id="gem3" name="gem3"></a>GEM 3 - Mathematical Ways of Knowing</h2>
 *   <table class="sc_courselist">
 *     <tbody>
 *       <tr>…<td class="codecol">…<a>MATH-123</a>…</td>…</tr>
 *       …
 *     </tbody>
 *   </table>
 *
 * The scraper returns one entry per anchored section. Downstream
 * (`GenEdImporter`) decides how to map those into the `gen_ed_category`
 * vocabulary and which course nodes to tag.
 */
final class GenEdScraper {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Fetch + parse a single degree-requirements page.
   *
   * @return array<int, array{
   *   label:string,
   *   fullName:string,
   *   anchor:string,
   *   courseNumbers:array<int,string>,
   * }>|null
   *   NULL on fetch failure. Empty array when the page has no anchored
   *   category sections (a degree-requirements page should always have some;
   *   empty means the page layout has changed).
   */
  public function scrape(string $url): ?array {
    $logger = $this->loggerFactory->get('programhub_certificate_import');

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'User-Agent' => 'ProgramHub/1.0 (gen-ed-import; +https://programhub)',
          'Accept' => 'text/html',
        ],
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $e) {
      $logger->error('Gen-ed fetch failed for @url: @msg', [
        '@url' => $url,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
    if ($response->getStatusCode() !== 200) {
      $logger->error('Gen-ed fetch non-200 for @url: @code', [
        '@url' => $url,
        '@code' => $response->getStatusCode(),
      ]);
      return NULL;
    }

    return $this->parse((string) $response->getBody());
  }

  /**
   * Public for testing — parse raw HTML.
   *
   * @return array<int, array{
   *   label:string,
   *   fullName:string,
   *   anchor:string,
   *   courseNumbers:array<int,string>,
   * }>
   */
  public function parse(string $html): array {
    if (trim($html) === '') {
      return [];
    }

    $dom = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new \DOMXPath($dom);

    // Every category section starts with an empty anchor inside a heading,
    // followed (as a sibling of the heading) by an `sc_courselist` table.
    // We scope to anchors whose id is a short slug — keeps us away from
    // unrelated `<a id="dialog-title">` / `<a id="footer">` chrome.
    $anchors = $xpath->query("//a[@id and @name and string-length(@id) <= 10 and @id = @name]");
    if ($anchors === FALSE) {
      return [];
    }

    $out = [];
    foreach ($anchors as $anchor) {
      if (!$anchor instanceof \DOMElement) {
        continue;
      }
      $anchorId = strtolower($anchor->getAttribute('id'));

      $heading = $this->headingForAnchor($anchor);
      if ($heading === '') {
        continue;
      }

      $table = $this->courselistTableAfter($anchor);
      if ($table === NULL) {
        // Anchor without an immediately-following courselist — not a
        // category section (could be a TOC anchor inside prose). Skip.
        continue;
      }

      $label = $this->canonicalLabel($heading, $anchorId);
      if ($label === '') {
        continue;
      }

      $out[] = [
        'label' => $label,
        'fullName' => $heading,
        'anchor' => $anchorId,
        'courseNumbers' => $this->extractCourseNumbers($xpath, $table),
      ];
    }

    return $out;
  }

  /**
   * Read the heading text that "owns" $anchor — typically the parent <hN>.
   * Falls back to text content immediately following the anchor at the same
   * tree level. Returns a cleaned single-line string.
   */
  private function headingForAnchor(\DOMElement $anchor): string {
    // Walk up to nearest heading element.
    $node = $anchor->parentNode;
    while ($node instanceof \DOMNode) {
      if ($node instanceof \DOMElement && preg_match('/^h[1-6]$/', strtolower($node->tagName))) {
        return $this->clean($node->textContent ?? '');
      }
      $node = $node->parentNode;
    }
    // Fallback: the anchor's own following sibling text.
    $text = '';
    $sib = $anchor->nextSibling;
    while ($sib !== NULL) {
      if ($sib instanceof \DOMElement && preg_match('/^h[1-6]$/', strtolower($sib->tagName))) {
        $text = $sib->textContent ?? '';
        break;
      }
      $text .= $sib->nodeValue ?? '';
      $sib = $sib->nextSibling;
    }
    return $this->clean($text);
  }

  /**
   * Walk forward from $anchor's enclosing heading (or the anchor itself) to
   * the next `sc_courselist` table at the same depth. Returns NULL if no
   * matching table appears before the next anchor.
   */
  private function courselistTableAfter(\DOMElement $anchor): ?\DOMElement {
    // Start from the heading if the anchor is wrapped in one.
    $start = $anchor;
    $parent = $anchor->parentNode;
    if ($parent instanceof \DOMElement && preg_match('/^h[1-6]$/', strtolower($parent->tagName))) {
      $start = $parent;
    }

    $sib = $start->nextSibling;
    while ($sib !== NULL) {
      if ($sib instanceof \DOMElement) {
        $tag = strtolower($sib->tagName);
        // Stop searching at the next heading — different section.
        if (preg_match('/^h[1-6]$/', $tag)) {
          return NULL;
        }
        if ($tag === 'table' && str_contains(' ' . $sib->getAttribute('class') . ' ', ' sc_courselist ')) {
          return $sib;
        }
      }
      $sib = $sib->nextSibling;
    }
    return NULL;
  }

  /**
   * Extract normalized course numbers from a courselist table body.
   *
   * Skips "course" anchors whose prefix is actually a category label
   * (GEM-3, AASID-…). Those are how the registrar cross-references one
   * category section from inside another — they're not real course codes
   * and shouldn't go into the spider queue.
   *
   * @return array<int,string>
   *   Deduplicated, in document order ("MATH-123" form).
   */
  private function extractCourseNumbers(\DOMXPath $xpath, \DOMElement $table): array {
    $anchors = $xpath->query(".//td[contains(concat(' ', normalize-space(@class), ' '), ' codecol ')]//a", $table);
    if ($anchors === FALSE) {
      return [];
    }
    $seen = [];
    $out = [];
    foreach ($anchors as $a) {
      $text = trim($a->textContent ?? '');
      if (!preg_match('/^([A-Z]{2,5})[- ](\d+[A-Z]?)$/u', $text, $m)) {
        continue;
      }
      if (in_array(strtoupper($m[1]), self::PSEUDO_COURSE_PREFIXES, TRUE)) {
        continue;
      }
      $number = strtoupper($m[1]) . '-' . $m[2];
      if (isset($seen[$number])) {
        continue;
      }
      $seen[$number] = TRUE;
      $out[] = $number;
    }
    return $out;
  }

  /**
   * Prefixes the catalog uses inside category tables as labels rather than
   * as real course identifiers. Must stay in sync with the same list in
   * {@see \Drupal\programhub_course_import\Service\CourseImporter}.
   */
  private const PSEUDO_COURSE_PREFIXES = ['GEM', 'AASID', 'AAS', 'INST'];

  /**
   * Derive the canonical short label for a category — what gets stored as the
   * `gen_ed_category` term name. Strategy:
   *
   *   - Heading starts with "GEM N" (digits and optional suffix letter, e.g.
   *     "GEM 7i"): label = "GEM 7i".
   *   - Otherwise: uppercase the anchor id ("aasid" → "AASID").
   *
   * Term naming is stable across catalogs, so the per-certificate importer's
   * `resolveCategoryLabels()` can key on this same shape.
   */
  private function canonicalLabel(string $heading, string $anchorId): string {
    if (preg_match('/^GEM\s+(\d+[a-z]?)/iu', $heading, $m)) {
      return 'GEM ' . strtolower($m[1]);
    }
    return $anchorId !== '' ? strtoupper($anchorId) : '';
  }

  private function clean(string $text): string {
    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $collapsed = preg_replace('/\s+/u', ' ', $decoded) ?? $decoded;
    return trim($collapsed);
  }

}
