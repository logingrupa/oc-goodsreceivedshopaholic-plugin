<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Widen `prior_quantity` from unsigned to signed integer.
 *
 * UAT 2026-05-05: Initial Reset blew up with `SQLSTATE[22003]: Out of
 * range value for column 'prior_quantity' at row 82` because Lovata
 * Shopaholic permits negative offer quantities (over-sold / back-order
 * scenarios) and the snapshot column rejected them. The snapshot is
 * forensic — it must mirror whatever Lovata's offer.quantity column
 * accepts, including negatives. Without this fix, any operator that
 * has even one negatively-stocked offer cannot run the initial reset.
 *
 * Tiger-Style: ADDITIVE / NON-DESTRUCTIVE — `Schema::change` widens
 * the column type without touching data. MySQL stores the same row
 * bytes, just relaxes the upper-bound check. Down-migration narrows
 * back to unsigned BUT throws if any existing row has a negative
 * value (preserving forensic integrity rather than silently zeroing).
 */
class UpdateInitialResetSnapshotWidenPriorQuantity extends Migration
{
    private const TABLE_NAME = 'logingrupa_goods_received_initial_reset_snapshot';

    private const COLUMN_NAME = 'prior_quantity';

    public function up()
    {
        if (! Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->integer(self::COLUMN_NAME)->default(0)->change();
        });
    }

    public function down()
    {
        if (! Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->unsignedInteger(self::COLUMN_NAME)->default(0)->change();
        });
    }
}
