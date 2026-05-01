<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Support;

/**
 * Sole regex source for variation extraction in the plugin.
 *
 * Splits an offer-name string ("Product, Variation" — last-comma greedy)
 * into its trailing variation token. Consumed by:
 *   - VariationMatcher (Pass 3 chain stage, plan 06-04)
 *   - models/invoiceline/_column_product_name.htm (UI render branch, plan 06-06)
 *
 * DRY contract (MATCH-03): the variation regex literal (see the
 * VARIATION_REGEX const below) appears EXACTLY ONCE in classes/.
 * Both consumers call this method rather than re-implementing the
 * pattern. Acceptance gate is a grep on the const's literal value
 * — keeping the pattern out of prose comments preserves the gate.
 *
 * Pure / stateless / final readonly per Tiger-Style.
 */
final readonly class VariationExtractor
{
    /**
     * Last-comma greedy regex — "A, B, C" splits as ("A, B", "C").
     */
    private const string VARIATION_REGEX = '/^(.+),\s+([^,]+)$/u';

    /**
     * Extract the trailing variation token after the LAST ", " in the
     * input. Returns trimmed token on hit, null on empty input or
     * no-comma input.
     */
    public static function extract(string $sName): ?string
    {
        if ($sName === '') {
            return null;
        }

        if (preg_match(self::VARIATION_REGEX, $sName, $arMatch) !== 1) {
            return null;
        }

        $sVariation = trim($arMatch[2]);

        return $sVariation === '' ? null : $sVariation;
    }
}
