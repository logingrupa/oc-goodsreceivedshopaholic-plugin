<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Apply;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InitialResetNotAllowedException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\SettingsAccessor;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

/**
 * One-shot baseline reset for the goods-received import pipeline.
 *
 * Used ONCE per site on day-zero so the plugin owns ALL stock state from that
 * point forward. The operator runs `reset()` before the first .HTM apply; from
 * then on, every offer's quantity and active flag is plugin-managed.
 *
 * Two-gate guard:
 *   1. `SettingsAccessor::allowInitialReset()` MUST be true. The Settings
 *      toggle is the operator-explicit consent — a misconfigured prod site can
 *      never trigger reset by accident.
 *   2. No prior `Invoice` with `initial_reset_applied=true` may exist. Even
 *      with the toggle on, reset is one-shot — repeated resets would erase
 *      apply-history snapshots and corrupt the audit trail.
 *
 * Operation order:
 *   1. Zero offers: chunk-of-500 → quantity=0, active=false,
 *      active_managed_by='plugin' via saveQuietly per row.
 *   2. Deactivate products: chunk-of-500 → active=false via saveQuietly.
 *   3. Mark Invoice.initial_reset_applied=true (saveQuietly).
 *
 * Caller controls the DB::transaction boundary — this service does NOT wrap
 * itself, so the controller can compose reset() with other operations.
 *
 * Snapshot dropped 2026-05-05 — there is no programmatic restore path
 * (rollback would only ever run once and the operator can recover from the
 * 3h backup cron). The snapshot table + writer code added unmaintained
 * scaffolding; deletion via the v1.0.2 migration consolidates the contract.
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

        $this->zeroAllOffers();
        $this->deactivateAllProducts();
        $this->markInvoiceReset($obInvoice);
    }

    /**
     * Two-gate guard. The reasons are surfaced via $arContext so the
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
     * zeroAllOffers; no provenance column on Product (Lovata Product has no
     * `active_managed_by` — just active=false).
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
