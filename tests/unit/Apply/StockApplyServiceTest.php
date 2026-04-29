<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\StockApplyOutcome;
use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\StockApplyService;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ApplyResult;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use Lovata\Shopaholic\Models\Offer;

require_once __DIR__.'/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * Plan 03-03 Task 1+2 — StockApplyService unit tests.
 *
 * Pins the load-bearing invariants for APPLY-01 / APPLY-02 / QA-04:
 *   - saveQuietly per offer (NOT per line; lines summed by offer first)
 *   - Lovata OfferModelHandler::afterSave does NOT fire (saveQuietly bypass)
 *   - returns StockApplyOutcome wrapping ApplyResult + affected_offer_ids list
 *   - null `matched_offer_id` lines are counted in lines_skipped
 *   - override_qty wins over qty (Phase 4 preview-edit support)
 *   - missing offer (find returns null) is recorded as skipped
 *   - line.applied=true + applied_at set after successful write
 *
 * Schema bootstrap rationale: hermetic minimal columns via `Schema::create()`
 * in `ApplyTestCase::setUp()` — same workaround as Phase 2 plan 02-06 (the
 * Lovata SQLite drop-indexed-column migration trap).
 */
function makeStockApplyInvoice(string $sNumber = 'PRO-TEST-001'): Invoice
{
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = $sNumber;
    $obInvoice->status = Invoice::STATUS_PARSED;
    $obInvoice->total_lines = 0;
    $obInvoice->matched_lines = 0;
    $obInvoice->unmatched_lines = 0;
    $obInvoice->stock_added_units = 0;
    $obInvoice->initial_reset_applied = false;
    $obInvoice->saveQuietly();

    return $obInvoice;
}

function makeStockApplyLine(
    int $iInvoiceId,
    int $iRowIndex,
    string $sEan,
    int $iQty,
    ?int $iMatchedOfferId,
    ?int $iOverrideQty = null,
): InvoiceLine {
    $obLine = new InvoiceLine();
    $obLine->invoice_id = $iInvoiceId;
    $obLine->row_index = $iRowIndex;
    $obLine->ean = $sEan;
    $obLine->qty = $iQty;
    $obLine->matched_offer_id = $iMatchedOfferId;
    $obLine->match_strategy = $iMatchedOfferId !== null
        ? InvoiceLine::MATCH_STRATEGY_OFFER_CODE
        : InvoiceLine::MATCH_STRATEGY_NONE;
    $obLine->applied = false;
    $obLine->override_qty = $iOverrideQty;
    $obLine->saveQuietly();

    return $obLine;
}

it('increments offer.quantity by line.qty additively (D-07)', function (): void {
    $obProduct = seedApplyProduct('PROD-A', 'prod-a');
    $obOffer = seedApplyOffer($obProduct->id, '4752307000097', iQuantity: 10);

    $obInvoice = makeStockApplyInvoice('PRO-ADD-001');
    makeStockApplyLine($obInvoice->id, 1, '4752307000097', iQty: 5, iMatchedOfferId: (int) $obOffer->id);

    $obService = new StockApplyService();
    $obOutcome = $obService->apply($obInvoice, $obInvoice->lines()->whereNotNull('matched_offer_id')->get());

    $obOffer->refresh();

    expect((int) $obOffer->quantity)->toBe(15);
    expect($obOutcome)->toBeInstanceOf(StockApplyOutcome::class);
    expect($obOutcome->result)->toBeInstanceOf(ApplyResult::class);
    expect($obOutcome->result->units_added)->toBe(5);
    expect($obOutcome->result->offers_touched)->toBe(1);
    expect($obOutcome->result->lines_applied)->toBe(1);
    expect($obOutcome->result->lines_skipped)->toBe(0);
    expect($obOutcome->affected_offer_ids)->toBe([(int) $obOffer->id]);
});

it('sums multiple lines targeting the same offer into ONE save (group-by-offer pre-pass)', function (): void {
    $obProduct = seedApplyProduct('PROD-B', 'prod-b');
    $obOffer = seedApplyOffer($obProduct->id, '4752307000165', iQuantity: 100);

    $obInvoice = makeStockApplyInvoice('PRO-SUM-001');
    makeStockApplyLine($obInvoice->id, 1, '4752307000165', iQty: 3, iMatchedOfferId: (int) $obOffer->id);
    makeStockApplyLine($obInvoice->id, 2, '4752307000165', iQty: 7, iMatchedOfferId: (int) $obOffer->id);

    $obService = new StockApplyService();
    $obOutcome = $obService->apply($obInvoice, $obInvoice->lines()->whereNotNull('matched_offer_id')->get());

    $obOffer->refresh();

    expect((int) $obOffer->quantity)->toBe(110);
    expect($obOutcome->result->units_added)->toBe(10);
    expect($obOutcome->result->offers_touched)->toBe(1);
    expect($obOutcome->result->lines_applied)->toBe(2);
    expect($obOutcome->affected_offer_ids)->toBe([(int) $obOffer->id]);
});

