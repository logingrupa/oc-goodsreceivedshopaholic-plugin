<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Parser;

use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvalidQuantityException;

/**
 * Stock-write guard at the HTM-string → typed-int boundary (Phase 2 plan 02-03,
 * D-21). Rejects decimal-comma / decimal-period / zero / negative quantities
 * BEFORE Eloquent's silent `setQuantityAttribute` int-clamp would corrupt
 * `offer.quantity` (e.g. `(int) '5,12' === 5`).
 *
 * Pure static helper — never throws on valid input, always throws
 * `InvalidQuantityException` on malformed input. Caller (Phase 2 plan 02-05
 * `HtmInvoiceParser`) supplies forensic context (row index etc.) via the
 * optional second arg.
 *
 * Threat-model coverage (T-02-03-01..02):
 *   - Strict regex `/^\d+$/` rejects decimal markers and scientific notation.
 *   - Explicit `<= 0` guard enforces Tiger-Style positive-space invariant.
 */
final class QuantityNormalizer
{
    /**
     * Parse a raw HTM cell string into a strictly-positive integer.
     *
     * @param  array<string, mixed>  $arContext  optional forensic context attached
     *                                          to thrown exceptions (e.g.,
     *                                          `['row_index' => 7]`)
     *
     * @throws InvalidQuantityException  on empty/non-integer/zero/negative input
     */
    public static function parseQuantity(string $sRaw, array $arContext = []): int
    {
        $sTrimmed = trim($sRaw);

        if ($sTrimmed === '' || preg_match('/^\d+$/', $sTrimmed) !== 1) {
            throw new InvalidQuantityException(
                (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.exception.invalid_quantity'),
                array_merge(['raw' => $sRaw], $arContext),
            );
        }

        $iQty = (int) $sTrimmed;

        if ($iQty <= 0) {
            throw new InvalidQuantityException(
                (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.exception.invalid_quantity'),
                array_merge(['raw' => $sRaw, 'parsed' => $iQty], $arContext),
            );
        }

        return $iQty;
    }
}
