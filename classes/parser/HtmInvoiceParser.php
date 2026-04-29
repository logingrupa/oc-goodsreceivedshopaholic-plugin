<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Parser;

use DOMDocument;
use DOMElement;
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
 * Real-fixture format anchors (verified against `Nr_PRO033328_no_13042026.HTM`):
 *   - UTF-8 BOM (\xEF\xBB\xBF) prefix — stripped before `loadHTML`.
 *   - HTML 4.0 Transitional doctype.
 *   - Data rows: `<TR CLASS=R20>` UPPERCASE UNQUOTED. `loadHTML` tolerates
 *     this natively, but the case-insensitive XPath translate() guards against
 *     distributor format drift to lowercase / mixed-case in future shipments.
 *   - Per row, 13 TD elements indexed by POSITION (not class — class names
 *     repeat: `R20C2` appears twice for EAN + name).
 *
 * Throw-vs-skip decision matrix (consumed by Phase 3 orchestrator):
 *   | Condition                              | Outcome                            |
 *   |----------------------------------------|------------------------------------|
 *   | libxml fatal error                     | throw `MalformedHtmException`      |
 *   | Zero R20/R21 rows extracted            | throw `MalformedHtmException`      |
 *   | Invoice number missing (body+filename) | throw `InvoiceNumberMissingException` (bubbles from resolver) |
 *   | Decimal / zero / negative qty          | throw `InvalidQuantityException` (bubbles from QuantityNormalizer) |
 *   | Row has < 10 TDs                       | append to `skipped_rows`, continue |
 *   | EAN not exactly 13 digits              | append to `skipped_rows`, continue |
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

    /** Strict 13-digit EAN validation regex (D-16). */
    private const string EAN_REGEX = '/^\d{13}$/';

    /**
     * Case-insensitive XPath for data rows. `translate(@class,'r','R')` lifts
     * any lowercase `r` to uppercase before the `contains()` test, so future
     * fixtures with `<TR class="r20">` parse identically (defensive — current
     * fixtures are uppercase).
     */
    private const string ROW_XPATH = "//tr[contains(translate(@class,'r','R'),'R20') or contains(translate(@class,'r','R'),'R21')]";

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
     * Exactly one of `line` / `skip` is non-null.
     *
     * @return array{line: ?ParsedLine, skip: ?array{row_index: int, reason: string, raw: string}}
     */
    private function parseOneRow(DOMNode $obRow, int $iRowIndex): array
    {
        $arTds = [];
        foreach ($obRow->childNodes as $obChild) {
            if ($obChild instanceof DOMElement && strtolower($obChild->nodeName) === 'td') {
                $arTds[] = trim($obChild->textContent);
            }
        }

        if (count($arTds) < self::MIN_TD_COUNT) {
            return [
                'line' => null,
                'skip' => [
                    'row_index' => $iRowIndex,
                    'reason' => 'insufficient_columns',
                    'raw' => $this->dumpRow($obRow),
                ],
            ];
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

        $obLine = new ParsedLine(
            row_index: $iRowIndex,
            ean: $sEan,
            product_name_raw: $sName,
            unit: $sUnit,
            qty: $iQty,
            unit_price: PriceNormalizer::parsePrice($arTds[6] ?? null),
            discount: PriceNormalizer::parsePrice($arTds[7] ?? null),
            line_price: PriceNormalizer::parsePrice($arTds[8] ?? null),
            total: PriceNormalizer::parsePrice($arTds[9] ?? null),
        );

        return ['line' => $obLine, 'skip' => null];
    }

    /**
     * Serialize a `<TR>` node to a short HTML snippet suitable for the
     * `skipped_rows` audit trail. Truncated to 200 chars so a malicious
     * 1MB row cannot bloat the audit log.
     */
    private function dumpRow(DOMNode $obRow): string
    {
        $obOwner = $obRow->ownerDocument;

        if ($obOwner === null) {
            return '';
        }

        $sHtml = $obOwner->saveHTML($obRow);

        if ($sHtml === false) {
            return '';
        }

        return substr($sHtml, 0, 200);
    }
}
