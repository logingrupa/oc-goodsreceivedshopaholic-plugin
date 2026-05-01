<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase;

/**
 * Plan 06-06 Task 1 (RED) — Render tests for `_column_product_name.htm`'s
 * 4-branch dispatch on `$record->match_strategy` (UI-13 / QA-15).
 *
 * Pins Phase 6 / D-25-update truths:
 *   - Strategy 'offer_code' / 'product_code_single_offer' → black/normal
 *     product link + offer-link in parens (BYTE-FOR-BYTE preserved against
 *     the pre-Plan-06 partial).
 *   - Strategy 'variation' → NEW branch: ORANGE styling + ASTERISK marker +
 *     resolved product name + offer-link in parens (Pass 3 visual signal).
 *   - Strategy 'none' → plain text + asterisk after variation (BYTE-FOR-BYTE
 *     preserved).
 *   - DRY invariant: regex literal `/^(.+),\s+([^,]+)$/u` appears EXACTLY ONCE
 *     in the entire plugin — only in `classes/support/VariationExtractor.php`.
 *     The partial MUST consume `VariationExtractor::extract($sName)` instead
 *     of re-implementing the regex inline.
 *
 * Hermetic: no DB. InvoiceLine stubs are constructed with `setRelation` to
 * inject a `matched_offer` stdClass without touching the DB. The Backend
 * facade is mocked via Mockery so `Backend::url('lovata/shopaholic/...')`
 * returns a deterministic '/back/...' string.
 *
 * The partial path is resolved relative to this file. October normally
 * invokes the partial via the ListController column type, but since the
 * partial is a plain PHP-tag file with no October-specific helpers (only
 * `e()` and `Backend::url(...)`), `require` under output buffering is
 * sufficient.
 */
uses(GoodsReceivedTestCase::class);

beforeEach(function (): void {
    // Mock Backend::url to return deterministic /back/<path> URLs so test
    // assertions can pin literal substrings without depending on the live
    // backend URI configuration (which differs between dev /admin and prod
    // /back).
    \Backend::shouldReceive('url')
        ->andReturnUsing(fn (string $sPath): string => '/back/'.$sPath);
});

afterEach(function (): void {
    \Mockery::close();
});

/**
 * Render the partial against an InvoiceLine stub and return the captured
 * output. The partial is a plain PHP-tag file (`require` + ob_start).
 */
function rcpn_renderPartial(InvoiceLine $obRecord): string
{
    /** @noinspection PhpUnusedLocalVariableInspection */
    $record = $obRecord; // partial reads `$record` per October ListController convention.

    ob_start();
    require __DIR__.'/../../../models/invoiceline/_column_product_name.htm';

    return (string) ob_get_clean();
}

it('renders matched offer_code branch with black product link + offer-link parens (no orange, no asterisk)', function (): void {
    $obOffer = (object) ['id' => 42, 'product_id' => 10];

    $obLine = new InvoiceLine();
    $obLine->product_name_raw = 'Gel, 12ml, 1081';
    $obLine->match_strategy = 'offer_code';
    $obLine->setRelation('matched_offer', $obOffer);

    $sOutput = rcpn_renderPartial($obLine);

    expect($sOutput)->toContain('href="/back/lovata/shopaholic/products/update/10"');
    expect($sOutput)->toContain('>Gel, 12ml</a>');
    expect($sOutput)->toContain('href="/back/lovata/shopaholic/offers/update/42"');
    expect($sOutput)->toContain('>1081</a>)');
    // No orange styling on this branch.
    expect($sOutput)->not->toContain('orange');
    // No asterisk on this branch (asterisk reserved for 'variation' + 'none').
    expect($sOutput)->not->toContain('*');
});

it('renders matched product_code_single_offer branch IDENTICAL byte-for-byte to offer_code branch', function (): void {
    $obOffer = (object) ['id' => 42, 'product_id' => 10];

    $obOfferCodeLine = new InvoiceLine();
    $obOfferCodeLine->product_name_raw = 'Gel, 12ml, 1081';
    $obOfferCodeLine->match_strategy = 'offer_code';
    $obOfferCodeLine->setRelation('matched_offer', $obOffer);

    $obProductCodeLine = new InvoiceLine();
    $obProductCodeLine->product_name_raw = 'Gel, 12ml, 1081';
    $obProductCodeLine->match_strategy = 'product_code_single_offer';
    $obProductCodeLine->setRelation('matched_offer', $obOffer);

    expect(rcpn_renderPartial($obProductCodeLine))->toBe(rcpn_renderPartial($obOfferCodeLine));
});

