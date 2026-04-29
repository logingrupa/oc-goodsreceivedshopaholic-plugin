<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Dto;

/**
 * Per-row data carrier produced by HtmInvoiceParser (Phase 2 plan 02-05) and
 * consumed by EanMatcherService (Phase 2 plan 02-06). Wraps a single data row
 * extracted from the distributor `.HTM` invoice (`<TR CLASS=R20|R21>`).
 *
 * Pure value type. No DOM, no DB, no validation logic — D-23 keeps validators
 * in dedicated normalizer classes (QuantityNormalizer / PriceNormalizer in
 * plan 02-03). EAN is preserved as STRING so leading zeros survive (D-27).
 *
 * @property-read int $row_index
 * @property-read string $ean
 * @property-read string $product_name_raw
 * @property-read string $unit
 * @property-read int $qty
 * @property-read float|null $unit_price
 * @property-read float|null $discount
 * @property-read float|null $line_price
 * @property-read float|null $total
 */
final readonly class ParsedLine
{
    public function __construct(
        public int $row_index,
        public string $ean,
        public string $product_name_raw,
        public string $unit,
        public int $qty,
        public ?float $unit_price,
        public ?float $discount,
        public ?float $line_price,
        public ?float $total,
    ) {
    }
}
