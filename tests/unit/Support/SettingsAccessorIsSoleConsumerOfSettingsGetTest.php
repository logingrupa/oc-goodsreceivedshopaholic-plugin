<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase;

uses(GoodsReceivedTestCase::class);

/**
 * QA-09: SettingsAccessor must be the SOLE consumer of `Settings::get(`.
 *
 * This Pest test mirrors the `make lint-settings-accessor` Makefile target
 * (D-02). Both fail on identical conditions — the duplication is intentional
 * so the invariant survives Makefile drift, removed CI steps, or local-dev
 * forgetting to run `make all`. Either gate alone is sufficient; both
 * together is defense-in-depth.
 *
 * Excludes from the scan:
 *   - The accessor file itself (legitimate sole caller).
 *   - The plugin's `tests/` directory — test code MAY use Settings::get/set
 *     directly for fixtures and assertions (the contract is about runtime
 *     code paths, not test scaffolding).
 *   - The `.planning/` directory — docs reference the literal string in
 *     prose / code blocks.
 *   - `vendor/` — composer-installed dependencies are not our code.
 *
 * Scope of the scan: `classes/`, `components/`, `models/`, and `Plugin.php`
 * — the runtime surface area that loads on every request. Keeps the test
 * fast (~10ms) and bounded.
 */
it('confirms Settings::get( appears only in classes/support/SettingsAccessor.php', function (): void {
    $sPluginRoot = realpath(__DIR__ . '/../../..');
    expect($sPluginRoot)->toBeString();
    expect(is_dir((string) $sPluginRoot))->toBeTrue();

    $arDirs = [
        $sPluginRoot . '/classes',
        $sPluginRoot . '/components',
        $sPluginRoot . '/models',
    ];
    $sPluginPhp = $sPluginRoot . '/Plugin.php';
    $sAccessorRelPath = 'classes/support/SettingsAccessor.php';

    /** @var list<string> $arOffenders */
    $arOffenders = [];

    foreach ($arDirs as $sDir) {
        if (! is_dir($sDir)) {
            continue;
        }
        $obIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sDir));
        foreach ($obIterator as $obFile) {
            if (! $obFile->isFile() || $obFile->getExtension() !== 'php') {
                continue;
            }
            $sPath = (string) $obFile->getPathname();
            if (str_ends_with(str_replace('\\', '/', $sPath), $sAccessorRelPath)) {
                continue;
            }
            $sContent = (string) file_get_contents($sPath);
            if (str_contains($sContent, 'Settings::get(')) {
                $arOffenders[] = $sPath;
            }
        }
    }

    if (file_exists($sPluginPhp)) {
        $sContent = (string) file_get_contents($sPluginPhp);
        if (str_contains($sContent, 'Settings::get(')) {
            $arOffenders[] = $sPluginPhp;
        }
    }

    expect($arOffenders)->toBe(
        [],
        'QA-09 violation: Settings::get( appears in: ' . implode(', ', $arOffenders)
    );
});
