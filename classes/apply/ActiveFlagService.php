<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Apply;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\SettingsAccessor;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use October\Rain\Database\Collection as DbCollection;

/**
 * Provenance-aware active-flag reconciler (APPLY-03 / APPLY-04 / QA-05).
 *
 * Honors the per-row provenance contract declared by D-11..D-15:
 *   - `active_managed_by='operator'` — NEVER touch (operator manually set).
 *   - `SettingsAccessor::autoDeactivateOnZero()` — qty<=0 → active=false.
 *   - `SettingsAccessor::autoActivateOnStock()` — qty>0 + currently inactive → active=true.
 *
 * When the service toggles `active`, it stamps `active_managed_by='plugin'`
 * so subsequent reconciles know this row is plugin-managed and re-touchable.
 * If a future operator edit flips that to 'operator', this service will
 * back off — that is the load-bearing safety contract for QA-05 / APPLY-03.
 *
 * Idempotent: a second reconcile of the same offers is a no-op (no save fires
 * when the offer is already in the target state). Asserted via query-count
 * test in ActiveFlagServiceTest.
 *
 * Writes go through `saveQuietly()` (D-08 — same rationale as StockApplyService:
 * no event spam, no Lovata `OfferModelHandler::afterSave` cache-cascade fan-out).
 * Cache flush is the orchestrator's responsibility — plan 03-07 ApplyOrchestrator
 * calls `StockApplyService::flushAffectedCaches()` AFTER the DB::transaction
 * commit, OUTSIDE which this service was invoked. ActiveFlagService itself
 * does NOT touch caches.
 *
 * Two entry points:
 *   - `reconcile(list<int>)`: typical Apply path — only the offers an inbound
 *     invoice actually touched. Bounded by the inbound batch size.
 *   - `reconcileAll(int)`: used by Phase 4 console command UI-11
 *     (`goodsreceived:recompute_active_from_stock`) — full table scan in
 *     chunks of `$iChunkSize`. Operator-managed rows are EXCLUDED at the
 *     query level (WHERE clause), not just at the per-row gate.
 *
 * Threat coverage (see plan 03-04 threat register):
 *   - T-03-04-01 Tampering — plugin overrides operator: mitigated. The first
 *     check inside reconcileSingleOffer short-circuits on operator provenance.
 *   - T-03-04-02 Tampering — direct SettingModel read bypass: mitigated.
 *     Service uses SettingsAccessor exclusively; QA-09 grep gate enforces.
 *   - T-03-04-03 DoS via reconcileAll memory: mitigated. chunkById($iChunkSize)
 *     never holds more than $iChunkSize Offer rows in memory.
 *
 * Class is intentionally NOT final — same boundary-mock precedent as
 * D-03-07-01 (ImportAuditService): plan 04-02 RecomputeActiveFromStock
 * console-command tests need to inject a failing service to assert the
 * Throwable-caught + exit-1 contract. ActiveFlagService is a service-layer
 * boundary (the orchestrator + the new CLI both consume it via app() IoC);
 * subclassing it for failure injection is the cleanest path. Production
 * code never subclasses — `app(ActiveFlagService::class)` always resolves
 * the leaf class via the default container binding. (D-04-02-01.)
 */
class ActiveFlagService
{
    private const PROVENANCE_OPERATOR = 'operator';

    private const PROVENANCE_PLUGIN = 'plugin';

    /**
     * Reconcile a specific subset of offers (the typical Apply path).
     *
     * Cheap settings gate up front — if BOTH toggles are off, no offer can
     * possibly change, so we skip the SELECT entirely. Otherwise one batched
     * `whereIn` fetch (matches StockApplyService's query-budget pattern).
     *
     * @param  list<int>  $arOfferIds  De-duplicated offer ids (typically
     *                                 sourced from `StockApplyOutcome::$affected_offer_ids`).
     */
    public function reconcile(array $arOfferIds): void
    {
        if ($arOfferIds === []) {
            return;
        }

        $bAutoDeactivate = SettingsAccessor::autoDeactivateOnZero();
        $bAutoActivate = SettingsAccessor::autoActivateOnStock();

        if (! $bAutoDeactivate && ! $bAutoActivate) {
            return;
        }

        /** @var DbCollection<int, Offer> $obOffers */
        $obOffers = Offer::whereIn('id', $arOfferIds)->get();

        $arActivatedProductIds = $this->reconcileOfferBatch($obOffers, $bAutoDeactivate, $bAutoActivate);

        if ($bAutoActivate && $arActivatedProductIds !== []) {
            $this->reactivateInactiveProducts($arActivatedProductIds);
        }
    }

    /**
     * Iterate the offer batch through `reconcileSingleOffer`, collecting
     * parent product ids of offers that flipped to active=true. Extracted
     * from `reconcile()` so the caller stays under the cyclomatic
     * threshold — the symmetric apply-after-reset case (D-19) requires
     * the parent-product activation pass to live alongside the offer
     * loop, but inlining tripped the gate.
     *
     * @param  DbCollection<int, Offer>  $obOffers
     * @return list<int>  Distinct product ids whose offers just flipped active=true.
     */
    private function reconcileOfferBatch(DbCollection $obOffers, bool $bAutoDeactivate, bool $bAutoActivate): array
    {
        $arActivatedProductIds = [];
        foreach ($obOffers as $obOffer) {
            $bActivated = $this->reconcileSingleOffer($obOffer, $bAutoDeactivate, $bAutoActivate);
            if (! $bActivated || (bool) $obOffer->active !== true) {
                continue;
            }
            $iProductId = (int) $obOffer->product_id;
            if ($iProductId > 0) {
                $arActivatedProductIds[$iProductId] = true;
            }
        }

        return array_keys($arActivatedProductIds);
    }

