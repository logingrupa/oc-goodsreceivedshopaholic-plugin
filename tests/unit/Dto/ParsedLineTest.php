<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedLine;

uses(\Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase::class);

/**
 * Plan 02-01 Task 2: ParsedLine DTO
 *
 * Asserts:
 *   - construction with full positive args
 *   - leading-zero EAN preserved as STRING (D-27, QA-02)
 *   - readonly enforcement (PHP 8.4 native; \Error on mutation)
 *   - nullable price fields accept null
 */

it('constructs with all required + price fields populated', function (): void {
    $obLine = new ParsedLine(
        row_index: 1,
        ean: '4752307000097',
        product_name_raw: 'Gel Polish UV/LED, 12ml, 1122',
        unit: 'PCE',
        qty: 5,
        unit_price: 3.86,
        discount: 0.0,
        line_price: 3.86,
        total: 19.30,
    );

    expect($obLine->row_index)->toBe(1);
    expect($obLine->ean)->toBe('4752307000097');
    expect($obLine->product_name_raw)->toBe('Gel Polish UV/LED, 12ml, 1122');
    expect($obLine->unit)->toBe('PCE');
    expect($obLine->qty)->toBe(5);
    expect($obLine->unit_price)->toBe(3.86);
    expect($obLine->discount)->toBe(0.0);
    expect($obLine->line_price)->toBe(3.86);
    expect($obLine->total)->toBe(19.30);
});

it('preserves leading-zero EAN as string', function (): void {
    $sEan = '0000000012345';
    $obLine = new ParsedLine(
        row_index: 1,
        ean: $sEan,
        product_name_raw: 'Edge case product',
        unit: 'PCE',
        qty: 1,
        unit_price: null,
        discount: null,
        line_price: null,
        total: null,
    );

    // Strict string equality — int coercion would yield '12345'
    expect($obLine->ean)->toBe('0000000012345');
    expect($obLine->ean)->toBeString();
    expect(strlen($obLine->ean))->toBe(13);
});

it('accepts null for all four price fields', function (): void {
    $obLine = new ParsedLine(
        row_index: 1,
        ean: '4752307000097',
        product_name_raw: 'Audit-blank prices',
        unit: 'PCE',
        qty: 1,
        unit_price: null,
        discount: null,
        line_price: null,
        total: null,
    );

    expect($obLine->unit_price)->toBeNull();
    expect($obLine->discount)->toBeNull();
    expect($obLine->line_price)->toBeNull();
    expect($obLine->total)->toBeNull();
});

it('rejects writes to readonly properties', function (): void {
    $obLine = new ParsedLine(
        row_index: 1,
        ean: '4752307000097',
        product_name_raw: 'Gel Polish UV/LED, 12ml, 1122',
        unit: 'PCE',
        qty: 5,
        unit_price: 3.86,
        discount: 0.0,
        line_price: 3.86,
        total: 19.30,
    );

    expect(fn () => $obLine->qty = 999)
        ->toThrow(\Error::class, 'Cannot modify readonly property');
});
