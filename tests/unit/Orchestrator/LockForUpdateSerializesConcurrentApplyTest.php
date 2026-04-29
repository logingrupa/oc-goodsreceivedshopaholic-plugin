<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ApplyOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use Lovata\Shopaholic\Models\Offer;

require_once __DIR__.'/../Apply/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * QA-03 LockForUpdateSerializesConcurrentApplyTest (plan 03-07 task 2 / D-24 step 1).
 *
 * Structural pin on the row-locking contract via TWO independent checks:
 *
 *   1) Source-level: ApplyOrchestrator.php contains `->lockForUpdate()` on
 *      the Invoice select inside the transaction closure. SQLite's grammar
 *      compiles `compileLock()` to an empty string (Laravel framework
 *      Database\Query\Grammars\SQLiteGrammar — by design; SQLite single-file
 *      databases cannot offer cross-process row locks). So the runtime SQL
 *      query log NEVER contains `for update` here — no amount of grepping
 *      executed SQL will find it. The source-level grep is the only correct
 *      pin for the lockForUpdate call itself.
 *
 *   2) Runtime ordering: the SELECT on `logingrupa_goods_received_invoices`
 *      MUST execute BEFORE any UPDATE on `lovata_shopaholic_offers`. This
 *      proves the orchestrator runs the lock-acquiring SELECT first, ahead
 *      of stock writes. On real MySQL/Postgres the SELECT carries the
 *      `FOR UPDATE` clause and serializes via row-lock; on SQLite the lock
 *      is a no-op but the order-of-execution contract holds.
 *
 * Threat coverage: T-03-07-01 (concurrent apply duplicates stock writes —
 * mitigated by lockForUpdate inside the tx).
 */
it('source contains ->lockForUpdate() on Invoice select inside the tx closure (QA-03 LockForUpdateSerializesConcurrentApplyTest source pin)', function (): void {
    $sSource = (string) file_get_contents(
        __DIR__.'/../../../classes/orchestrator/ApplyOrchestrator.php',
    );

    // Source-level grep: the lockForUpdate() call must exist on Invoice.
    // SQLite's grammar strips `for update` from compiled SQL, so the only
    // reliable pin for the CALL itself is the source.
    expect($sSource)->toContain('Invoice::where(');
    expect($sSource)->toContain('->lockForUpdate()');
    expect($sSource)->toContain('->firstOrFail()');

    // The lockForUpdate call must live INSIDE executeInTransaction (i.e.
    // inside the DB::transaction closure), NOT in apply() directly. Find
    // the executeInTransaction declaration, then search for the actual
    // lockForUpdate code-call AFTER that point — earlier occurrences live
    // in the class docblock and do not prove call-site order.
    $iExecPos = strpos($sSource, 'private function executeInTransaction');
    expect($iExecPos)->not->toBeFalse();

    // Search for the call-site (executable code, not docblock prose) AFTER
    // the executeInTransaction declaration.
    $iLockCallPos = strpos($sSource, '->lockForUpdate()', (int) $iExecPos);
    expect($iLockCallPos)->not->toBeFalse();
    expect($iLockCallPos)->toBeGreaterThan((int) $iExecPos);

    // apply() must call DB::transaction (post-commit flush ordering proof).
    expect($sSource)->toContain('DB::transaction(');
    expect($sSource)->toContain('flushAffectedCaches(');

    // Post-commit ordering: in apply()'s body, flushAffectedCaches MUST
    // appear AFTER DB::transaction (proving the flush runs OUTSIDE the
    // transaction closure, per D-10). Search code-call positions AFTER
    // the apply() declaration to skip docblock occurrences earlier in
    // the file.
    $iApplyPos = strpos($sSource, 'public function apply(');
    expect($iApplyPos)->not->toBeFalse();

    $iTxCallPos = strpos($sSource, 'DB::transaction(', (int) $iApplyPos);
    $iFlushCallPos = strpos($sSource, '->flushAffectedCaches(', (int) $iApplyPos);
    expect($iTxCallPos)->not->toBeFalse();
    expect($iFlushCallPos)->not->toBeFalse();
    expect($iFlushCallPos)->toBeGreaterThan((int) $iTxCallPos);

    // The flush call must also appear BEFORE the executeInTransaction
    // declaration, proving it sits in apply()'s body (not inside the
    // transaction closure that delegates to executeInTransaction).
    expect($iFlushCallPos)->toBeLessThan((int) $iExecPos);
});

it('runs SELECT on invoices BEFORE any UPDATE on offers during apply (QA-03 LockForUpdateSerializesConcurrentApplyTest runtime pin)', function (): void {
    $obProduct = seedApplyProduct('LFU-CODE', 'lfu-product');
    $obOffer = seedApplyOffer((int) $obProduct->id, '4752307444444', iQuantity: 0);

    $obInvoice = new Invoice();
    $obInvoice->invoice_number = 'LFU-001';
    $obInvoice->status = Invoice::STATUS_PARSED;
    $obInvoice->total_lines = 1;
    $obInvoice->matched_lines = 1;
    $obInvoice->unmatched_lines = 0;
    $obInvoice->stock_added_units = 0;
    $obInvoice->initial_reset_applied = false;
    $obInvoice->parsed_at = \Carbon\Carbon::now();
    $obInvoice->saveQuietly();

    $obLine = new InvoiceLine();
    $obLine->invoice_id = (int) $obInvoice->id;
    $obLine->row_index = 1;
    $obLine->ean = '4752307444444';
    $obLine->qty = 3;
    $obLine->matched_offer_id = (int) $obOffer->id;
    $obLine->match_strategy = InvoiceLine::MATCH_STRATEGY_OFFER_CODE;
    $obLine->applied = false;
    $obLine->saveQuietly();

    DB::flushQueryLog();
    DB::enableQueryLog();

    (new ApplyOrchestrator())->apply((int) $obInvoice->id, iAppliedByUserId: 1);

    $arQueryLog = DB::getQueryLog();
    DB::disableQueryLog();

    $iInvoiceSelectIndex = null;
    $iFirstOfferUpdateIndex = null;

    foreach ($arQueryLog as $iIdx => $arEntry) {
        $sQuery = strtolower((string) ($arEntry['query'] ?? ''));
        $sTrimmed = ltrim($sQuery);

        // The first SELECT on the invoices table — this is the
        // lockForUpdate-bearing query (SQLite strips the lock fragment, so
        // we cannot grep `for update`; it is enough that this SELECT runs
        // before stock writes).
        if ($iInvoiceSelectIndex === null
            && str_starts_with($sTrimmed, 'select')
            && str_contains($sQuery, 'logingrupa_goods_received_invoices')
        ) {
            $iInvoiceSelectIndex = $iIdx;
        }
        // The first UPDATE on offers (StockApplyService stock write).
        if ($iFirstOfferUpdateIndex === null
            && str_starts_with($sTrimmed, 'update')
            && str_contains($sQuery, 'lovata_shopaholic_offers')
        ) {
            $iFirstOfferUpdateIndex = $iIdx;
        }
    }

    expect($iInvoiceSelectIndex)->not->toBeNull();
    expect($iFirstOfferUpdateIndex)->not->toBeNull();

    // Invoice SELECT MUST precede the stock-write UPDATE — that is the
    // serialization contract Phase 4 prod relies on under MySQL InnoDB.
    expect($iInvoiceSelectIndex)->toBeLessThan($iFirstOfferUpdateIndex);

    // Sanity: stock write actually landed (0 + 3 = 3).
    $obOffer->refresh();
    expect((int) $obOffer->quantity)->toBe(3);
});
