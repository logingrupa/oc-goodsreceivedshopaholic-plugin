<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\MatchedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Match\ProductCodeSingleOfferMatcher;
use Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

/**
 * Plan 06-03 Task 2 — ProductCodeSingleOfferMatcher (Pass 2 chain stage)
 * integration tests.
 *
 * Pins MATCH-07 invariants:
 *   - Single-offer match emits MatchedLine with strategy
 *     'product_code_single_offer' and matched_offer_id === sole offer's id.
 *   - Multi-offer (2+) skip — `has('offer', '=', 1)` guard rejects, line
 *     falls through to the next chain stage / 'none' (Tiger-Style: never
 *     silently best-guess at multi-offer ambiguity).
 *   - No product match returns empty list.
 *   - Query budget: EXACTLY 1 query — `addSelect(...subquery...)` is a
 *     correlated SELECT subquery (same SQL statement, NOT a second
 *     round-trip; D-25-update budget for Pass 2).
 *   - Empty input short-circuits with zero queries.
 *
 * Hermetic schema rationale (mirrors EanMatcherTestCase / OfferCodeMatcherTestCase):
 *   `protected $autoMigrate = true;` would break on the upstream
 *   `update_table_offers_remove_price_field` migration under SQLite. We
 *   only need `id`, `code`, `product_id` from offers and `id`, `code`
 *   from products for this matcher's contract.
 */
abstract class ProductCodeSingleOfferMatcherTestCase extends GoodsReceivedTestCase
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
            $obTable->boolean('active')->default(true);
            $obTable->integer('quantity')->default(0);
            $obTable->integer('sort_order')->default(0);
            $obTable->timestamps();
            $obTable->softDeletes();
            $obTable->index('code');
            $obTable->index('product_id');
        });
    }

    protected function tearDown(): void
    {
        \Schema::dropIfExists('lovata_shopaholic_offers');
        \Schema::dropIfExists('lovata_shopaholic_products');
        parent::tearDown();
    }
}

uses(ProductCodeSingleOfferMatcherTestCase::class);

function pcsom_seedProduct(string $sCode, string $sSlug): Product
{
    $obProduct = new Product();
    $obProduct->name = 'Seeded Product '.$sSlug;
    $obProduct->slug = $sSlug;
    $obProduct->code = $sCode;
    $obProduct->active = true;
    $obProduct->saveQuietly();

    return $obProduct;
}

function pcsom_seedOffer(int $iProductId, string $sCode, string $sName = 'Seeded Offer'): Offer
{
    $obOffer = new Offer();
    $obOffer->product_id = $iProductId;
    $obOffer->name = $sName;
    $obOffer->code = $sCode;
    $obOffer->active = true;
    $obOffer->saveQuietly();

    return $obOffer;
}

function pcsom_makeLine(string $sEan, int $iRowIndex = 1, string $sName = 'Item'): ParsedLine
{
    return new ParsedLine(
        row_index: $iRowIndex,
        ean: $sEan,
        product_name_raw: $sName,
        unit: 'PCE',
        qty: 1,
        unit_price: null,
        discount: null,
        line_price: null,
        total: null,
    );
}

it('emits MatchedLine with strategy=product_code_single_offer for single-offer product hit', function (): void {
    $obProduct = pcsom_seedProduct('4752307000200', 'prod-single');
    $obOffer = pcsom_seedOffer($obProduct->id, 'INNER-OFFER-NOT-EAN');

    $obLine = pcsom_makeLine('4752307000200');

    $arResult = (new ProductCodeSingleOfferMatcher())->match([$obLine]);

    expect($arResult)->toHaveCount(1);
    expect($arResult[0])->toBeInstanceOf(MatchedLine::class);
    expect($arResult[0]->line)->toBe($obLine);
    expect($arResult[0]->matched_offer_id)->toBe((int) $obOffer->id);
    expect($arResult[0]->match_strategy)->toBe('product_code_single_offer');
});

it('skips product with multiple offers — single-offer guard (line falls through)', function (): void {
    $obProduct = pcsom_seedProduct('4752307000300', 'prod-multi');
    pcsom_seedOffer($obProduct->id, 'INNER-OFFER-A');
    pcsom_seedOffer($obProduct->id, 'INNER-OFFER-B');

    $obLine = pcsom_makeLine('4752307000300');

    $arResult = (new ProductCodeSingleOfferMatcher())->match([$obLine]);

    expect($arResult)->toBe([]);
});

it('returns empty list when no product matches the EAN', function (): void {
    // No product seeded with this code.
    $obLine = pcsom_makeLine('9999999999999');

    $arResult = (new ProductCodeSingleOfferMatcher())->match([$obLine]);

    expect($arResult)->toBe([]);
});

it('issues EXACTLY 1 query for non-empty input — correlated addSelect is in same SELECT (D-25)', function (): void {
    $obProduct = pcsom_seedProduct('4752307000200', 'prod-single-q');
    pcsom_seedOffer($obProduct->id, 'INNER-Q-OFFER');

    $arLines = [
        pcsom_makeLine('4752307000200', 1),
        pcsom_makeLine('4752307000300', 2), // miss
    ];

    \DB::flushQueryLog();
    \DB::enableQueryLog();

    (new ProductCodeSingleOfferMatcher())->match($arLines);

    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    expect($iQueryCount)->toBe(1);
});

it('returns empty list for empty input with ZERO queries (short-circuit)', function (): void {
    \DB::flushQueryLog();
    \DB::enableQueryLog();

    $arResult = (new ProductCodeSingleOfferMatcher())->match([]);

    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    expect($arResult)->toBe([]);
    expect($iQueryCount)->toBe(0);
});
