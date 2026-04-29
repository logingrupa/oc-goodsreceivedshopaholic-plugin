<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Apply;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InitialResetNotAllowedException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\SettingsAccessor;
use Logingrupa\GoodsReceivedShopaholic\Models\InitialResetSnapshot;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

/**
 * One-shot baseline reset for the goods-received import pipeline
 * (APPLY-05 / QA-06).
 *
 * Used ONCE per site on day-zero so the plugin owns ALL stock state from that
 * point forward. The operator runs reset() before the first .HTM apply; from
 * then on, every offer's quantity and active flag is plugin-managed.
 *
 * Two-gate guard (D-17):
 *   1. `SettingsAccessor::allowInitialReset()` MUST be true. The Settings
 *      toggle is the operator-explicit consent: a misconfigured prod site can
 *      never trigger reset by accident.
 *   2. No prior `Invoice` with `initial_reset_applied=true` may exist. Even
 *      with the toggle on, reset is one-shot — repeated resets would erase
 *      apply-history snapshots and corrupt the audit trail.
 *
 * Operation order is contract (D-18 — SnapshotsBeforeWriteTest):
 *   1. Snapshot every Offer (with its Product) into
 *      logingrupa_goods_received_initial_reset_snapshot. Chunk-of-500 batched
 *      INSERT — NEVER per-row create() (which fires per-row events + 500
 *      individual INSERT statements). The snapshot is the rollback artifact.
 *   2. Zero offers: chunk-of-500 → quantity=0, active=false,
 *      active_managed_by='plugin' via saveQuietly per row.
 *   3. Deactivate products: chunk-of-500 → active=false via saveQuietly.
 *   4. Mark Invoice.initial_reset_applied=true (saveQuietly).
 *
 * Caller controls the DB::transaction boundary — this service does NOT wrap
 * itself, so a Phase 4 controller can compose reset() with other operations.
 * Inside a transaction, partial failure rolls back the entire reset including
 * the snapshot — the operator can retry. Outside, partial failure leaves the
 * snapshot rows AS THE rollback record.
 *
 * Rollback story (D-20): the snapshot is rich enough to reconstruct prior
 * state (quantity + active per offer, active per product). Phase 3 ships ONLY
 * the WRITE path; the rollback CLI lives in the Phase 5 ops runbook.
 *
 * Threat coverage (see plan 03-05 threat register):
 *   - T-03-05-01 Tampering — repeated reset destroys apply history:
 *     mitigated by two-gate guard (Settings + DB one-shot check).
 *   - T-03-05-02 Information disclosure — lost prior state on accidental
 *     reset: mitigated by snapshot-before-write contract.
 *   - T-03-05-03 DoS — OOM on 50,000-offer catalog: mitigated by
 *     chunkById(500) on every iteration.
 *   - T-03-05-04 Tampering — DB::statement bypass of cache hooks: mitigated
 *     by saveQuietly per row + batched InitialResetSnapshot::insert (the
 *     snapshot table has no model handlers, so insert is safe).
 */
final class InitialResetService
{
    private const CHUNK_SIZE = 500;

    private const PROVENANCE_PLUGIN = 'plugin';

    private const REASON_SETTINGS_DISABLED = 'settings_disabled';

    private const REASON_ALREADY_APPLIED = 'already_applied';

    /**
     * Run the one-shot baseline reset. Throws BEFORE any write if either
     * guard fails (defense in depth: an accidental call from a misconfigured
     * site never reaches the write path).
     *
     * @throws InitialResetNotAllowedException
     */
    public function reset(Invoice $obInvoice): void
    {
        $this->assertAllowed();

        $iInvoiceId = (int) $obInvoice->id;
        $this->snapshotAllOffers($iInvoiceId);
        $this->zeroAllOffers();
        $this->deactivateAllProducts();
        $this->markInvoiceReset($obInvoice);
    }

