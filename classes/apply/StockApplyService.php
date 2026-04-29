<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Apply;

use Carbon\Carbon;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ApplyResult;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use Lovata\Shopaholic\Classes\Item\OfferItem;
use Lovata\Shopaholic\Classes\Store\Offer\ActiveListStore as OfferActiveListStore;
use Lovata\Shopaholic\Classes\Store\Offer\SortingListStore as OfferSortingListStore;
use Lovata\Shopaholic\Classes\Store\OfferListStore;
use Lovata\Shopaholic\Classes\Store\Product\ActiveListStore as ProductActiveListStore;
use Lovata\Shopaholic\Models\Offer;
use October\Rain\Database\Collection as DbCollection;

/**
 * Stock writer for the goods-received import pipeline (APPLY-01 / APPLY-02 / QA-04).
 *
 * Contract:
 *   - `apply()` mutates `lovata_shopaholic_offers.quantity` via Eloquent
 *     `saveQuietly()` so Lovata's `OfferModelHandler::afterSave` is NOT
 *     invoked — eliminating the 8-12 cache-flush cascade per save. Returns
 *     `StockApplyOutcome` (ApplyResult counters + affected_offer_ids list).
 *   - `flushAffectedCaches()` is called by `ApplyOrchestrator` (plan 03-07)
 *     AFTER the DB transaction commits (D-10). Bounded at O(stores), not
 *     O(lines) — the entire QA-04 contract.
 *
 * NEVER uses `DB::statement` (skips cache invalidation hooks AND attribute
 * casting — T-03-03-01). NEVER uses `->save()` per-line (8-12 flushes per
 * offer × N lines = QA-04 anti-pattern; T-03-03-02).
 *
 * Threat coverage (see plan 03-03 threat register):
 *   - T-03-03-01 Tampering via raw DB writes: mitigated (Eloquent only).
 *   - T-03-03-02 DoS via per-save cache cascade: mitigated (saveQuietly +
 *     post-commit batched flush).
 *   - T-03-03-03 Stale-cache race: mitigated (flushAffectedCaches is a
 *     SEPARATE public method the orchestrator calls AFTER commit).
 *   - T-03-03-05 Soft-deleted offer info disclosure: accepted (find() respects
 *     softDelete; deleted offers → line is skipped, audit row preserved).
 */
final class StockApplyService
{
    /**
     * Apply the matched lines of `$obInvoice` to live offer stock.
     *
     * Pass 1 groups lines by `matched_offer_id` and SUMS the effective qty
     * (override_qty when set, else qty) so each offer is hit by exactly ONE
     * `saveQuietly()` write — eliminating duplicate writes when an invoice
     * lists the same offer on multiple lines. Pass 2 then marks each LINE
     * applied=true / applied_at=now (one saveQuietly per line; lines are
     * distinct audit rows even when they share an offer).
     *
     * Offers are batch-fetched via `Offer::whereIn('id', ...)` so the pass
     * issues 1 SELECT + N UPDATE writes regardless of line count (200-line
     * apply ≤ 500 queries — pinned by test).
     *
     * @param  DbCollection<int, InvoiceLine>  $obMatchedLines  Lines passed by
     *                                                          the orchestrator;
     *                                                          may include null-match
     *                                                          rows (counted in
     *                                                          lines_skipped).
     */
    public function apply(Invoice $obInvoice, DbCollection $obMatchedLines): StockApplyOutcome
    {
        unset($obInvoice); // Reserved for future audit context; kept in signature for orchestrator binding.

        $arOfferDeltas = $this->groupLinesByOffer($obMatchedLines);

        $iLinesSkipped = 0;
        $iLinesApplied = 0;
        $iUnitsAdded = 0;
        $arAffectedOfferIds = [];

        // Pass 1: write offer stock — ONE save per UNIQUE offer (sum-by-id),
        // batched-fetch to keep query count O(1) for SELECTs.
        if ($arOfferDeltas !== []) {
            $obOffers = Offer::whereIn('id', array_keys($arOfferDeltas))->get()->keyBy('id');

            foreach ($arOfferDeltas as $iOfferId => $iDelta) {
                $obOffer = $obOffers->get($iOfferId);
                if (! $obOffer instanceof Offer) {
                    // Defensive: line FK to a deleted/soft-deleted offer.
                    // Counted as a skipped line in pass 2.
                    continue;
                }

                $obOffer->quantity = intval($obOffer->quantity) + $iDelta;
                $obOffer->saveQuietly();
                $arAffectedOfferIds[] = $iOfferId;
                $iUnitsAdded += $iDelta;
            }
        }

        // Pass 2: per-LINE applied=true marker (audit row precision).
        foreach ($obMatchedLines as $obLine) {
            if ($obLine->matched_offer_id === null) {
                $iLinesSkipped++;

                continue;
            }
            if (! in_array(intval($obLine->matched_offer_id), $arAffectedOfferIds, true)) {
                // Offer missing in pass 1 (deleted FK) — line is skipped.
                $iLinesSkipped++;

                continue;
            }

            $this->markLineApplied($obLine);
            $iLinesApplied++;
        }

        return new StockApplyOutcome(
            result: new ApplyResult(
                units_added: $iUnitsAdded,
                offers_touched: count($arAffectedOfferIds),
                lines_applied: $iLinesApplied,
                lines_skipped: $iLinesSkipped,
            ),
            affected_offer_ids: array_values(array_unique($arAffectedOfferIds)),
        );
    }

