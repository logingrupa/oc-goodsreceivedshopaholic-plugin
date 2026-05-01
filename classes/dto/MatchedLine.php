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
 * Pass 3 chain stage (offer-name variation token match). DB column
 * `match_strategy varchar(32)` already fits — no migration shipped.
 *
 * @property-read ParsedLine $line
 * @property-read int|null $matched_offer_id
 * @property-read 'offer_code'|'product_code_single_offer'|'variation'|'none' $match_strategy
 */
final readonly class MatchedLine
{
    /**
     * @param  'offer_code'|'product_code_single_offer'|'variation'|'none'  $match_strategy
     */
    public function __construct(
        public ParsedLine $line,
        public ?int $matched_offer_id,
        public string $match_strategy,
    ) {
    }
}
