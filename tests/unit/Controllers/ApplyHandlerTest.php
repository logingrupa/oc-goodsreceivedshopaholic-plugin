<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ApplyResult;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\ApplyAlreadyDoneException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ApplyOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;

require_once __DIR__.'/../Apply/ApplyTestCase.php';
require_once __DIR__.'/InvoiceUploadTestHelpers.php';

uses(ApplyTestCase::class);

/**
 * UI-04 / D-11..D-15 / D-04-05-01..D-04-05-05.
 *
 * Plan 04-05 Task 1: onApplyShowConfirm + onApply AJAX handlers — confirmation
 * modal listing total units / matched offer count / unmatched line count, and
 * the actual apply path with `Cache::lock('apply-invoice-{id}', 60)`
 * try/finally debouncing against double-click double-apply.
 *
 * Tiger-Style invariants pinned here:
 *   - Permission gate at handler entry (`apply_invoices`); deny ⇒ AjaxException
 *     (handled in production by October's AJAX dispatcher; throws here in test
 *     context per the same path as `onUpload` in 04-04).
 *   - `Cache::lock` is acquired BEFORE any orchestrator call; release runs in
 *     a `finally` block so a thrown exception still releases the row lock for
 *     the next attempt (T-04-05-02).
 *   - When the lock cannot be acquired (concurrent click holds it), the
 *     handler renders the `_apply_in_progress` partial and SKIPS the
 *     orchestrator call (T-04-05-01 first-line-of-defense).
 *   - `ApplyAlreadyDoneException` (raised by Phase 3 ApplyOrchestrator when
 *     status='applied') is caught and rendered as the structured
 *     `_apply_already_done` partial with prior-result context (T-04-05-04).
 *
 * Test seams reuse the TestableInvoices shim from InvoiceUploadTestHelpers
 * (plan 04-04 D-04-04-02 — boundary-mocking via protected hooks instead of
 * facade-mocking BackendAuth + Input + app(), which collides with
 * Backend\Classes\Controller's __construct). The shim's
 * `obOrchestratorResolver` hook is reused here for ApplyOrchestrator
 * injection (parallel to its existing ParseAndPersistOrchestrator role).
 */

/**
 * Helper to seed an Invoice + a single matched InvoiceLine pair for the
 * apply tests. Mirrors `makeApplyOrchestratorInvoice` /
 * `makeApplyOrchestratorLine` from tests/unit/Orchestrator/ApplyOrchestratorTest
 * but inlined here so this file is self-contained per the plan's "copy them
 * locally" guidance.
 */
function seedApplyHandlerInvoice(string $sNumber = 'AHT-001'): Invoice
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

function seedApplyHandlerLine(int $iInvoiceId, string $sEan, int $iQty, ?int $iOfferId): InvoiceLine
{
    $obLine = new InvoiceLine();
    $obLine->invoice_id = $iInvoiceId;
    $obLine->row_index = 1;
    $obLine->ean = $sEan;
    $obLine->qty = $iQty;
    $obLine->matched_offer_id = $iOfferId;
    $obLine->match_strategy = $iOfferId !== null
        ? InvoiceLine::MATCH_STRATEGY_OFFER_CODE
        : InvoiceLine::MATCH_STRATEGY_NONE;
    $obLine->applied = false;
    $obLine->saveQuietly();

    return $obLine;
}

