<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ApplyOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\SettingsAccessor;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use Logingrupa\GoodsReceivedShopaholic\Models\Settings;
use Lovata\Shopaholic\Models\Offer;

require_once __DIR__.'/../Apply/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * QA-08 ActiveFlagInsideSameTransactionAsStockApplyTest (plan 03-07 task 3 / D-15 / D-24).
 *
 * Pins the contract that ActiveFlagService::reconcile runs INSIDE the same
 * DB::transaction as StockApplyService::apply. Approach: Laravel's
 * `Connection::beforeExecuting` instrumentation hook records the
 * `transactionLevel()` for every executed query. After apply() runs, the
 * recorded levels for both the offer.quantity UPDATE (StockApply) and the
 * offer.active UPDATE (ActiveFlag) MUST be >= 1 (inside a tx).
 *
 * Tiger-Style note: `beforeExecuting` is a Laravel instrumentation seam,
 * NOT a business-logic mock. The actual services run real code; the hook
 * is observation-only.
 *
 * Threat coverage: T-03-07-03 (ActiveFlag write outside the apply
 * transaction → orphaned active toggle if stock writes roll back).
 */
it('runs ActiveFlagService::reconcile inside the SAME DB::transaction as StockApplyService::apply (QA-08 ActiveFlagInsideSameTransactionAsStockApplyTest)', function (): void {
    // Activate both ActiveFlag toggles so reconcile actually fires writes.
    Settings::set('auto_deactivate_on_zero', true);
    Settings::set('auto_activate_on_stock', true);
    SettingsAccessor::flush();

    // Seed offer with qty=0 + active=false. After StockApply increments to
    // 5, ActiveFlag's auto_activate_on_stock toggle should flip active=true
    // — that produces the SECOND offers UPDATE the assertions key on.
    $obProduct = seedApplyProduct('AFT-CODE', 'aft-product');

    $obOffer = new Offer();
    $obOffer->product_id = (int) $obProduct->id;
    $obOffer->name = 'ActiveFlag-in-tx test';
    $obOffer->code = '4752307666666';
    $obOffer->quantity = 0;
    $obOffer->active = false;
    $obOffer->active_managed_by = 'plugin';
    $obOffer->saveQuietly();

    $obInvoice = new Invoice();
    $obInvoice->invoice_number = 'AFT-001';
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
    $obLine->ean = '4752307666666';
    $obLine->qty = 5;
    $obLine->matched_offer_id = (int) $obOffer->id;
    $obLine->match_strategy = InvoiceLine::MATCH_STRATEGY_OFFER_CODE;
    $obLine->applied = false;
    $obLine->saveQuietly();

    // Hook beforeExecuting to record transactionLevel for every offers UPDATE.
    /** @var list<array{query: string, level: int}> $arObserved */
    $arObserved = [];
    DB::connection()->beforeExecuting(
        function (string $sQuery, array $arBindings, Connection $obConnection) use (&$arObserved): void {
            unset($arBindings);
            $sLower = strtolower($sQuery);
            if (! str_starts_with(ltrim($sLower), 'update')) {
                return;
            }
            if (! str_contains($sLower, 'lovata_shopaholic_offers')) {
                return;
            }
            $arObserved[] = [
                'query' => $sLower,
                'level' => $obConnection->transactionLevel(),
            ];
        },
    );

    (new ApplyOrchestrator())->apply((int) $obInvoice->id, iAppliedByUserId: 1);

    // Sanity: the offer was written to twice (once by StockApply for
    // quantity, once by ActiveFlag for active toggle), OR a single UPDATE
    // statement may carry both columns if Eloquent batches dirty
    // attributes. Either way, AT LEAST ONE offers UPDATE happened, and
    // every observed UPDATE must run with transactionLevel >= 1.
    expect($arObserved)->not->toBeEmpty();
    foreach ($arObserved as $arEntry) {
        expect($arEntry['level'])->toBeGreaterThanOrEqual(1);
    }

    // Stronger structural assertion: both columns (quantity AND active)
    // appear among the recorded UPDATE statements. A regression where
    // ActiveFlag.reconcile is called OUTSIDE the transaction would still
    // produce an `active` UPDATE — but at transactionLevel=0 — failing
    // the loop assertion above.
    $bSawQuantityUpdate = false;
    $bSawActiveUpdate = false;
    foreach ($arObserved as $arEntry) {
        if (str_contains($arEntry['query'], 'quantity')) {
            $bSawQuantityUpdate = true;
        }
        if (str_contains($arEntry['query'], 'active')) {
            $bSawActiveUpdate = true;
        }
    }
    expect($bSawQuantityUpdate)->toBeTrue();
    expect($bSawActiveUpdate)->toBeTrue();

    // Final state correctness: offer.active flipped to true (proves
    // ActiveFlag actually ran AND committed inside the same tx).
    $obOffer->refresh();
    expect((int) $obOffer->quantity)->toBe(5);
    expect((bool) $obOffer->active)->toBeTrue();
});
