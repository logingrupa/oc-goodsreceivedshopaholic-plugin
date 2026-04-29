<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Parser;

use DateTimeImmutable;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvoiceNumberMissingException;

/**
 * Resolve the canonical invoice number for a `.HTM` upload (Phase 2 plan
 * 02-04, D-18..D-20).
 *
 * Two-tier resolution order:
 *   1. **Body marker** (authoritative). Distributor-controlled HTM content
 *      contains a label "Invoice No" / "Faktura Nr" / "Rechnung Nr" followed
 *      by the canonical `PRO033328`-style identifier. Real fixtures split
 *      label and value across two `<TD>` cells with embedded `<SPAN>` and
 *      `&nbsp;`; we therefore strip tags + decode entities + collapse
 *      whitespace before regex.
 *   2. **Filename** (fallback). Pattern `Nr_PRO<num>_<country>_<DDMMYYYY>.HTM`
 *      yields the same canonical number plus operator metadata
 *      (`country_code`, `invoice_date`).
 *   3. **Both miss** → throw `InvoiceNumberMissingException`. Body-first
 *      ordering makes accidental rename harmless; explicit throw on total
 *      miss prevents nameless invoices from being persisted (T-02-04-01).
 *
 * The filename is parsed even when the body wins so `country_code` and
 * `invoice_date` are populated for `ParsedInvoice` header without a second
 * filename parse downstream (D-18 efficiency note).
 *
 * Threat-model coverage (T-02-04-01..04):
 *   - Body-first ordering defeats silent-swap-via-rename.
 *   - `BODY_MARKER_REGEX` has no nested quantifiers and no overlapping
 *     alternations — PCRE worst-case is linear in input.
 *   - Exception `arContext` carries only `source_filename` (operator-supplied)
 *     plus boolean tried-flags; no body content leaks.
 *   - Malformed date in filename forces throw, never loops or retries.
 */
final class InvoiceNumberResolver
{
    /**
     * Body-marker regex — matches the label-and-value pair after HTML tag
     * strip and entity decode. Covers EN ("Invoice No"), Norwegian
     * ("Faktura Nr"), and German ("Rechnung Nr") variants. `[^\w\d]*` skips
     * any colons, periods, or whitespace between label and value (including
     * decoded `&nbsp;` collapsed by `preg_replace('/\s+/u', ' ', ...)`).
     * `([A-Z]{0,4}\d{4,})` greedily captures `PRO033328`-style identifiers.
     * `u` flag enables Unicode-safe matching.
     */
    private const BODY_MARKER_REGEX = '/(?:Invoice|Faktura|Rechnung)\s*(?:No|Nr|nr)[^\w\d]*([A-Z]{0,4}\d{4,})/iu';

    /**
     * Filename regex — anchored both ends. Captures (1) canonical
     * `PRO\d+` identifier, (2) two-or-three-letter country code, (3)
     * eight-digit `DDMMYYYY` date string.
     */
    private const FILENAME_REGEX = '/^Nr_(PRO\d+)_([A-Za-z]{2,3})_(\d{8})\.HTM$/i';

    /**
     * Resolve the canonical invoice number, country code, and invoice date
     * for a `.HTM` upload.
     *
     * @return array{
     *     invoice_number: string,
     *     country_code: ?string,
     *     invoice_date: ?\DateTimeImmutable,
     *     resolved_via: 'body'|'filename'
     * }
     *
     * @throws InvoiceNumberMissingException  when neither body nor filename
     *                                        yields a number
     */
    public static function resolve(string $sHtmlBody, string $sFilename): array
    {
        $sBasename = basename($sFilename);
        $arFilenameMeta = self::parseFilenameMeta($sBasename);
        $sBodyNumber = self::extractFromBody($sHtmlBody);

        if ($sBodyNumber !== null) {
            return [
                'invoice_number' => $sBodyNumber,
                'country_code' => $arFilenameMeta['country_code'] ?? null,
                'invoice_date' => $arFilenameMeta['invoice_date'] ?? null,
                'resolved_via' => 'body',
            ];
        }

        if ($arFilenameMeta !== null) {
            return [
                'invoice_number' => $arFilenameMeta['invoice_number'],
                'country_code' => $arFilenameMeta['country_code'],
                'invoice_date' => $arFilenameMeta['invoice_date'],
                'resolved_via' => 'filename',
            ];
        }

        throw new InvoiceNumberMissingException(
            (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.exception.invoice_number_missing'),
            [
                'source_filename' => $sBasename,
                'tried_body' => true,
                'tried_filename' => true,
            ],
        );
    }

    /**
     * Extract the canonical invoice number from the HTM body if a marker is
     * present. Returns `null` on miss.
     *
     * Steps:
     *   1. `strip_tags` removes the `<TD>` / `<SPAN>` wrapping that splits
     *      label and value across cells in real fixtures.
     *   2. `html_entity_decode` resolves `&nbsp;` and other entities.
     *   3. `preg_replace('/\s+/u', ' ', ...)` collapses the now-decoded
     *      whitespace runs so the regex sees a single space.
     *   4. `BODY_MARKER_REGEX` then matches in linear time.
     */
    private static function extractFromBody(string $sHtmlBody): ?string
    {
        $sText = html_entity_decode(strip_tags($sHtmlBody), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $sText = preg_replace('/\s+/u', ' ', $sText) ?? '';

        if (preg_match(self::BODY_MARKER_REGEX, $sText, $arMatch) === 1) {
            return strtoupper($arMatch[1]);
        }

        return null;
    }

    /**
     * Parse the filename for canonical number, country code, and invoice
     * date. Returns `null` when the filename does not match the
     * `Nr_PRO<num>_<country>_<DDMMYYYY>.HTM` pattern OR the date string is
     * malformed (e.g. `99999999`).
     *
     * @return array{invoice_number: string, country_code: string, invoice_date: \DateTimeImmutable}|null
     */
    private static function parseFilenameMeta(string $sBasename): ?array
    {
        if (preg_match(self::FILENAME_REGEX, $sBasename, $arMatch) !== 1) {
            return null;
        }

        $obDate = DateTimeImmutable::createFromFormat('!dmY', $arMatch[3]);

        if ($obDate === false) {
            return null;
        }

        // `createFromFormat` accepts overflow dates ('99999999' becomes year
        // 10007 via month/day carry-over). `getLastErrors()` flags these via
        // `warning_count > 0` while keeping `error_count === 0`. We treat any
        // warning as a parse miss to prevent silent date drift in
        // `ParsedInvoice.invoice_date` (D-19 strictness).
        $arErrors = DateTimeImmutable::getLastErrors();

        if ($arErrors !== false && $arErrors['warning_count'] > 0) {
            return null;
        }

        return [
            'invoice_number' => strtoupper($arMatch[1]),
            'country_code' => strtolower($arMatch[2]),
            'invoice_date' => $obDate,
        ];
    }
}
