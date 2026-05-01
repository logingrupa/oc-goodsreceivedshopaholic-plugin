<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\MatchedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Match\OfferCodeMatcher;
use Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

/**
 * Plan 06-03 Task 1 — OfferCodeMatcher (Pass 1 chain stage) integration tests.
 *
 * Pins MATCH-06 invariants:
 *   - Single hit emits MatchedLine with match_strategy === 'offer_code'.
 *   - Miss returns empty list (residue forwarded to next stage by chain runner).
 *   - Mixed input emits MatchedLine ONLY for hits, omits misses.
 *   - Query budget proof: EXACTLY 1 query for non-empty input
 *     (Offer::whereIn('code', $arUnique)->get(['id', 'code'])).
 *   - Empty input short-circuits with zero queries.
 *
 * Hermetic schema rationale (mirrors EanMatcherServiceTest):
 *   `protected $autoMigrate = true;` would attempt to migrate the full
 *   Lovata.Shopaholic plugin into SQLite-in-memory and trip on the upstream
 *   `update_table_offers_remove_price_field` migration (no-such-column under
 *   SQLite, since it cannot drop a column with an attached index without an
 *   explicit dropIndex). We only need `id`, `code`, `product_id` from offers
 *   and `id`, `code` from products for this matcher's contract.
 */
abstract class OfferCodeMatcherTestCase extends GoodsReceivedTestCase
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
            // Lovata\Shopaholic\Models\Offer uses October's Sortable trait,
            // which orders queries by `sort_order` and writes back on save.
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

uses(OfferCodeMatcherTestCase::class);

function ocm_seedProduct(string $sCode, string $sSlug): Product
{
    $obProduct = new Product();
    $obProduct->name = 'Seeded Product '.$sSlug;
    $obProduct->slug = $sSlug;
    $obProduct->code = $sCode;
    $obProduct->active = true;
    $obProduct->saveQuietly();

    return $obProduct;
}

function ocm_seedOffer(int $iProductId, string $sCode, string $sName = 'Seeded Offer'): Offer
{
    $obOffer = new Offer();
    $obOffer->product_id = $iProductId;
    $obOffer->name = $sName;
    $obOffer->code = $sCode;
    $obOffer->active = true;
    $obOffer->saveQuietly();

    return $obOffer;
}

function ocm_makeLine(string $sEan, int $iRowIndex = 1, string $sName = 'Item'): ParsedLine
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

it('emits MatchedLine with match_strategy=offer_code for direct hit (single)', function (): void {
    $obProduct = ocm_seedProduct('PROD-A-CODE', 'prod-a');
    $obOffer = ocm_seedOffer($obProduct->id, '4752307000097');

    $obLine = ocm_makeLine('4752307000097');

    $arResult = (new OfferCodeMatcher())->match([$obLine]);

    expect($arResult)->toHaveCount(1);
    expect($arResult[0])->toBeInstanceOf(MatchedLine::class);
    expect($arResult[0]->line)->toBe($obLine);
    expect($arResult[0]->matched_offer_id)->toBe((int) $obOffer->id);
    expect($arResult[0]->match_strategy)->toBe('offer_code');
});

it('returns empty list for unmatched EAN (miss — residue forwarded to next stage)', function (): void {
    // No offer seeded with this EAN.
    $obLine = ocm_makeLine('9999999999999');

    $arResult = (new OfferCodeMatcher())->match([$obLine]);

    expect($arResult)->toBe([]);
});

it('mixed input — emits MatchedLine ONLY for hit, omits miss', function (): void {
    $obProduct = ocm_seedProduct('PROD-MIX-CODE', 'prod-mix');
    $obOffer = ocm_seedOffer($obProduct->id, '4752307000097');

    $obHit = ocm_makeLine('4752307000097', 1, 'Item Hit');
    $obMiss = ocm_makeLine('9999999999999', 2, 'Item Miss');

    $arResult = (new OfferCodeMatcher())->match([$obHit, $obMiss]);

    expect($arResult)->toHaveCount(1);
    expect($arResult[0]->line)->toBe($obHit);
    expect($arResult[0]->matched_offer_id)->toBe((int) $obOffer->id);
    expect($arResult[0]->match_strategy)->toBe('offer_code');
});

it('issues EXACTLY 1 query for non-empty input (D-25 query budget)', function (): void {
    $obProduct = ocm_seedProduct('PROD-Q-CODE', 'prod-q');
    ocm_seedOffer($obProduct->id, '4752307000097');
    ocm_seedOffer($obProduct->id, '4752307000165');

    $arLines = [
        ocm_makeLine('4752307000097', 1),
        ocm_makeLine('4752307000165', 2),
        ocm_makeLine('4752307000300', 3), // miss
    ];

    \DB::flushQueryLog();
    \DB::enableQueryLog();

    (new OfferCodeMatcher())->match($arLines);

    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    expect($iQueryCount)->toBe(1);
});

it('returns empty list for empty input with ZERO queries (short-circuit)', function (): void {
    \DB::flushQueryLog();
    \DB::enableQueryLog();

    $arResult = (new OfferCodeMatcher())->match([]);

    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    expect($arResult)->toBe([]);
    expect($iQueryCount)->toBe(0);
});
