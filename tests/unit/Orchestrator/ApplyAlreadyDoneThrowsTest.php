<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\ApplyAlreadyDoneException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ApplyOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;

require_once __DIR__.'/../Apply/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * QA-03 ApplyAlreadyDoneThrowsTest (plan 03-07 task 2 / D-24 step 2).
 *
 * The orchestrator's idempotency gate: an Invoice already at status='applied'
 * MUST be rejected with ApplyAlreadyDoneException. The exception's `arContext`
 * MUST carry rich prior-result data so the Phase 4 controller can render a
 * useful error to the operator (when, by whom, how many units the prior apply
 * touched).
 *
 * Threat coverage: T-03-07-01 (concurrent apply on same invoice → second
 * caller blocks on lockForUpdate, then sees status='applied' here, throws).
 */
it('throws ApplyAlreadyDoneException with prior result context when invoice is already applied (QA-03 ApplyAlreadyDoneThrowsTest)', function (): void {
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = 'AAD-001';
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

    $obOrchestrator = new ApplyOrchestrator();
    $obException = null;

    try {
        $obOrchestrator->apply((int) $obInvoice->id, iAppliedByUserId: 99);
    } catch (ApplyAlreadyDoneException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect($obException)->toBeInstanceOf(ApplyAlreadyDoneException::class);

    expect($obException->arContext)->toHaveKey('invoice_id');
    expect((int) $obException->arContext['invoice_id'])->toBe((int) $obInvoice->id);

    expect($obException->arContext)->toHaveKey('invoice_number');
    expect($obException->arContext['invoice_number'])->toBe('AAD-001');

    expect($obException->arContext)->toHaveKey('prior_applied_at');
    expect($obException->arContext['prior_applied_at'])->toBeString();

    expect($obException->arContext)->toHaveKey('prior_applied_by');
    expect((int) $obException->arContext['prior_applied_by'])->toBe(7);

    expect($obException->arContext)->toHaveKey('prior_stock_added_units');
    expect((int) $obException->arContext['prior_stock_added_units'])->toBe(42);

    // Invoice unchanged after rejection — orchestrator never wrote anything.
    $obRefreshed = Invoice::find($obInvoice->id);
    expect($obRefreshed)->not->toBeNull();
    expect((int) $obRefreshed->stock_added_units)->toBe(42);
    expect((int) $obRefreshed->applied_by_user_id)->toBe(7);
});
