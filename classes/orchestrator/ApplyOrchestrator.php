<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\ActiveFlagService;
use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\StockApplyOutcome;
use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\StockApplyService;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ApplyResult;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\ApplyAlreadyDoneException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\ImportAuditService;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use October\Rain\Database\Collection as DbCollection;

/**
 * Apply-side orchestrator for the goods-received import pipeline
 * (APPLY-07 / APPLY-08 / QA-03 / QA-08 / D-23..D-25).
 *
 * Sequence (D-24):
 *   1) DB::transaction starts — the FIRST tx is the lock holder. lockForUpdate()
 *      OUTSIDE a transaction would be a no-op on most drivers (the row lock
 *      releases immediately because there is no tx to hold it). Opening the
 *      transaction FIRST and acquiring the lock INSIDE is the canonical
 *      Laravel/Postgres/MySQL pattern: the lock is held until commit/rollback.
 *   2) `Invoice::where('id', $iId)->lockForUpdate()->firstOrFail()` — concurrent
 *      apply() calls on the SAME invoice id block on this row lock. Pinned
 *      structurally by `LockForUpdateSerializesConcurrentApplyTest` (the lock
 *      SELECT precedes any stock-write UPDATE in the query log).
 *   3) `status==='applied'` → throw `ApplyAlreadyDoneException` with rich prior
 *      result context (invoice_id, invoice_number, prior_applied_at,
 *      prior_applied_by, prior_stock_added_units). Pinned by
 *      `ApplyAlreadyDoneThrowsTest`.
 *   4) `StockApplyService::apply` → returns `StockApplyOutcome`
 *      (`ApplyResult` counters + `affected_offer_ids` list). Per-line
 *      `applied=true / applied_at=now` is set inside this call.
 *   5) `ActiveFlagService::reconcile($outcome->affected_offer_ids)` — INSIDE
 *      the SAME transaction (QA-08 contract:
 *      `ActiveFlagInsideSameTransactionAsStockApplyTest`).
 *   6) Invoice header flips: `status='applied'`, `applied_at=now`,
 *      `applied_by_user_id=$iAppliedByUserId`, `stock_added_units=$result->units_added`.
 *      `saveQuietly()` — counter update does not need to fire model handlers.
 *   7) `ImportAuditService::logApply` — INSIDE the tx so the log entry is
 *      written (and rolled back) atomically with the rest of the unit. Phase 4
 *      consumers join by `correlation_id` + invoice status to detect any
 *      "intent vs outcome" mismatch (T-03-07-07).
 *   8) tx COMMITS — at this point the writes are durable.
 *   9) `StockApplyService::flushAffectedCaches($outcome->affected_offer_ids)` —
 *      OUTSIDE the closure (D-10). Flushing INSIDE the tx repopulates list
 *      stores from in-flight stale data; flushing AFTER commit is the only
 *      correct ordering.
 *
 * Override-reimport semantics (D-28): no special case here. The orchestrator
 * runs identically on override invoices. Their `override_of_invoice_id`
 * pointer is metadata only; apply behavior is ADDITIVE because
 * `StockApplyService` does `qty += delta`. Pinned by
 * `OverrideReimportAddsOnTopTest`.
 *
 * Threat coverage (see plan 03-07 threat register):
 *   - T-03-07-01 Tampering — concurrent apply on same invoice creates duplicate
 *     stock writes: mitigated. `Invoice::lockForUpdate()` inside the tx
 *     serializes via row-lock. The second click blocks until the first
 *     commits, then sees `status='applied'` and throws
 *     `ApplyAlreadyDoneException`.
 *   - T-03-07-02 Tampering — half-applied invoice (stock writes committed but
 *     status=parsed): mitigated. Single tx boundary; status flip is the LAST
 *     write before audit log; any failure rolls back all four writes (stock
 *     + active-flag + status + audit). Pinned by
 *     `PartialFailureRollsBackEverythingTest`.
 *   - T-03-07-03 Tampering — ActiveFlag write outside the apply transaction:
 *     mitigated. `reconcile()` is called from inside `executeInTransaction`,
 *     observed at runtime via `DB::beforeExecuting` transactionLevel hook.
 *   - T-03-07-04 Tampering — cache flush inside transaction repopulates from
 *     stale data: mitigated. `flushAffectedCaches` is called AFTER
 *     `DB::transaction` returns — outside the closure, in `apply()`.
 *
 * No try/catch around `DB::transaction` here: typed exceptions propagate to
 * the Phase 4 controller. The transaction rolls back automatically;
 * `ApplyAlreadyDoneException` and any other `GoodsReceivedException` reach
 * the caller cleanly. Tiger-Style fail-fast — log + rethrow happens at the
 * boundary layer (controller / console command), not here.
 *
 * Boundary-mock note (D-04-05-01 / mirror of D-03-07-01 + D-04-02-01): NOT
 * marked `final` so the Phase 4 `onApply` AJAX handler can be exercised in
 * Pest unit tests with a synthetic failing-orchestrator subclass that pins
 * the `try { ... } finally { $obLock->release(); }` lock-release contract
 * (T-04-05-02). Production code never subclasses — `app(ApplyOrchestrator::class)`
 * always resolves the leaf class.
 *
 * @internal The class behaves as if final at the production-code boundary.
 *           Subclassing is sanctioned ONLY for unit-test failing-orchestrator
 *           shims used to pin boundary-layer cleanup contracts.
 */
