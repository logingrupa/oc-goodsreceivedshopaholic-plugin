<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\MatchedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvalidEanException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Match\EanMatcherService;
use Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

/**
 * Plan 06-05 Task 1 (RED) / Task 2 (GREEN): EanMatcherService chain integration tests.
 *
 * Pins MATCH-08 / MATCH-10 / QA-14 invariants for the chain runner refactor:
 *   - matchLines(list<ParsedLine>): list<MatchedLine> is the single public method.
 *     `matchBatch` and `buildMatchedLines` are GONE — no shim, no back-compat.
 *   - 3-query budget (D-25-update) — DB::enableQueryLog() asserts EXACTLY 3 queries
 *     for a full-cascade input touching all three stages.
 *   - 1-query short-circuit when Pass 1 catches everything (Pass 2 + Pass 3 inputs
 *     empty → no SELECT).
 *   - All 4 strategy literals reachable end-to-end:
 *     'offer_code' | 'product_code_single_offer' | 'variation' | 'none'.
 *   - Output preserves input order (input row_index sequence equals output sequence).
 *   - Pass 3 single-offer guard escapes ambiguous variations as 'none' (residue).
 *   - QA-02 leading-zero EAN '0000000012345' survives the chain as STRING.
 *   - InvalidEanException defense-in-depth: throws BEFORE any DB query
 *     (T-02-06-01 / T-06-05-01 mitigation).
 *   - Empty input → empty output, zero queries.
 *
 * Schema bootstrap rationale (mirrors VariationMatcherTestCase):
 * `protected $autoMigrate = true;` would attempt full Lovata.Shopaholic migration
 * under SQLite-in-memory and trip on `update_table_offers_remove_price_field`.
 * Workaround: hermetic minimal schema with only the columns the chain reads —
 * offers (id, code, variation, product_id, name, sort_order, active) and
 * products (id, code, name, slug, active). The `variation` column is added
 * by sibling plugin logingrupa/storeextender in production.
 */
abstract class EanMatcherTestCase extends GoodsReceivedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Hermetic minimal schema: only the columns the matcher chain reads.
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
            // Lovata\Shopaholic\Models\Offer uses October's Sortable trait.
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

uses(EanMatcherTestCase::class);

/**
 * Seed a minimum-viable Product row. Lovata Product requires `name` + unique `slug`.
 * `code` is the column matched by Pass 2 (ProductCodeSingleOfferMatcher).
 *
 * Uses `saveQuietly()` to skip Lovata's afterSave hooks (which load multisite
 * relations from tables our hermetic schema does not provide).
 */
function seedProduct(string $sCode, string $sSlug): Product
{
    $obProduct = new Product();
    $obProduct->name = 'Seeded Product '.$sSlug;
    $obProduct->slug = $sSlug;
    $obProduct->code = $sCode;
    $obProduct->active = true;
    $obProduct->saveQuietly();

    return $obProduct;
}

/**
 * Seed a minimum-viable Offer row. `code` is the column matched by Pass 1
 * (OfferCodeMatcher). Uses `saveQuietly()` for the same reason as `seedProduct()`.
 */
function seedOffer(int $iProductId, string $sCode, string $sName = 'Seeded Offer'): Offer
{
    $obOffer = new Offer();
    $obOffer->product_id = $iProductId;
    $obOffer->name = $sName;
    $obOffer->code = $sCode;
    $obOffer->active = true;
    $obOffer->saveQuietly();

    return $obOffer;
}

/**
 * Seed an Offer with a `variation` value (Pass 3 chain stage column). Optional
 * `code` may be passed when the same offer should also be reachable via Pass 1.
 */
function seedOfferWithVariation(int $iProductId, string $sVariation, ?string $sCode = null, string $sName = 'Seeded Offer'): Offer
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

/**
 * Build a ParsedLine for chain runner input. EAN is preserved as STRING (D-27).
 */
function makeParsedLine(string $sEan, string $sProductNameRaw, int $iRowIndex = 1): ParsedLine
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