    /**
     * Post-commit batched cache flush. Called by `ApplyOrchestrator` AFTER
     * `DB::transaction` commits (D-10). One flush per affected list-store
     * (NOT one per line — the entire QA-04 contract).
     *
     * Empty `$arOfferIds` is a deliberate no-op so an invoice with zero
     * matched lines does not spuriously invalidate the catalog cache.
     *
     * @param  list<int>  $arOfferIds  De-duplicated offer ids from
     *                                 `StockApplyOutcome::$affected_offer_ids`.
     */
    public function flushAffectedCaches(array $arOfferIds): void
    {
        if ($arOfferIds === []) {
            return;
        }

        // List-level stores: flush ONCE each. The bound is O(stores), so the
        // count stays at 4 regardless of whether the apply touched 1 or 200
        // offers. (QA-04 ≤ 5 list flushes hard contract.)
        //
        // We dispatch via each leaf-class `::instance()` (Singleton trait)
        // rather than `OfferListStore::instance()->active` because the
        // sub-store is the SAME singleton in both paths (registered via
        // `addToStoreList('active', ActiveListStore::class)` which calls
        // `ActiveListStore::instance()`). Going leaf-direct gives PHPStan
        // L10 a typed return (the magic `__get` on AbstractListStore returns
        // `mixed`, which the level-10 caller cannot dereference cleanly).
        OfferActiveListStore::instance()->clear();
        OfferSortingListStore::instance()->clear(OfferListStore::SORT_NO);
        OfferSortingListStore::instance()->clear(OfferListStore::SORT_NEW);
        ProductActiveListStore::instance()->clear();

        // Item-level cache: per-offer-id loop (O(unique_offers), bounded by
        // the dedup'd list — never O(lines × stores)). Each call is a single
        // CCache::clear by tag — cheap, no event cascade.
        foreach ($arOfferIds as $iOfferId) {
            OfferItem::clearCache($iOfferId);
        }
    }

    /**
     * Group matched lines by offer id; sum effective qty (override_qty when
     * set, else qty). Lines with null `matched_offer_id` are silently dropped
     * here — pass 2 of `apply()` counts them in `lines_skipped`.
     *
     * @param  DbCollection<int, InvoiceLine>  $obMatchedLines
     * @return array<int, int>  map<offer_id, total_qty_delta>
     */
    private function groupLinesByOffer(DbCollection $obMatchedLines): array
    {
        $arDeltas = [];

        foreach ($obMatchedLines as $obLine) {
            if ($obLine->matched_offer_id === null) {
                continue;
            }

            $iOfferId = intval($obLine->matched_offer_id);
            $iLineQty = $this->effectiveQty($obLine);
            $arDeltas[$iOfferId] = ($arDeltas[$iOfferId] ?? 0) + $iLineQty;
        }

        return $arDeltas;
    }

    /**
     * Effective qty per line: `override_qty` wins when not null (Phase 4
     * preview-edit support), else `qty` from the parsed invoice row.
     */
    private function effectiveQty(InvoiceLine $obLine): int
    {
        return $obLine->override_qty !== null
            ? intval($obLine->override_qty)
            : intval($obLine->qty);
    }

    /**
     * Per-line audit marker: `applied=true` + `applied_at=now`. saveQuietly()
     * — line saves do NOT need to fire `OfferModelHandler` (the offer write
     * already happened in pass 1) and we don't want to trigger any line-side
     * subscribers either (Phase 3 has no such subscribers; future-proofing).
     */
    private function markLineApplied(InvoiceLine $obLine): void
    {
        $obLine->applied = true;
        $obLine->applied_at = Carbon::now();
        $obLine->saveQuietly();
    }
}
