<?php namespace Logingrupa\GoodsReceivedShopaholic\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Class CreateLogingrupaGoodsReceivedInitialResetSnapshotTable
 *
 * Captures the prior state of every offer/product BEFORE the one-shot
 * baseline reset writes zeroes/inactives. Snapshot rows are write-once —
 * only `created_at` (no `updated_at`).
 *
 * Per CONTEXT.md decision D-12:
 *  - cascadeOnDelete from invoices (deleting the reset invoice deletes
 *    its snapshot rows)
 *  - NO FK from offer_id / prior_product_id to lovata_shopaholic_offers /
 *    lovata_shopaholic_products — preserves rollback history when offers
 *    or products are subsequently deleted
 *
 * @package Logingrupa\GoodsReceivedShopaholic\Updates
 */
class CreateLogingrupaGoodsReceivedInitialResetSnapshotTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('logingrupa_goods_received_initial_reset_snapshot')) {
            return;
        }

        Schema::create('logingrupa_goods_received_initial_reset_snapshot', function (Blueprint $obTable) {
            $obTable->engine = 'InnoDB';
            $obTable->bigIncrements('id');
            $obTable->unsignedBigInteger('invoice_id');
            $obTable->unsignedInteger('offer_id');
            // Signed int — Lovata `lovata_shopaholic_offers.quantity` permits
            // negative values (over-sold / back-order). The snapshot must
            // mirror that semantics or the initial-reset transaction blows
            // up with SQLSTATE[22003] on the first negative row.
            $obTable->integer('prior_quantity')->default(0);
            $obTable->boolean('prior_offer_active')->default(false);
            $obTable->unsignedInteger('prior_product_id')->nullable();
            $obTable->boolean('prior_product_active')->default(false);
            $obTable->timestamp('created_at')->nullable();

            $obTable->index('invoice_id', 'gr_reset_snap_invoice_id_idx');
            $obTable->index('offer_id', 'gr_reset_snap_offer_id_idx');
            $obTable->index('prior_product_id', 'gr_reset_snap_prior_product_id_idx');

            $obTable->foreign('invoice_id', 'gr_reset_snap_invoice_id_fk')
                ->references('id')->on('logingrupa_goods_received_invoices')
                ->cascadeOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('logingrupa_goods_received_initial_reset_snapshot');
    }
}
