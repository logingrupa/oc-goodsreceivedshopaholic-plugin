<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Match;

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\MatchedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvalidEanException;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

/**
 * EAN → Offer-id resolver for the goods-received import pipeline (Phase 2 plan
 * 02-06). Reads `lovata_shopaholic_offers.code` first; falls back to
 * `lovata_shopaholic_products.code` with a single-offer guard. Issues exactly
 * TWO queries per `matchBatch` call regardless of input size — proven by the
 * `DB::enableQueryLog` count assertion in `EanMatcherServiceTest`.
 *
 * Public surface (D-24..D-26):
 *   - `matchBatch(list<string> $arEans): array<string, MatchResult>` — input
 *     EAN list, output map keyed by EAN preserving leading-zero strings.
 *   - `buildMatchedLines(list<ParsedLine>, MatchMap): list<MatchedLine>` —
 *     convenience wrapper for the Phase 3 ParseAndPersistOrchestrator.
 *
 * MatchResult shape (D-26):
 *   `array{matched_offer_id: int|null, match_strategy: 'offer_code'|'product_code_single_offer'|'none'}`
 *
 * Strategy decision matrix:
 *   | Found in                                  | match_strategy               |
 *   |-------------------------------------------|------------------------------|
 *   | offers.code (Pass 1)                      | offer_code                   |
 *   | products.code with exactly 1 offer (P2)   | product_code_single_offer    |
 *   | products.code with 0 or >=2 offers (P2)   | none                         |
 *   | nowhere                                   | none                         |
 *
 * Threat-model coverage:
 *   - T-02-06-01 (SQL injection via attacker-controlled EAN): Eloquent
 *     `whereIn` parameterizes IN-clause via PDO bind variables — attacker
 *     strings never touch the SQL string. Defense-in-depth:
 *     `assertAllEansValid` regex-rejects anything not exactly 13 digits
 *     BEFORE the query is built, so even a malformed EAN that bypasses the
 *     parser cannot reach the DB. Tested explicitly via the
 *     "InvalidEan throws BEFORE any DB query" test.
 *   - T-02-06-04 (silent leading-zero strip): EAN strings flow through
 *     `whereIn` and the result map keys verbatim. PHP keeps `'0000000012345'`
 *     as a string array key (it's not a "decimal-int representable" string).
 *     Pinned by QA-02 PreservesLeadingZeroEanTest.
 *
 * Hungarian notation throughout per Lovata Toolbox standard.
 */
final class EanMatcherService
{
    /**
     * Strict 13-digit EAN regex. Defense-in-depth check at the matcher
     * boundary — the parser already filters non-13-digit rows (D-16 lenient
     * skip). If a bug ever lets a malformed EAN through, this assertion
     * raises `InvalidEanException` BEFORE any DB query.
     */
    private const string EAN_REGEX = '/^\d{13}$/';

    /**
     * Resolve a batch of EAN strings to offer ids using exactly TWO queries
     * (one query when all EANs are matched in Pass 1; zero queries when the
     * input is empty).
     *
     * @param  list<string>  $arEans
     * @return array<string, array{matched_offer_id: int|null, match_strategy: 'offer_code'|'product_code_single_offer'|'none'}>
     *
     * @throws InvalidEanException  on any input that is not exactly 13 digits
     */
    public function matchBatch(array $arEans): array
    {
        $this->assertAllEansValid($arEans);

        if ($arEans === []) {
            return [];
        }

        $arUnique = array_values(array_unique($arEans));

        // Pass 1 — Offer.code direct match (one query).
        $arOfferMap = $this->lookupOffersByCode($arUnique);

        $arUnmatched = array_values(array_diff($arUnique, array_keys($arOfferMap)));

        // Pass 2 — Product.code with single-offer guard (one query, skipped
        // when every EAN already matched in Pass 1).
        $arProductMap = $this->lookupProductsWithSingleOffer($arUnmatched);

        return $this->assembleResultMap($arUnique, $arOfferMap, $arProductMap);
    }

