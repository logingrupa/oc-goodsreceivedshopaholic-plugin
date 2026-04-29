<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ApplyResult;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ApplyOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use Lovata\Shopaholic\Models\Offer;

require_once __DIR__.'/../Apply/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * Plan 03-07 Task 2 — ApplyOrchestrator happy path (APPLY-07 / D-24).
 *
 * Pins the load-bearing apply flow:
 *   1) StockApplyService writes offer.quantity additively (10+5=15).
 *   2) Per-line applied=true / applied_at!=null markers land.
 *   3) Invoice header flips to status='applied' + applied_at + applied_by_user_id
 *      + stock_added_units.
 *   4) Returned ApplyResult carries the right counter shape.
 *
 * The QA-03 idempotency cases + QA-08 transaction-safety cases live in their
 * own dedicated files (one Pest file per QA-named test) for grep-by-name and
 * CI failure attribution clarity — see plan 03-07 task 2 + 3 spec.
 */
function makeApplyOrchestratorInvoice(string $sNumber = 'APO-001'): Invoice
{
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = $sNumber;
    $obInvoice->status = Invoice::STATUS_PARSED;
    $obInvoice->total_lines = 1;
    $obInvoice->matched_lines = 1;
    $obInvoice->unmatched_lines = 0;
    $obInvoice->stock_added_units = 0;
    $obInvoice->initial_reset_applied = false;
    $obInvoice->parsed_at = \Carbon\Carbon::now();
    $obInvoice->saveQuietly();

    return $obInvoice;
}

function makeApplyOrchestratorLine(int $iInvoiceId, string $sEan, int $iQty, int $iOfferId): InvoiceLine
{
    $obLine = new InvoiceLine();
    $obLine->invoice_id = $iInvoiceId;
    $obLine->row_index = 1;
    $obLine->ean = $sEan;
    $obLine->qty = $iQty;
    $obLine->matched_offer_id = $iOfferId;
    $obLine->match_strategy = InvoiceLine::MATCH_STRATEGY_OFFER_CODE;
    $obLine->applied = false;
    $obLine->saveQuietly();

    return $obLine;
}

it('applies stock additively, marks lines applied, marks invoice applied, returns ApplyResult (APPLY-07 happy path)', function (): void {
    $obProduct = seedApplyProduct('APO-CODE', 'apo-product');
    $obOffer = seedApplyOffer((int) $obProduct->id, '4752307000111', iQuantity: 10);

    $obInvoice = makeApplyOrchestratorInvoice('APO-HAPPY-001');
    $obLine = makeApplyOrchestratorLine((int) $obInvoice->id, '4752307000111', 5, (int) $obOffer->id);

    $obOrchestrator = new ApplyOrchestrator();
    $obResult = $obOrchestrator->apply((int) $obInvoice->id, iAppliedByUserId: 99);

    expect($obResult)->toBeInstanceOf(ApplyResult::class);
    expect($obResult->units_added)->toBe(5);
    expect($obResult->offers_touched)->toBe(1);
    expect($obResult->lines_applied)->toBe(1);
    expect($obResult->lines_skipped)->toBe(0);

    // Stock additively incremented (10 + 5 = 15).
    $obOffer->refresh();
    expect((int) $obOffer->quantity)->toBe(15);

    // Invoice header flipped.
    $obRefreshed = Invoice::find($obInvoice->id);
    expect($obRefreshed)->not->toBeNull();
    expect((string) $obRefreshed->status)->toBe(Invoice::STATUS_APPLIED);
    expect((int) $obRefreshed->applied_by_user_id)->toBe(99);
    expect($obRefreshed->applied_at)->not->toBeNull();
    expect((int) $obRefreshed->stock_added_units)->toBe(5);

    // Per-line marker.
    $obLine->refresh();
    expect((bool) $obLine->applied)->toBeTrue();
    expect($obLine->applied_at)->not->toBeNull();
});
