<?php namespace Logingrupa\GoodsReceivedShopaholic\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Class CreateLogingrupaGoodsReceivedInvoicesTable
 *
 * Header table for distributor goods-received notes (GRN). One row per .HTM
 * delivery receipt. UNIQUE(invoice_number) enforces idempotency at the DB
 * layer per success criterion 5 — a re-upload of the same invoice number is
 * rejected by the engine before application logic runs.
 *
 * Per CONTEXT.md decisions:
 *  - D-04: invoice_number string(64) UNIQUE
 *  - D-09: status string(32) (cross-driver portable; no native ENUM in SQLite)
 *  - D-11: override_of_invoice_id self-FK with nullOnDelete (preserves audit
 *          trail when prior invoice deleted; NOT cascade)
 *
 * @package Logingrupa\GoodsReceivedShopaholic\Updates
 */
class CreateLogingrupaGoodsReceivedInvoicesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('logingrupa_goods_received_invoices')) {
            return;
        }

        Schema::create('logingrupa_goods_received_invoices', function (Blueprint $obTable) {
            $obTable->engine = 'InnoDB';
            $obTable->increments('id')->unsigned();
            $obTable->string('invoice_number', 64)->unique();
            $obTable->date('invoice_date')->nullable();
            $obTable->string('country_code', 2)->nullable();
            $obTable->string('source_filename')->nullable();
            $obTable->string('source_path')->nullable();
            $obTable->string('status', 32)->default('parsed');
            $obTable->unsignedInteger('total_lines')->default(0);
            $obTable->unsignedInteger('matched_lines')->default(0);
            $obTable->unsignedInteger('unmatched_lines')->default(0);
            $obTable->unsignedInteger('stock_added_units')->default(0);
            $obTable->unsignedInteger('applied_by_user_id')->nullable();
            $obTable->timestamp('parsed_at')->nullable();
            $obTable->timestamp('applied_at')->nullable();
            $obTable->boolean('initial_reset_applied')->default(false);
            $obTable->unsignedBigInteger('override_of_invoice_id')->nullable();
            $obTable->text('notes')->nullable();
            $obTable->timestamps();

            $obTable->index('status');
            $obTable->index('applied_by_user_id');
            $obTable->index('applied_at');
            $obTable->index('initial_reset_applied');

            $obTable->foreign('override_of_invoice_id')
                ->references('id')->on('logingrupa_goods_received_invoices')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('logingrupa_goods_received_invoices');
    }
}