it('rejects request without apply_invoices permission (D-44 / T-04-05-03)', function (): void {
    $obController = makeTestController(bHasPermission: false, arFiles: null);

    $obException = null;
    try {
        $obController->onApply();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect($arContents)->toBeArray();
    expect($arContents)->toHaveKey('message');
    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.apply_invoices']);
});

it('onApplyShowConfirm rejects without apply_invoices permission (D-04-05-03)', function (): void {
    $obController = makeTestController(bHasPermission: false, arFiles: null);

    $obException = null;
    try {
        $obController->onApplyShowConfirm();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.apply_invoices']);
});

it('onApplyShowConfirm renders modal partial with summary numbers (UI-04 / D-12)', function (): void {
    $obInvoice = seedApplyHandlerInvoice('AHT-CONFIRM-001');
    // 3 lines: 2 matched (qty 5+10), 1 unmatched (qty 7).
    seedApplyHandlerLine((int) $obInvoice->id, '4752307000111', 5, 100);
    seedApplyHandlerLine((int) $obInvoice->id, '4752307000222', 10, 101);
    seedApplyHandlerLine((int) $obInvoice->id, '4752307000333', 7, null);

    // Update header counters to reflect the 3 lines.
    $obInvoice->total_lines = 3;
    $obInvoice->matched_lines = 2;
    $obInvoice->unmatched_lines = 1;
    $obInvoice->saveQuietly();

    \Input::merge(['invoice_id' => (int) $obInvoice->id]);

    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $arResponse = $obController->onApplyShowConfirm();

    expect($arResponse)->toBeArray();
    // Canonical popup widget contract: response carries `result` key with
    // rendered partial markup. October popup.js (line 93) reads
    // `data.result` via `setContent(data.result)`. Larajax wraps non-`#`
    // keys into the response data field so `data.result` is consumable
    // client-side. Prior shape (`#applyConfirm` selector key) routed
    // through dataWithUpdateSelectors as a patchDom op against a
    // non-existent DOM anchor — silent no-op but violates the canonical
    // contract documented in
    // modules/backend/assets/foundation/controls/popup/README.md.
    expect($arResponse)->toHaveKey('result');
    expect((string) $arResponse['result'])->toContain('_partials/apply_confirm');

    $arConfirmCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/apply_confirm') {
            $arConfirmCall = $arCall;
            break;
        }
    }
    expect($arConfirmCall)->not->toBeNull();
    expect((int) $arConfirmCall['data']['total_units'])->toBe(15); // 5 + 10 from matched lines only
    expect((int) $arConfirmCall['data']['offer_count'])->toBe(2);
    expect((int) $arConfirmCall['data']['unmatched_count'])->toBe(1);
});

it('onApplyShowConfirm throws when invoice_id does not exist', function (): void {
    \Input::merge(['invoice_id' => 99999]);

    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $obException = null;
    try {
        $obController->onApplyShowConfirm();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    // Lang::get returns the key path under the unit-bootstrap (D-04-04-07);
    // assert on the key fragment that uniquely pins the not-found throw site.
    expect(strtolower((string) $obException->getContents()['message']))->toContain('invoice_not_found');
});

it('onApply happy path applies stock + flips status + returns success partial (UI-04 happy path)', function (): void {
    $obProduct = seedApplyProduct('AHT-CODE', 'aht-product');
    $obOffer = seedApplyOffer((int) $obProduct->id, '4752307000444', iQuantity: 10);

    $obInvoice = seedApplyHandlerInvoice('AHT-HAPPY-001');
    $obLine = seedApplyHandlerLine((int) $obInvoice->id, '4752307000444', 5, (int) $obOffer->id);

    \Input::merge(['invoice_id' => (int) $obInvoice->id]);

    $obController = makeTestController(bHasPermission: true, arFiles: null, iUserId: 99);
    $arResponse = $obController->onApply();

    expect($arResponse)->toBeArray();
    expect($arResponse)->toHaveKey('#applyResult');
    expect((string) $arResponse['#applyResult'])->toContain('_partials/apply_success');

    // Stock additively incremented: 10 + 5 = 15.
    $obOffer->refresh();
    expect((int) $obOffer->quantity)->toBe(15);

    // Invoice header flipped.
    $obRefreshed = Invoice::find($obInvoice->id);
    expect($obRefreshed)->not->toBeNull();
    expect((string) $obRefreshed->status)->toBe(Invoice::STATUS_APPLIED);
    expect((int) $obRefreshed->applied_by_user_id)->toBe(99);
    expect((int) $obRefreshed->stock_added_units)->toBe(5);

    // Per-line marker.
    $obLine->refresh();
    expect((bool) $obLine->applied)->toBeTrue();

    // Success partial received the ApplyResult DTO.
    $arSuccessCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/apply_success') {
            $arSuccessCall = $arCall;
            break;
        }
    }
    expect($arSuccessCall)->not->toBeNull();
    expect($arSuccessCall['data'])->toHaveKey('result');
    expect($arSuccessCall['data']['result'])->toBeInstanceOf(ApplyResult::class);
    expect((int) $arSuccessCall['data']['result']->units_added)->toBe(5);
});