    /**
     * Convert a list of ParsedLine + match-map into a list of MatchedLine
     * DTOs preserving input order. Defensive default: any line whose EAN is
     * absent from the map lands as `match_strategy='none'`.
     *
     * @param  list<ParsedLine>  $arLines
     * @param  array<string, array{matched_offer_id: int|null, match_strategy: string}>  $arMatchMap
     * @return list<MatchedLine>
     */
    public function buildMatchedLines(array $arLines, array $arMatchMap): array
    {
        $arResult = [];

        foreach ($arLines as $obLine) {
            $arEntry = $arMatchMap[$obLine->ean] ?? ['matched_offer_id' => null, 'match_strategy' => 'none'];

            /** @var 'offer_code'|'product_code_single_offer'|'none' $sStrategy */
            $sStrategy = $arEntry['match_strategy'];

            $arResult[] = new MatchedLine(
                line: $obLine,
                matched_offer_id: $arEntry['matched_offer_id'],
                match_strategy: $sStrategy,
            );
        }

        return $arResult;
    }

    /**
     * Defense-in-depth EAN regex guard. Throws BEFORE any DB query so an
     * invalid EAN cannot reach the IN-clause builder (T-02-06-01).
     *
     * @param  list<string>  $arEans
     *
     * @throws InvalidEanException
     */
    private function assertAllEansValid(array $arEans): void
    {
        foreach ($arEans as $sEan) {
            if (preg_match(self::EAN_REGEX, $sEan) === 1) {
                continue;
            }

            throw new InvalidEanException(
                (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.exception.invalid_ean'),
                ['raw' => $sEan, 'expected_format' => '13 digits'],
            );
        }
    }

    /**
     * Pass 1 — Offer.code direct match. Returns map<ean, offer_id>.
     *
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

    /**
     * Pass 2 — Product.code match with `has('offer', '=', 1)` single-offer
     * guard. Skipped when input is empty (Pass 1 caught everything). Returns
     * map<ean, offer_id>.
     *
     * Single-query strategy (D-25 "exactly TWO queries" budget):
     *   - `has('offer', '=', 1)` adds a correlated COUNT subquery to the
     *     WHERE clause — same SQL statement, NOT a second round-trip.
     *   - `addSelect(...subquery...)` inlines the sole offer's `id` via a
     *     correlated SELECT subquery — same SQL statement.
     * Net: one SELECT statement, no `->with()` eager-load round-trip.
     *
     * `->limit(1)` on the subquery is defense-in-depth: the WHERE guard
     * already restricts to exactly-one-offer products, but the LIMIT keeps
     * the subquery deterministic if the guard ever drifts.
     *
     * @param  list<string>  $arEans
     * @return array<string, int>
     */
    private function lookupProductsWithSingleOffer(array $arEans): array
    {
        if ($arEans === []) {
            return [];
        }

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
                // Safety net: has('offer', '=', 1) should guarantee a numeric
                // id, but the correlated subquery may return null under
                // driver edge cases (deleted_at race, broken FK). Treat any
                // non-numeric (incl. null) as "skip this row".
                continue;
            }

            /** @phpstan-ignore-next-line property.notFound — Lovata Product lacks IDE-helper PHPDoc; columns verified at DB layer */
            $sCode = (string) $obRow->code;

            $arProductMap[$sCode] = (int) $mOfferId;
        }

        return $arProductMap;
    }

    /**
     * Assemble the final result map for every unique EAN, picking the first
     * available strategy (offer_code → product_code_single_offer → none).
     *
     * @param  list<string>  $arUnique
     * @param  array<string, int>  $arOfferMap
     * @param  array<string, int>  $arProductMap
     * @return array<string, array{matched_offer_id: int|null, match_strategy: 'offer_code'|'product_code_single_offer'|'none'}>
     */
    private function assembleResultMap(array $arUnique, array $arOfferMap, array $arProductMap): array
    {
        $arResult = [];

        foreach ($arUnique as $sEan) {
            if (isset($arOfferMap[$sEan])) {
                $arResult[$sEan] = [
                    'matched_offer_id' => $arOfferMap[$sEan],
                    'match_strategy' => 'offer_code',
                ];

                continue;
            }

            if (isset($arProductMap[$sEan])) {
                $arResult[$sEan] = [
                    'matched_offer_id' => $arProductMap[$sEan],
                    'match_strategy' => 'product_code_single_offer',
                ];

                continue;
            }

            $arResult[$sEan] = [
                'matched_offer_id' => null,
                'match_strategy' => 'none',
            ];
        }

        return $arResult;
    }
}