    /**
     * Activate products that are currently inactive and now have at least
     * one in-stock offer. Single batched UPDATE per call. NO provenance
     * gate on Product (D-19 retained — Lovata Product has no
     * `active_managed_by` column); the only safety is the inactive-only
     * filter so an operator-deactivated product is NOT clobbered.
     *
     * Note: this method does NOT deactivate products on zero stock — that
     * concern is left to a future product-level reconcile pass. Reset is
     * the only path that broadly deactivates products today, so the
     * forward direction (apply re-activates) is the only one needed for
     * the symmetric apply-after-reset workflow.
     *
     * @param  list<int>  $arProductIds  Distinct product ids whose offers
     *                                   just flipped to active=true.
     */
    private function reactivateInactiveProducts(array $arProductIds): void
    {
        if ($arProductIds === []) {
            return;
        }

        Product::whereIn('id', $arProductIds)
            ->where('active', false)
            ->update(['active' => true]);
    }

    /**
     * Reconcile every non-operator offer in chunks. Used by Phase 4 console
     * command UI-11 (`goodsreceived:recompute_active_from_stock`).
     *
     * Uses `chunkById` (NOT `chunk`) — when a row is updated mid-iteration
     * its position can shift under offset-based chunk(); chunkById sorts by
     * primary key and pages by id range so updates never disturb iteration.
     *
     * Operator-managed offers are excluded AT THE QUERY LEVEL, so they never
     * even hydrate into memory — defense-in-depth atop the per-row provenance
     * gate inside `reconcileSingleOffer`.
     *
     * @return int  count of offers actually touched (saveQuietly fired)
     */
    public function reconcileAll(int $iChunkSize = 500): int
    {
        $bAutoDeactivate = SettingsAccessor::autoDeactivateOnZero();
        $bAutoActivate = SettingsAccessor::autoActivateOnStock();

        if (! $bAutoDeactivate && ! $bAutoActivate) {
            return 0;
        }

        $iTouched = 0;
        Offer::where('active_managed_by', '!=', self::PROVENANCE_OPERATOR)
            ->chunkById($iChunkSize, function (EloquentCollection $obChunk) use (&$iTouched, $bAutoDeactivate, $bAutoActivate): void {
                foreach ($obChunk as $obModel) {
                    if (! $obModel instanceof Offer) {
                        // Defensive narrowing: chunkById's PHPStan closure
                        // signature declares the parameter as Eloquent\Model
                        // (the base type for Builder<Model>). The WHERE clause
                        // guarantees Offer rows at runtime; instanceof gives
                        // PHPStan L10 the typed narrowing into reconcileSingleOffer.
                        continue;
                    }
                    if ($this->reconcileSingleOffer($obModel, $bAutoDeactivate, $bAutoActivate)) {
                        $iTouched++;
                    }
                }
            });

        return $iTouched;
    }

    /**
     * Reconcile a single offer. Returns true iff a saveQuietly() fired.
     *
     * The provenance gate is the FIRST check — operator-managed offers are
     * skipped before settings are even consulted. This is the QA-05 contract
     * surface: even with `autoActivateOnStock=true` and qty=10, an
     * operator-locked offer must NOT be touched.
     */
    private function reconcileSingleOffer(Offer $obOffer, bool $bAutoDeactivate, bool $bAutoActivate): bool
    {
        if ($this->managedByOperator($obOffer)) {
            return false;
        }

        $bTarget = $this->decideTargetState($obOffer, $bAutoDeactivate, $bAutoActivate);
        if ($bTarget === null) {
            return false;
        }

        if ((bool) $obOffer->active === $bTarget) {
            // Idempotent: already in target state, no save needed.
            return false;
        }

        $obOffer->active = $bTarget;
        $obOffer->active_managed_by = self::PROVENANCE_PLUGIN;
        $obOffer->saveQuietly();

        return true;
    }

    /**
     * Provenance check on an Offer row. PHPStan L10 sees the Eloquent magic
     * property as `mixed`; the explicit `is_scalar` guard narrows it to a type
     * `strval()` accepts, satisfying the analyzer without inline @var or
     * inline ignore directives. The column DDL is `string(16) default 'system'`
     * so the runtime type is always string — the guard is a static-analysis
     * formality, not a runtime branch we expect to hit.
     */
    private function managedByOperator(Offer $obOffer): bool
    {
        $mValue = $obOffer->active_managed_by;
        if (! is_scalar($mValue)) {
            return false;
        }

        return strval($mValue) === self::PROVENANCE_OPERATOR;
    }

    /**
     * 4-cell decision matrix (D-13). Returns:
     *   - false  → target state should be `active=false` (deactivate).
     *   - true   → target state should be `active=true` (activate).
     *   - null   → no change requested by the active settings.
     *
     * Note: when the offer is already in the requested state, this method
     * still returns the target — the idempotency early-return lives in
     * `reconcileSingleOffer`. Keeping the matrix logic pure makes it
     * easier to reason about against the D-13 truth table.
     */
    private function decideTargetState(Offer $obOffer, bool $bAutoDeactivate, bool $bAutoActivate): ?bool
    {
        $iQty = intval($obOffer->quantity);

        if ($iQty <= 0 && $bAutoDeactivate) {
            return false;
        }

        if ($iQty > 0 && $bAutoActivate && (bool) $obOffer->active === false) {
            return true;
        }

        return null;
    }
}
