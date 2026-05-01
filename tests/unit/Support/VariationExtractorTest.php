<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Support\VariationExtractor;

/**
 * Plan 06-01 — VariationExtractor regex behavior pin (MATCH-03 / QA-13).
 *
 * Pure / hermetic / no-DB test. The extractor is a stateless `final readonly`
 * helper (no model events, no settings, no Lang) so this file deliberately
 * does NOT extend GoodsReceivedTestCase — running through the October test
 * harness would only add boot overhead without adding signal.
 *
 * The regex literal `/^(.+),\s+([^,]+)$/u` is the SOLE source of truth for
 * variation extraction across the plugin (DRY contract — see acceptance
 * criteria in 06-01-PLAN.md). Plan 06-06 will DRY-replace the inline copy
 * currently in `models/invoiceline/_column_product_name.htm` with a call to
 * `VariationExtractor::extract`.
 *
 * Five behavior cases pinned (matches `must_haves.truths` in 06-01-PLAN.md):
 *   1. Last-comma greedy split  — "Gel Polish UV/LED, 12ml, 1081" → "1081"
 *   2. No comma                 — "SingleWordName" → null
 *   3. Empty input              — "" → null
 *   4. Whitespace trim          — "A,   1081" → "1081" (group 2 trimmed)
 *   5. Multi-comma greediness   — "A, B, 1081" → "1081" (NOT "B, 1081")
 */
it('extracts the trailing variation token after the LAST ", " (last-comma greedy)', function (): void {
    expect(VariationExtractor::extract('Gel Polish UV/LED, 12ml, 1081'))->toBe('1081');
});

it('returns null when the input contains no comma', function (): void {
    expect(VariationExtractor::extract('SingleWordName'))->toBeNull();
});

it('returns null on empty input', function (): void {
    expect(VariationExtractor::extract(''))->toBeNull();
});

it('trims whitespace from the extracted variation token', function (): void {
    expect(VariationExtractor::extract('A,   1081'))->toBe('1081');
});

it('splits at the LAST comma, not the first, on multi-comma input', function (): void {
    expect(VariationExtractor::extract('A, B, 1081'))->toBe('1081');
});
