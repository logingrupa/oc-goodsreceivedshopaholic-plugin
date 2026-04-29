<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Parser\PriceNormalizer;

uses(\Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase::class);

/**
 * Plan 02-03 Task 2: PriceNormalizer.
 *
 * Audit-only normalizer (D-22) — never throws. Returns null on
 * null/empty/whitespace/non-numeric/multi-decimal input. The negative path
 * emits `Log::warning` with the raw value so an operator gets a forensic
 * trail without aborting the import.
 *
 * Asserts (per CONTEXT.md D-22, plan behavior list):
 *   - parsePrice(null)   → null
 *   - parsePrice('')     → null
 *   - parsePrice('   ')  → null
 *   - parsePrice('3,86') → 3.86 (decimal-comma)
 *   - parsePrice('3.86') → 3.86 (decimal-period also accepted)
 *   - parsePrice('0,00') → 0.0 (zero is a valid audit price)
 *   - parsePrice('-1,50') → -1.5 (negative valid for discount columns)
 *   - parsePrice('abc')   → null + Log::warning fired
 *   - parsePrice('1.234.567,89') → null (multiple decimal markers — malformed)
 *
 * Threat-model coverage:
 *   - T-02-03-05: log injection — Laravel Log::warning serializes the array
 *     payload via the framework, escaping control characters; raw input is
 *     never spliced into the message string.
 */

it('returns null for null input', function (): void {
    expect(PriceNormalizer::parsePrice(null))->toBeNull();
});

it('returns null for empty string', function (): void {
    expect(PriceNormalizer::parsePrice(''))->toBeNull();
});

it('returns null for whitespace-only string', function (): void {
    expect(PriceNormalizer::parsePrice('   '))->toBeNull();
});

it('parses decimal-comma price to float', function (): void {
    expect(PriceNormalizer::parsePrice('3,86'))->toBe(3.86);
});

it('parses decimal-period price to float (distributor format flexibility)', function (): void {
    expect(PriceNormalizer::parsePrice('3.86'))->toBe(3.86);
});

it('treats zero as a valid audit price', function (): void {
    expect(PriceNormalizer::parsePrice('0,00'))->toBe(0.0);
});

it('parses negative price (discount columns can be negative)', function (): void {
    expect(PriceNormalizer::parsePrice('-1,50'))->toBe(-1.5);
});

it('returns null for non-numeric input and emits a warning log entry', function (): void {
    \Log::spy();

    $obResult = PriceNormalizer::parsePrice('abc');

    expect($obResult)->toBeNull();
    \Log::shouldHaveReceived('warning')->once();
});

it('returns null for input with multiple decimal markers', function (): void {
    expect(PriceNormalizer::parsePrice('1.234.567,89'))->toBeNull();
});

it('trims surrounding whitespace before parsing', function (): void {
    expect(PriceNormalizer::parsePrice('  19,30  '))->toBe(19.3);
});
