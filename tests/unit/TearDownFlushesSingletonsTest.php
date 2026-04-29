<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase;

uses(GoodsReceivedTestCase::class);

/**
 * QA-11: prove that GoodsReceivedTestCase::tearDown() invokes the
 * flushPluginSingletons() hook, BEFORE parent::tearDown(), so cross-test
 * bleed is impossible once Phase 2/3 populate the hook with real flush() calls.
 *
 * We verify via reflection that:
 *   1. The method `flushPluginSingletons` exists on the test base.
 *   2. It is `protected void`.
 *   3. The method body is invoked from `tearDown` (string-search the source —
 *      reliable because the test base is a small file, fully under our control).
 *
 * Phase 3 plan 03-01 update: the body now contains
 * `SettingsAccessor::flush()` per D-03. The original "body is empty" test
 * was wired by Phase 1 to fail by-design once Phase 2/3 populated the hook
 * (see commit history of this file). Replaced with an assertion that pins
 * the FIRST populated singleton flush — subsequent Phase 3 plans MAY add
 * lines but MUST NOT remove this one.
 */

it('declares a protected flushPluginSingletons(): void method on the test base', function (): void {
    $obReflection = new ReflectionClass(GoodsReceivedTestCase::class);

    expect($obReflection->hasMethod('flushPluginSingletons'))->toBeTrue();

    $obMethod = $obReflection->getMethod('flushPluginSingletons');
    expect($obMethod->isProtected())->toBeTrue();
    expect((string) $obMethod->getReturnType())->toBe('void');
});

it('invokes flushPluginSingletons() from within tearDown()', function (): void {
    $sBaseFile = (new ReflectionClass(GoodsReceivedTestCase::class))->getFileName();
    expect($sBaseFile)->not->toBeFalse();

    $sSource = file_get_contents($sBaseFile);
    expect($sSource)->toContain('$this->flushPluginSingletons();');
});

it('invokes flushPluginSingletons() BEFORE parent::tearDown() (correct lifecycle order)', function (): void {
    $sBaseFile = (new ReflectionClass(GoodsReceivedTestCase::class))->getFileName();
    $sSource = file_get_contents($sBaseFile);

    $iFlushPos = strpos($sSource, '$this->flushPluginSingletons();');
    $iParentPos = strpos($sSource, 'parent::tearDown();');

    expect($iFlushPos)->not->toBeFalse();
    expect($iParentPos)->not->toBeFalse();
    expect($iFlushPos)->toBeLessThan($iParentPos);
});

it('flushPluginSingletons body invokes SettingsAccessor::flush (Phase 3 plan 03-01 / D-03)', function (): void {
    // Phase 3 plan 03-01 contract per CONTEXT.md D-03: the FIRST populated
    // singleton flush is SettingsAccessor::flush(). Subsequent Phase 3 plans
    // MAY append more flush() calls (e.g., ImportAuditService) but MUST NOT
    // remove this one — losing it would re-open T-03-01-01 (cross-test bleed
    // of cached settings booleans).
    $obMethod = (new ReflectionClass(GoodsReceivedTestCase::class))->getMethod('flushPluginSingletons');
    $iStart = $obMethod->getStartLine();
    $iEnd = $obMethod->getEndLine();
    $sBaseFile = $obMethod->getFileName();
    $arLines = file($sBaseFile);
    $sBody = implode('', array_slice($arLines, $iStart - 1, $iEnd - $iStart + 1));

    expect($sBody)->toContain('SettingsAccessor::flush()');
    expect(substr_count($sBody, 'SettingsAccessor::flush()'))->toBe(1);
});
