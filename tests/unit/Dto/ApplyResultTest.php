<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ApplyResult;

uses(\Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase::class);

/**
 * Plan 02-01 Task 2: ApplyResult DTO
 *
 * Asserts:
 *   - stores 4 integer counters (units_added, offers_touched, lines_applied, lines_skipped)
 *   - readonly enforcement (PHP 8.4 native; \Error on mutation)
 *   - zero-value summary is constructable (Phase 3 idempotent re-run path)
 */

it('stores 4 integer counters', function (): void {
    $obResult = new ApplyResult(
        units_added: 150,
        offers_touched: 12,
        lines_applied: 18,
        lines_skipped: 3,
    );

    expect($obResult->units_added)->toBe(150);
    expect($obResult->offers_touched)->toBe(12);
    expect($obResult->lines_applied)->toBe(18);
    expect($obResult->lines_skipped)->toBe(3);
});

it('accepts zero-value counters for idempotent re-run path', function (): void {
    $obResult = new ApplyResult(
        units_added: 0,
        offers_touched: 0,
        lines_applied: 0,
        lines_skipped: 0,
    );

    expect($obResult->units_added)->toBe(0);
    expect($obResult->offers_touched)->toBe(0);
    expect($obResult->lines_applied)->toBe(0);
    expect($obResult->lines_skipped)->toBe(0);
});

it('rejects writes to readonly properties', function (): void {
    $obResult = new ApplyResult(
        units_added: 150,
        offers_touched: 12,
        lines_applied: 18,
        lines_skipped: 3,
    );

    expect(fn () => $obResult->units_added = 999)
        ->toThrow(\Error::class, 'Cannot modify readonly property');
});