it('skips lines with matched_offer_id=null and counts in lines_skipped', function (): void {
    $obProduct = seedApplyProduct('PROD-C', 'prod-c');
    $obOffer = seedApplyOffer($obProduct->id, '4752307000200', iQuantity: 0);

    $obInvoice = makeStockApplyInvoice('PRO-SKIP-001');
    makeStockApplyLine($obInvoice->id, 1, '4752307000200', iQty: 4, iMatchedOfferId: (int) $obOffer->id);
    makeStockApplyLine($obInvoice->id, 2, '4752307999991', iQty: 2, iMatchedOfferId: null);
    makeStockApplyLine($obInvoice->id, 3, '4752307999992', iQty: 6, iMatchedOfferId: null);

    $obService = new StockApplyService();
    // Pass ALL lines (matched + null) so the service exercises its skip path.
    $obOutcome = $obService->apply($obInvoice, $obInvoice->lines()->get());

    $obOffer->refresh();

    expect((int) $obOffer->quantity)->toBe(4);
    expect($obOutcome->result->lines_applied)->toBe(1);
    expect($obOutcome->result->lines_skipped)->toBe(2);
    expect($obOutcome->result->offers_touched)->toBe(1);
});

it('uses override_qty when not null in preference to qty (Phase 4 preview-edit support)', function (): void {
    $obProduct = seedApplyProduct('PROD-D', 'prod-d');
    $obOffer = seedApplyOffer($obProduct->id, '4752307000300', iQuantity: 50);

    $obInvoice = makeStockApplyInvoice('PRO-OVR-001');
    // qty=5 from invoice, but operator overrode to 20 in preview screen.
    makeStockApplyLine($obInvoice->id, 1, '4752307000300', iQty: 5, iMatchedOfferId: (int) $obOffer->id, iOverrideQty: 20);

    $obService = new StockApplyService();
    $obOutcome = $obService->apply($obInvoice, $obInvoice->lines()->whereNotNull('matched_offer_id')->get());

    $obOffer->refresh();

    expect((int) $obOffer->quantity)->toBe(70);
    expect($obOutcome->result->units_added)->toBe(20);
});

it('marks line.applied=true and applied_at!=null after successful apply', function (): void {
    $obProduct = seedApplyProduct('PROD-E', 'prod-e');
    $obOffer = seedApplyOffer($obProduct->id, '4752307000400', iQuantity: 1);

    $obInvoice = makeStockApplyInvoice('PRO-MARK-001');
    $obLine = makeStockApplyLine($obInvoice->id, 1, '4752307000400', iQty: 2, iMatchedOfferId: (int) $obOffer->id);

    expect($obLine->applied)->toBeFalse();
    expect($obLine->applied_at)->toBeNull();

    $obService = new StockApplyService();
    $obService->apply($obInvoice, $obInvoice->lines()->whereNotNull('matched_offer_id')->get());

    $obLine->refresh();

    expect($obLine->applied)->toBeTrue();
    expect($obLine->applied_at)->not->toBeNull();
});

it('returns StockApplyOutcome with correct ApplyResult shape and affected_offer_ids list (no duplicates)', function (): void {
    $obProductA = seedApplyProduct('PROD-FA', 'prod-fa');
    $obProductB = seedApplyProduct('PROD-FB', 'prod-fb');
    $obOfferA = seedApplyOffer($obProductA->id, '4752307000500', iQuantity: 0);
    $obOfferB = seedApplyOffer($obProductB->id, '4752307000600', iQuantity: 0);

    $obInvoice = makeStockApplyInvoice('PRO-SHAPE-001');
    // Two lines for offer A — affected_offer_ids must dedup to single entry.
    makeStockApplyLine($obInvoice->id, 1, '4752307000500', iQty: 3, iMatchedOfferId: (int) $obOfferA->id);
    makeStockApplyLine($obInvoice->id, 2, '4752307000500', iQty: 4, iMatchedOfferId: (int) $obOfferA->id);
    makeStockApplyLine($obInvoice->id, 3, '4752307000600', iQty: 2, iMatchedOfferId: (int) $obOfferB->id);

    $obService = new StockApplyService();
    $obOutcome = $obService->apply($obInvoice, $obInvoice->lines()->whereNotNull('matched_offer_id')->get());

    expect($obOutcome->result->units_added)->toBe(9);
    expect($obOutcome->result->offers_touched)->toBe(2);
    expect($obOutcome->result->lines_applied)->toBe(3);
    expect($obOutcome->result->lines_skipped)->toBe(0);

    // Copy the readonly list before sorting (PHP forbids in-place mutation).
    $arActual = $obOutcome->affected_offer_ids;
    sort($arActual);
    $arExpected = [(int) $obOfferA->id, (int) $obOfferB->id];
    sort($arExpected);
    expect($arActual)->toBe($arExpected);
    // Dedup proof: 3 lines, 2 offers, list length = 2.
    expect($obOutcome->affected_offer_ids)->toHaveCount(2);
});

