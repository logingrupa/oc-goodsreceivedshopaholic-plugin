<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\InitialResetService;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ApplyOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ParseAndPersistOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\SettingsAccessor;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\Settings;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

require_once __DIR__.'/../Apply/ApplyTestCase.php';
require_once __DIR__.'/InvoiceUploadTestHelpers.php';

uses(ApplyTestCase::class);

/**
 * UI-08 / D-22..D-25 / D-04-06-06..D-04-06-10.
 *
 * Plan 04-06 Task 2: typed-RESET confirmation gate for the one-shot
 * baseline-reset flow.
 *
 * Tiger-Style invariants pinned here:
 *   - Permission gate at handler entry (`run_initial_reset`); deny ⇒ AjaxException.
 *   - Two-gate guard (mirrors Phase 3 InitialResetService::assertAllowed):
 *     SettingsAccessor::allowInitialReset() must be true AND no prior Invoice
 *     with initial_reset_applied=true exists. The controller adds permission
 *     gate as defense-in-depth (T-04-06-03).
 *   - Pre-mutation snapshot count: total Offer rows + total Product rows are
 *     surfaced to the operator BEFORE the destructive op runs (D-23).
 *   - Server-side strict equality on the literal RESET (T-04-06-01) — case-
 *     sensitive, lowercased / mistyped / missing entries reject BEFORE
 *     InitialResetService::reset is even called.
 *   - Reset → Apply order is contract per D-24: reset zeros offers + flips
 *     invoice.initial_reset_applied=true BEFORE the new invoice's stock is
 *     incremented by ApplyOrchestrator::apply. Side-effect verification
 *     pins this without timing-coupling: after onInitialResetConfirm,
 *     invoice.initial_reset_applied=true AND offer.quantity reflects the
 *     applied invoice's qty (NOT zero — apply ran AFTER reset).
 *
 * Test seams reuse the TestableInvoices shim from InvoiceUploadTestHelpers.
 */

/**
 * Configure both Settings keys + drop ALL caches so the service reads them
 * fresh. Mirrors `resetSettings` in InitialResetServiceTest (the shared helper
 * cannot be reused across files because Pest auto-loads test files but
 * not declared functions across them; copying ~3 lines is the
 * project-standard self-contained-test pattern per plan 04-04 D-04-04-04).
 */
function resetSettingsForController(bool $bAllow): void
{
    Settings::clearInternalCache();
    Settings::set('allow_initial_reset', $bAllow);
    SettingsAccessor::flush();
}

/**
 * Seed N offers attached to N products for the snapshot-count test.
 *
 * @return list<Offer>
 */
function seedOffersAndProducts(int $iCount): array
{
    $arOffers = [];
    for ($iIndex = 1; $iIndex <= $iCount; $iIndex++) {
        $obProduct = seedApplyProduct(sprintf('IR-CODE-%d', $iIndex), sprintf('ir-product-%d', $iIndex));
        $arOffers[] = seedApplyOffer((int) $obProduct->id, sprintf('4752307%06d', $iIndex), iQuantity: 0);
    }

    return $arOffers;
}

