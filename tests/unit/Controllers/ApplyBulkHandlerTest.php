<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ApplyResult;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ApplyOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;

require_once __DIR__.'/../Apply/ApplyTestCase.php';
require_once __DIR__.'/InvoiceUploadTestHelpers.php';

uses(ApplyTestCase::class);

/**
 * Bulk apply modal handler — UAT 2026-05-05.
 *
 * Pins the contract for `onApplyBulk` after the modal redesign that
 * collapsed N "Apply now" buttons into ONE "Apply selected" button + N
 * checkboxes. Per-invoice override_qty + notes payload arrives under
 * the namespaced `invoice[<id>][override_qty][<lineId>]` +
 * `invoice[<id>][notes]` keys; the handler MUST persist each checked
 * invoice's edits BEFORE applying.
 *
 * Coverage:
 *   1. Permission gate at handler entry.
 *   2. Empty-selection AjaxException (no fall-through into apply path).
 *   3. Each checked invoice's override_qty + notes persisted on its row
 *      (invoice ids NOT in `checked[]` are ignored — operator deselected).
 *   4. ApplyOrchestrator::apply called once per checked PARSED invoice.
 *   5. status != parsed → silently skipped (counted in `skipped`).
 *   6. Empty `invoice[]` payload → no persist, just apply (no-op edits OK).
 */

function seedBulkInvoice(string $sNumber, string $sStatus = Invoice::STATUS_PARSED): Invoice
{
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = $sNumber;
    $obInvoice->status = $sStatus;
    $obInvoice->total_lines = 1;
    $obInvoice->matched_lines = 1;
    $obInvoice->parsed_at = \Carbon\Carbon::now();
    $obInvoice->saveQuietly();

    return $obInvoice;
}

function seedBulkLine(int $iInvoiceId, string $sEan, int $iQty): InvoiceLine
{
    $obLine = new InvoiceLine();
    $obLine->invoice_id = $iInvoiceId;
    $obLine->row_index = 1;
    $obLine->ean = $sEan;
    $obLine->qty = $iQty;
    $obLine->matched_offer_id = 100 + $iInvoiceId;
    $obLine->match_strategy = InvoiceLine::MATCH_STRATEGY_OFFER_CODE;
    $obLine->applied = false;
    $obLine->saveQuietly();

    return $obLine;
}

function makeBulkOrchestrator(\stdClass $obTracker): ApplyOrchestrator
{
    return new class ($obTracker) extends ApplyOrchestrator {
        public function __construct(public \stdClass $obTracker)
        {
        }

        #[\Override]
        public function apply(int $iInvoiceId, int $iAppliedByUserId): ApplyResult
        {
            $this->obTracker->iCalls = ((int) ($this->obTracker->iCalls ?? 0)) + 1;
            $this->obTracker->arApplied[] = $iInvoiceId;

            return new ApplyResult(
                invoice_id: $iInvoiceId,
                units_added: 7,
                offers_touched: 1,
                lines_applied: 1,
                lines_skipped: 0,
            );
        }
    };
}

function newBulkTracker(): \stdClass
{
    $obTracker = new \stdClass();
    $obTracker->iCalls = 0;
    $obTracker->arApplied = [];

    return $obTracker;
}