it('renders NEW variation branch with ORANGE styling + ASTERISK + resolved product name + offer-link parens', function (): void {
    $obOffer = (object) ['id' => 99, 'product_id' => 11];

    $obLine = new InvoiceLine();
    $obLine->product_name_raw = 'Top Coat, 8ml, 2002';
    $obLine->match_strategy = 'variation';
    $obLine->setRelation('matched_offer', $obOffer);

    $sOutput = rcpn_renderPartial($obLine);

    // Orange visual signal — inline style attribute carrying 'orange'.
    expect($sOutput)->toContain('orange');
    // Asterisk marker (Pass 3 visual distinguishment).
    expect($sOutput)->toContain('*');
    // Cleaned product name visible (last comma trimmed).
    expect($sOutput)->toContain('Top Coat, 8ml');
    // Variation token visible.
    expect($sOutput)->toContain('2002');
    // Deep links to product + offer present.
    expect($sOutput)->toContain('href="/back/lovata/shopaholic/products/update/11"');
    expect($sOutput)->toContain('href="/back/lovata/shopaholic/offers/update/99"');
});

it('renders unmatched none branch as plain text with asterisk after variation (no anchor tags)', function (): void {
    $obLine = new InvoiceLine();
    $obLine->product_name_raw = 'Gel, 12ml, 1081';
    $obLine->match_strategy = 'none';

    $sOutput = rcpn_renderPartial($obLine);

    // Plain text — no anchor tags.
    expect($sOutput)->not->toContain('<a ');
    expect($sOutput)->not->toContain('</a>');
    // Cleaned name + variation in parens with asterisk marker.
    expect(trim($sOutput))->toBe('Gel, 12ml (1081*)');
});

it('renders none branch with no comma in name as bare cleaned name (no parens, no asterisk, no anchor)', function (): void {
    $obLine = new InvoiceLine();
    $obLine->product_name_raw = 'SingleWord';
    $obLine->match_strategy = 'none';

    $sOutput = rcpn_renderPartial($obLine);

    expect($sOutput)->not->toContain('<a ');
    expect($sOutput)->not->toContain('(');
    expect($sOutput)->not->toContain('*');
    expect(trim($sOutput))->toBe('SingleWord');
});

it('renders variation branch defensively when name has no comma (orange + asterisk + product link, no parens)', function (): void {
    $obOffer = (object) ['id' => 99, 'product_id' => 11];

    $obLine = new InvoiceLine();
    $obLine->product_name_raw = 'SingleWord';
    $obLine->match_strategy = 'variation';
    $obLine->setRelation('matched_offer', $obOffer);

    $sOutput = rcpn_renderPartial($obLine);

    // Orange signal still present.
    expect($sOutput)->toContain('orange');
    // Asterisk still present.
    expect($sOutput)->toContain('*');
    // Product link still present (graceful render — VariationExtractor returns null,
    // partial degrades to product-only display without the offer-link parens).
    expect($sOutput)->toContain('href="/back/lovata/shopaholic/products/update/11"');
});

it('enforces DRY invariant: regex literal /^(.+),\s+([^,]+)$/u appears EXACTLY ONCE plugin-wide (only in VariationExtractor)', function (): void {
    $sPluginRoot = realpath(__DIR__.'/../../..');

    expect($sPluginRoot)->not->toBeFalse();

    // Grep across the production-code surface — classes/ + models/ — for the
    // regex literal. Acceptance: exactly 1 hit (only classes/support/VariationExtractor.php).
    $sCommand = sprintf(
        'grep -RFn -- %s %s %s 2>/dev/null',
        escapeshellarg('/^(.+),\s+([^,]+)$/u'),
        escapeshellarg($sPluginRoot.'/classes'),
        escapeshellarg($sPluginRoot.'/models'),
    );

    $sOutput = (string) shell_exec($sCommand);
    $arHits = $sOutput === '' ? [] : array_filter(explode("\n", trim($sOutput)));

    expect($arHits)->toHaveCount(1);
    expect($arHits[array_key_first($arHits)])->toContain('classes/support/VariationExtractor.php');
});
