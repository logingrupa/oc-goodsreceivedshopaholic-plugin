<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Dto;

/**
 * Match decision DTO produced by EanMatcherService (Phase 2 plan 02-06) for
 * each ParsedLine. Wraps the original line, the resolved offer id (or null
 * for unmatched lines), and the literal-string strategy used to resolve it.
 *
 * Strategy is a string union (D-26) for SQLite portability with Phase 1
 * persistence — no enum object so legacy storage remains a flat varchar.
 *
 * Phase 6 / D-25-update widens the union with `'variation'` for the new
 * Pass 3 chain stage (offer-name variation token match). Duplicate-code
 * handling (2026-09-08) adds `'offer_code_active'` (one active offer in a
 * duplicate group, picked by rule) and `'ambiguous'` (no rule applies,
 * `matched_offer_id` null until the operator picks; that choice is stored
 * as `'offer_code_operator'` on the InvoiceLine row, never emitted here).
 * DB column `match_strategy varchar(32)` fits every literal — no migration.
 *
 * @property-read ParsedLine $line
 * @property-read int|null $matched_offer_id
 * @property-read 'offer_code'|'product_code_single_offer'|'offer_code_active'|'ambiguous'|'variation'|'none' $match_strategy
 */
final readonly class MatchedLine
{
    /**
     * @param  'offer_code'|'product_code_single_offer'|'offer_code_active'|'ambiguous'|'variation'|'none'  $match_strategy
     */
    public function __construct(
        public ParsedLine $line,
        public ?int $matched_offer_id,
        public string $match_strategy,
    ) {
    }
}