    /**
     * Two-gate guard. The reasons are surfaced via $arContext so the Phase 4
     * controller can render distinct error UX per cause.
     *
     * @throws InitialResetNotAllowedException
     */
    private function assertAllowed(): void
    {
        if (! SettingsAccessor::allowInitialReset()) {
            throw new InitialResetNotAllowedException(
                (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.exception.initial_reset_not_allowed'),
                ['reason' => self::REASON_SETTINGS_DISABLED],
            );
        }

        if (Invoice::where('initial_reset_applied', true)->exists()) {
            throw new InitialResetNotAllowedException(
                (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.exception.initial_reset_not_allowed'),
                ['reason' => self::REASON_ALREADY_APPLIED],
            );
        }
    }

    /**
     * Snapshot every offer's prior state into the snapshot table BEFORE any
     * mutation runs. Chunk-of-500 + batched insert — at most 500 rows are
     * held in memory at any moment, and one INSERT statement covers the
     * batch (D-18). Returns the count of rows inserted for ops audit.
     *
     * Per-chunk product hydration: we collect distinct product_ids from the
     * chunk, fetch them in a single whereIn (id, active) query, then build
     * snapshot rows from the in-memory map. This avoids both the N+1
     * per-offer product fetch AND the larastan.relationExistence false
     * positive Larastan emits when it cannot see October's array-style
     * `$belongsTo` declaration on the Lovata Offer model.
     */
    private function snapshotAllOffers(int $iInvoiceId): int
    {
        $sNow = Carbon::now()->toDateTimeString();
        $iCount = 0;

        Offer::chunkById(self::CHUNK_SIZE, function (EloquentCollection $obChunk) use ($iInvoiceId, $sNow, &$iCount): void {
            $arRows = $this->buildSnapshotChunkRows($iInvoiceId, $obChunk, $sNow);
            if ($arRows === []) {
                return;
            }
            InitialResetSnapshot::insert($arRows);
            $iCount += count($arRows);
        });

        return $iCount;
    }

    /**
     * Build the snapshot row payloads for a single chunk of offers. Hydrates
     * distinct products in ONE query per chunk so the INSERT batch carries
     * the prior_product_active value alongside per-offer state.
     *
     * @param  EloquentCollection<int, \Illuminate\Database\Eloquent\Model>  $obChunk
     * @return list<array<string, int|bool|string|null>>
     */
    private function buildSnapshotChunkRows(int $iInvoiceId, EloquentCollection $obChunk, string $sNow): array
    {
        $arProductActive = $this->hydrateProductActiveMap($obChunk);

        $arRows = [];
        foreach ($obChunk as $obModel) {
            if (! $obModel instanceof Offer) {
                // Defensive narrowing: chunkById's PHPStan closure signature
                // declares EloquentCollection<int, Model>; the runtime type
                // is always Offer (Builder<Offer>), but the analyzer needs
                // the instanceof to typed-narrow into Offer attribute reads.
                continue;
            }

            $iProductId = (int) $obModel->product_id;
            $bProductActive = $arProductActive[$iProductId] ?? false;

            $arRows[] = [
                'invoice_id' => $iInvoiceId,
                'offer_id' => (int) $obModel->id,
                'prior_quantity' => intval($obModel->quantity),
                'prior_offer_active' => (bool) $obModel->active,
                'prior_product_id' => $iProductId,
                'prior_product_active' => $bProductActive,
                'created_at' => $sNow,
            ];
        }

        return $arRows;
    }

    /**
     * One whereIn lookup → map of product_id → active. Avoids the magic
     * `$obOffer->product` accessor (Larastan cannot see October array-style
     * relations on Lovata models) AND collapses what would otherwise be N
     * Product fetches into ONE statement per chunk.
     *
     * @param  EloquentCollection<int, \Illuminate\Database\Eloquent\Model>  $obChunk
     * @return array<int, bool>
     */
    private function hydrateProductActiveMap(EloquentCollection $obChunk): array
    {
        $arProductIds = [];
        foreach ($obChunk as $obModel) {
            if (! $obModel instanceof Offer) {
                continue;
            }
            $iProductId = (int) $obModel->product_id;
            if ($iProductId === 0) {
                // Defensive: Lovata's docblock types product_id as int, but
                // the column is nullable at the schema layer. A 0-cast from a
                // null read here means "no product attached" — skip the
                // hydration row, the row builder defaults prior_product_active
                // to false in that case.
                continue;
            }
            $arProductIds[$iProductId] = true;
        }
        if ($arProductIds === []) {
            return [];
        }

        $arMap = [];
        foreach (Product::whereIn('id', array_keys($arProductIds))->get(['id', 'active']) as $obProduct) {
            if (! $obProduct instanceof Product) {
                continue;
            }
            $arMap[(int) $obProduct->id] = (bool) $obProduct->active;
        }

        return $arMap;
    }

    /**
     * Zero every offer: quantity=0 + active=false + provenance='plugin'.
     * chunkById (NOT chunk) so mid-iteration writes never disturb paging.
     * Per-row saveQuietly — explicit "no event spam, no cache cascade".
     */
    private function zeroAllOffers(): int
    {
        $iCount = 0;
        Offer::chunkById(self::CHUNK_SIZE, function (EloquentCollection $obChunk) use (&$iCount): void {
            foreach ($obChunk as $obModel) {
                if (! $obModel instanceof Offer) {
                    continue;
                }
                $obModel->quantity = 0;
                $obModel->active = false;
                $obModel->active_managed_by = self::PROVENANCE_PLUGIN;
                $obModel->saveQuietly();
                $iCount++;
            }
        });

        return $iCount;
    }

    /**
     * Deactivate every product. Same chunkById + saveQuietly pattern as
     * zeroAllOffers; no provenance column on Product (D-19 keeps Product's
     * prior contract: just active=false).
     */
    private function deactivateAllProducts(): int
    {
        $iCount = 0;
        Product::chunkById(self::CHUNK_SIZE, function (EloquentCollection $obChunk) use (&$iCount): void {
            foreach ($obChunk as $obModel) {
                if (! $obModel instanceof Product) {
                    continue;
                }
                $obModel->active = false;
                $obModel->saveQuietly();
                $iCount++;
            }
        });

        return $iCount;
    }

    /**
     * Final step — flip the invoice's one-shot bit. Subsequent reset attempts
     * (even with allow_initial_reset=true) will be rejected by the
     * already_applied guard.
     */
    private function markInvoiceReset(Invoice $obInvoice): void
    {
        $obInvoice->initial_reset_applied = true;
        $obInvoice->saveQuietly();
    }
}
