<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase;

/**
 * Hermetic schema slice for tests that exercise the polymorphic
 * `system_files` table (Invoice cascade-delete + orphan cleanup contracts).
 *
 * Same forensic rationale as `ApplyTestCase`: we cannot run
 * `migrateModules()` because the upstream Lovata migration trips an SQLite
 * drop-column-with-index trap. So we recreate the minimum set of columns
 * the System\Models\File model needs to round-trip in storage.
 *
 * The `disk_name` / `file_name` / `attachment_type` / `attachment_id`
 * columns mirror October's production schema; the others are the small
 * housekeeping fields System.File reads in its own getters/setters.
 *
 * tearDown drops every table so SQLite-in-memory stays hermetic
 * across tests.
 */
abstract class FilesTestCase extends GoodsReceivedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        \Schema::create('system_files', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('disk_name')->nullable();
            $obTable->string('file_name')->nullable();
            $obTable->integer('file_size')->nullable();
            $obTable->string('content_type', 128)->nullable();
            $obTable->string('title')->nullable();
            $obTable->text('description')->nullable();
            $obTable->string('field')->nullable();
            $obTable->morphs('attachment');
            $obTable->boolean('is_public')->default(true);
            $obTable->integer('sort_order')->default(0);
            $obTable->timestamps();
        });
    }

    protected function tearDown(): void
    {
        \Schema::dropIfExists('system_files');
        \Schema::dropIfExists('logingrupa_goods_received_invoices');
        parent::tearDown();
    }
}