it('rejects without apply_invoices permission', function (): void {
    $obController = makeTestController(bHasPermission: false, arFiles: null);

    $obException = null;
    try {
        $obController->onApplyBulk();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.apply_invoices']);
});

it('throws when checked[] is empty', function (): void {
    \Input::merge(['checked' => []]);
    $obController = makeTestController(bHasPermission: true, arFiles: null);

    $obException = null;
    try {
        $obController->onApplyBulk();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
});

it('persists per-invoice override_qty + notes BEFORE apply (single-form bulk modal)', function (): void {
    $obA = seedBulkInvoice('BULK-001');
    $obLineA = seedBulkLine((int) $obA->id, '4752307AA0001', 5);
    $obB = seedBulkInvoice('BULK-002');
    $obLineB = seedBulkLine((int) $obB->id, '4752307BB0001', 8);

    \Input::merge([
        'checked' => [(string) $obA->id, (string) $obB->id],
        'invoice' => [
            (string) $obA->id => [
                'override_qty' => [(string) $obLineA->id => '11'],
                'notes'        => 'A notes',
            ],
            (string) $obB->id => [
                'override_qty' => [(string) $obLineB->id => '22'],
                'notes'        => 'B notes',
            ],
        ],
    ]);

    $obTracker = newBulkTracker();
    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $obController->obApplyOrchestratorResolver = fn () => makeBulkOrchestrator($obTracker);

    $obController->onApplyBulk();

    expect((int) $obTracker->iCalls)->toBe(2);
    expect(count((array) $obTracker->arApplied))->toBe(2);

    $obLineARefreshed = InvoiceLine::find($obLineA->id);
    expect($obLineARefreshed)->not->toBeNull();
    expect((int) $obLineARefreshed->override_qty)->toBe(11);

    $obLineBRefreshed = InvoiceLine::find($obLineB->id);
    expect($obLineBRefreshed)->not->toBeNull();
    expect((int) $obLineBRefreshed->override_qty)->toBe(22);

    $obARefreshed = Invoice::find($obA->id);
    expect($obARefreshed)->not->toBeNull();
    expect((string) $obARefreshed->notes)->toBe('A notes');

    $obBRefreshed = Invoice::find($obB->id);
    expect($obBRefreshed)->not->toBeNull();
    expect((string) $obBRefreshed->notes)->toBe('B notes');
});

it('skips invoices whose status is not parsed (counted as skipped, not applied)', function (): void {
    $obParsed = seedBulkInvoice('BULK-PARSED-001');
    $obApplied = seedBulkInvoice('BULK-APPLIED-001', Invoice::STATUS_APPLIED);

    \Input::merge([
        'checked' => [(string) $obParsed->id, (string) $obApplied->id],
        'invoice' => [],
    ]);

    $obTracker = newBulkTracker();
    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $obController->obApplyOrchestratorResolver = fn () => makeBulkOrchestrator($obTracker);

    $obController->onApplyBulk();

    expect((int) $obTracker->iCalls)->toBe(1);
    expect((array) $obTracker->arApplied)->toBe([(int) $obParsed->id]);
});

it('does not touch an invoice the operator did not check', function (): void {
    $obA = seedBulkInvoice('BULK-CHECK-001');
    $obLineA = seedBulkLine((int) $obA->id, '4752307AA0002', 5);
    $obB = seedBulkInvoice('BULK-CHECK-002');
    $obLineB = seedBulkLine((int) $obB->id, '4752307BB0002', 8);

    // Operator checked only A — but the form payload for B is still in POST
    // (the inputs are part of the same form). Handler MUST respect
    // `checked[]` and ignore B entirely.
    \Input::merge([
        'checked' => [(string) $obA->id],
        'invoice' => [
            (string) $obA->id => [
                'override_qty' => [(string) $obLineA->id => '99'],
                'notes'        => 'Only A',
            ],
            (string) $obB->id => [
                'override_qty' => [(string) $obLineB->id => '777'],
                'notes'        => 'Should be ignored',
            ],
        ],
    ]);

    $obTracker = newBulkTracker();
    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $obController->obApplyOrchestratorResolver = fn () => makeBulkOrchestrator($obTracker);

    $obController->onApplyBulk();

    expect((int) $obTracker->iCalls)->toBe(1);
    expect((array) $obTracker->arApplied)->toBe([(int) $obA->id]);

    expect((int) InvoiceLine::find($obLineA->id)->override_qty)->toBe(99);
    expect(InvoiceLine::find($obLineB->id)->override_qty)->toBeNull();
    expect(Invoice::find($obB->id)->notes)->toBeNull();
});

it('handles missing invoice payload gracefully (apply runs, no edit-side persist)', function (): void {
    $obA = seedBulkInvoice('BULK-NOEDITS-001');
    seedBulkLine((int) $obA->id, '4752307NN0001', 5);

    \Input::merge([
        'checked' => [(string) $obA->id],
        // no `invoice` key at all
    ]);

    $obTracker = newBulkTracker();
    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $obController->obApplyOrchestratorResolver = fn () => makeBulkOrchestrator($obTracker);

    $obController->onApplyBulk();

    expect((int) $obTracker->iCalls)->toBe(1);
    expect((array) $obTracker->arApplied)->toBe([(int) $obA->id]);
});
