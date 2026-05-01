<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Match;

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\MatchedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedLine;

/**
 * Chain-stage contract for the EAN match pipeline (Phase 6 / D-25-update).
 *
 * Each implementer represents one pass in the chain runner orchestrated by
 * EanMatcherService. Stages execute in deterministic order
 * (OfferCodeMatcher → ProductCodeSingleOfferMatcher → VariationMatcher),
 * each receiving ONLY the residue unmatched by prior stages, returning
 * its own list of MatchedLine DTOs.
 *
 * Total chain query budget = 3 (one per stage, regardless of input size).
 * Counter-pinned by EanMatcherServiceTest::it_runs_at_most_3_queries (plan
 * 06-05).
 *
 * Implementers MUST be `final` (Tiger-Style: no subclassing). Implementers
 * MUST decorate `match()` with `#[\Override]`.
 */
interface MatchStrategy
{
    /**
     * Match a list of ParsedLine residue against this stage's strategy.
     * Lines this stage cannot match are simply omitted from the return
     * list — the chain runner forwards the residue (any input ParsedLine
     * NOT present in the return list) to the next stage.
     *
     * @param  list<ParsedLine>  $arUnmatched  ParsedLines unmatched by prior chain stages.
     * @return list<MatchedLine>  Matches produced by this stage (subset of input).
     */
    public function match(array $arUnmatched): array;
}
