<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Match;

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\MatchedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\VariationExtractor;
use Lovata\Shopaholic\Models\Offer;

/**
 * Pass 3 chain stage — Offer.variation last-comma-segment match with
 * single-offer guard (Phase 6 / D-25-update).
 *
 * Recovers ParsedLines whose EAN missed both Pass 1 (offer.code) and
 * Pass 2 (product.code single-offer) by extracting the trailing
 * comma-segment from `product_name_raw` ("Product, Variation") via
 * VariationExtractor and matching against `lovata_shopaholic_offers.variation`
 * (column added by sibling plugin logingrupa/storeextender).
 *
 * Issues EXACTLY ONE query for any non-empty deduped variation list:
 *   `Offer::whereIn('variation', $arUnique)->select(['id','variation','product_id'])->get()`
 *
 * Tiger-Style deterministic ambiguity skip: variation hits ≥2 offers →
 * line is OMITTED (chain residue → 'none'); never silently best-guess.
 *
 * DRY contract: variation extraction is delegated to VariationExtractor —
 * the regex literal appears EXACTLY ONCE in classes/.
 */
final class VariationMatcher implements MatchStrategy
{
    #[\Override]
    public function match(array $arUnmatched): array
    {
        if ($arUnmatched === []) {
            return [];
        }

        // Per-line variation extraction (delegate regex to sole source).
        $arLineVariation = [];
        foreach ($arUnmatched as $iIdx => $obLine) {
            $sVariation = VariationExtractor::extract($obLine->product_name_raw);
            if ($sVariation === null) {
                continue;
            }
            $arLineVariation[$iIdx] = $sVariation;
        }

        if ($arLineVariation === []) {
            return [];
        }

        $arUnique = array_values(array_unique($arLineVariation));

        // Single SELECT for the whole batch (D-25-update query #3).
        $arOfferRows = $this->lookupOffersByVariation($arUnique);

        // Group offer ids by variation for the single-offer guard.
        $arGrouped = [];
        foreach ($arOfferRows as $arRow) {
            $arGrouped[$arRow['variation']][] = $arRow['id'];
        }

        $arResult = [];
        foreach ($arUnmatched as $iIdx => $obLine) {
            if (! isset($arLineVariation[$iIdx])) {
                continue;
            }
            $sVariation = $arLineVariation[$iIdx];
            $arOfferIds = $arGrouped[$sVariation] ?? [];
            if (count($arOfferIds) !== 1) {
                // 0 hits OR ≥2 hits — skip (residue → 'none').
                continue;
            }
            $arResult[] = new MatchedLine(
                line: $obLine,
                matched_offer_id: $arOfferIds[0],
                match_strategy: 'variation',
            );
        }

        return $arResult;
    }

    /**
     * @param  list<string>  $arUniqueVariations
     * @return list<array{id: int, variation: string, product_id: int}>
     */
    private function lookupOffersByVariation(array $arUniqueVariations): array
    {
        $obRows = Offer::whereIn('variation', $arUniqueVariations)
            ->select(['id', 'variation', 'product_id'])
            ->get();

        $arOut = [];
        foreach ($obRows as $obRow) {
            /** @phpstan-ignore-next-line property.notFound — Lovata Offer lacks IDE-helper PHPDoc; columns verified at DB layer */
            $sVariation = (string) $obRow->variation;
            /** @phpstan-ignore-next-line property.notFound — Lovata Offer lacks IDE-helper PHPDoc; columns verified at DB layer */
            $iId = (int) $obRow->id;
            /** @phpstan-ignore-next-line property.notFound — Lovata Offer lacks IDE-helper PHPDoc; columns verified at DB layer */
            $iProductId = (int) $obRow->product_id;
            $arOut[] = ['id' => $iId, 'variation' => $sVariation, 'product_id' => $iProductId];
        }

        return $arOut;
    }
}
