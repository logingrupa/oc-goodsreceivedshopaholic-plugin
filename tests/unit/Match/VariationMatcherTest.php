<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\MatchedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Match\VariationMatcher;
use Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

/**
 * Plan 06-04 Task 1 — VariationMatcher (Pass 3 chain stage) integration tests.
 *
 * Pins MATCH-04 / QA-12 invariants:
 *   - Single-offer hit (variation maps to exactly 1 offer) emits MatchedLine
 *     with match_strategy === 'variation'.
 *   - Ambiguous variation (≥2 offers same variation) → omitted (residue;
 *     Tiger-Style determinism per D-25-update single-offer guard).
 *   - Empty / no-comma offer name (VariationExtractor::extract returns null)
 *     → omitted from match() output AND zero queries when no input yields
 *     a variation (short-circuit before the SELECT).
 *   - Leading-zero EAN preserved on the carried ParsedLine (D-27 string
 *     preservation; matcher operates on variation column, EAN is passthrough).
 *   - Query budget: EXACTLY 1 query for non-empty deduped variation list
 *     (D-25-update budget #3 — `Offer::whereIn('variation', $arUnique)
 *     ->select(['id', 'variation', 'product_id'])->get()`).
 *
 * Hermetic schema rationale (mirrors OfferCodeMatcherTestCase):
 *   `protected $autoMigrate = true;` would attempt to migrate the full
 *   Lovata.Shopaholic plugin under SQLite-in-memory and trip on the
 *   upstream `update_table_offers_remove_price_field` migration. The
 *   `variation` column is added to `lovata_shopaholic_offers` by sibling
 *   plugin `logingrupa/storeextender` — we add it explicitly here to keep
 *   the test hermetic and reflect production schema.
 */
abstract class VariationMatcherTestCase extends GoodsReceivedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Schema::create('lovata_shopaholic_products', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('code')->nullable();
            $obTable->string('name');
            $obTable->string('slug')->unique();
            $obTable->boolean('active')->default(true);
            $obTable->timestamps();
            $obTable->softDeletes();
            $obTable->index('code');
        });

        \Schema::create('lovata_shopaholic_offers', function ($obTable): void {
            $obTable->increments('id');
            $obTable->integer('product_id')->unsigned()->nullable();
            $obTable->string('code')->nullable();
            $obTable->string('name');
            // `variation` column is added in production by sibling plugin
            // logingrupa/storeextender:
            //   updates/update_table_lovata_shopaholic_offers.php:21
            //   $obTable->string('variation')->nullable()->after('description');
            $obTable->string('variation')->nullable();
            $obTable->boolean('active')->default(true);
            $obTable->integer('quantity')->default(0);
            $obTable->integer('sort_order')->default(0);
            $obTable->timestamps();
            $obTable->softDeletes();
            $obTable->index('code');
            $obTable->index('product_id');
            $obTable->index('variation');
        });
    }

    protected function tearDown(): void
    {
        \Schema::dropIfExists('lovata_shopaholic_offers');
        \Schema::dropIfExists('lovata_shopaholic_products');
        parent::tearDown();
    }
}

uses(VariationMatcherTestCase::class);

function vm_seedProduct(string $sSlug, ?string $sCode = null): Product
{
    $obProduct = new Product();
    $obProduct->name = 'Seeded Product '.$sSlug;
    $obProduct->slug = $sSlug;
    $obProduct->code = $sCode;
    $obProduct->active = true;
    $obProduct->saveQuietly();

    return $obProduct;
}

function vm_seedOfferWithVariation(int $iProductId, string $sVariation, ?string $sCode = null, string $sName = 'Seeded Offer'): Offer
{
    $obOffer = new Offer();
    $obOffer->product_id = $iProductId;
    $obOffer->name = $sName;
    $obOffer->code = $sCode;
    $obOffer->variation = $sVariation;
    $obOffer->active = true;
    $obOffer->saveQuietly();

    return $obOffer;
}

function vm_makeLine(string $sEan, string $sProductNameRaw, int $iRowIndex = 1): ParsedLine
{
    return new ParsedLine(
        row_index: $iRowIndex,
        ean: $sEan,
        product_name_raw: $sProductNameRaw,
        unit: 'PCE',
        qty: 1,
        unit_price: null,
        discount: null,
        line_price: null,
        total: null,
    );
}