it('runs the full chain end-to-end emitting all 4 strategy literals in input order', function (): void {
    // Seed all 3 success paths.
    // Pass 1: offer.code direct hit.
    $obProductA = seedProduct('PROD-A-CODE', 'prod-a');
    $obOfferA = seedOffer($obProductA->id, '4752307000097');

    // Pass 2: product.code single-offer.
    $obProductB = seedProduct('4752307000200', 'prod-b');
    $obOfferB = seedOffer($obProductB->id, 'OFFER-B-INNER');

    // Pass 3: variation single-offer (no code, no product.code match for this EAN).
    $obProductC = seedProduct('PROD-C-CODE', 'prod-c');
    $obOfferC = seedOfferWithVariation($obProductC->id, '1081', 'IRRELEVANT-CODE-C');

    $arLines = [
        makeParsedLine('4752307000097', 'Item A', 1),                   // Pass 1
        makeParsedLine('4752307000200', 'Item B, irrelevant', 2),       // Pass 2
        makeParsedLine('5555555555555', 'Item C, 1081', 3),             // Pass 3
        makeParsedLine('9999999999999', 'Item D, 9999', 4),             // miss → 'none'
    ];

    $arResult = (new EanMatcherService())->matchLines($arLines);

    expect($arResult)->toHaveCount(4);
    expect($arResult[0])->toBeInstanceOf(MatchedLine::class);
    expect($arResult[0]->line)->toBe($arLines[0]);
    expect($arResult[0]->matched_offer_id)->toBe((int) $obOfferA->id);
    expect($arResult[0]->match_strategy)->toBe('offer_code');

    expect($arResult[1]->line)->toBe($arLines[1]);
    expect($arResult[1]->matched_offer_id)->toBe((int) $obOfferB->id);
    expect($arResult[1]->match_strategy)->toBe('product_code_single_offer');

    expect($arResult[2]->line)->toBe($arLines[2]);
    expect($arResult[2]->matched_offer_id)->toBe((int) $obOfferC->id);
    expect($arResult[2]->match_strategy)->toBe('variation');

    expect($arResult[3]->line)->toBe($arLines[3]);
    expect($arResult[3]->matched_offer_id)->toBeNull();
    expect($arResult[3]->match_strategy)->toBe('none');
});

it('runs at most 3 queries for full-cascade input (D-25-update budget)', function (): void {
    $obProductA = seedProduct('PROD-A-CODE', 'prod-a');
    seedOffer($obProductA->id, '4752307000097');

    $obProductB = seedProduct('4752307000200', 'prod-b');
    seedOffer($obProductB->id, 'OFFER-B-INNER');

    $obProductC = seedProduct('PROD-C-CODE', 'prod-c');
    seedOfferWithVariation($obProductC->id, '1081', 'IRRELEVANT-CODE-C');

    $arLines = [
        makeParsedLine('4752307000097', 'Item A', 1),
        makeParsedLine('4752307000200', 'Item B, irrelevant', 2),
        makeParsedLine('5555555555555', 'Item C, 1081', 3),
        makeParsedLine('9999999999999', 'Item D, 9999', 4),
    ];

    \DB::flushQueryLog();
    \DB::enableQueryLog();

    (new EanMatcherService())->matchLines($arLines);

    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    expect($iQueryCount)->toBe(3);
});

it('issues exactly ONE query when all lines match in Pass 1 (short-circuit Pass 2 + Pass 3)', function (): void {
    $obProduct = seedProduct('PROD-X-CODE', 'prod-x');
    seedOffer($obProduct->id, '4752307000097');
    seedOffer($obProduct->id, '4752307000165');

    $arLines = [
        makeParsedLine('4752307000097', 'Item One', 1),
        makeParsedLine('4752307000165', 'Item Two', 2),
    ];

    \DB::flushQueryLog();
    \DB::enableQueryLog();

    $arResult = (new EanMatcherService())->matchLines($arLines);

    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    expect($iQueryCount)->toBe(1);
    expect($arResult)->toHaveCount(2);
    expect($arResult[0]->match_strategy)->toBe('offer_code');
    expect($arResult[1]->match_strategy)->toBe('offer_code');
});

it('preserves leading-zero EAN as STRING through the chain (QA-02 / D-27)', function (): void {
    $obProduct = seedProduct('PROD-LZ-CODE', 'prod-lz');
    $obOffer = seedOffer($obProduct->id, '0000000012345');

    $arLines = [makeParsedLine('0000000012345', 'Leading-zero item', 1)];

    $arResult = (new EanMatcherService())->matchLines($arLines);

    expect($arResult)->toHaveCount(1);
    expect($arResult[0]->line->ean)->toBe('0000000012345');
    expect($arResult[0]->matched_offer_id)->toBe((int) $obOffer->id);
    expect($arResult[0]->match_strategy)->toBe('offer_code');
});

