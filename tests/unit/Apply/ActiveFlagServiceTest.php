<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\ActiveFlagService;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\SettingsAccessor;
use Logingrupa\GoodsReceivedShopaholic\Models\Settings;
use Lovata\Shopaholic\Models\Offer;

require_once __DIR__.'/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * Plan 03-04 — ActiveFlagService unit tests.
 *
 * Pins the load-bearing invariants for APPLY-03 / APPLY-04 / QA-05:
 *   - 4-cell matrix: (autoDeactivateOnZero on/off) × (autoActivateOnStock on/off)
 *   - When toggling, active_managed_by stamps to 'plugin' (provenance markers)
 *   - Idempotent — second reconcile with identical input fires NO write
 *   - reconcileAll iterates in chunks; returns count of touched offers
 *   - reconcileAll skips operator-managed rows AT THE QUERY LEVEL (where filter)
 *
 * Settings drive: each test calls Settings::set + SettingsAccessor::flush so
 * the memo cache reads fresh values within the test body. The
 * `flushPluginSingletons()` in GoodsReceivedTestCase handles cross-test
 * isolation; intra-test settings flips need explicit flush.
 */

/**
 * Helper: configure both settings + drop the memo cache so the service reads them fresh.
 *
 * Mirror of `SettingsAccessorTest::beforeEach` Settings::clearInternalCache call —
 * `SettingModel` caches the loaded row per process; without clearing the
 * internal cache, the freshly-set value is invisible to subsequent reads.
 */
function applySettings(bool $bDeactivate, bool $bActivate): void
{
    Settings::clearInternalCache();
    Settings::set('auto_deactivate_on_zero', $bDeactivate);
    Settings::set('auto_activate_on_stock', $bActivate);
    SettingsAccessor::flush();
}

it('matrix cell (deactivate=on, activate=on): qty=0+active=true → active=false+managed_by=plugin', function (): void {
    applySettings(bDeactivate: true, bActivate: true);

    $obProduct = seedApplyProduct('PROD-MX1', 'prod-mx1');
    $obOffer = seedApplyOffer($obProduct->id, '4752307100001', iQuantity: 0, bActive: true);

    (new ActiveFlagService())->reconcile([(int) $obOffer->id]);

    $obOffer->refresh();
    expect((bool) $obOffer->active)->toBeFalse();
    expect((string) $obOffer->active_managed_by)->toBe('plugin');
});

it('matrix cell (deactivate=on, activate=on): qty>0+active=false → active=true+managed_by=plugin', function (): void {
    applySettings(bDeactivate: true, bActivate: true);

    $obProduct = seedApplyProduct('PROD-MX2', 'prod-mx2');
    $obOffer = seedApplyOffer($obProduct->id, '4752307100002', iQuantity: 10, bActive: false);
    // Was previously plugin-deactivated; provenance is plugin (not operator).
    $obOffer->active_managed_by = 'plugin';
    $obOffer->saveQuietly();

    (new ActiveFlagService())->reconcile([(int) $obOffer->id]);

    $obOffer->refresh();
    expect((bool) $obOffer->active)->toBeTrue();
    expect((string) $obOffer->active_managed_by)->toBe('plugin');
});

it('matrix cell (deactivate=off, activate=on): qty=0+active=true → unchanged (deactivate disabled)', function (): void {
    applySettings(bDeactivate: false, bActivate: true);

    $obProduct = seedApplyProduct('PROD-MX3', 'prod-mx3');
    $obOffer = seedApplyOffer($obProduct->id, '4752307100003', iQuantity: 0, bActive: true);

    (new ActiveFlagService())->reconcile([(int) $obOffer->id]);

    $obOffer->refresh();
    expect((bool) $obOffer->active)->toBeTrue();
    expect((string) $obOffer->active_managed_by)->toBe('system');
});

it('matrix cell (deactivate=on, activate=off): qty>0+active=false → unchanged (activate disabled)', function (): void {
    applySettings(bDeactivate: true, bActivate: false);

    $obProduct = seedApplyProduct('PROD-MX4', 'prod-mx4');
    $obOffer = seedApplyOffer($obProduct->id, '4752307100004', iQuantity: 10, bActive: false);

    (new ActiveFlagService())->reconcile([(int) $obOffer->id]);

    $obOffer->refresh();
    expect((bool) $obOffer->active)->toBeFalse();
    expect((string) $obOffer->active_managed_by)->toBe('system');
});