it('skips lines whose matched offer cannot be found (defensive against deleted offer FK)', function (): void {
    $obInvoice = makeStockApplyInvoice('PRO-MISSING-001');
    // matched_offer_id points at id=999999 which has no Offer row.
    makeStockApplyLine($obInvoice->id, 1, '4752307000700', iQty: 5, iMatchedOfferId: 999999);

    $obService = new StockApplyService();
    $obOutcome = $obService->apply($obInvoice, $obInvoice->lines()->whereNotNull('matched_offer_id')->get());

    expect($obOutcome->result->units_added)->toBe(0);
    expect($obOutcome->result->offers_touched)->toBe(0);
    expect($obOutcome->result->lines_applied)->toBe(0);
    expect($obOutcome->result->lines_skipped)->toBe(1);
    expect($obOutcome->affected_offer_ids)->toBe([]);
});

it('uses saveQuietly so Lovata model.afterSave events do NOT fire on Offer or InvoiceLine', function (): void {
    $obProduct = seedApplyProduct('PROD-Q', 'prod-q');
    $obOffer = seedApplyOffer($obProduct->id, '4752307000800', iQuantity: 0);

    $obInvoice = makeStockApplyInvoice('PRO-QUIET-001');
    makeStockApplyLine($obInvoice->id, 1, '4752307000800', iQty: 3, iMatchedOfferId: (int) $obOffer->id);

    $iOfferAfterSaveCount = 0;
    $iLineAfterSaveCount = 0;

    Offer::extend(function (Offer $obModel) use (&$iOfferAfterSaveCount): void {
        $obModel->bindEvent('model.afterSave', function () use (&$iOfferAfterSaveCount): void {
            $iOfferAfterSaveCount++;
        });
    });
    InvoiceLine::extend(function (InvoiceLine $obModel) use (&$iLineAfterSaveCount): void {
        $obModel->bindEvent('model.afterSave', function () use (&$iLineAfterSaveCount): void {
            $iLineAfterSaveCount++;
        });
    });

    $obService = new StockApplyService();
    $obService->apply($obInvoice, $obInvoice->lines()->whereNotNull('matched_offer_id')->get());

    // saveQuietly bypasses bound events — counters stay at 0 (the QA-04 contract).
    expect($iOfferAfterSaveCount)->toBe(0);
    expect($iLineAfterSaveCount)->toBe(0);

    // Sanity: the actual write DID land.
    $obOffer->refresh();
    expect((int) $obOffer->quantity)->toBe(3);
});

it('200-line apply issues at most 500 queries (batched fetch + per-offer save + per-line save)', function (): void {
    $obProduct = seedApplyProduct('PROD-BUDGET', 'prod-budget');

    // Seed 200 unique offers — note we pre-seed via saveQuietly so this loop
    // does not pollute the apply()-phase query log we measure below.
    /** @var array<int, Offer> $arOffers */
    $arOffers = [];
    for ($iIdx = 1; $iIdx <= 200; $iIdx++) {
        $arOffers[] = seedApplyOffer(
            (int) $obProduct->id,
            sprintf('%013d', $iIdx),
            iQuantity: 0,
        );
    }

    $obInvoice = makeStockApplyInvoice('PRO-BUDGET-001');
    foreach ($arOffers as $iIdx => $obOffer) {
        makeStockApplyLine(
            (int) $obInvoice->id,
            $iIdx + 1,
            sprintf('%013d', $iIdx + 1),
            iQty: 2,
            iMatchedOfferId: (int) $obOffer->id,
        );
    }

    $obMatched = $obInvoice->lines()->whereNotNull('matched_offer_id')->get();
    expect($obMatched)->toHaveCount(200);

    \DB::flushQueryLog();
    \DB::enableQueryLog();

    $obService = new StockApplyService();
    $obOutcome = $obService->apply($obInvoice, $obMatched);

    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    // Budget: 1 batched offer fetch + 200 offer UPDATE saveQuietly + 200 line
    // UPDATE saveQuietly = 401 baseline. Allow 500 for any model-internal
    // overhead (e.g., Sortable trait order-preserving SELECT). The hard ceiling
    // is the assertion: catastrophic regression to per-line find() would
    // explode this past 600+ instantly.
    expect($iQueryCount)->toBeLessThanOrEqual(500);
    expect($obOutcome->result->lines_applied)->toBe(200);
    expect($obOutcome->result->offers_touched)->toBe(200);
});
