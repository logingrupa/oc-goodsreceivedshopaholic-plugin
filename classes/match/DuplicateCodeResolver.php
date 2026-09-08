<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Match;

use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;

/**
 * Decides, per code, which offer a chain stage may emit when the same code
 * belongs to more than one live offer. Shared by OfferCodeMatcher and
 * ProductCodeSingleOfferMatcher so the rule lives once.
 *
 * Rule per code group:
 *   - one candidate            -> that offer, the stage's own strategy
 *   - 2+ candidates, 1 active  -> the active offer, `offer_code_active`
 *   - 2+ candidates, 0 or 2+ active -> no offer, `ambiguous` (operator picks)
 *
 * Pure PHP over rows the stage already fetched: no query of its own, so the
 * chain stays at one SELECT per stage.
 */
final class DuplicateCodeResolver
{
    /**
     * @param  list<array{code: string, offer_id: int, active: bool}>  $arCandidates
     * @param  'offer_code'|'product_code_single_offer'  $sUniqueStrategy
     * @return array<string, array{offer_id: ?int, strategy: 'offer_code'|'product_code_single_offer'|'offer_code_active'|'ambiguous'}>
     */
    public static function resolveByCode(array $arCandidates, string $sUniqueStrategy): array
    {
        $arGrouped = [];
        foreach ($arCandidates as $arCandidate) {
            $arGrouped[$arCandidate['code']][] = $arCandidate;
        }

        $arDecisions = [];
        foreach ($arGrouped as $sCode => $arGroup) {
            $arDecisions[(string) $sCode] = self::decide($arGroup, $sUniqueStrategy);
        }

        return $arDecisions;
    }

    /**
     * @param  non-empty-list<array{code: string, offer_id: int, active: bool}>  $arGroup
     * @param  'offer_code'|'product_code_single_offer'  $sUniqueStrategy
     * @return array{offer_id: ?int, strategy: 'offer_code'|'product_code_single_offer'|'offer_code_active'|'ambiguous'}
     */
    private static function decide(array $arGroup, string $sUniqueStrategy): array
    {
        if (count($arGroup) === 1) {
            return ['offer_id' => $arGroup[0]['offer_id'], 'strategy' => $sUniqueStrategy];
        }

        $arActive = array_values(array_filter(
            $arGroup,
            static fn (array $arCandidate): bool => $arCandidate['active'],
        ));
        if (count($arActive) === 1) {
            return [
                'offer_id' => $arActive[0]['offer_id'],
                'strategy' => InvoiceLine::MATCH_STRATEGY_OFFER_CODE_ACTIVE,
            ];
        }

        return ['offer_id' => null, 'strategy' => InvoiceLine::MATCH_STRATEGY_AMBIGUOUS];
    }
}
