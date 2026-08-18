<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\ActiveFlagService;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\SettingsAccessor;
use Logingrupa\GoodsReceivedShopaholic\Console\RecomputeActiveFromStock;
use Logingrupa\GoodsReceivedShopaholic\Models\Settings;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

require_once __DIR__.'/../Apply/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * Plan 04-02 — RecomputeActiveFromStock console command tests (UI-11 / D-29..D-33 / D-37 / D-45).
 *
 * Pins the load-bearing CLI invariants:
 *   - artisan command resolves + exits 0 on success (artisan-side contract).
 *   - --chunk option propagates int into ActiveFlagService::reconcileAll.
 *   - operator-managed offers (active_managed_by='operator') are skipped end-to-end.
 *   - reconciles every non-operator offer in chunked passes.
 *   - exit 1 + $this->error() emission when ActiveFlagService throws.
 *
 * The artisan kernel is fed the command instance directly via beforeEach
 * (the plugin's register() pathway depends on $autoRegister=true which the
 * Apply test base does not flip — so we register the command on the IoC
 * console kernel for these tests rather than relying on Plugin::register()
 * to fire during the test bootstrap).
 */

/**
 * Helper: configure both auto-flag toggles + drop the memo cache so the
 * service reads fresh values within the test body. Mirror of the
 * `applySettings()` helper in ActiveFlagServiceTest — same rationale.
 */
function applyAutoFlagSettings(bool $bDeactivate, bool $bActivate): void
{
    Settings::clearInternalCache();
    Settings::set('auto_deactivate_on_zero', $bDeactivate);
    Settings::set('auto_activate_on_stock', $bActivate);
    SettingsAccessor::flush();
}

beforeEach(function (): void {
    /** @var \Illuminate\Contracts\Console\Kernel $obKernel */
    $obKernel = $this->app[\Illuminate\Contracts\Console\Kernel::class];
    $obKernel->registerCommand(new RecomputeActiveFromStock());
});

it('exits 0 on a clean reconcile with auto-flag settings off (no-op)', function (): void {
    applyAutoFlagSettings(bDeactivate: false, bActivate: false);

    $iExitCode = \Artisan::call('goodsreceived:recompute_active_from_stock');

    expect($iExitCode)->toBe(0);
    expect(\Artisan::output())->toContain('Reconciled 0 offers');
});

it('reconciles every non-operator offer in chunks and reports the count', function (): void {
    applyAutoFlagSettings(bDeactivate: true, bActivate: false);

    $obProduct = seedApplyProduct('PROD-CMD1', 'prod-cmd1');
    $arZeroIds = [];
    for ($iIdx = 0; $iIdx < 5; $iIdx++) {
        $arZeroIds[] = (int) seedApplyOffer($obProduct->id, sprintf('47523071%05d', $iIdx), iQuantity: 0, bActive: true)->id;
    }

    $iExitCode = \Artisan::call('goodsreceived:recompute_active_from_stock', ['--chunk' => 2]);

    expect($iExitCode)->toBe(0);
    expect(\Artisan::output())->toContain('Reconciled 5 offers');

    foreach ($arZeroIds as $iOfferId) {
        $obOffer = Offer::find($iOfferId);
        expect($obOffer)->not->toBeNull();
        expect((bool) $obOffer->active)->toBeFalse();
        expect((string) $obOffer->active_managed_by)->toBe('plugin');
    }
});

