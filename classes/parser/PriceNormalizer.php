<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Parser;

/**
 * Audit-only price normalizer (Phase 2 plan 02-03, D-22). Converts
 * decimal-comma HTM cells (e.g. `'3,86'`) into PHP floats for display in
 * the parsed-line audit trail. **NEVER written to stock** — only `qty`
 * (parsed by `QuantityNormalizer`) is.
 *
 * Contract: never throws. Returns `null` for null/empty/whitespace/non-numeric
 * input. The non-numeric path emits `Log::warning` with the raw value so an
 * operator gets a forensic trail without aborting the import (Tiger-Style
 * "fail fast at the failing module's contract" — and this module's contract
 * is "audit-only, lenient").
 *
 * Threat-model coverage (T-02-03-05): the raw value is passed inside the
 * `$arContext` array argument to `Log::warning`, never spliced into the
 * message string. The framework serializes the array via JSON, escaping
 * control characters and blocking log injection.
 */
final class PriceNormalizer
{
    /**
     * Parse a raw HTM price cell into a float, or null on missing/invalid
     * input. Decimal-comma is normalized to decimal-period before casting.
     *
     * Accepted shapes (post-trim):
     *   - `null`, `''`, whitespace-only          → null
     *   - `'3,86'`, `'3.86'`, `'0,00'`, `'-1,50'` → float
     *   - `'abc'`, `'1.234.567,89'`              → null + Log::warning
     */
    public static function parsePrice(?string $sRaw): ?float
    {
        if ($sRaw === null) {
            return null;
        }

        $sTrimmed = trim($sRaw);

        if ($sTrimmed === '') {
            return null;
        }

        $sNormalized = str_replace(',', '.', $sTrimmed);

        if (preg_match('/^-?\d+(\.\d+)?$/', $sNormalized) !== 1) {
            \Log::warning(
                'logingrupa.goodsreceivedshopaholic: price normalization skip',
                ['raw' => $sRaw, 'reason' => 'non-numeric or multiple decimal markers'],
            );

            return null;
        }

        return (float) $sNormalized;
    }
}
