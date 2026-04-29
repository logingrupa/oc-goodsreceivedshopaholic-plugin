<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;

/**
 * Shared hermetic schema base for tests/unit/Apply/* (Phase 3 plan 03-03+).
 *
 * Same forensic rationale as `tests/unit/Match/EanMatcherServiceTest.php`:
 * Lovata's `update_table_offers_remove_price_field` migration fails on SQLite
 * because the price index is not dropped before the column. So we sidestep
 * `migrateModules()` entirely and create only the columns the Apply layer
 * actually reads/writes:
 *
 *   - lovata_shopaholic_products: id + code + name + slug + active
 *   - lovata_shopaholic_offers: id + product_id + code + name + active
 *     + active_managed_by + quantity + sort_order
 *   - logingrupa_goods_received_invoices: full Phase 1 column set
 *   - logingrupa_goods_received_invoice_lines: full Phase 1 column set
 *
 * The Phase 1 plugin migrations DO work via `migrateCurrentPlugin()` (no
 * drop-column-with-index trap), but we recreate them inline so this base is
 * decoupled from migration order entirely. tearDown drops every table.
 */
abstract class ApplyTestCase extends GoodsReceivedTestCase
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
            $obTable->string('active_managed_by', 16)->default('system');
            $obTable->integer('quantity')->default(0);
            $obTable->integer('sort_order')->default(0);
            $obTable->timestamps();
            $obTable->softDeletes();
            $obTable->index('code');
            $obTable->index('product_id');
            $obTable->index('active_managed_by');
        });

        \Schema::create('logingrupa_goods_received_invoices', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('invoice_number', 64)->unique();
            $obTable->dateTime('invoice_date')->nullable();
            $obTable->string('country_code', 4)->nullable();
            $obTable->string('source_filename')->nullable();
            $obTable->string('source_path')->nullable();
            $obTable->string('status', 32)->default('parsed');
            $obTable->unsignedInteger('total_lines')->default(0);
            $obTable->unsignedInteger('matched_lines')->default(0);
            $obTable->unsignedInteger('unmatched_lines')->default(0);
            $obTable->unsignedInteger('stock_added_units')->default(0);
            $obTable->unsignedBigInteger('applied_by_user_id')->nullable();
            $obTable->dateTime('parsed_at')->nullable();
            $obTable->dateTime('applied_at')->nullable();
            $obTable->boolean('initial_reset_applied')->default(false);
            $obTable->unsignedBigInteger('override_of_invoice_id')->nullable();
            $obTable->text('notes')->nullable();
            $obTable->timestamps();
        });

        \Schema::create('logingrupa_goods_received_invoice_lines', function ($obTable): void {
            $obTable->increments('id');
            $obTable->unsignedBigInteger('invoice_id');
            $obTable->unsignedInteger('row_index');
            $obTable->string('ean', 13);
            $obTable->string('product_name_raw')->nullable();
            $obTable->unsignedInteger('qty');
            $obTable->decimal('unit_price', 12, 4)->nullable();
            $obTable->unsignedBigInteger('matched_offer_id')->nullable();
            $obTable->unsignedBigInteger('matched_product_id')->nullable();
            $obTable->string('match_strategy', 32)->default('none');
            $obTable->boolean('applied')->default(false);
            $obTable->unsignedInteger('override_qty')->nullable();
            $obTable->string('override_reason')->nullable();
            $obTable->dateTime('applied_at')->nullable();
            $obTable->timestamps();
            $obTable->index('ean');
            $obTable->index('matched_offer_id');
        });
    }

    protected function tearDown(): void
    {
        \Schema::dropIfExists('logingrupa_goods_received_invoice_lines');
        \Schema::dropIfExists('logingrupa_goods_received_invoices');
        \Schema::dropIfExists('lovata_shopaholic_offers');
        \Schema::dropIfExists('lovata_shopaholic_products');
        parent::tearDown();
    }
}

/**
 * Seed a minimum-viable Product row. Same `saveQuietly()` rationale as the
 * EanMatcher tests — afterSave hooks load main_price from a table our
 * hermetic schema intentionally does not provide.
 */
function seedApplyProduct(string $sCode, string $sSlug): Product
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
 * Seed a minimum-viable Offer row attached to a Product, with explicit
 * starting `quantity` (default 0) for additive-write assertions.
 */
function seedApplyOffer(int $iProductId, string $sCode, int $iQuantity = 0, bool $bActive = true): Offer
{
    $obOffer = new Offer();
    $obOffer->product_id = $iProductId;
    $obOffer->name = 'Seeded Offer '.$sCode;
    $obOffer->code = $sCode;
    $obOffer->active = $bActive;
    $obOffer->quantity = $iQuantity;
    $obOffer->saveQuietly();

    return $obOffer;
}
