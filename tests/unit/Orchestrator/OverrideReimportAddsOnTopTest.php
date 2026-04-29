<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ApplyOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use Lovata\Shopaholic\Models\Offer;

require_once __DIR__.'/../Apply/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * QA-03 OverrideReimportAddsOnTopTest (plan 03-07 task 2 / D-12 / D-28).
 *
 * Locks the override-reimport semantics: applying an override invoice ADDS
 * qty additively on top of the prior apply. NO decrement-then-reapply, NO
 * special-case logic in ApplyOrchestrator — the additive math emerges
 * naturally because StockApplyService writes `qty += delta`.
 *
 * Setup:
 *   - Offer.quantity = 10
 *   - Apply prior invoice (qty=5)             → Offer.quantity = 15
 *   - Apply override invoice with same qty=5  → Offer.quantity = 20
 *
 * Skipping the parser path keeps the test laser-focused on the apply-side
 * additive contract — the parser-side override flow (runOverride creating
 * an Invoice with override_of_invoice_id set + suffixed invoice_number) is
 * already pinned by ParseAndPersistOrchestratorTest plan 03-06.
 */
function makeOverrideInvoice(string $sNumber, int $iOfferId, ?int $iOverrideOfId = null): Invoice
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
    $obInvoice->override_of_invoice_id = $iOverrideOfId;
    $obInvoice->saveQuietly();

    $obLine = new InvoiceLine();
    $obLine->invoice_id = (int) $obInvoice->id;
    $obLine->row_index = 1;
    $obLine->ean = '4752307333333';
    $obLine->qty = 5;
    $obLine->matched_offer_id = $iOfferId;
    $obLine->match_strategy = InvoiceLine::MATCH_STRATEGY_OFFER_CODE;
    $obLine->applied = false;
    $obLine->saveQuietly();

    return $obInvoice;
}

it('override-reimport apply adds qty additively on top of the prior apply (QA-03 OverrideReimportAddsOnTopTest, D12)', function (): void {
    $obProduct = seedApplyProduct('OVR-CODE', 'ovr-product');
    $obOffer = seedApplyOffer((int) $obProduct->id, '4752307333333', iQuantity: 10);

    // Round 1: prior invoice. Offer.quantity: 10 → 15.
    $obInvoice1 = makeOverrideInvoice('OVR-001', (int) $obOffer->id);
    (new ApplyOrchestrator())->apply((int) $obInvoice1->id, iAppliedByUserId: 1);

    $obOffer->refresh();
    expect((int) $obOffer->quantity)->toBe(15);

    // Round 2: override invoice. Offer.quantity: 15 + 5 = 20.
    // override_of_invoice_id points at prior; suffixed invoice_number to
    // satisfy the UNIQUE index (D-26).
    $obInvoice2 = makeOverrideInvoice(
        'OVR-001-OVR-'.(int) $obInvoice1->id,
        (int) $obOffer->id,
        iOverrideOfId: (int) $obInvoice1->id,
    );
    (new ApplyOrchestrator())->apply((int) $obInvoice2->id, iAppliedByUserId: 1);

    $obOffer->refresh();
    // ADDITIVE: 15 + 5 = 20 (NOT 15, NOT 10+5 reset, NOT decrement-then-reapply).
    expect((int) $obOffer->quantity)->toBe(20);

    // Override pointer chain preserved.
    $obRefreshed = Invoice::find($obInvoice2->id);
    expect($obRefreshed)->not->toBeNull();
    expect((int) $obRefreshed->override_of_invoice_id)->toBe((int) $obInvoice1->id);
    expect((string) $obRefreshed->status)->toBe(Invoice::STATUS_APPLIED);

    // Both invoices flipped to applied.
    $obRefreshed1 = Invoice::find($obInvoice1->id);
    expect($obRefreshed1)->not->toBeNull();
    expect((string) $obRefreshed1->status)->toBe(Invoice::STATUS_APPLIED);
});
