<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Match;

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\MatchedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedLine;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

/**
 * Pass 2 chain stage — Product.code match with single-offer guard.
 *
 * Extracted from the legacy EanMatcherService::lookupProductsWithSingleOffer
 * private method (Phase 2 plan 02-06) into a MatchStrategy implementation
 * as part of Phase 6 / D-25-update (chain runner refactor).
 *
 * Issues EXACTLY ONE query — `has('offer', '=', 1)` is a correlated COUNT
 * in the WHERE clause; `addSelect(...subquery...)` inlines the sole offer
 * id via a correlated SELECT subquery. Same SQL statement, NOT a second
 * round-trip. `->limit(1)` defense-in-depth keeps the subquery
 * deterministic if the WHERE guard ever drifts.
 */
final class ProductCodeSingleOfferMatcher implements MatchStrategy
{
    #[\Override]
    public function match(array $arUnmatched): array
    {
        if ($arUnmatched === []) {
            return [];
        }

        $arEans = array_values(array_unique(array_map(
            static fn (ParsedLine $obLine): string => $obLine->ean,
            $arUnmatched,
        )));

        $arProductMap = $this->lookupProductsWithSingleOffer($arEans);

        $arResult = [];
        foreach ($arUnmatched as $obLine) {
            if (! isset($arProductMap[$obLine->ean])) {
                continue;
            }
            $arResult[] = new MatchedLine(
                line: $obLine,
                matched_offer_id: $arProductMap[$obLine->ean],
                match_strategy: 'product_code_single_offer',
            );
        }

        return $arResult;
    }

    /**
     * @param  list<string>  $arEans
     * @return array<string, int>
     */
    private function lookupProductsWithSingleOffer(array $arEans): array
    {
        $arProductMap = [];
        $obRows = Product::whereIn('code', $arEans)
            ->has('offer', '=', 1)
            ->select(['id', 'code'])
            ->addSelect([
                'matched_offer_id' => Offer::select('id')
                    ->whereColumn('product_id', 'lovata_shopaholic_products.id')
                    ->limit(1),
            ])
            ->get();

        foreach ($obRows as $obRow) {
            /** @phpstan-ignore-next-line property.notFound — Lovata Product lacks IDE-helper PHPDoc; correlated `addSelect` exposes `matched_offer_id` at runtime */
            $mOfferId = $obRow->matched_offer_id;
            if (! is_numeric($mOfferId)) {
                continue;
            }
            /** @phpstan-ignore-next-line property.notFound — Lovata Product lacks IDE-helper PHPDoc; columns verified at DB layer */
            $sCode = (string) $obRow->code;
            $arProductMap[$sCode] = (int) $mOfferId;
        }

        return $arProductMap;
    }
}
