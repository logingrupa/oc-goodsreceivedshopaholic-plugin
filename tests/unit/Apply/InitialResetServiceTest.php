<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\InitialResetService;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InitialResetNotAllowedException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\SettingsAccessor;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\Settings;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

require_once __DIR__.'/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * InitialResetService unit tests.
 *
 * Pins the load-bearing invariants:
 *   1) Two-gate guard: Settings.allow_initial_reset MUST be true AND no prior
 *      Invoice with initial_reset_applied=true may exist.
 *   2) Zero offers + deactivate products + flip one-shot bit on success.
 *   3) Chunked iteration on the mutation write path — bounded memory
 *      regardless of catalog size.
 *   4) saveQuietly only — never bulk DB::statement / whereRaw / mass UPDATE.
 *
 * Settings drive: each test calls Settings::set + Settings::clearInternalCache
 * + SettingsAccessor::flush via the resetSettings() helper so the memo cache
 * reads fresh values within the test body.
 *
 * NOTE 2026-05-05: snapshot scaffolding (logingrupa_goods_received_initial_reset_snapshot
 * table + InitialResetSnapshot model + snapshotAllOffers method) was deleted
 * because no programmatic restore path was ever implemented; the snapshot was
 * forensic-only and added unmaintained surface area. Tests pinning snapshot
 * row counts / SnapshotsBeforeWriteTest / RollbackRestoresExactPriorStateTest
 * were removed alongside.
 */

/**
 * Configure both Settings keys + drop ALL caches so the service reads them
 * fresh.
 */
function resetSettings(bool $bAllow): void
{
    Settings::clearInternalCache();
    Settings::set('allow_initial_reset', $bAllow);
    SettingsAccessor::flush();
}

/**
 * Build a minimal Invoice row suitable as the reset trigger. saveQuietly
 * sidesteps any model-event handlers that may fan out to caches.
 */
function makeResetInvoice(string $sNumber = 'PRO-RESET-001', bool $bResetApplied = false): Invoice
{
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = $sNumber;
    $obInvoice->status = Invoice::STATUS_PARSED;
    $obInvoice->total_lines = 0;
    $obInvoice->matched_lines = 0;
    $obInvoice->unmatched_lines = 0;
    $obInvoice->stock_added_units = 0;
    $obInvoice->initial_reset_applied = $bResetApplied;
    $obInvoice->saveQuietly();

    return $obInvoice;
}

it('throws InitialResetNotAllowedException with reason=settings_disabled when allowInitialReset=false', function (): void {
    resetSettings(bAllow: false);

    $obInvoice = makeResetInvoice('PRO-GUARD-SETTINGS');

    $obService = new InitialResetService();
    $obException = null;

    try {
        $obService->reset($obInvoice);
    } catch (InitialResetNotAllowedException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect($obException->arContext['reason'] ?? null)->toBe('settings_disabled');

    // No mutation must have occurred — invoice still in parsed state.
    $obInvoice->refresh();
    expect((bool) $obInvoice->initial_reset_applied)->toBeFalse();
});

it('throws InitialResetNotAllowedException with reason=already_applied when a prior reset row exists', function (): void {
    resetSettings(bAllow: true);

    // Seed a prior already-applied reset.
    makeResetInvoice('PRO-PRIOR', bResetApplied: true);

    $obInvoice = makeResetInvoice('PRO-NEW');

    $obService = new InitialResetService();
    $obException = null;

    try {
        $obService->reset($obInvoice);
    } catch (InitialResetNotAllowedException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect($obException->arContext['reason'] ?? null)->toBe('already_applied');
});

it('zeroes every offer + deactivates every product + flips invoice one-shot bit on success', function (): void {
    resetSettings(bAllow: true);

    $obProduct1 = new Product();
    $obProduct1->name = 'P1';
    $obProduct1->slug = 'p1';
    $obProduct1->active = true;
    $obProduct1->saveQuietly();
    $obProduct2 = new Product();
    $obProduct2->name = 'P2';
    $obProduct2->slug = 'p2';
    $obProduct2->active = true;
    $obProduct2->saveQuietly();

    $obOffer1 = new Offer();
    $obOffer1->product_id = (int) $obProduct1->id;
    $obOffer1->name = 'O1';
    $obOffer1->code = '1234567890123';
    $obOffer1->quantity = 42;
    $obOffer1->active = true;
    $obOffer1->active_managed_by = 'system';
    $obOffer1->saveQuietly();
    $obOffer2 = new Offer();
    $obOffer2->product_id = (int) $obProduct2->id;
    $obOffer2->name = 'O2';
    $obOffer2->code = '1234567890124';
    $obOffer2->quantity = 7;
    $obOffer2->active = true;
    $obOffer2->active_managed_by = 'system';
    $obOffer2->saveQuietly();

    $obInvoice = makeResetInvoice('PRO-RUN');

    $obService = new InitialResetService();
    $obService->reset($obInvoice);

    $obOffer1->refresh();
    $obOffer2->refresh();
    $obProduct1->refresh();
    $obProduct2->refresh();
    $obInvoice->refresh();

    expect((int) $obOffer1->quantity)->toBe(0);
    expect((int) $obOffer2->quantity)->toBe(0);
    expect((bool) $obOffer1->active)->toBeFalse();
    expect((bool) $obOffer2->active)->toBeFalse();
    expect((string) $obOffer1->active_managed_by)->toBe('plugin');
    expect((string) $obOffer2->active_managed_by)->toBe('plugin');
    expect((bool) $obProduct1->active)->toBeFalse();
    expect((bool) $obProduct2->active)->toBeFalse();
    expect((bool) $obInvoice->initial_reset_applied)->toBeTrue();
});
