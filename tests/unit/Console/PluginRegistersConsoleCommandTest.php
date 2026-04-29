<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Console\RecomputeActiveFromStock;

require_once __DIR__.'/../Apply/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * Plan 04-02 — Plugin::register() wiring contract pin (UI-11 / D-33).
 *
 * Source-grep gate: Plugin::register() must call registerConsoleCommand
 * for the new RecomputeActiveFromStock class. Pure structural test —
 * the IoC-side runtime test belongs to PluginTestCase (which boots the
 * full plugin lifecycle); here we pin the source declaration so a
 * future refactor cannot silently drop the registerConsoleCommand line
 * and ship a CLI-broken plugin.
 *
 * Same defense-in-depth pattern as the ActiveFlagServiceTest source-grep
 * + runtime split (D-03-04-03): structural assertion + runtime assertion
 * cover the contract from two independent angles.
 */

it('Plugin::register() body invokes registerConsoleCommand for RecomputeActiveFromStock', function (): void {
    $sPluginSource = file_get_contents(__DIR__.'/../../../Plugin.php');

    expect($sPluginSource)->toBeString();
    expect($sPluginSource)->toContain('public function register()');
    expect($sPluginSource)->toContain('registerConsoleCommand(');
    expect($sPluginSource)->toContain(RecomputeActiveFromStock::class);
    // Artisan signature must be the IoC binding key — keeps `php artisan
    // goodsreceived:recompute_active_from_stock` consistent with the
    // command's `$signature` declaration. (D-33 + plan note on dotted
    // alias: signature is what artisan dispatches on.)
    expect($sPluginSource)->toContain("'goodsreceived:recompute_active_from_stock'");
});

it('Plugin::register() carries the #[\\Override] attribute (PluginBase parent contract)', function (): void {
    $sPluginSource = file_get_contents(__DIR__.'/../../../Plugin.php');

    expect($sPluginSource)->toBeString();
    // Match the attribute on the line ABOVE 'public function register()'.
    // Guard against future drift where a plugin author drops the attribute
    // and Pint's strict_attribute_use rule starts emitting warnings.
    expect($sPluginSource)->toMatch('/#\[\\\\Override\]\s+public function register\(\): void/');
});