it('rejects onInitialResetShowConfirm without run_initial_reset permission (D-04-06-03 / T-04-06-03)', function (): void {
    $obController = makeTestController(bHasPermission: false, arFiles: null);

    $obException = null;
    try {
        $obController->onInitialResetShowConfirm();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.run_initial_reset']);
});

it('onInitialResetShowConfirm rejects when allow_initial_reset setting is off (D-22 settings_disabled)', function (): void {
    resetSettingsForController(bAllow: false);

    $obController = makeTestController(bHasPermission: true, arFiles: null);

    $obException = null;
    try {
        $obController->onInitialResetShowConfirm();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect($arContents)->toHaveKey('reason');
    expect($arContents['reason'])->toBe('settings_disabled');
});

it('onInitialResetShowConfirm rejects when prior reset already applied (D-22 already_applied)', function (): void {
    resetSettingsForController(bAllow: true);

    // Seed a prior invoice with initial_reset_applied=true (one-shot consumed).
    $obPrior = new Invoice();
    $obPrior->invoice_number = 'PRO-PRIOR-RESET';
    $obPrior->status = Invoice::STATUS_APPLIED;
    $obPrior->total_lines = 1;
    $obPrior->matched_lines = 1;
    $obPrior->unmatched_lines = 0;
    $obPrior->stock_added_units = 1;
    $obPrior->initial_reset_applied = true;
    $obPrior->saveQuietly();

    $obController = makeTestController(bHasPermission: true, arFiles: null);

    $obException = null;
    try {
        $obController->onInitialResetShowConfirm();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect($arContents)->toHaveKey('reason');
    expect($arContents['reason'])->toBe('already_applied');
});

it('onInitialResetShowConfirm renders modal with snapshot counts (D-23)', function (): void {
    resetSettingsForController(bAllow: true);

    // Seed 5 offers + 5 products (the helper creates them in pairs).
    seedOffersAndProducts(5);
    expect(Offer::count())->toBe(5);
    expect(Product::count())->toBe(5);

    $obController = makeTestController(bHasPermission: true, arFiles: null);

    $arResponse = $obController->onInitialResetShowConfirm();

    expect($arResponse)->toBeArray();
    expect($arResponse)->toHaveKey('#initialResetConfirm');
    expect((string) $arResponse['#initialResetConfirm'])->toContain('_partials/_initial_reset_confirm');

    $arPartialCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/_initial_reset_confirm') {
            $arPartialCall = $arCall;
            break;
        }
    }
    expect($arPartialCall)->not->toBeNull();
    expect((int) $arPartialCall['data']['offer_count'])->toBe(5);
    expect((int) $arPartialCall['data']['product_count'])->toBe(5);
});

it('rejects onInitialResetConfirm without run_initial_reset permission', function (): void {
    $obController = makeTestController(bHasPermission: false, arFiles: null);

    $obException = null;
    try {
        $obController->onInitialResetConfirm();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.run_initial_reset']);
});

it('onInitialResetConfirm rejects when typed string is wrong case (D-24 case-sensitive)', function (): void {
    $obController = makeTestController(bHasPermission: true, arFiles: null);
    \Input::merge(['confirm_typed' => 'reset']);

    $obException = null;
    try {
        $obController->onInitialResetConfirm();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect((string) $obException->getContents()['message'])->toContain('RESET');
});

it('onInitialResetConfirm with literal RESET runs reset BEFORE apply, returns success (D-24 happy path)', function (): void {
    resetSettingsForController(bAllow: true);

    // Seed Product + Offer that the fixture invoice's lines will match against
    // (the EAN 4752307003700 is in the Nr_PRO033328_no_13042026.HTM fixture).
    $obProduct = seedApplyProduct('IR-HAPPY-CODE', 'ir-happy-product');
    $obOffer = seedApplyOffer((int) $obProduct->id, '4752307003700', iQuantity: 50);

    expect((bool) $obOffer->active)->toBeTrue();
    expect((int) $obOffer->quantity)->toBe(50);

    // Stage the fixture file.
    $arStaged = stageFixtureUpload('Nr_PRO033328_no_13042026.HTM');

    \Input::merge(['confirm_typed' => 'RESET']);

    $obController = makeTestController(bHasPermission: true, arFiles: [$arStaged['file']], iUserId: 99);

    try {
        $arResponse = $obController->onInitialResetConfirm();

        expect($arResponse)->toBeArray();
        expect($arResponse)->toHaveKey('#applyResult');
        expect((string) $arResponse['#applyResult'])->toContain('_partials/_apply_success');

        // Reset ran BEFORE apply: reset zeroed everything, then apply added
        // the fixture's qty for the matched offer. The offer's final quantity
        // is the FIXTURE's qty (NOT 50 + qty, because reset zeroed it first).
        $obOffer->refresh();
        expect((int) $obOffer->quantity)->toBeGreaterThan(0);
        expect((int) $obOffer->quantity)->toBeLessThan(50); // proves reset zeroed the prior 50

        // The new Invoice has initial_reset_applied=true (reset flipped it
        // BEFORE apply ran).
        $obInvoice = Invoice::query()->orderByDesc('id')->first();
        expect($obInvoice)->not->toBeNull();
        expect((bool) $obInvoice->initial_reset_applied)->toBeTrue();
        expect((string) $obInvoice->status)->toBe(Invoice::STATUS_APPLIED);
    } finally {
        @unlink($arStaged['path']);
    }
});

it('onInitialResetConfirm rejects when allow_initial_reset is off (defense-in-depth T-04-06-02)', function (): void {
    resetSettingsForController(bAllow: false);

    $arStaged = stageFixtureUpload('Nr_PRO033328_no_13042026.HTM');

    \Input::merge(['confirm_typed' => 'RESET']);

    $obController = makeTestController(bHasPermission: true, arFiles: [$arStaged['file']]);

    $obException = null;
    try {
        $obController->onInitialResetConfirm();
    } catch (\Throwable $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();

    @unlink($arStaged['path']);
});

it('shouldShowInitialReset returns false when settings is off', function (): void {
    resetSettingsForController(bAllow: false);

    $obController = new TestableInvoices();

    expect($obController->shouldShowInitialReset())->toBeFalse();
});

it('shouldShowInitialReset returns true when settings is on AND no prior reset', function (): void {
    resetSettingsForController(bAllow: true);

    $obController = new TestableInvoices();

    expect($obController->shouldShowInitialReset())->toBeTrue();
});

it('shouldShowInitialReset returns false when settings is on but prior reset exists', function (): void {
    resetSettingsForController(bAllow: true);

    $obPrior = new Invoice();
    $obPrior->invoice_number = 'PRO-PRIOR-RESET-2';
    $obPrior->status = Invoice::STATUS_APPLIED;
    $obPrior->total_lines = 1;
    $obPrior->matched_lines = 1;
    $obPrior->unmatched_lines = 0;
    $obPrior->stock_added_units = 1;
    $obPrior->initial_reset_applied = true;
    $obPrior->saveQuietly();

    $obController = new TestableInvoices();

    expect($obController->shouldShowInitialReset())->toBeFalse();
});
