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

it('flushPluginSingletons body is empty in Phase 1 (Phases 2/3 will populate)', function (): void {
    // Phase 1 contract per CONTEXT.md D-22: hook is wired but empty. This test pins
    // that contract — when Phase 2/3 add real flush() calls the test will fail
    // by design, prompting an update of THIS test (acceptable) at that time.
    $obMethod = (new ReflectionClass(GoodsReceivedTestCase::class))->getMethod('flushPluginSingletons');
    $iStart = $obMethod->getStartLine();
    $iEnd = $obMethod->getEndLine();
    $sBaseFile = $obMethod->getFileName();
    $arLines = file($sBaseFile);
    $sBody = implode('', array_slice($arLines, $iStart - 1, $iEnd - $iStart + 1));

    // Body should contain only the comment placeholder, NO statements that aren't comments.
    // We assert: no `::flush()` or `->flush()` calls yet (Phase 1 = empty body).
    expect($sBody)->not->toContain('::flush()');
    expect($sBody)->not->toContain('->flush()');
});
