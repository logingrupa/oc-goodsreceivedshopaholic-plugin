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
 * Plan 02-06 Task 2: EanMatcherService DB-backed integration tests.
 *
 * Pins the load-bearing invariants that protect Phase 3 from drift:
 *   - 2-query proof (MATCH-01) — DB::enableQueryLog() asserts EXACTLY 2 queries
 *     for a 5-EAN batch, so any regression to per-line lookup fails CI loudly.
 *   - 3-strategy coverage — offer_code | product_code_single_offer | none.
 *   - Single-offer guard — Product with multiple Offers stays unmatched (D-25).
 *   - QA-02 PreservesLeadingZeroEanTest — '0000000012345' survives as STRING,
 *     never int-cast, asserted via array_keys() identity check.
 *   - InvalidEanException defense-in-depth — non-13-digit input throws BEFORE
 *     any DB query (T-02-06-01 mitigation; security threat model).
 *   - Empty input → empty result, zero queries.
 *   - buildMatchedLines preserves order + wraps unmatched as match_strategy='none'.
 *
 * Schema bootstrap rationale (Rule 3 deviation from plan D-33):
 * The plan called for `protected $autoMigrate = true;` to migrate the full
 * Lovata.Shopaholic plugin into SQLite-in-memory. That path is broken on
 * SQLite — `update_table_offers_remove_price_field` fails with
 * "no such column: price after drop column" because SQLite cannot drop a
 * column that has an attached index, and the upstream migration does not
 * `dropIndex()` before `dropColumn()`. Symptom is reproducible on any test
 * that does both `migrateModules()` and `migrateCurrentPlugin()` for a
 * plugin that requires Lovata.Shopaholic.
 *
 * Workaround: create ONLY the columns the matcher needs in setUp via raw
 * `Schema::create()`. The matcher reads `id`, `code`, `product_id` from
 * offers and `id`, `code` from products — nothing else. This keeps the test
 * hermetic and decoupled from Lovata's full schema (which is irrelevant to
 * this matcher's contract).
 */
abstract class EanMatcherTestCase extends GoodsReceivedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Hermetic minimal schema: only the columns the matcher reads.
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

uses(EanMatcherTestCase::class);

