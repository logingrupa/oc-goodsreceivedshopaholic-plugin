<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Parser;

use DOMDocument;
use DOMElement;
use DOMNameSpaceNode;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use LibXMLError;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedInvoice;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\MalformedHtmException;

/**
 * Distributor `.HTM` → `ParsedInvoice` DTO converter (Phase 2 plan 02-05).
 *
 * Pure function-of-bytes input boundary for the entire goods-received pipeline.
 * No DB, no IO beyond the input string. Calls into four building blocks:
 * `InvoiceNumberResolver` (header), `QuantityNormalizer` (strict int qty),
 * `PriceNormalizer` (audit-only float prices), and `MalformedHtmException`
 * (whole-file failure).
 *
 * Real-fixture format anchors (verified against `Nr_PRO033328_no_13042026.HTM`
 * and `Nr_PRO033436_no_07082026.HTM`):
 *   - UTF-8 BOM (\xEF\xBB\xBF) prefix — stripped before `loadHTML`.
 *   - HTML 4.0 Transitional doctype.
 *   - Rows carry `<TR CLASS=R20>` style UPPERCASE UNQUOTED attributes.
 *     `loadHTML` tolerates this natively. The class index is a per-template
 *     layout style (R20/R21 in the EN template, R22/R23 in the LV one, the
 *     higher number being the taller two-line variant) and is NOT used for
 *     row selection — see `ROW_XPATH`.
 *   - Per row, 12-13 TD elements indexed by POSITION (not class — class names
 *     repeat: `R20C2` appears twice for EAN + name).
 *
 * Throw-vs-skip decision matrix (consumed by Phase 3 orchestrator):
 *   | Condition                              | Outcome                            |
 *   |----------------------------------------|------------------------------------|
 *   | libxml fatal error                     | throw `MalformedHtmException`      |
 *   | Zero data rows extracted               | throw `MalformedHtmException`      |
 *   | Rows extracted, ZERO valid EAN lines   | throw `MalformedHtmException` (`missing_ean_column` message — distributor's no-EAN print template) |
 *   | Invoice number missing (body+filename) | throw `InvoiceNumberMissingException` (bubbles from resolver) |
 *   | Decimal / zero / negative qty          | throw `InvalidQuantityException` (bubbles from QuantityNormalizer) |
 *   | EAN not 8, 12 or 13 digits             | append to `skipped_rows`, continue |
 *   | Unparseable price cell                 | parsed line `unit_price=null` etc. |
 *
 * Threat-model coverage:
 *   - T-02-05-01 (XXE): `LIBXML_NONET` flag passed to `loadHTML` blocks
 *     external entity loading even though `loadHTML` does not process
 *     DOCTYPE entities by default. Defense-in-depth.
 *   - T-02-05-02 (DoS / unbounded loop): `MAX_ROWS = 10000` cap with `break`
 *     forces bounded iteration. Real fixtures contain <200 rows.
 *   - T-02-05-05 (silent qty corruption): parser MUST NOT catch
 *     `InvalidQuantityException`; `parseOneRow` invokes the normalizer
 *     uncaught so the throw bubbles to caller. Tested explicitly.
 *   - T-02-05-06 (invalid EAN): row-level guard rejects malformed EAN BEFORE
 *     constructing `ParsedLine`. EanMatcherService (plan 02-06) re-checks.
 */
final class HtmInvoiceParser
{
    /** Hard cap on row iteration — DoS guard (T-02-05-02). */
    public const int MAX_ROWS = 10000;

    /** Minimum TD count for a usable data row (positions 0..9 populated). */
    private const int MIN_TD_COUNT = 10;

    /**
     * Accepted barcode shapes (D-16): EAN-8, UPC-A (12) and EAN-13, compared
     * as exact strings against `offers.code`. UPC-A is NOT zero-padded to 13
     * because the catalog stores it as a 12-character string. Any other
     * length, and any non-digit content, is skipped at row level.
     */
    private const string EAN_REGEX = '/^(\d{8}|\d{12}|\d{13})$/';

