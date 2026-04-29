<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedInvoice;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedLine;

uses(\Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase::class);

/**
 * Plan 02-01 Task 2: ParsedInvoice DTO
 *
 * Asserts:
 *   - construction with required + optional fields
 *   - skipped_rows defaults to [] (per revised D-04)
 *   - readonly enforcement (PHP 8.4 native; \Error on mutation)
 *   - nested list<ParsedLine> structure preserved
 */

it('constructs with required + optional fields', function (): void {
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

    $obInvoice = new ParsedInvoice(
        invoice_number: 'PRO033328',
        country_code: 'no',
        invoice_date: new \DateTimeImmutable('2026-04-13'),
        source_filename: 'Nr_PRO033328_no_13042026.HTM',
        lines: [$obLine],
        skipped_rows: [
            ['row_index' => 7, 'reason' => 'invalid_ean', 'raw' => '<TR>...</TR>'],
        ],
    );

    expect($obInvoice->invoice_number)->toBe('PRO033328');
    expect($obInvoice->country_code)->toBe('no');
    expect($obInvoice->invoice_date)->toBeInstanceOf(\DateTimeImmutable::class);
    expect($obInvoice->invoice_date->format('Y-m-d'))->toBe('2026-04-13');
    expect($obInvoice->source_filename)->toBe('Nr_PRO033328_no_13042026.HTM');
    expect(count($obInvoice->lines))->toBe(1);
    expect($obInvoice->lines[0]->ean)->toBe('4752307000097');
    expect(count($obInvoice->skipped_rows))->toBe(1);
    expect($obInvoice->skipped_rows[0]['reason'])->toBe('invalid_ean');
});

it('defaults skipped_rows to empty array', function (): void {
    $obInvoice = new ParsedInvoice(
        invoice_number: 'PRO033328',
        country_code: 'no',
        invoice_date: new \DateTimeImmutable('2026-04-13'),
        source_filename: 'Nr_PRO033328_no_13042026.HTM',
        lines: [],
    );

    expect($obInvoice->skipped_rows)->toBe([]);
});

it('accepts null country_code and null invoice_date for filename-only resolution', function (): void {
    $obInvoice = new ParsedInvoice(
        invoice_number: 'BODY12345',
        country_code: null,
        invoice_date: null,
        source_filename: 'unstructured.htm',
        lines: [],
    );

    expect($obInvoice->country_code)->toBeNull();
    expect($obInvoice->invoice_date)->toBeNull();
});

it('rejects writes to readonly properties', function (): void {
    $obInvoice = new ParsedInvoice(
        invoice_number: 'PRO033328',
        country_code: 'no',
        invoice_date: new \DateTimeImmutable('2026-04-13'),
        source_filename: 'Nr_PRO033328_no_13042026.HTM',
        lines: [],
    );

    expect(fn () => $obInvoice->invoice_number = 'X')
        ->toThrow(\Error::class, 'Cannot modify readonly property');
});
