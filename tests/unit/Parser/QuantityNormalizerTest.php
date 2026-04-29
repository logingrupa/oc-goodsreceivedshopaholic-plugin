<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvalidQuantityException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Parser\QuantityNormalizer;

uses(\Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase::class);

/**
 * Plan 02-03 Task 1: QuantityNormalizer.
 *
 * Asserts (per CONTEXT.md D-21, plan behavior list, QA-02):
 *   - parseQuantity('5') returns int 5
 *   - parseQuantity('  42 ') returns int 42 (trim leading/trailing whitespace)
 *   - parseQuantity('5,12') throws InvalidQuantityException — decimal-comma
 *     rejected BEFORE Eloquent silent int-clamp (the QA-02 RejectsDecimalQuantityTest
 *     core invariant)
 *   - parseQuantity('5.12') throws — decimal-period equally rejected
 *   - parseQuantity('0') throws — qty must be > 0 (Tiger-Style positive-space)
 *   - parseQuantity('-3') throws
 *   - parseQuantity('') throws — empty input is malformed
 *   - parseQuantity('abc') throws
 *   - parseQuantity('5e2') throws — scientific notation rejected
 *   - Exception carries `arContext` with `raw` value, plus any caller-supplied keys
 *
 * Threat-model coverage:
 *   - T-02-03-01: silent stock corruption from decimal-comma input
 *   - T-02-03-02: zero/negative qty invariant violation
 */

it('parses a simple positive integer string to int', function (): void {
    expect(QuantityNormalizer::parseQuantity('5'))->toBe(5);
});

it('trims surrounding whitespace before validation', function (): void {
    expect(QuantityNormalizer::parseQuantity('  42 '))->toBe(42);
});

it('rejects decimal-comma quantity (QA-02 RejectsDecimalQuantityTest)', function (): void {
    expect(fn () => QuantityNormalizer::parseQuantity('5,12'))
        ->toThrow(InvalidQuantityException::class);
});

it('rejects decimal-period quantity', function (): void {
    expect(fn () => QuantityNormalizer::parseQuantity('5.12'))
        ->toThrow(InvalidQuantityException::class);
});

it('rejects zero (Tiger-Style qty > 0 invariant)', function (): void {
    expect(fn () => QuantityNormalizer::parseQuantity('0'))
        ->toThrow(InvalidQuantityException::class);
});

it('rejects negative quantity', function (): void {
    expect(fn () => QuantityNormalizer::parseQuantity('-3'))
        ->toThrow(InvalidQuantityException::class);
});

it('rejects empty string', function (): void {
    expect(fn () => QuantityNormalizer::parseQuantity(''))
        ->toThrow(InvalidQuantityException::class);
});

it('rejects whitespace-only string', function (): void {
    expect(fn () => QuantityNormalizer::parseQuantity('   '))
        ->toThrow(InvalidQuantityException::class);
});

it('rejects non-numeric string', function (): void {
    expect(fn () => QuantityNormalizer::parseQuantity('abc'))
        ->toThrow(InvalidQuantityException::class);
});

it('rejects scientific notation', function (): void {
    expect(fn () => QuantityNormalizer::parseQuantity('5e2'))
        ->toThrow(InvalidQuantityException::class);
});

it('attaches raw value to exception context on rejection', function (): void {
    try {
        QuantityNormalizer::parseQuantity('5,12');
        $this->fail('expected InvalidQuantityException not thrown');
    } catch (InvalidQuantityException $obException) {
        expect($obException->arContext)->toMatchArray(['raw' => '5,12']);
    }
});

it('merges caller-supplied context with raw value', function (): void {
    try {
        QuantityNormalizer::parseQuantity('5,12', ['row_index' => 7]);
        $this->fail('expected InvalidQuantityException not thrown');
    } catch (InvalidQuantityException $obException) {
        expect($obException->arContext)->toMatchArray([
            'raw'       => '5,12',
            'row_index' => 7,
        ]);
    }
});

it('attaches parsed value to exception context for zero/negative path', function (): void {
    try {
        QuantityNormalizer::parseQuantity('0');
        $this->fail('expected InvalidQuantityException not thrown');
    } catch (InvalidQuantityException $obException) {
        expect($obException->arContext)->toMatchArray([
            'raw'    => '0',
            'parsed' => 0,
        ]);
    }
});
