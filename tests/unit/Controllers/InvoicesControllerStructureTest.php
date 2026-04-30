<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Controllers\Invoices;
use Logingrupa\GoodsReceivedShopaholic\Plugin;
use Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase;

uses(GoodsReceivedTestCase::class);

/**
 * UI-01 / UI-05 / UI-06 / UI-07 — D-01..D-04 / D-26..D-28.
 *
 * Plan 04-03: Backend Invoices controller foundation + audit history list +
 * Invoice attachOne. The Tiger-Style invariant we pin here:
 *
 *   - The controller MUST be a thin Backend\Classes\Controller subclass that
 *     delegates ALL business logic to Phase 3 orchestrators (D-03). Adding
 *     business code to the controller class breaks the orchestrator boundary
 *     and silently grows the surface that needs auth/test coverage.
 *   - The controller MUST declare `$requiredPermissions` at class level
 *     gating `upload_invoices` (D-02 — loose "operators can use this controller
 *     at all" gate). Per-action gates (apply / override / initial_reset)
 *     ship in 04-04..04-06.
 *   - All three behaviors (List + Form + Relation) MUST be present on
 *     `$implement`; missing any one breaks the audit history (List), detail
 *     view (Form), or Lines panel (Relation).
 *   - The 3 YAML configs MUST literally reference the canonical model class
 *     `Logingrupa\GoodsReceivedShopaholic\Models\Invoice` — drift here loses
 *     the audit history binding (T-04-03-03 mitigation).
 *   - All 3 view templates + 4 partial files MUST exist; List/Form/Relation
 *     behaviors require them at runtime even when bodies are skeletal.
 *   - `Plugin::registerSettings()` MUST return TWO entries — the original
 *     `goodsreceived-settings` (Settings model) plus a NEW
 *     `goodsreceived-invoices` entry routing to the Invoices controller URL
 *     (UI-05 audit history reachable from Settings menu, alternative D-04).
 *
 * Structural contract tests only: NO backend HTTP request simulation here.
 * Per-action permission gates land in 04-04 (onUpload), 04-05 (onApply),
 * 04-06 (onOverrideConfirm + onInitialResetConfirm) with the 4 dedicated
 * QA-10 permission tests (D-36 / D-37).
 */
it('controller class exists in the canonical namespace', function (): void {
    expect(class_exists(Invoices::class))->toBeTrue();
});

it('controller extends Backend\\Classes\\Controller (final removed in 04-04 for boundary-mock — D-04-04-01)', function (): void {
    $obReflection = new \ReflectionClass(Invoices::class);
    expect($obReflection->getParentClass()?->getName())->toBe('Backend\\Classes\\Controller');
    // `final` was removed in plan 04-04 to enable a TestableInvoices shim
    // for Pest unit tests of the AJAX handlers (mirrors D-03-07-01 +
    // D-04-02-01 precedent for ImportAuditService + ActiveFlagService
    // boundary-mock support).
});

it('controller implements ListController + FormController + RelationController behaviors', function (): void {
    $obReflection = new \ReflectionClass(Invoices::class);
    $obProp = $obReflection->getProperty('implement');
    $obProp->setAccessible(true);
    $arImplement = $obProp->getDefaultValue();
    expect($arImplement)->toContain('Backend.Behaviors.ListController');
    expect($arImplement)->toContain('Backend.Behaviors.FormController');
    expect($arImplement)->toContain('Backend.Behaviors.RelationController');
});

it('controller declares required-permissions array gating upload_invoices at class level', function (): void {
    $obReflection = new \ReflectionClass(Invoices::class);
    $obProp = $obReflection->getProperty('requiredPermissions');
    $obProp->setAccessible(true);
    expect($obProp->getDefaultValue())->toBe(['logingrupa.goodsreceived.upload_invoices']);
});

it('three controller config YAMLs exist and reference the canonical model class', function (): void {
    $sBase = __DIR__.'/../../../controllers/invoices';
    foreach (['config_list.yaml', 'config_form.yaml', 'config_relation.yaml'] as $sFile) {
        expect(file_exists($sBase.'/'.$sFile))->toBeTrue();
    }
    $sList = (string) file_get_contents($sBase.'/config_list.yaml');
    expect($sList)->toContain('Logingrupa\\GoodsReceivedShopaholic\\Models\\Invoice');
});

it('view templates and partials exist', function (): void {
    $sBase = __DIR__.'/../../../controllers/invoices';
    $arFiles = [
        'index.htm',
        'update.htm',
        'preview.htm',
        '_partials/_audit_panel.htm',
        '_partials/_apply_in_progress.htm',
        '_partials/_apply_success.htm',
        '_partials/_reject.htm',
    ];
    foreach ($arFiles as $sFile) {
        expect(file_exists($sBase.'/'.$sFile))->toBeTrue();
    }
});

it('Plugin::registerNavigation returns goodsreceived entry routing to the Invoices controller', function (): void {
    $obPlugin = new Plugin($this->app);
    $arNav = $obPlugin->registerNavigation();
    expect($arNav)->toHaveKey('goodsreceived');
    expect($arNav['goodsreceived'])->toHaveKey('url');
    expect($arNav['goodsreceived']['url'])->toContain('logingrupa/goodsreceivedshopaholic/invoices');
    expect($arNav['goodsreceived'])->toHaveKey('permissions');
    expect($arNav['goodsreceived']['permissions'])->toContain('logingrupa.goodsreceived.upload_invoices');
});

it('Plugin::registerSettings returns only the canonical goodsreceived-settings entry (single-surface fix)', function (): void {
    $obPlugin = new Plugin($this->app);
    $arSettings = $obPlugin->registerSettings();
    expect($arSettings)->toHaveKey('goodsreceived-settings');
    expect($arSettings)->not->toHaveKey('goodsreceived-invoices');
});
