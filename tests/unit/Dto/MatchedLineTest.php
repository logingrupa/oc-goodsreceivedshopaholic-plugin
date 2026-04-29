<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\MatchedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedLine;

uses(\Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase::class);

/**
 * Plan 02-01 Task 2: MatchedLine DTO
 *
 * Asserts:
 *   - wraps a ParsedLine with match strategy
 *   - accepts null matched_offer_id for the 'none' strategy (D-26)
 *   - readonly enforcement (PHP 8.4 native; \Error on mutation)
 */

function makeParsedLineFixture(): ParsedLine
{
    return new ParsedLine(
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
}

it('wraps a ParsedLine with match strategy', function (): void {
    $obLine = makeParsedLineFixture();
    $obMatched = new MatchedLine(
        line: $obLine,
        matched_offer_id: 42,
        match_strategy: 'offer_code',
    );

    expect($obMatched->line)->toBe($obLine);
    expect($obMatched->line->ean)->toBe('4752307000097');
    expect($obMatched->matched_offer_id)->toBe(42);
    expect($obMatched->match_strategy)->toBe('offer_code');
});

it('accepts null matched_offer_id for unmatched lines', function (): void {
    $obLine = makeParsedLineFixture();
    $obMatched = new MatchedLine(
        line: $obLine,
        matched_offer_id: null,
        match_strategy: 'none',
    );

    expect($obMatched->matched_offer_id)->toBeNull();
    expect($obMatched->match_strategy)->toBe('none');
});

it('accepts product_code_single_offer fallback strategy', function (): void {
    $obLine = makeParsedLineFixture();
    $obMatched = new MatchedLine(
        line: $obLine,
        matched_offer_id: 99,
        match_strategy: 'product_code_single_offer',
    );

    expect($obMatched->match_strategy)->toBe('product_code_single_offer');
    expect($obMatched->matched_offer_id)->toBe(99);
});

it('rejects writes to readonly properties', function (): void {
    $obLine = makeParsedLineFixture();
    $obMatched = new MatchedLine(
        line: $obLine,
        matched_offer_id: 42,
        match_strategy: 'offer_code',
    );

    expect(fn () => $obMatched->matched_offer_id = 7)
        ->toThrow(\Error::class, 'Cannot modify readonly property');
});
