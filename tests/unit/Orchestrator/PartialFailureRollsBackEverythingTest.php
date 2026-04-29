<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\ActiveFlagService;
use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\StockApplyService;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ApplyResult;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ApplyOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\ImportAuditService;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use Lovata\Shopaholic\Models\Offer;

require_once __DIR__.'/../Apply/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * QA-08 PartialFailureRollsBackEverythingTest (plan 03-07 task 3 / D-25).
 *
 * Inject a failing audit dependency. The orchestrator's apply sequence is
 * (D-24): lock → status check → StockApply → ActiveFlag.reconcile →
 * status flip → audit.logApply. Audit is the LAST call inside the
 * transaction; throwing there triggers full rollback of the THREE prior
 * writes (stock, active-flag, status). Test asserts that after the throw,
 * the offer.quantity reverted, line.applied=false, invoice.status='parsed'.
 *
 * Why audit boundary stub is allowed (Tiger-Style rule note):
 *   CLAUDE.md mandates "no mocking business logic". The audit service is a
 *   LOGGING boundary — Log::info / Log::warning calls. Boundary doubles
 *   are allowed; only domain-rule mocking is prohibited. The actual
 *   StockApply + ActiveFlag services run REAL code against REAL SQLite
 *   tables here.
 *
 * Threat coverage: T-03-07-02 (half-applied invoice — stock writes
 * committed but status=parsed leaves the system in inconsistent state).
 */
final class FailingAuditService extends ImportAuditService
{
    #[\Override]
    public function logApply(int $iInvoiceId, ApplyResult $obResult, int $iAppliedByUserId): void
    {
        unset($iInvoiceId, $obResult, $iAppliedByUserId);
        throw new \RuntimeException('Audit pipeline broken (PartialFailureRollsBackEverythingTest)');
    }
}

it('rolls back stock writes + line applied + invoice status when any step inside the transaction fails (QA-08 PartialFailureRollsBackEverythingTest)', function (): void {
    $obProduct = seedApplyProduct('PFR-CODE', 'pfr-product');
    $obOffer = seedApplyOffer((int) $obProduct->id, '4752307555555', iQuantity: 10);
    $iOriginalQty = (int) $obOffer->quantity;

    $obInvoice = new Invoice();
    $obInvoice->invoice_number = 'PFR-001';
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
    $obLine->ean = '4752307555555';
    $obLine->qty = 5;
    $obLine->matched_offer_id = (int) $obOffer->id;
    $obLine->match_strategy = InvoiceLine::MATCH_STRATEGY_OFFER_CODE;
    $obLine->applied = false;
    $obLine->saveQuietly();

    // Inject the failing audit. Real StockApply + ActiveFlag services.
    $obOrchestrator = new ApplyOrchestrator(
        new StockApplyService(),
        new ActiveFlagService(),
        new FailingAuditService(),
    );

    // Apply must throw the audit's RuntimeException.
    $obException = null;
    try {
        $obOrchestrator->apply((int) $obInvoice->id, iAppliedByUserId: 1);
    } catch (\RuntimeException $obCaught) {
        $obException = $obCaught;
    }
    expect($obException)->not->toBeNull();
    expect($obException->getMessage())->toContain('Audit pipeline broken');

    // Stock write rolled back.
    $obOfferRefreshed = Offer::find($obOffer->id);
    expect($obOfferRefreshed)->not->toBeNull();
    expect((int) $obOfferRefreshed->quantity)->toBe($iOriginalQty);

    // Line marker rolled back.
    $obLineRefreshed = InvoiceLine::find($obLine->id);
    expect($obLineRefreshed)->not->toBeNull();
    expect((bool) $obLineRefreshed->applied)->toBeFalse();
    expect($obLineRefreshed->applied_at)->toBeNull();

    // Invoice status flip rolled back.
    $obInvoiceRefreshed = Invoice::find($obInvoice->id);
    expect($obInvoiceRefreshed)->not->toBeNull();
    expect((string) $obInvoiceRefreshed->status)->toBe(Invoice::STATUS_PARSED);
    expect($obInvoiceRefreshed->applied_at)->toBeNull();
    expect($obInvoiceRefreshed->applied_by_user_id)->toBeNull();
    expect((int) $obInvoiceRefreshed->stock_added_units)->toBe(0);
});
