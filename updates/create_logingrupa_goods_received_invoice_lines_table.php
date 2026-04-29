<?php namespace Logingrupa\GoodsReceivedShopaholic\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Class CreateLogingrupaGoodsReceivedInvoiceLinesTable
 *
 * One row per parsed `<TR class="R20|R21">` data row in a `.HTM` invoice.
 *
 * Per CONTEXT.md decisions:
 *  - D-05: ean stored as string(13) (NOT integer) to preserve leading zeros
 *  - D-06: qty unsignedInteger (decimal qty rejected by QuantityNormalizer
 *          BEFORE Eloquent — guards setQuantityAttribute silent int-clamp)
 *  - D-07: unit_price decimal(12,4) nullable, audit-only (never written to stock)
 *  - D-08: match_strategy string(32) default 'none'
 *  - D-12: cascadeOnDelete to invoices (deleting invoice deletes lines).
 *          NO FK from matched_offer_id / matched_product_id — preserves
 *          audit history when offers/products are subsequently deleted.
 *
 * @package Logingrupa\GoodsReceivedShopaholic\Updates
 */
class CreateLogingrupaGoodsReceivedInvoiceLinesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('logingrupa_goods_received_invoice_lines')) {
            return;
        }

        Schema::create('logingrupa_goods_received_invoice_lines', function (Blueprint $obTable) {
            $obTable->engine = 'InnoDB';
            $obTable->increments('id')->unsigned();
            $obTable->unsignedBigInteger('invoice_id');
            $obTable->unsignedInteger('row_index');
            $obTable->string('ean', 13);
            $obTable->string('product_name_raw');
            $obTable->unsignedInteger('qty');
            $obTable->decimal('unit_price', 12, 4)->nullable();
            $obTable->unsignedInteger('matched_offer_id')->nullable();
            $obTable->unsignedInteger('matched_product_id')->nullable();
            $obTable->string('match_strategy', 32)->default('none');
            $obTable->boolean('applied')->default(false);
            $obTable->unsignedInteger('override_qty')->nullable();
            $obTable->string('override_reason')->nullable();
            $obTable->timestamp('applied_at')->nullable();
            $obTable->timestamps();

            $obTable->index('invoice_id');
            $obTable->index('ean');
            $obTable->index('matched_offer_id');
            $obTable->index('matched_product_id');
            $obTable->index('match_strategy');
            $obTable->index('applied');

            $obTable->foreign('invoice_id')
                ->references('id')->on('logingrupa_goods_received_invoices')
                ->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('logingrupa_goods_received_invoice_lines');
    }
}