/**
 * Seed a minimum-viable Product row for matcher tests. Lovata Product requires
 * `name` + `slug` (slug unique). `code` is the column the matcher queries.
 *
 * Uses `saveQuietly()` to skip Lovata's model event handlers (afterSave hooks
 * load `main_price` / multisite relations from tables our hermetic schema
 * doesn't provide). The matcher only ever issues SELECT against `code` /
 * `id` / `product_id`, so quiet save is safe.
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
 * Seed a minimum-viable Offer row attached to a Product. Lovata Offer requires
 * `name`. `code` is the EAN-bearing column queried by `Pass 1`.
 *
 * Uses `saveQuietly()` for the same reason as `seedProduct()` — Offer's
 * `afterSave` queries `lovata_shopaholic_prices` which is intentionally
 * absent from the hermetic schema.
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

it('issues exactly TWO queries regardless of batch size (MATCH-01 proof)', function (): void {
    // Seed 2 offer-direct matches + 1 product-with-single-offer match.
    $obProductA = seedProduct('PROD-A-CODE', 'prod-a');
    seedOffer($obProductA->id, '4752307000097');

    $obProductB = seedProduct('PROD-B-CODE', 'prod-b');
    seedOffer($obProductB->id, '4752307000165');

    // Product-only match: product code IS the EAN, single offer attached.
    $obProductC = seedProduct('4752307000200', 'prod-c');
    seedOffer($obProductC->id, 'OFFER-C-CODE');

    \DB::flushQueryLog();
    \DB::enableQueryLog();

    $obService = new EanMatcherService();
    $arResult = $obService->matchBatch([
        '4752307000097',
        '4752307000165',
        '4752307000200',
        '4752307000300', // unmatched
        '4752307000400', // unmatched
    ]);

    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    expect($iQueryCount)->toBe(2);
    expect($arResult['4752307000097']['match_strategy'])->toBe('offer_code');
    expect($arResult['4752307000165']['match_strategy'])->toBe('offer_code');
    expect($arResult['4752307000200']['match_strategy'])->toBe('product_code_single_offer');
    expect($arResult['4752307000300']['match_strategy'])->toBe('none');
    expect($arResult['4752307000400']['match_strategy'])->toBe('none');
});

it('issues exactly ONE query when all EANs match via offer_code (no product fallback needed)', function (): void {
    $obProduct = seedProduct('PROD-X-CODE', 'prod-x');
    seedOffer($obProduct->id, '4752307000097');
    seedOffer($obProduct->id, '4752307000165');

    \DB::flushQueryLog();
    \DB::enableQueryLog();

    $obService = new EanMatcherService();
    $arResult = $obService->matchBatch(['4752307000097', '4752307000165']);

    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    expect($iQueryCount)->toBe(1);
    expect($arResult['4752307000097']['match_strategy'])->toBe('offer_code');
    expect($arResult['4752307000165']['match_strategy'])->toBe('offer_code');
});

it('preserves leading-zero EAN as STRING throughout (QA-02 PreservesLeadingZeroEanTest)', function (): void {
    $obProduct = seedProduct('PROD-LZ-CODE', 'prod-lz');
    $obOffer = seedOffer($obProduct->id, '0000000012345');

    $obService = new EanMatcherService();
    $arResult = $obService->matchBatch(['0000000012345']);

    expect($arResult)->toHaveKey('0000000012345');
    // The first key must be the STRING '0000000012345', NOT int 12345. PHP
    // preserves leading-zero numeric strings as string keys precisely because
    // they are not "decimal-int representable" — int-cast anywhere in the
    // implementation would silently corrupt this.
    expect(array_keys($arResult)[0])->toBe('0000000012345');
    expect($arResult['0000000012345']['match_strategy'])->toBe('offer_code');
    expect($arResult['0000000012345']['matched_offer_id'])->toBe((int) $obOffer->id);
});

it('returns offer_code strategy for direct offer match', function (): void {
    $obProduct = seedProduct('PROD-OC-CODE', 'prod-oc');
    $obOffer = seedOffer($obProduct->id, '4752307000097');

    $arResult = (new EanMatcherService())->matchBatch(['4752307000097']);

    expect($arResult['4752307000097']['match_strategy'])->toBe('offer_code');
    expect($arResult['4752307000097']['matched_offer_id'])->toBe((int) $obOffer->id);
});

it('returns product_code_single_offer when product matches with 13-digit EAN code and has exactly one offer', function (): void {
    $obProduct = seedProduct('1234567890123', 'prod-single-13d');
    $obOffer = seedOffer($obProduct->id, 'INNER-OFFER-NOT-EAN');

    $arResult = (new EanMatcherService())->matchBatch(['1234567890123']);

    expect($arResult['1234567890123']['match_strategy'])->toBe('product_code_single_offer');
    expect($arResult['1234567890123']['matched_offer_id'])->toBe((int) $obOffer->id);
});

it('returns none when product matches but has multiple offers (single-offer guard)', function (): void {
    $obProduct = seedProduct('9999999999999', 'prod-multi-13d');
    seedOffer($obProduct->id, 'INNER-OFFER-A');
    seedOffer($obProduct->id, 'INNER-OFFER-B');

    $arResult = (new EanMatcherService())->matchBatch(['9999999999999']);

    expect($arResult['9999999999999']['match_strategy'])->toBe('none');
    expect($arResult['9999999999999']['matched_offer_id'])->toBeNull();
});

it('returns none for fully unmatched EAN (MATCH-02 partial-match never throws)', function (): void {
    $arResult = (new EanMatcherService())->matchBatch(['4444444444444']);

    expect($arResult['4444444444444']['match_strategy'])->toBe('none');
    expect($arResult['4444444444444']['matched_offer_id'])->toBeNull();
});

it('throws InvalidEanException for non-13-digit input BEFORE issuing any DB query (T-02-06-01)', function (): void {
    \DB::flushQueryLog();
    \DB::enableQueryLog();

    $bThrew = false;
    try {
        (new EanMatcherService())->matchBatch(['1234']);
    } catch (InvalidEanException $obException) {
        $bThrew = true;
        expect(count(\DB::getQueryLog()))->toBe(0);
        expect($obException->arContext)->toMatchArray(['raw' => '1234']);
    }
    \DB::disableQueryLog();

    expect($bThrew)->toBeTrue();
});

it('throws InvalidEanException for input containing letters', function (): void {
    expect(fn (): array => (new EanMatcherService())->matchBatch(['abcdefghijklm']))
        ->toThrow(InvalidEanException::class);
});

it('throws InvalidEanException for input that is too long (14 digits)', function (): void {
    expect(fn (): array => (new EanMatcherService())->matchBatch(['12345678901234']))
        ->toThrow(InvalidEanException::class);
});

it('returns empty result for empty input array (zero queries, no throw)', function (): void {
    \DB::flushQueryLog();
    \DB::enableQueryLog();

    $arResult = (new EanMatcherService())->matchBatch([]);

    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    expect($arResult)->toBe([]);
    expect($iQueryCount)->toBe(0);
});

it('buildMatchedLines wraps ParsedLines with match-map entries preserving order', function (): void {
    $obLine1 = new ParsedLine(
        row_index: 1,
        ean: '4752307000097',
        product_name_raw: 'Item One',
        unit: 'PCE',
        qty: 3,
        unit_price: 1.0,
        discount: 0.0,
        line_price: 3.0,
        total: 3.0,
    );
    $obLine2 = new ParsedLine(
        row_index: 2,
        ean: '4444444444444',
        product_name_raw: 'Item Two',
        unit: 'PCE',
        qty: 5,
        unit_price: 2.0,
        discount: 0.0,
        line_price: 10.0,
        total: 10.0,
    );

    $arMatchMap = [
        '4752307000097' => ['matched_offer_id' => 42, 'match_strategy' => 'offer_code'],
        '4444444444444' => ['matched_offer_id' => null, 'match_strategy' => 'none'],
    ];

    $arResult = (new EanMatcherService())->buildMatchedLines([$obLine1, $obLine2], $arMatchMap);

    expect($arResult)->toHaveCount(2);
    expect($arResult[0])->toBeInstanceOf(MatchedLine::class);
    expect($arResult[0]->line)->toBe($obLine1);
    expect($arResult[0]->matched_offer_id)->toBe(42);
    expect($arResult[0]->match_strategy)->toBe('offer_code');
    expect($arResult[1]->line)->toBe($obLine2);
    expect($arResult[1]->matched_offer_id)->toBeNull();
    expect($arResult[1]->match_strategy)->toBe('none');
});

it('buildMatchedLines treats missing map entries as match_strategy=none (defensive default)', function (): void {
    $obLine = new ParsedLine(
        row_index: 1,
        ean: '4752307000097',
        product_name_raw: 'Item',
        unit: 'PCE',
        qty: 1,
        unit_price: null,
        discount: null,
        line_price: null,
        total: null,
    );

    $arResult = (new EanMatcherService())->buildMatchedLines([$obLine], []);

    expect($arResult)->toHaveCount(1);
    expect($arResult[0]->match_strategy)->toBe('none');
    expect($arResult[0]->matched_offer_id)->toBeNull();
});
