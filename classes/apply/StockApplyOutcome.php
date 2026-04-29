<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Apply;

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ApplyResult;

/**
 * Carrier returned by `StockApplyService::apply()`. Per D-29 (tuple decision),
 * Phase 2's `ApplyResult` DTO stays as a pure 4-int counter bag and the list
 * of `affected_offer_ids` (consumed by `flushAffectedCaches()` post-commit and
 * by `ActiveFlagService::reconcile()` inside the same transaction) is carried
 * alongside via this small carrier rather than mutating the locked DTO shape.
 *
 * Why a separate type instead of an inline tuple: type-safety + naming. The
 * orchestrator (plan 03-07) reads `$obOutcome->result` and
 * `$obOutcome->affected_offer_ids` directly — no destructuring ambiguity.
 *
 * @property-read ApplyResult $result
 * @property-read list<int> $affected_offer_ids
 */
final readonly class StockApplyOutcome
{
    /**
     * @param  list<int>  $affected_offer_ids  De-duplicated offer ids touched
     *                                         by the apply pass; passed AS-IS
     *                                         to `flushAffectedCaches()` and
     *                                         to `ActiveFlagService::reconcile()`.
     */
    public function __construct(
        public ApplyResult $result,
        public array $affected_offer_ids,
    ) {
    }
}
