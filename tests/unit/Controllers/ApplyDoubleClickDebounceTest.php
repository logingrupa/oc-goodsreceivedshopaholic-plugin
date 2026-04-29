<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ApplyResult;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ApplyOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;

require_once __DIR__.'/../Apply/ApplyTestCase.php';
require_once __DIR__.'/InvoiceUploadTestHelpers.php';

uses(ApplyTestCase::class);

/**
 * UI-04 / D-13 / T-04-05-01 — Cache::lock double-click debounce contract.
 *
 * Plan 04-05 Task 2: pins the load-bearing debounce contract via two
 * complementary assertion classes (mirrors Phase 3 D-03-07-05 dual-pin
 * pattern):
 *
 * 1. SOURCE-GREP — pins the literal lock-key shape (`apply-invoice-`) and
 *    TTL (`APPLY_LOCK_TTL_SECONDS = 60`) in the controller source. Survives
 *    any test-driver where Cache::lock is a no-op (e.g. `array` driver in
 *    some bootstrap configurations); the source itself proves the contract.
 *
 * 2. RUNTIME — pins the actual short-circuit behavior on a driver that DOES
 *    support locking. The orchestrator MUST NOT be called when the lock is
 *    held (counter-pin: $iApplyOrchestratorResolvedCount === 0); when no
 *    concurrent lock exists, the orchestrator IS called (control case).
 *
 * Together these two pins survive: (a) Makefile drift / removed CI steps,
 * (b) cache-driver swaps in test bootstrap, (c) silent re-keying of the
 * lock name in a future refactor (would break the source-grep AND the
 * runtime pin).
 */

it('source-grep: APPLY_LOCK_TTL_SECONDS = 60 literal appears in controller source (D-13)', function (): void {
    $sControllerSource = (string) file_get_contents(
        __DIR__.'/../../../controllers/Invoices.php',
    );
    expect($sControllerSource)->toContain('APPLY_LOCK_TTL_SECONDS = 60');
});

it('source-grep: lock-key shape `apply-invoice-` literal appears in controller source (D-13)', function (): void {
    $sControllerSource = (string) file_get_contents(
        __DIR__.'/../../../controllers/Invoices.php',
    );
    expect($sControllerSource)->toContain('apply-invoice-');
});

it('source-grep: Cache::lock( call site appears in controller source (D-13)', function (): void {
    $sControllerSource = (string) file_get_contents(
        __DIR__.'/../../../controllers/Invoices.php',
    );
    expect($sControllerSource)->toContain('Cache::lock(');
});

it('source-grep: try / finally release pattern appears in controller source (T-04-05-02)', function (): void {
    $sControllerSource = (string) file_get_contents(
        __DIR__.'/../../../controllers/Invoices.php',
    );
    // The release call inside a finally block is the literal load-bearing
    // pattern (T-04-05-02). Source-grep both halves to pin the contract
    // survives a future refactor without behavior loss.
    expect($sControllerSource)->toContain('->release()');
    expect($sControllerSource)->toContain('finally');
});

it('runtime: second concurrent onApply call returns apply_in_progress partial WITHOUT calling ApplyOrchestrator (T-04-05-01)', function (): void {
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = 'DCD-RUNTIME-001';
    $obInvoice->status = Invoice::STATUS_PARSED;
    $obInvoice->total_lines = 1;
    $obInvoice->matched_lines = 1;
    $obInvoice->unmatched_lines = 0;
    $obInvoice->stock_added_units = 0;
    $obInvoice->initial_reset_applied = false;
    $obInvoice->parsed_at = \Carbon\Carbon::now();
    $obInvoice->saveQuietly();

    // Manually take the SAME-NAMED lock so the handler's get() returns false.
    $obHeldLock = \Cache::lock(sprintf('apply-invoice-%d', (int) $obInvoice->id), 60);
    $bHeld = $obHeldLock->get();

    if (! $bHeld) {
        // Cache driver does not honor lock semantics under this bootstrap
        // (e.g. `array` driver). Source-grep pins above remain authoritative;
        // skip the runtime arm.
        $this->markTestSkipped('Cache driver does not support locking under this test bootstrap.');
    }

    try {
        \Input::merge(['invoice_id' => (int) $obInvoice->id]);

        // Use the apply-orchestrator resolver hook to install a tracking
        // double — counter MUST stay at 0 because the lock-not-acquired
        // branch SKIPS the orchestrator call entirely (D-13 fail-fast).
        $obController = makeTestController(bHasPermission: true, arFiles: null);
        $obController->obApplyOrchestratorResolver = fn (): ApplyOrchestrator => new class () extends ApplyOrchestrator {
            #[\Override]
            public function apply(int $iInvoiceId, int $iAppliedByUserId): ApplyResult
            {
                throw new \LogicException('Orchestrator MUST NOT be called when lock is held.');
            }
        };

        $arResponse = $obController->onApply();

        expect($arResponse)->toHaveKey('#applyResult');
        expect((string) $arResponse['#applyResult'])->toContain('_partials/_apply_in_progress');

        // Counter-pin: orchestrator resolver was NEVER invoked under the
        // lock-not-acquired branch (D-13 fail-fast contract).
        expect($obController->iApplyOrchestratorResolvedCount)->toBe(0);

        // Invoice unchanged.
        $obRefreshed = Invoice::find($obInvoice->id);
        expect((string) $obRefreshed->status)->toBe(Invoice::STATUS_PARSED);
    } finally {
        $obHeldLock->release();
    }
});

it('runtime: orchestrator IS called when no concurrent lock exists (control case)', function (): void {
    $obProduct = seedApplyProduct('DCD-CONTROL-CODE', 'dcd-control-product');
    $obOffer = seedApplyOffer((int) $obProduct->id, '4752307000999', iQuantity: 0);

    $obInvoice = new Invoice();
    $obInvoice->invoice_number = 'DCD-CONTROL-001';
    $obInvoice->status = Invoice::STATUS_PARSED;
    $obInvoice->total_lines = 1;
    $obInvoice->matched_lines = 1;
    $obInvoice->unmatched_lines = 0;
    $obInvoice->stock_added_units = 0;
    $obInvoice->initial_reset_applied = false;
    $obInvoice->parsed_at = \Carbon\Carbon::now();
    $obInvoice->saveQuietly();

    $obLine = new \Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine();
    $obLine->invoice_id = (int) $obInvoice->id;
    $obLine->row_index = 1;
    $obLine->ean = '4752307000999';
    $obLine->qty = 5;
    $obLine->matched_offer_id = (int) $obOffer->id;
    $obLine->match_strategy = \Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine::MATCH_STRATEGY_OFFER_CODE;
    $obLine->applied = false;
    $obLine->saveQuietly();

    \Input::merge(['invoice_id' => (int) $obInvoice->id]);

    $obController = makeTestController(bHasPermission: true, arFiles: null, iUserId: 7);
    $arResponse = $obController->onApply();

    expect($arResponse)->toHaveKey('#applyResult');
    expect((string) $arResponse['#applyResult'])->toContain('_partials/_apply_success');

    // Counter-pin: orchestrator resolver WAS invoked under the
    // happy-path branch (control proves the lock isn't trivially blocking).
    expect($obController->iApplyOrchestratorResolvedCount)->toBe(1);

    // Stock incremented + status flipped.
    $obOffer->refresh();
    expect((int) $obOffer->quantity)->toBe(5);

    $obRefreshed = Invoice::find($obInvoice->id);
    expect((string) $obRefreshed->status)->toBe(Invoice::STATUS_APPLIED);
});