it('emits MatchedLine with match_strategy=variation for single-offer variation hit (query count = 1)', function (): void {
    $obProduct = vm_seedProduct('prod-a');
    $obOffer = vm_seedOfferWithVariation($obProduct->id, '1081', 'IRRELEVANT');

    $obLine = vm_makeLine('9999999999999', 'Gel Polish UV/LED, 12ml, 1081');

    \DB::flushQueryLog();
    \DB::enableQueryLog();

    $arResult = (new VariationMatcher())->match([$obLine]);

    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    expect($arResult)->toHaveCount(1);
    expect($arResult[0])->toBeInstanceOf(MatchedLine::class);
    expect($arResult[0]->line)->toBe($obLine);
    expect($arResult[0]->matched_offer_id)->toBe((int) $obOffer->id);
    expect($arResult[0]->match_strategy)->toBe('variation');
    expect($iQueryCount)->toBe(1);
});

it('skips ambiguous variation (≥2 offers same variation — single-offer guard)', function (): void {
    $obProductA = vm_seedProduct('prod-a-amb');
    $obProductB = vm_seedProduct('prod-b-amb');
    vm_seedOfferWithVariation($obProductA->id, '1081', 'CODE-A');
    vm_seedOfferWithVariation($obProductB->id, '1081', 'CODE-B');

    $obLine = vm_makeLine('9999999999999', 'Gel Polish UV/LED, 12ml, 1081');

    $arResult = (new VariationMatcher())->match([$obLine]);

    expect($arResult)->toBe([]);
});

it('omits no-comma / empty offer names with ZERO queries (short-circuit when no variation extracted)', function (): void {
    $obProduct = vm_seedProduct('prod-a-empty');
    vm_seedOfferWithVariation($obProduct->id, '1081', 'CODE-EMPTY');

    $arLines = [
        vm_makeLine('1111111111111', 'SingleWordName', 1),
        vm_makeLine('2222222222222', '', 2),
    ];

    \DB::flushQueryLog();
    \DB::enableQueryLog();

    $arResult = (new VariationMatcher())->match($arLines);

    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    expect($arResult)->toBe([]);
    expect($iQueryCount)->toBe(0);
});

it('preserves leading-zero EAN as STRING on the carried ParsedLine (D-27 passthrough)', function (): void {
    $obProduct = vm_seedProduct('prod-a-lz');
    $obOffer = vm_seedOfferWithVariation($obProduct->id, '1081', 'CODE-LZ');

    $obLine = vm_makeLine('0000000012345', 'X, 1081');

    $arResult = (new VariationMatcher())->match([$obLine]);

    expect($arResult)->toHaveCount(1);
    expect($arResult[0]->matched_offer_id)->toBe((int) $obOffer->id);
    expect($arResult[0]->match_strategy)->toBe('variation');
    expect($arResult[0]->line->ean)->toBe('0000000012345');
});

it('mixed input — single hit + ambiguous skip + miss; emits ONLY hit; query count = 1', function (): void {
    $obProductA = vm_seedProduct('prod-a-mix');
    $obProductB = vm_seedProduct('prod-b-mix');
    $obProductC = vm_seedProduct('prod-c-mix');

    // Variation '1081' → exactly 1 offer (will hit).
    $obHitOffer = vm_seedOfferWithVariation($obProductA->id, '1081', 'CODE-HIT');
    // Variation '2002' → 2 offers (will be skipped by single-offer guard).
    vm_seedOfferWithVariation($obProductB->id, '2002', 'CODE-AMB-A');
    vm_seedOfferWithVariation($obProductC->id, '2002', 'CODE-AMB-B');
    // Variation '9999' → no offer (residue → 'none' in chain runner).

    $arLines = [
        vm_makeLine('1111111111111', 'X, 1081', 1), // matched
        vm_makeLine('2222222222222', 'Y, 2002', 2), // ambiguous — skipped
        vm_makeLine('3333333333333', 'Z, 9999', 3), // no offer — skipped
    ];

    \DB::flushQueryLog();
    \DB::enableQueryLog();

    $arResult = (new VariationMatcher())->match($arLines);

    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    expect($arResult)->toHaveCount(1);
    expect($arResult[0]->line)->toBe($arLines[0]);
    expect($arResult[0]->matched_offer_id)->toBe((int) $obHitOffer->id);
    expect($arResult[0]->match_strategy)->toBe('variation');
    expect($iQueryCount)->toBe(1);
});
