<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Dto;

/**
 * Summary DTO returned by Phase 3 ApplyOrchestrator::run(). Locked here so
 * Phase 2 sibling plans and Phase 3 can build against the contract without
 * later schema drift. Pure 4-int counter bag; no aggregation logic.
 *
 * @property-read int $units_added       Sum of qty across all applied lines
 * @property-read int $offers_touched    Count of distinct offers whose stock changed
 * @property-read int $lines_applied     Count of lines successfully applied
 * @property-read int $lines_skipped     Count of lines skipped (unmatched / invalid)
 */
final readonly class ApplyResult
{
    public function __construct(
        public int $units_added,
        public int $offers_touched,
        public int $lines_applied,
        public int $lines_skipped,
    ) {
    }
}
