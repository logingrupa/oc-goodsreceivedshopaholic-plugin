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
 *   `Offer::whereIn('code', $arUnique)->get(['id', 'code'])`
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

        $arOfferMap = $this->lookupOffersByCode($arEans);

        $arResult = [];
        foreach ($arUnmatched as $obLine) {
            if (! isset($arOfferMap[$obLine->ean])) {
                continue;
            }
            $arResult[] = new MatchedLine(
                line: $obLine,
                matched_offer_id: $arOfferMap[$obLine->ean],
                match_strategy: 'offer_code',
            );
        }

        return $arResult;
    }

    /**
     * @param  list<string>  $arEans
     * @return array<string, int>
     */
    private function lookupOffersByCode(array $arEans): array
    {
        $arOfferMap = [];
        $obRows = Offer::whereIn('code', $arEans)->get(['id', 'code']);

        foreach ($obRows as $obRow) {
            /** @phpstan-ignore-next-line property.notFound — Lovata Offer lacks IDE-helper PHPDoc; columns verified at DB layer */
            $sCode = (string) $obRow->code;
            /** @phpstan-ignore-next-line property.notFound — Lovata Offer lacks IDE-helper PHPDoc; columns verified at DB layer */
            $iOfferId = (int) $obRow->id;
            $arOfferMap[$sCode] = $iOfferId;
        }

        return $arOfferMap;
    }
}