it('matrix cell (deactivate=off, activate=off): no changes regardless of qty/active', function (): void {
    applySettings(bDeactivate: false, bActivate: false);

    $obProduct = seedApplyProduct('PROD-MX5', 'prod-mx5');
    $obOffer1 = seedApplyOffer($obProduct->id, '4752307100005', iQuantity: 0, bActive: true);
    $obOffer2 = seedApplyOffer($obProduct->id, '4752307100006', iQuantity: 10, bActive: false);

    (new ActiveFlagService())->reconcile([(int) $obOffer1->id, (int) $obOffer2->id]);

    $obOffer1->refresh();
    $obOffer2->refresh();
    expect((bool) $obOffer1->active)->toBeTrue();
    expect((string) $obOffer1->active_managed_by)->toBe('system');
    expect((bool) $obOffer2->active)->toBeFalse();
    expect((string) $obOffer2->active_managed_by)->toBe('system');
});

it('is idempotent — second reconcile with same ids does not write again', function (): void {
    applySettings(bDeactivate: true, bActivate: true);

    $obProduct = seedApplyProduct('PROD-IDEMP', 'prod-idemp');
    $obOffer = seedApplyOffer($obProduct->id, '4752307100007', iQuantity: 0, bActive: true);

    $obService = new ActiveFlagService();

    // First reconcile: SELECT (offers) + UPDATE (offer toggle) = 2 queries.
    \DB::flushQueryLog();
    \DB::enableQueryLog();
    $obService->reconcile([(int) $obOffer->id]);
    $iFirstQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    // Verify state changed (sanity).
    $obOffer->refresh();
    expect((bool) $obOffer->active)->toBeFalse();
    expect((string) $obOffer->active_managed_by)->toBe('plugin');

    // Second reconcile: SELECT only (no UPDATE — already in target state).
    \DB::flushQueryLog();
    \DB::enableQueryLog();
    $obService->reconcile([(int) $obOffer->id]);
    $iSecondQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    // First call: at least 1 SELECT + 1 UPDATE; second call: only 1 SELECT.
    expect($iFirstQueryCount)->toBeGreaterThan($iSecondQueryCount);
    expect($iSecondQueryCount)->toBe(1);
});

it('reconcileAll iterates in chunks and returns count of touched offers', function (): void {
    applySettings(bDeactivate: true, bActivate: false);

    $obProduct = seedApplyProduct('PROD-ALL', 'prod-all');
    // 4 zero-qty active offers → should deactivate. 3 stocked active offers → no change.
    $arZeroIds = [];
    for ($iIdx = 0; $iIdx < 4; $iIdx++) {
        $arZeroIds[] = (int) seedApplyOffer($obProduct->id, sprintf('47523072%05d', $iIdx), iQuantity: 0, bActive: true)->id;
    }
    $arStockedIds = [];
    for ($iIdx = 0; $iIdx < 3; $iIdx++) {
        $arStockedIds[] = (int) seedApplyOffer($obProduct->id, sprintf('47523073%05d', $iIdx), iQuantity: 10, bActive: true)->id;
    }

    $iTouched = (new ActiveFlagService())->reconcileAll(iChunkSize: 3);
    expect($iTouched)->toBe(4);

    foreach ($arZeroIds as $iOfferId) {
        $obOffer = Offer::find($iOfferId);
        expect($obOffer)->not->toBeNull();
        expect((bool) $obOffer->active)->toBeFalse();
        expect((string) $obOffer->active_managed_by)->toBe('plugin');
    }
    foreach ($arStockedIds as $iOfferId) {
        $obOffer = Offer::find($iOfferId);
        expect($obOffer)->not->toBeNull();
        expect((bool) $obOffer->active)->toBeTrue();
        expect((string) $obOffer->active_managed_by)->toBe('system');
    }
});

it('reconcileAll excludes operator-managed offers via WHERE filter at query level', function (): void {
    applySettings(bDeactivate: true, bActivate: true);

    $obProduct = seedApplyProduct('PROD-OPS', 'prod-ops');

    // Operator-locked offer: qty=0 active=true (would normally deactivate),
    // but operator owns the row.
    $obOpOffer = seedApplyOffer($obProduct->id, '4752307400001', iQuantity: 0, bActive: true);
    $obOpOffer->active_managed_by = 'operator';
    $obOpOffer->saveQuietly();

    // Non-operator companion: same state — should be deactivated.
    $obSysOffer = seedApplyOffer($obProduct->id, '4752307400002', iQuantity: 0, bActive: true);

    $iTouched = (new ActiveFlagService())->reconcileAll();
    expect($iTouched)->toBe(1);

    $obOpRefreshed = Offer::find((int) $obOpOffer->id);
    expect($obOpRefreshed)->not->toBeNull();
    expect((bool) $obOpRefreshed->active)->toBeTrue();
    expect((string) $obOpRefreshed->active_managed_by)->toBe('operator');

    $obSysRefreshed = Offer::find((int) $obSysOffer->id);
    expect($obSysRefreshed)->not->toBeNull();
    expect((bool) $obSysRefreshed->active)->toBeFalse();
    expect((string) $obSysRefreshed->active_managed_by)->toBe('plugin');
});