class ApplyOrchestrator
{
    private readonly StockApplyService $obStockApply;

    private readonly ActiveFlagService $obActiveFlag;

    private readonly ImportAuditService $obAudit;

    /**
     * Constructor with optional injection — production callers use the
     * default zero-arg form; tests can swap in spies/fakes by passing
     * explicit instances. Defaults are constructed in the body (NOT as
     * `new` parameter defaults) for the same reason
     * `ParseAndPersistOrchestrator` does: PHPStan strict-types and PHP 8.3
     * default-value rules stay quiet across dev environments.
     */
    public function __construct(
        ?StockApplyService $obStockApply = null,
        ?ActiveFlagService $obActiveFlag = null,
        ?ImportAuditService $obAudit = null,
    ) {
        $this->obStockApply = $obStockApply ?? new StockApplyService();
        $this->obActiveFlag = $obActiveFlag ?? new ActiveFlagService();
        $this->obAudit = $obAudit ?? new ImportAuditService();
    }

    /**
     * Apply the parsed invoice identified by `$iInvoiceId` to live offer stock.
     *
     * @throws ApplyAlreadyDoneException When the invoice is already in
     *                                   `status='applied'` (idempotency gate).
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When the
     *                                                              invoice id
     *                                                              does not
     *                                                              exist.
     */
    public function apply(int $iInvoiceId, int $iAppliedByUserId): ApplyResult
    {
        /** @var StockApplyOutcome $obOutcome */
        $obOutcome = DB::transaction(
            fn (): StockApplyOutcome => $this->executeInTransaction($iInvoiceId, $iAppliedByUserId),
        );

        // Post-commit cache flush (D-10). Flushing INSIDE the tx repopulates
        // stores from in-flight stale data; the only correct ordering is
        // AFTER commit.
        $this->obStockApply->flushAffectedCaches($obOutcome->affected_offer_ids);

        return $obOutcome->result;
    }

