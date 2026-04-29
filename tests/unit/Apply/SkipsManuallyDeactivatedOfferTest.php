<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\ActiveFlagService;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\SettingsAccessor;
use Logingrupa\GoodsReceivedShopaholic\Models\Settings;
use Lovata\Shopaholic\Models\Offer;

require_once __DIR__.'/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * QA-05 dedicated case (per REQUIREMENTS.md line 89: "SkipsManuallyDeactivatedOfferTest").
 *
 * Threat: an operator manually deactivated an offer (e.g. for QA hold) and set
 * `active_managed_by='operator'` in the backend. The next inbound stock arrival
 * MUST NOT reactivate it — operator wins. This is the load-bearing provenance
 * contract for APPLY-03.
 *
 * Distinct from ActiveFlagServiceTest's reconcileAll-level filter: THIS test
 * pins the per-row provenance gate inside `reconcileSingleOffer()` (the Apply
 * orchestrator path; D-15 ApplyOrchestrator wraps this inside its DB::transaction).
 */
it('skips offer with active_managed_by=operator even when autoActivateOnStock would otherwise activate', function (): void {
    // Settings would otherwise activate any qty>0 inactive offer.
    Settings::clearInternalCache();
    Settings::set('auto_deactivate_on_zero', false);
    Settings::set('auto_activate_on_stock', true);
    SettingsAccessor::flush();

    $obProduct = seedApplyProduct('OPSEED-CODE', 'op-seed-product');
    $obOffer = new Offer();
    $obOffer->product_id = $obProduct->id;
    $obOffer->name = 'Operator-locked offer';
    $obOffer->code = '4752307999999';
    $obOffer->active = false;
    $obOffer->quantity = 10;
    $obOffer->active_managed_by = 'operator';
    $obOffer->saveQuietly();
    $iOfferId = (int) $obOffer->id;

    // Reconcile via the Apply path → MUST NOT touch this offer.
    (new ActiveFlagService())->reconcile([$iOfferId]);

    $obRefreshed = Offer::find($iOfferId);
    expect($obRefreshed)->not->toBeNull();
    expect((bool) $obRefreshed->active)->toBeFalse();
    expect((string) $obRefreshed->active_managed_by)->toBe('operator');
});
