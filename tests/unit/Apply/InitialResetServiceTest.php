<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\InitialResetService;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InitialResetNotAllowedException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\SettingsAccessor;
use Logingrupa\GoodsReceivedShopaholic\Models\InitialResetSnapshot;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\Settings;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

require_once __DIR__.'/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * Plan 03-05 — InitialResetService unit tests (APPLY-05 / QA-06).
 *
 * Pins the load-bearing invariants:
 *   1) Two-gate guard: Settings.allow_initial_reset MUST be true AND no prior
 *      Invoice with initial_reset_applied=true may exist.
 *   2) Snapshot rows for EVERY offer captured BEFORE any mutation — the
 *      snapshot is the rollback artifact (Phase 4/5 ops runbook reads it).
 *   3) Chunked iteration across BOTH the snapshot read path and the mutation
 *      write path — bounded memory regardless of catalog size.
 *   4) Snapshot rows are RICH ENOUGH to restore the exact prior state.
 *   5) saveQuietly only — never bulk DB::statement / whereRaw / mass UPDATE.
 *
 * Settings drive: each test calls Settings::set + Settings::clearInternalCache
 * + SettingsAccessor::flush via the resetSettings() helper so the memo cache
 * reads fresh values within the test body.
 */

/**
 * Configure both Settings keys + drop ALL caches so the service reads them
 * fresh. SettingModel caches the loaded row per process; without
 * clearInternalCache the freshly-set value is invisible to subsequent reads.
 * SettingsAccessor adds its own memo on top.
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

it('throws InitialResetNotAllowedException with reason=settings_disabled when allowInitialReset=false (QA-06 RequiresAllowInitialResetSetting)', function (): void {
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
    expect($obException)->toBeInstanceOf(InitialResetNotAllowedException::class);
    expect($obException->arContext)->toHaveKey('reason');
    expect($obException->arContext['reason'])->toBe('settings_disabled');

    // No mutation must have occurred — snapshot table empty.
    expect(InitialResetSnapshot::count())->toBe(0);
});

it('throws InitialResetNotAllowedException with reason=already_applied when a prior invoice has initial_reset_applied=true (QA-06 OneShotEnforced)', function (): void {
    resetSettings(bAllow: true);

    // Seed a prior invoice with the one-shot bit set.
    makeResetInvoice('PRO-PRIOR-RESET', bResetApplied: true);

    // Try to run reset on a fresh invoice — must be blocked.
    $obFresh = makeResetInvoice('PRO-SECOND-ATTEMPT');

    $obService = new InitialResetService();
    $obException = null;

    try {
        $obService->reset($obFresh);
    } catch (InitialResetNotAllowedException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect($obException)->toBeInstanceOf(InitialResetNotAllowedException::class);
    expect($obException->arContext)->toHaveKey('reason');
    expect($obException->arContext['reason'])->toBe('already_applied');

    // No mutation: snapshot still empty.
    expect(InitialResetSnapshot::count())->toBe(0);
});

it('snapshots every offer prior state BEFORE zeroing offers (QA-06 SnapshotsBeforeWriteTest)', function (): void {
    resetSettings(bAllow: true);

    // Seed varied prior state to make the snapshot capture meaningful.
    $obProductA = seedApplyProduct('PROD-SBW-A', 'prod-sbw-a');
    $obProductB = seedApplyProduct('PROD-SBW-B', 'prod-sbw-b');
    $obProductC = seedApplyProduct('PROD-SBW-C', 'prod-sbw-c');

    $obOffer1 = seedApplyOffer($obProductA->id, '4752307500001', iQuantity: 10, bActive: true);
    $obOffer2 = seedApplyOffer($obProductA->id, '4752307500002', iQuantity: 5, bActive: true);
    $obOffer3 = seedApplyOffer($obProductB->id, '4752307500003', iQuantity: 0, bActive: false);
    $obOffer4 = seedApplyOffer($obProductB->id, '4752307500004', iQuantity: 20, bActive: true);
    $obOffer5 = seedApplyOffer($obProductC->id, '4752307500005', iQuantity: 3, bActive: false);

    $obInvoice = makeResetInvoice('PRO-SBW-001');

    (new InitialResetService())->reset($obInvoice);

    // Snapshot was taken for every offer.
    expect(InitialResetSnapshot::count())->toBe(5);

    // Each snapshot row pins the PRIOR quantity (proves ordering: snapshot
    // happened BEFORE the qty=0 write that follows).
    $arPriorQuantities = [
        (int) $obOffer1->id => 10,
        (int) $obOffer2->id => 5,
        (int) $obOffer3->id => 0,
        (int) $obOffer4->id => 20,
        (int) $obOffer5->id => 3,
    ];
    $arPriorOfferActive = [
        (int) $obOffer1->id => true,
        (int) $obOffer2->id => true,
        (int) $obOffer3->id => false,
        (int) $obOffer4->id => true,
        (int) $obOffer5->id => false,
    ];

    foreach (InitialResetSnapshot::all() as $obSnap) {
        $iOfferId = (int) $obSnap->offer_id;
        expect($arPriorQuantities)->toHaveKey($iOfferId);
        expect((int) $obSnap->prior_quantity)->toBe($arPriorQuantities[$iOfferId]);
        expect((bool) $obSnap->prior_offer_active)->toBe($arPriorOfferActive[$iOfferId]);
        expect((int) $obSnap->invoice_id)->toBe((int) $obInvoice->id);
    }

    // Now assert the post-mutation state: every offer is zeroed + deactivated +
    // managed by 'plugin', and every product is inactive.
    foreach ([$obOffer1, $obOffer2, $obOffer3, $obOffer4, $obOffer5] as $obOffer) {
        $obOffer->refresh();
        expect((int) $obOffer->quantity)->toBe(0);
        expect((bool) $obOffer->active)->toBeFalse();
        expect((string) $obOffer->active_managed_by)->toBe('plugin');
    }
    foreach ([$obProductA, $obProductB, $obProductC] as $obProduct) {
        $obProduct->refresh();
        expect((bool) $obProduct->active)->toBeFalse();
    }

    // Invoice has been marked.
    $obInvoice->refresh();
    expect((bool) $obInvoice->initial_reset_applied)->toBeTrue();
});

it('snapshot is rich enough to restore exact prior state (QA-06 RollbackRestoresExactPriorStateTest)', function (): void {
    resetSettings(bAllow: true);

    // Seed varied prior state.
    $obProductX = seedApplyProduct('PROD-RBK-X', 'prod-rbk-x');
    $obProductY = seedApplyProduct('PROD-RBK-Y', 'prod-rbk-y');
    $obOffer1 = seedApplyOffer($obProductX->id, '4752307600001', iQuantity: 12, bActive: true);
    $obOffer2 = seedApplyOffer($obProductX->id, '4752307600002', iQuantity: 4, bActive: false);
    $obOffer3 = seedApplyOffer($obProductY->id, '4752307600003', iQuantity: 99, bActive: true);

    // Capture pre-reset state.
    $arPrior = [];
    foreach ([$obOffer1, $obOffer2, $obOffer3] as $obOffer) {
        $arPrior[(int) $obOffer->id] = [
            'qty' => (int) $obOffer->quantity,
            'active' => (bool) $obOffer->active,
        ];
    }
    $arPriorProducts = [
        (int) $obProductX->id => (bool) $obProductX->active,
        (int) $obProductY->id => (bool) $obProductY->active,
    ];

    $obInvoice = makeResetInvoice('PRO-RBK-001');
    (new InitialResetService())->reset($obInvoice);

    // Sanity: state IS reset.
    $obOffer1->refresh();
    expect((int) $obOffer1->quantity)->toBe(0);
    expect((bool) $obOffer1->active)->toBeFalse();

    // Manually walk snapshot rows and restore — proves rollback feasibility.
    foreach (InitialResetSnapshot::all() as $obSnap) {
        $obOfferRestore = Offer::find((int) $obSnap->offer_id);
        expect($obOfferRestore)->not->toBeNull();
        $obOfferRestore->quantity = (int) $obSnap->prior_quantity;
        $obOfferRestore->active = (bool) $obSnap->prior_offer_active;
        $obOfferRestore->saveQuietly();

        if ($obSnap->prior_product_id !== null) {
            $obProductRestore = Product::find((int) $obSnap->prior_product_id);
            if ($obProductRestore !== null) {
                $obProductRestore->active = (bool) $obSnap->prior_product_active;
                $obProductRestore->saveQuietly();
            }
        }
    }

    // Assert post-restore state matches pre-reset state EXACTLY.
    foreach ($arPrior as $iOfferId => $arState) {
        $obOfferAfter = Offer::find($iOfferId);
        expect($obOfferAfter)->not->toBeNull();
        expect((int) $obOfferAfter->quantity)->toBe($arState['qty']);
        expect((bool) $obOfferAfter->active)->toBe($arState['active']);
    }
    foreach ($arPriorProducts as $iProductId => $bActive) {
        $obProductAfter = Product::find($iProductId);
        expect($obProductAfter)->not->toBeNull();
        expect((bool) $obProductAfter->active)->toBe($bActive);
    }
});

it('chunks offers and products in 500s — does NOT issue a single bulk UPDATE statement (QA-06 ChunkedNotSingleStatementTest)', function (): void {
    resetSettings(bAllow: true);

    // Seed 1 product + 1500 offers under it. 1500 > 3 chunks of 500.
    $obProduct = seedApplyProduct('PROD-CHK-1', 'prod-chk-1');

    for ($iIdx = 0; $iIdx < 1500; $iIdx++) {
        // Hand-roll seed (not seedApplyOffer) so we skip its overhead and
        // keep this test seed cheap — 1500 inserts is the dominant cost here.
        $obOffer = new Offer();
        $obOffer->product_id = (int) $obProduct->id;
        $obOffer->name = 'CHK Offer '.$iIdx;
        $obOffer->code = sprintf('4752308%06d', $iIdx);
        $obOffer->active = true;
        $obOffer->quantity = ($iIdx % 7) + 1; // 1..7 — non-zero so the
        //                                       prior_quantity assertion is meaningful.
        $obOffer->saveQuietly();
    }

    expect(Offer::count())->toBe(1500);

    $obInvoice = makeResetInvoice('PRO-CHK-001');

    \DB::flushQueryLog();
    \DB::enableQueryLog();
    (new InitialResetService())->reset($obInvoice);
    $arQueryLog = \DB::getQueryLog();
    \DB::disableQueryLog();

    // Count INSERT statements against the snapshot table — chunked batch
    // INSERT means at least 3 batches (1500 rows / 500 per chunk = 3 batches).
    $iSnapshotInserts = count(array_filter($arQueryLog, function (array $arEntry): bool {
        $sQuery = strtolower((string) ($arEntry['query'] ?? ''));

        return str_contains($sQuery, 'insert into "logingrupa_goods_received_initial_reset_snapshot"');
    }));
    expect($iSnapshotInserts)->toBeGreaterThanOrEqual(3);

    // Count SELECT statements against the offers table — chunkById issues
    // one SELECT per chunk page, so 1500 / 500 → at least 3 select pages
    // (plus the one extra empty-page fetch chunkById uses to terminate).
    $iOfferSelects = count(array_filter($arQueryLog, function (array $arEntry): bool {
        $sQuery = strtolower((string) ($arEntry['query'] ?? ''));

        return str_contains($sQuery, 'select') && str_contains($sQuery, '"lovata_shopaholic_offers"');
    }));
    expect($iOfferSelects)->toBeGreaterThanOrEqual(3);

    // Count UPDATE statements against offers — saveQuietly per-row means
    // 1500 individual UPDATEs, NOT one bulk statement. The contract is
    // "many small updates", which is the proxy for "no DB::statement bypass".
    $iOfferUpdates = count(array_filter($arQueryLog, function (array $arEntry): bool {
        $sQuery = strtolower((string) ($arEntry['query'] ?? ''));

        return str_contains($sQuery, 'update "lovata_shopaholic_offers"');
    }));
    expect($iOfferUpdates)->toBe(1500);

    // Snapshot rows match the catalog size exactly.
    expect(InitialResetSnapshot::count())->toBe(1500);

    // Final state: every offer zeroed + deactivated + managed by 'plugin'.
    expect(Offer::where('quantity', 0)->count())->toBe(1500);
    expect(Offer::where('active', false)->count())->toBe(1500);
    expect(Offer::where('active_managed_by', 'plugin')->count())->toBe(1500);
});