    /**
     * The transactional unit: lock + status check + stock writes + active-flag
     * reconcile + status flip + audit log. Any throw inside this body triggers
     * full rollback (T-03-07-02).
     *
     * @throws ApplyAlreadyDoneException
     */
    private function executeInTransaction(int $iInvoiceId, int $iAppliedByUserId): StockApplyOutcome
    {
        $obInvoice = Invoice::where('id', $iInvoiceId)->lockForUpdate()->firstOrFail();
        if (! $obInvoice instanceof Invoice) {
            // Defensive: firstOrFail returns Eloquent\Model in PHPStan's view,
            // not the typed Invoice. instanceof gives the analyzer the
            // narrowing it needs without inline @var.
            throw new \RuntimeException('Invoice::firstOrFail returned non-Invoice');
        }

        $this->assertNotApplied($obInvoice);

        // Query InvoiceLine directly via the model rather than the Invoice
        // hasMany magic-relation accessor: PHPStan L10 sees October's hasMany
        // declaration as `mixed`, so `->lines()->whereNotNull(...)` cannot be
        // narrowed without inline @var (forbidden by phpstan.neon comments).
        // Going through the model class gives a typed Builder<InvoiceLine>
        // and the same SELECT plan (the FK index covers it).
        //
        // Re-narrow into a typed October\Rain Database\Collection<int, InvoiceLine>
        // — runtime behavior is identical (October's class extends Eloquent's
        // Collection), but the explicit instanceof loop satisfies PHPStan L10
        // generic-tracking without inline directives.
        $obMatchedLines = $this->loadMatchedLines((int) $obInvoice->id);

        $obOutcome = $this->obStockApply->apply($obInvoice, $obMatchedLines);

        // ActiveFlagService runs INSIDE the same tx (QA-08 / T-03-07-03).
        $this->obActiveFlag->reconcile($obOutcome->affected_offer_ids);

        $this->markInvoiceApplied($obInvoice, $obOutcome, $iAppliedByUserId);

        $this->obAudit->logApply((int) $obInvoice->id, $obOutcome->result, $iAppliedByUserId);

        return $obOutcome;
    }

    /**
     * Idempotency gate (D-24 step 2 / QA-03). Throws on a previously-applied
     * invoice with rich prior-result context so the Phase 4 controller can
     * render a useful error to the operator: when, by whom, and how many
     * units the prior apply added.
     *
     * @throws ApplyAlreadyDoneException
     */
    private function assertNotApplied(Invoice $obInvoice): void
    {
        if ((string) $obInvoice->status !== Invoice::STATUS_APPLIED) {
            return;
        }

        $mPriorAppliedAt = $obInvoice->applied_at;
        $sPriorAppliedAt = $mPriorAppliedAt instanceof Carbon
            ? $mPriorAppliedAt->toIso8601String()
            : null;

        throw new ApplyAlreadyDoneException(
            (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.exception.apply_already_done'),
            [
                'invoice_id' => (int) $obInvoice->id,
                'invoice_number' => $obInvoice->invoice_number,
                'prior_applied_at' => $sPriorAppliedAt,
                'prior_applied_by' => $obInvoice->applied_by_user_id,
                'prior_stock_added_units' => (int) $obInvoice->stock_added_units,
            ],
        );
    }

    /**
     * Load matched lines for `$iInvoiceId` and return a typed October\Rain
     * Database\Collection<int, InvoiceLine>. Querying through the
     * `InvoiceLine` model (NOT the `Invoice::lines()` magic relation) gives
     * PHPStan L10 a typed `Builder<InvoiceLine>`; the explicit instanceof
     * loop then narrows the runtime rows into a `list<InvoiceLine>` that
     * the October Collection constructor accepts. Same SELECT plan as the
     * magic relation (the `matched_offer_id` index covers the WHERE).
     *
     * @return DbCollection<int, InvoiceLine>
     */
    private function loadMatchedLines(int $iInvoiceId): DbCollection
    {
        /** @var list<InvoiceLine> $arRows */
        $arRows = [];
        foreach (InvoiceLine::where('invoice_id', $iInvoiceId)
            ->whereNotNull('matched_offer_id')
            ->get() as $obRow) {
            if ($obRow instanceof InvoiceLine) {
                $arRows[] = $obRow;
            }
        }

        /** @var DbCollection<int, InvoiceLine> $obCollection */
        $obCollection = new DbCollection($arRows);

        return $obCollection;
    }

    /**
     * Stamp the Invoice header with apply-completion metadata. `saveQuietly`
     * — header-counter updates do not need to fire model handlers; the
     * load-bearing writes (offer.quantity, offer.active) already happened in
     * `StockApplyService::apply` and `ActiveFlagService::reconcile`.
     */
    private function markInvoiceApplied(
        Invoice $obInvoice,
        StockApplyOutcome $obOutcome,
        int $iAppliedByUserId,
    ): void {
        $obInvoice->status = Invoice::STATUS_APPLIED;
        $obInvoice->applied_at = Carbon::now();
        $obInvoice->applied_by_user_id = $iAppliedByUserId;
        $obInvoice->stock_added_units = $obOutcome->result->units_added;
        $obInvoice->saveQuietly();
    }
}
