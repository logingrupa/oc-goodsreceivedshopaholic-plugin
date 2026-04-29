<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Dto;

use DateTimeImmutable;

/**
 * Top-level invoice DTO produced by HtmInvoiceParser (Phase 2 plan 02-05) and
 * consumed by Phase 3 ParseAndPersistOrchestrator. Carries the resolved invoice
 * header (number, country, date, source filename), the list of successfully
 * parsed lines, and a list of rows that were skipped during parsing.
 *
 * Per revised D-04 (CONTEXT.md), `skipped_rows` records boundary-layer lenient
 * skips (malformed EAN, short row, etc.) without throwing — Phase 3 surfaces
 * these in import audit logs. Defaults to `[]` so existing call-sites that
 * predate the revision continue to construct cleanly.
 *
 * @property-read string $invoice_number
 * @property-read string|null $country_code
 * @property-read DateTimeImmutable|null $invoice_date
 * @property-read string $source_filename
 * @property-read list<ParsedLine> $lines
 * @property-read list<array{row_index: int, reason: string, raw: string}> $skipped_rows
 */
final readonly class ParsedInvoice
{
    /**
     * @param  list<ParsedLine>  $lines
     * @param  list<array{row_index: int, reason: string, raw: string}>  $skipped_rows
     */
    public function __construct(
        public string $invoice_number,
        public ?string $country_code,
        public ?DateTimeImmutable $invoice_date,
        public string $source_filename,
        public array $lines,
        public array $skipped_rows = [],
    ) {
    }
}
