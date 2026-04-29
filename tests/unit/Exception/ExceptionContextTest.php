<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\GoodsReceivedException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvalidQuantityException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvoiceNumberMissingException;

uses(\Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase::class);

/**
 * Plan 02-02 Task 2: Exception context array assertions.
 *
 * Asserts:
 *   - Constructor accepts structured $arContext, exposed via readonly property
 *   - Context defaults to []
 *   - Mutation throws \Error (PHP 8.4 readonly enforcement; T-02-02-01)
 *   - jsonContext() escapes newlines/CR for log-injection safety (T-02-02-02)
 *   - jsonContext() returns '{}' on unencodable input (T-02-02-03)
 *   - Previous (chained) exception is preserved
 */

it('stores structured context array', function (): void {
    $arContext = ['raw' => '5,12', 'row' => 7];
    $obException = new InvalidQuantityException('bad qty', $arContext);

    expect($obException->arContext)->toBe(['raw' => '5,12', 'row' => 7]);
    expect($obException->getMessage())->toBe('bad qty');
});

it('defaults context to empty array', function (): void {
    $obException = new InvoiceNumberMissingException('missing');

    expect($obException->arContext)->toBe([]);
});

it('preserves previous (chained) exception', function (): void {
    $obPrevious = new \RuntimeException('underlying');
    $obException = new InvalidQuantityException('bad qty', ['raw' => 'x'], $obPrevious);

    expect($obException->getPrevious())->toBe($obPrevious);
});

it('rejects writes to readonly $arContext', function (): void {
    $obException = new InvalidQuantityException('bad qty', ['raw' => '5,12']);

    expect(fn () => $obException->arContext = ['hacked' => true])
        ->toThrow(\Error::class, 'Cannot modify readonly property');
});

it('jsonContext escapes newlines and carriage returns for log-injection safety', function (): void {
    $arContext = ['evil' => "line1\nline2\rDELETE FROM users;"];

    $sJson = (function () use ($arContext): string {
        return GoodsReceivedException::jsonContext($arContext);
    })->bindTo(null, GoodsReceivedException::class)();

    // Result is non-empty JSON
    expect($sJson)->toBeString();
    expect($sJson)->not->toBe('{}');

    // Literal escape sequences appear in the output
    expect(str_contains($sJson, '\\n'))->toBeTrue();
    expect(str_contains($sJson, '\\r'))->toBeTrue();

    // No raw newline / CR bytes (the actual log-injection guard)
    expect(strpos($sJson, "\n"))->toBe(false);
    expect(strpos($sJson, "\r"))->toBe(false);
});

it('jsonContext returns the literal string {} for unencodable input', function (): void {
    $arContext = ['stream' => fopen('php://memory', 'r')];

    $sJson = (function () use ($arContext): string {
        return GoodsReceivedException::jsonContext($arContext);
    })->bindTo(null, GoodsReceivedException::class)();

    expect($sJson)->toBe('{}');

    // Tidy up the resource we opened for the test.
    if (is_resource($arContext['stream'])) {
        fclose($arContext['stream']);
    }
});