it('throws InvalidEanException BEFORE any DB query (T-02-06-01 / T-06-05-01 defense-in-depth)', function (): void {
    \DB::flushQueryLog();
    \DB::enableQueryLog();

    $bThrew = false;
    try {
        (new EanMatcherService())->matchLines([
            makeParsedLine('1234', 'Bad EAN line', 1),
        ]);
    } catch (InvalidEanException $obException) {
        $bThrew = true;
        expect(count(\DB::getQueryLog()))->toBe(0);
        expect($obException->arContext)->toMatchArray(['raw' => '1234']);
    }
    \DB::disableQueryLog();

    expect($bThrew)->toBeTrue();
});

it('returns empty list with zero queries for empty input', function (): void {
    \DB::flushQueryLog();
    \DB::enableQueryLog();

    $arResult = (new EanMatcherService())->matchLines([]);

    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    expect($arResult)->toBe([]);
    expect($iQueryCount)->toBe(0);
});

it('preserves input order across all 4 strategies (output row_index sequence equals input)', function (): void {
    // Seed in opposite order to the input ParsedLines so the chain stages
    // would naturally produce out-of-order matches if input order were not
    // explicitly restored by the runner.
    $obProductA = seedProduct('PROD-A-CODE', 'prod-a');
    seedOffer($obProductA->id, '4752307000097');

    $obProductB = seedProduct('4752307000200', 'prod-b');
    seedOffer($obProductB->id, 'OFFER-B-INNER');

    $obProductC = seedProduct('PROD-C-CODE', 'prod-c');
    seedOfferWithVariation($obProductC->id, '1081', 'IRRELEVANT-CODE-C');

    // Mix all 4 strategies in a non-cascade input order.
    $arLines = [
        makeParsedLine('5555555555555', 'Item C, 1081', 1),             // Pass 3
        makeParsedLine('4752307000097', 'Item A', 2),                   // Pass 1
        makeParsedLine('9999999999999', 'Item D, 9999', 3),             // miss
        makeParsedLine('4752307000200', 'Item B, irrelevant', 4),       // Pass 2
        makeParsedLine('4752307000097', 'Item A duplicate', 5),         // Pass 1 (dup ean OK)
    ];

    $arResult = (new EanMatcherService())->matchLines($arLines);

    expect($arResult)->toHaveCount(5);
    expect($arResult[0]->line->row_index)->toBe(1);
    expect($arResult[1]->line->row_index)->toBe(2);
    expect($arResult[2]->line->row_index)->toBe(3);
    expect($arResult[3]->line->row_index)->toBe(4);
    expect($arResult[4]->line->row_index)->toBe(5);
    expect($arResult[0]->match_strategy)->toBe('variation');
    expect($arResult[1]->match_strategy)->toBe('offer_code');
    expect($arResult[2]->match_strategy)->toBe('none');
    expect($arResult[3]->match_strategy)->toBe('product_code_single_offer');
    expect($arResult[4]->match_strategy)->toBe('offer_code');
});

it('Pass 3 single-offer guard escapes ambiguous variations as none (residue from all 3 stages)', function (): void {
    // Two offers with same variation '2002' — Pass 3 single-offer guard
    // refuses to silently best-guess; the line drops to chain residue.
    $obProductA = seedProduct('PROD-AMB-A', 'prod-amb-a');
    $obProductB = seedProduct('PROD-AMB-B', 'prod-amb-b');
    seedOfferWithVariation($obProductA->id, '2002', 'CODE-AMB-A');
    seedOfferWithVariation($obProductB->id, '2002', 'CODE-AMB-B');

    $arLines = [
        makeParsedLine('3333333333333', 'Y, 2002', 1),
    ];

    $arResult = (new EanMatcherService())->matchLines($arLines);

    expect($arResult)->toHaveCount(1);
    expect($arResult[0]->match_strategy)->toBe('none');
    expect($arResult[0]->matched_offer_id)->toBeNull();
    expect($arResult[0]->line)->toBe($arLines[0]);
});
