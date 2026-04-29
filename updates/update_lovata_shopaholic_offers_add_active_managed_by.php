<?php namespace Logingrupa\GoodsReceivedShopaholic\Updates;

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Schema;

/**
 * Class UpdateLovataShopaholicOffersAddActiveManagedBy
 *
 * ADDITIVE column-add on the Shopaholic-owned `lovata_shopaholic_offers`
 * table. Adds `active_managed_by` (string(16), default 'system') used by
 * `ActiveFlagService` to skip rows where the operator manually deactivated
 * the offer (`active_managed_by = 'operator'`).
 *
 * THREAT MITIGATION (T-01-01, locked): This migration MUST NOT touch any
 * existing column or row. It only adds a column with a DEFAULT — the
 * storage engine backfills existing rows via the column default, NOT via
 * application code. No row-mutation statements, no raw SQL writes, no
 * Eloquent saves are permitted in this file. A grep gate enforces the rule.
 *
 * Per CONTEXT.md decision D-10: string(16) (not enum) for cross-driver
 * portability. Application constrains values to: system | operator | plugin.
 *
 * @package Logingrupa\GoodsReceivedShopaholic\Updates
 */
class UpdateLovataShopaholicOffersAddActiveManagedBy extends Migration
{
    private const TABLE_NAME = 'lovata_shopaholic_offers';

    private const COLUMN_NAME = 'active_managed_by';

    public function up()
    {
        if (!Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        if (Schema::hasColumn(self::TABLE_NAME, self::COLUMN_NAME)) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->string(self::COLUMN_NAME, 16)->default('system')->after('active');
            $obTable->index(self::COLUMN_NAME);
        });
    }

    public function down()
    {
        if (!Schema::hasTable(self::TABLE_NAME)) {
            return;
        }

        if (!Schema::hasColumn(self::TABLE_NAME, self::COLUMN_NAME)) {
            return;
        }

        Schema::table(self::TABLE_NAME, function (Blueprint $obTable) {
            $obTable->dropIndex([self::COLUMN_NAME]);
            $obTable->dropColumn(self::COLUMN_NAME);
        });
    }
}