    /**
     * Structural XPath for data rows: at least MIN_TD_COUNT cells AND a
     * purely numeric "Nr." cell at position 1 (XPath `td[2]`). Both
     * distributor templates share column positions (Nr@1, EAN@2, name@3,
     * unit@4, qty@5, prices@6..9). Header rows carry a text label in the
     * Nr. cell and totals/footer rows span fewer cells, so neither is
     * selected. Row CLASS names are deliberately ignored: they are layout
     * style indices that change per template and per row height
     * (`Nr_PRO033436_no_07082026.HTM` styles wrapped two-line rows R23
     * next to single-line R22 rows).
     */
    private const string ROW_XPATH = '//tr[count(td) >= '.self::MIN_TD_COUNT
        ." and normalize-space(td[2]) != '' and translate(normalize-space(td[2]), '0123456789', '') = '']";

    /**
     * Parse distributor `.HTM` bytes into a typed `ParsedInvoice` DTO.
     *
     * @throws MalformedHtmException                                            on libxml fatal OR zero rows extractable
     * @throws \Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvoiceNumberMissingException  bubbled from resolver
     * @throws \Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvalidQuantityException       bubbled from QuantityNormalizer
     */
    public function parse(string $sHtml, string $sSourceFilename): ParsedInvoice
    {
        $sStripped = $this->stripBom($sHtml);
        $arHeader = InvoiceNumberResolver::resolve($sStripped, $sSourceFilename);
        $obDom = $this->loadDom($sStripped);
        $arResult = $this->extractRows($obDom);

        if ($arResult['lines'] === [] && $arResult['skipped'] === []) {
            throw new MalformedHtmException(
                (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.exception.malformed_htm'),
                [
                    'reason' => 'no_rows_extracted',
                    'source_filename' => basename($sSourceFilename),
                ],
            );
        }

        // Rows matched but NOT ONE carried an EAN-8, UPC-A or EAN-13 — the
        // distributor's no-EAN print template (no "Bar code" column at all;
        // e.g. Nr_PRO034535_no_09072026.HTM).
        // Such a file can never match offers, so reject the whole upload
        // with an actionable operator message instead of persisting a
        // useless zero-line invoice. Row-level EAN leniency (D-16) still
        // holds whenever at least one row parses.
        if ($arResult['lines'] === []) {
            throw new MalformedHtmException(
                (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.exception.missing_ean_column'),
                [
                    'reason' => 'no_valid_ean_lines',
                    'skipped_row_count' => count($arResult['skipped']),
                    'source_filename' => basename($sSourceFilename),
                ],
            );
        }

        return new ParsedInvoice(
            invoice_number: $arHeader['invoice_number'],
            country_code: $arHeader['country_code'],
            invoice_date: $arHeader['invoice_date'],
            source_filename: basename($sSourceFilename),
            lines: $arResult['lines'],
            skipped_rows: $arResult['skipped'],
        );
    }

    /**
     * Strip leading UTF-8 BOM (\xEF\xBB\xBF) so DOMDocument does not see it
     * as a stray text node before `<!DOCTYPE>`. `ltrim` is byte-oriented and
     * removes any character listed in the charlist — repeated BOMs are
     * stripped down to nothing in one call.
     */
    private function stripBom(string $sHtml): string
    {
        return ltrim($sHtml, "\xEF\xBB\xBF");
    }

    /**
     * Load HTML into a `DOMDocument` with libxml errors collected (not echoed)
     * and external entity loading disabled (XXE defense, T-02-05-01).
     *
     * @throws MalformedHtmException  when libxml emits a fatal error or
     *                                `loadHTML` returns false
     */
    private function loadDom(string $sHtml): DOMDocument
    {
        $obDom = new DOMDocument();
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        $bLoaded = $obDom->loadHTML($sHtml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $arErrors = libxml_get_errors();
        libxml_clear_errors();

        $arFatalErrors = array_filter(
            $arErrors,
            static fn (LibXMLError $obError): bool => $obError->level === LIBXML_ERR_FATAL,
        );

        if ($bLoaded === false || $arFatalErrors !== []) {
            throw new MalformedHtmException(
                (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.exception.malformed_htm'),
                [
                    'reason' => 'libxml_fatal',
                    'libxml_errors' => array_values(array_map(
                        static fn (LibXMLError $obError): string => trim($obError->message),
                        $arFatalErrors,
                    )),
                ],
            );
        }

        return $obDom;
    }

    /**
     * Iterate matched data rows under the `MAX_ROWS` cap, building the
     * lines / skipped tuple consumed by `parse()`.
     *
     * @return array{lines: list<ParsedLine>, skipped: list<array{row_index: int, reason: string, raw: string}>}
     */
    private function extractRows(DOMDocument $obDom): array
    {
        $obXpath = new DOMXPath($obDom);
        $obRows = $obXpath->query(self::ROW_XPATH);

        if (! $obRows instanceof DOMNodeList) {
            return ['lines' => [], 'skipped' => []];
        }

        $arLines = [];
        $arSkipped = [];
        $iRowCount = 0;

        foreach ($obRows as $obRow) {
            if (++$iRowCount > self::MAX_ROWS) {
                break;
            }

            $arOutcome = $this->parseOneRow($obRow, $iRowCount);

            if ($arOutcome['line'] !== null) {
                $arLines[] = $arOutcome['line'];

                continue;
            }

            if ($arOutcome['skip'] !== null) {
                $arSkipped[] = $arOutcome['skip'];
            }
        }

        return ['lines' => $arLines, 'skipped' => $arSkipped];
    }

    /**
     * Convert one DOM `<TR>` node into either a `ParsedLine` or a skip record.
     * Exactly one of `line` / `skip` is non-null. Accepts the union returned
     * by `DOMNodeList` iteration (`DOMNameSpaceNode|DOMNode`) so PHPStan
     * level 10 is satisfied without inline type-overrides; non-`DOMNode`
     * cases are filtered out at the children-iteration step.
     *
     * @return array{line: ?ParsedLine, skip: ?array{row_index: int, reason: string, raw: string}}
     */
    private function parseOneRow(DOMNameSpaceNode|DOMNode $obRow, int $iRowIndex): array
    {
        if (! $obRow instanceof DOMNode) {
            return [
                'line' => null,
                'skip' => [
                    'row_index' => $iRowIndex,
                    'reason' => 'non_element_row',
                    'raw' => '',
                ],
            ];
        }

        $arTds = [];
        foreach ($obRow->childNodes as $obChild) {
            if ($obChild instanceof DOMElement && strtolower($obChild->nodeName) === 'td') {
                $arTds[] = trim($obChild->textContent);
            }
        }

        $sEan = $arTds[2];
        $sName = $arTds[3];
        $sUnit = $arTds[4];
        $sQtyRaw = $arTds[5];

        if (preg_match(self::EAN_REGEX, $sEan) !== 1) {
            \Log::warning(
                'logingrupa.goodsreceivedshopaholic: row skipped — invalid EAN',
                ['row_index' => $iRowIndex, 'ean' => $sEan],
            );

            return [
                'line' => null,
                'skip' => [
                    'row_index' => $iRowIndex,
                    'reason' => 'invalid_ean',
                    'raw' => $sEan,
                ],
            ];
        }

        $iQty = QuantityNormalizer::parseQuantity(
            $sQtyRaw,
            ['row_index' => $iRowIndex, 'ean' => $sEan],
        );

        // Positions 2..9 are guaranteed to exist because `ROW_XPATH` only
        // selects rows with at least MIN_TD_COUNT cells; PriceNormalizer
        // accepts the trimmed strings directly and returns `null` for
        // non-numeric content.
        $obLine = new ParsedLine(
            row_index: $iRowIndex,
            ean: $sEan,
            product_name_raw: $sName,
            unit: $sUnit,
            qty: $iQty,
            unit_price: PriceNormalizer::parsePrice($arTds[6]),
            discount: PriceNormalizer::parsePrice($arTds[7]),
            line_price: PriceNormalizer::parsePrice($arTds[8]),
            total: PriceNormalizer::parsePrice($arTds[9]),
        );

        return ['line' => $obLine, 'skip' => null];
    }
}
