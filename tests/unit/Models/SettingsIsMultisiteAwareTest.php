<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Models\Settings;

/**
 * QA-07 part 1: prove the Settings model has the multisite plumbing required
 * for per-site isolation. This is a pure-introspection test (no DB) — fast and
 * reliable. Runs without `uses(GoodsReceivedTestCase::class)` because we don't
 * need DB / app boot.
 */

it('uses the October\\Rain\\Database\\Traits\\Multisite trait', function (): void {
    $arTraits = class_uses_recursive(Settings::class);

    expect($arTraits)->toHaveKey('October\\Rain\\Database\\Traits\\Multisite');
});

it('declares an empty $propagatable array (required by the Multisite trait)', function (): void {
    $obReflection = new ReflectionClass(Settings::class);

    expect($obReflection->hasProperty('propagatable'))->toBeTrue();

    $obProperty = $obReflection->getProperty('propagatable');
    $obProperty->setAccessible(true);

    $obSettings = $obReflection->newInstanceWithoutConstructor();
    $arPropagatable = $obProperty->getValue($obSettings);

    expect($arPropagatable)->toBeArray();
    expect($arPropagatable)->toBeEmpty();
});

it('extends System\\Models\\SettingModel directly (per locked decision D15)', function (): void {
    $obReflection = new ReflectionClass(Settings::class);
    $sParentClass = $obReflection->getParentClass()->getName();

    // D15: extend SettingModel directly, NOT Lovata\Toolbox\Models\CommonSettings
    expect($sParentClass)->toBe('System\\Models\\SettingModel');
});

it('exposes SETTINGS_CODE constant matching $settingsCode', function (): void {
    expect(Settings::SETTINGS_CODE)->toBe('logingrupa_goodsreceivedshopaholic_settings');

    $obSettings = (new ReflectionClass(Settings::class))->newInstanceWithoutConstructor();
    expect($obSettings->settingsCode)->toBe('logingrupa_goodsreceivedshopaholic_settings');
});

it('uses fields.yaml for backend form definition', function (): void {
    $obSettings = (new ReflectionClass(Settings::class))->newInstanceWithoutConstructor();

    expect($obSettings->settingsFields)->toBe('fields.yaml');
});