it('skips operator-managed offers end-to-end via the command', function (): void {
    applyAutoFlagSettings(bDeactivate: true, bActivate: true);

    $obProduct = seedApplyProduct('PROD-CMD2', 'prod-cmd2');

    // Operator-locked offer (qty=0, active=true): should remain untouched.
    $obOpOffer = seedApplyOffer($obProduct->id, '4752307200001', iQuantity: 0, bActive: true);
    $obOpOffer->active_managed_by = 'operator';
    $obOpOffer->saveQuietly();

    // Companion non-operator offer with same state: should be deactivated.
    $obSysOffer = seedApplyOffer($obProduct->id, '4752307200002', iQuantity: 0, bActive: true);

    $iExitCode = \Artisan::call('goodsreceived:recompute_active_from_stock');

    expect($iExitCode)->toBe(0);
    expect(\Artisan::output())->toContain('Reconciled 1 offers');

    $obOpRefreshed = Offer::find((int) $obOpOffer->id);
    expect($obOpRefreshed)->not->toBeNull();
    expect((bool) $obOpRefreshed->active)->toBeTrue();
    expect((string) $obOpRefreshed->active_managed_by)->toBe('operator');

    $obSysRefreshed = Offer::find((int) $obSysOffer->id);
    expect($obSysRefreshed)->not->toBeNull();
    expect((bool) $obSysRefreshed->active)->toBeFalse();
    expect((string) $obSysRefreshed->active_managed_by)->toBe('plugin');
});

it('honors the --chunk option (large dataset, small chunk)', function (): void {
    applyAutoFlagSettings(bDeactivate: true, bActivate: false);

    $obProduct = seedApplyProduct('PROD-CMD3', 'prod-cmd3');
    for ($iIdx = 0; $iIdx < 12; $iIdx++) {
        seedApplyOffer($obProduct->id, sprintf('47523073%05d', $iIdx), iQuantity: 0, bActive: true);
    }

    $iExitCode = \Artisan::call('goodsreceived:recompute_active_from_stock', ['--chunk' => 5]);
    $sOutput = \Artisan::output();

    expect($iExitCode)->toBe(0);
    expect($sOutput)->toContain('Reconciled 12 offers');
    expect($sOutput)->toContain('chunk=5');
});

it('reactivates the parent product of an offer it activates', function (): void {
    // The .no recovery case: a reset left every product inactive, stock came
    // back, and the CLI flipped offers to active while every parent product
    // stayed inactive — so ProductActiveListStore kept the catalog empty.
    // reconcile() (the invoice path) always did this; reconcileAll() did not.
    applyAutoFlagSettings(bDeactivate: false, bActivate: true);

    $obProduct = seedApplyProduct('PROD-CMD4', 'prod-cmd4');
    $obProduct->active = false;
    $obProduct->saveQuietly();

    $obOffer = seedApplyOffer($obProduct->id, '4752307400001', iQuantity: 7, bActive: false);

    $iExitCode = \Artisan::call('goodsreceived:recompute_active_from_stock');

    expect($iExitCode)->toBe(0);
    expect(\Artisan::output())->toContain('Reconciled 1 offers');

    $obOfferRefreshed = Offer::find((int) $obOffer->id);
    expect($obOfferRefreshed)->not->toBeNull();
    expect((bool) $obOfferRefreshed->active)->toBeTrue();

    $obProductRefreshed = Product::find((int) $obProduct->id);
    expect($obProductRefreshed)->not->toBeNull();
    expect((bool) $obProductRefreshed->active)->toBeTrue();
});

it('reports the resolved site context and the toggles it read', function (): void {
    // A run against the wrong site's settings row was indistinguishable from a
    // correct one — that is what produced "95 activated, 0 deactivated" on .no.
    applyAutoFlagSettings(bDeactivate: true, bActivate: false);

    \Artisan::call('goodsreceived:recompute_active_from_stock');

    expect(\Artisan::output())
        ->toContain('Site context:')
        ->toContain('auto_deactivate_on_zero=true')
        ->toContain('auto_activate_on_stock=false');
});

it('returns exit 1 and prints error when ActiveFlagService throws', function (): void {
    // Bind a failing fake service into the container so handle() catches it.
    $this->app->bind(ActiveFlagService::class, fn (): ActiveFlagService => new class () extends ActiveFlagService {
        #[\Override]
        public function reconcileAllDetailed(int $iChunkSize = 500): array
        {
            throw new \RuntimeException('forced failure for test');
        }
    });

    $iExitCode = \Artisan::call('goodsreceived:recompute_active_from_stock');
    $sOutput = \Artisan::output();

    expect($iExitCode)->toBe(1);
    expect($sOutput)->toContain('Recompute failed:');
    expect($sOutput)->toContain('forced failure for test');
});
