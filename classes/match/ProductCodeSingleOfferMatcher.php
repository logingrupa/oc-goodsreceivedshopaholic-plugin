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
 * id and its active flag via correlated SELECT subqueries. Same SQL
 * statement, NOT a second round-trip. `->limit(1)` defense-in-depth keeps
 * the subqueries deterministic if the WHERE guard ever drifts.
 *
 * A code carried by 2+ products (each with its single offer) is decided by
 * DuplicateCodeResolver on those rows: exactly one active offer is picked
 * as `offer_code_active`, otherwise the line is emitted as `ambiguous`.
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

        $arDecisions = DuplicateCodeResolver::resolveByCode(
            $this->lookupProductsWithSingleOffer($arEans),
            'product_code_single_offer',
        );

        $arResult = [];
        foreach ($arUnmatched as $obLine) {
            if (! isset($arDecisions[$obLine->ean])) {
                continue;
            }
            $arResult[] = new MatchedLine(
                line: $obLine,
                matched_offer_id: $arDecisions[$obLine->ean]['offer_id'],
                match_strategy: $arDecisions[$obLine->ean]['strategy'],
            );
        }

        return $arResult;
    }

    /**
     * @param  list<string>  $arEans
     * @return list<array{code: string, offer_id: int, active: bool}>
     */
    private function lookupProductsWithSingleOffer(array $arEans): array
    {
        $arCandidates = [];
        $obRows = Product::whereIn('code', $arEans)
            ->has('offer', '=', 1)
            ->select(['id', 'code'])
            ->addSelect([
                'matched_offer_id' => Offer::select('id')
                    ->whereColumn('product_id', 'lovata_shopaholic_products.id')
                    ->limit(1),
                'matched_offer_active' => Offer::select('active')
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
            $arCandidates[] = [
                /** @phpstan-ignore-next-line property.notFound — Lovata Product lacks IDE-helper PHPDoc; columns verified at DB layer */
                'code' => (string) $obRow->code,
                'offer_id' => (int) $mOfferId,
                /** @phpstan-ignore-next-line property.notFound — Lovata Product lacks IDE-helper PHPDoc; correlated `addSelect` exposes `matched_offer_active` at runtime */
                'active' => (bool) $obRow->matched_offer_active,
            ];
        }

        return $arCandidates;
    }
}
