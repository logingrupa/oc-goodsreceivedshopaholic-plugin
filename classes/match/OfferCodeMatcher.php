<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Match;

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\MatchedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedLine;
use Lovata\Shopaholic\Models\Offer;

/**
 * Pass 1 chain stage — direct EAN match against `lovata_shopaholic_offers.code`.
 *
 * Extracted from the legacy EanMatcherService::lookupOffersByCode private
 * method (Phase 2 plan 02-06) into a MatchStrategy implementation as
 * part of Phase 6 / D-25-update (chain runner refactor).
 *
 * Issues EXACTLY ONE query regardless of input size:
 *   `Offer::whereIn('code', $arUnique)->get(['id', 'code', 'active'])`
 *
 * A code carried by 2+ live offers is decided by DuplicateCodeResolver on
 * the rows of that same query: exactly one active offer is picked as
 * `offer_code_active`, otherwise the line is emitted as `ambiguous` with
 * no offer id, which ends the chain for that line so the operator picks.
 *
 * Returns MatchedLine instances ONLY for ParsedLines whose EAN was found.
 * Unmatched ParsedLines are omitted — the chain runner forwards them to
 * the next stage (ProductCodeSingleOfferMatcher).
 */
final class OfferCodeMatcher implements MatchStrategy
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
            $this->lookupOffersByCode($arEans),
            'offer_code',
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
    private function lookupOffersByCode(array $arEans): array
    {
        $arCandidates = [];
        $obRows = Offer::whereIn('code', $arEans)->get(['id', 'code', 'active']);

        foreach ($obRows as $obRow) {
            $arCandidates[] = [
                /** @phpstan-ignore-next-line property.notFound — Lovata Offer lacks IDE-helper PHPDoc; columns verified at DB layer */
                'code' => (string) $obRow->code,
                /** @phpstan-ignore-next-line property.notFound — Lovata Offer lacks IDE-helper PHPDoc; columns verified at DB layer */
                'offer_id' => (int) $obRow->id,
                /** @phpstan-ignore-next-line property.notFound — Lovata Offer lacks IDE-helper PHPDoc; columns verified at DB layer */
                'active' => (bool) $obRow->active,
            ];
        }

        return $arCandidates;
    }
}