it('onApply throws when invoice_id is missing or zero (defensive guard)', function (): void {
    \Input::merge(['invoice_id' => 0]);

    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $obException = null;
    try {
        $obController->onApply();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect(strtolower((string) $obException->getContents()['message']))->toContain('invoice_id');
});

it('onApply renders apply_in_progress partial when Cache::lock cannot be acquired (T-04-05-01)', function (): void {
    $obInvoice = seedApplyHandlerInvoice('AHT-LOCKED-001');

    \Input::merge(['invoice_id' => (int) $obInvoice->id]);

    // Manually grab the SAME-NAMED lock so the handler's get() returns false.
    $obHeldLock = \Cache::lock(sprintf('apply-invoice-%d', (int) $obInvoice->id), 60);
    $bHeld = $obHeldLock->get();

    if (! $bHeld) {
        // Cache driver cannot honor lock semantics in this test bootstrap
        // (e.g. `array` driver no-op). Source-grep pin in
        // ApplyDoubleClickDebounceTest covers the contract; skip the runtime
        // assertion here so the suite stays driver-portable.
        $this->markTestSkipped('Cache driver does not support locking under this test bootstrap.');
    }

    try {
        $obController = makeTestController(bHasPermission: true, arFiles: null);
        $arResponse = $obController->onApply();

        expect($arResponse)->toHaveKey('#applyResult');
        expect((string) $arResponse['#applyResult'])->toContain('_partials/apply_in_progress');

        // Invoice status NOT flipped — orchestrator was never called.
        $obRefreshed = Invoice::find($obInvoice->id);
        expect((string) $obRefreshed->status)->toBe(Invoice::STATUS_PARSED);
    } finally {
        $obHeldLock->release();
    }
});

it('onApply releases lock in finally even when ApplyOrchestrator throws (T-04-05-02)', function (): void {
    $obInvoice = seedApplyHandlerInvoice('AHT-FAILING-001');

    \Input::merge(['invoice_id' => (int) $obInvoice->id]);

    // Bind a failing orchestrator into the container — the boundary-mock pattern
    // sanctioned by D-03-07-01 for this kind of throw-injected lock-release pin.
    $obFailing = new class () extends ApplyOrchestrator {
        #[\Override]
        public function apply(int $iInvoiceId, int $iAppliedByUserId): ApplyResult
        {
            throw new \RuntimeException('Synthetic orchestrator failure for T-04-05-02 pin.');
        }
    };
    app()->instance(ApplyOrchestrator::class, $obFailing);

    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $obException = null;
    try {
        $obController->onApply();
    } catch (\Throwable $obCaught) {
        $obException = $obCaught;
    }
    expect($obException)->not->toBeNull();
    expect($obException->getMessage())->toContain('Synthetic orchestrator failure');

    // Critical: lock is released — a fresh ::lock(...)->get() must succeed.
    $obFreshLock = \Cache::lock(sprintf('apply-invoice-%d', (int) $obInvoice->id), 60);
    expect($obFreshLock->get())->toBeTrue();
    $obFreshLock->release();

    // Reset the IoC binding so subsequent tests get a real orchestrator.
    app()->forgetInstance(ApplyOrchestrator::class);
});

it('onApply persists override_qty[] POST array onto matching InvoiceLine rows (modal POST round-trip)', function (): void {
    // UX redesign 2026-04-30 / Chunk F — `_apply_modal.htm` posts
    // `override_qty[<lineId>]` for each line; `onApply` MUST persist those
    // values BEFORE the ApplyOrchestrator runs so StockApplyService picks
    // up `override_qty ?? qty` per D-12 / T-04-04-08. This test pins the
    // round-trip from POST array → InvoiceLine.override_qty column.
    $obProduct = seedApplyProduct('AHT-OVR-CODE', 'aht-ovr-product');
    $obOffer = seedApplyOffer((int) $obProduct->id, '4752307000900', iQuantity: 10);

    $obInvoice = seedApplyHandlerInvoice('AHT-OVR-001');
    $obLine = seedApplyHandlerLine((int) $obInvoice->id, '4752307000900', 5, (int) $obOffer->id);

    \Input::merge([
        'invoice_id'   => (int) $obInvoice->id,
        'override_qty' => [
            (string) $obLine->id => '7',
        ],
    ]);

    $obController = makeTestController(bHasPermission: true, arFiles: null, iUserId: 99);
    $obController->onApply();

    // override_qty persisted before apply ran.
    $obLine->refresh();
    expect($obLine->override_qty)->toBe(7);

    // StockApplyService used override_qty (7) instead of parsed qty (5):
    // 10 + 7 = 17.
    $obOffer->refresh();
    expect((int) $obOffer->quantity)->toBe(17);

    // Invoice header reflects the override total in stock_added_units.
    $obRefreshed = Invoice::find($obInvoice->id);
    expect($obRefreshed)->not->toBeNull();
    expect((int) $obRefreshed->stock_added_units)->toBe(7);
});

it('onApply persists empty-string override_qty as NULL (clear-override UX)', function (): void {
    // The modal's number input shows the prior override as the value; when
    // an operator CLEARS it, the empty string is posted. The persist helper
    // normalizes empty string to NULL so StockApplyService falls back to
    // the parsed qty.
    $obProduct = seedApplyProduct('AHT-CLR-CODE', 'aht-clr-product');
    $obOffer = seedApplyOffer((int) $obProduct->id, '4752307000901', iQuantity: 10);

    $obInvoice = seedApplyHandlerInvoice('AHT-CLR-001');
    $obLine = seedApplyHandlerLine((int) $obInvoice->id, '4752307000901', 5, (int) $obOffer->id);
    // Pre-seed an override that the operator will clear.
    $obLine->override_qty = 9;
    $obLine->saveQuietly();
    expect($obLine->override_qty)->toBe(9);

    \Input::merge([
        'invoice_id'   => (int) $obInvoice->id,
        'override_qty' => [
            (string) $obLine->id => '',
        ],
    ]);

    $obController = makeTestController(bHasPermission: true, arFiles: null, iUserId: 99);
    $obController->onApply();

    $obLine->refresh();
    expect($obLine->override_qty)->toBeNull();

    // StockApplyService used parsed qty (5) since override is now NULL:
    // 10 + 5 = 15.
    $obOffer->refresh();
    expect((int) $obOffer->quantity)->toBe(15);
});

it('onApply persists notes POST field onto Invoice.notes', function (): void {
    // BUG 5 / Chunk F — notes round-trip from modal POST → Invoice.notes
    // column. Persists BEFORE the ApplyOrchestrator runs.
    $obProduct = seedApplyProduct('AHT-NOTES-CODE', 'aht-notes-product');
    $obOffer = seedApplyOffer((int) $obProduct->id, '4752307000902', iQuantity: 10);

    $obInvoice = seedApplyHandlerInvoice('AHT-NOTES-001');
    seedApplyHandlerLine((int) $obInvoice->id, '4752307000902', 3, (int) $obOffer->id);

    \Input::merge([
        'invoice_id' => (int) $obInvoice->id,
        'notes'      => '  Operator commentary about this invoice.  ',
    ]);

    $obController = makeTestController(bHasPermission: true, arFiles: null, iUserId: 99);
    $obController->onApply();

    $obRefreshed = Invoice::find($obInvoice->id);
    expect($obRefreshed)->not->toBeNull();
    // Trimmed but otherwise verbatim.
    expect((string) $obRefreshed->notes)->toBe('Operator commentary about this invoice.');
});

it('onApply normalizes empty notes string to NULL', function (): void {
    $obInvoice = seedApplyHandlerInvoice('AHT-NOTES-EMPTY-001');
    $obInvoice->notes = 'prior content';
    $obInvoice->saveQuietly();

    \Input::merge([
        'invoice_id' => (int) $obInvoice->id,
        'notes'      => '   ',
    ]);

    $obController = makeTestController(bHasPermission: true, arFiles: null, iUserId: 99);
    try {
        $obController->onApply();
    } catch (\Throwable $obException) {
        // Apply may fail for an invoice without lines; we only care about
        // the persist-edits side effect that runs BEFORE the orchestrator.
    }

    $obRefreshed = Invoice::find($obInvoice->id);
    expect($obRefreshed)->not->toBeNull();
    expect($obRefreshed->notes)->toBeNull();
});

it('onApply rejects non-integer override_qty values silently (clamps to NULL)', function (): void {
    // Server-side defense — a malformed POST (negative, non-numeric,
    // decimal) should NOT bubble a UI error; instead it clamps to NULL.
    // Operator-correctable noise.
    $obInvoice = seedApplyHandlerInvoice('AHT-MALFORMED-001');
    $obLine = seedApplyHandlerLine((int) $obInvoice->id, '4752307000903', 5, null);

    \Input::merge([
        'invoice_id'   => (int) $obInvoice->id,
        'override_qty' => [
            (string) $obLine->id => 'banana',
        ],
    ]);

    $obController = makeTestController(bHasPermission: true, arFiles: null, iUserId: 99);
    try {
        $obController->onApply();
    } catch (\Throwable $obException) {
        // Apply path may fail since line is unmatched; we only care about
        // the override_qty normalization, not orchestrator behavior.
    }

    $obLine->refresh();
    expect($obLine->override_qty)->toBeNull();
});

it('onApply renders apply_already_done partial when invoice status=applied (T-04-05-04)', function (): void {
    // Pre-set the invoice as already applied so the orchestrator throws the
    // typed exception when we run apply.
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = 'AHT-DONE-001';
    $obInvoice->status = Invoice::STATUS_APPLIED;
    $obInvoice->total_lines = 1;
    $obInvoice->matched_lines = 1;
    $obInvoice->unmatched_lines = 0;
    $obInvoice->stock_added_units = 42;
    $obInvoice->applied_by_user_id = 7;
    $obInvoice->applied_at = \Carbon\Carbon::now()->subMinutes(5);
    $obInvoice->parsed_at = \Carbon\Carbon::now()->subMinutes(10);
    $obInvoice->initial_reset_applied = false;
    $obInvoice->saveQuietly();

    \Input::merge(['invoice_id' => (int) $obInvoice->id]);

    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $arResponse = $obController->onApply();

    expect($arResponse)->toHaveKey('#applyResult');
    expect((string) $arResponse['#applyResult'])->toContain('_partials/apply_already_done');

    $arDoneCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/apply_already_done') {
            $arDoneCall = $arCall;
            break;
        }
    }
    expect($arDoneCall)->not->toBeNull();
    expect($arDoneCall['data'])->toHaveKey('context');
    expect((string) $arDoneCall['data']['context']['invoice_number'])->toBe('AHT-DONE-001');
    expect((int) $arDoneCall['data']['context']['prior_stock_added_units'])->toBe(42);
    expect((int) $arDoneCall['data']['context']['prior_applied_by'])->toBe(7);

    // ApplyAlreadyDoneException's lang key is the rendered exception message
    // when the orchestrator throws. The partial-data context mirror is the
    // structured surface for the operator UI.
});
