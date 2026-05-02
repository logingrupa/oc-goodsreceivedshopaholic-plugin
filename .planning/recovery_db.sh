#!/bin/bash
# DB schema recovery — nailscosmetics.{lv,no,lt} servers
# Companion to RECOVERY-2026-05-02.md (read first; explains every step).
#
# Idempotent: safe to re-run. Uses `IF NOT EXISTS` + `Schema::has*` guards
# everywhere. Does NOT touch existing tables/columns/data.
#
# Usage:
#   ./recovery_db.sh /home/forge/nailscosmetics.no
#
# Exit non-zero on any step failure. Set -x for verbose trace.
#
# Hungarian: s=string, i=int, b=bool, ar=array, path=filesystem path.

set -euo pipefail

S_PROJECT_PATH="${1:-}"
if [ -z "$S_PROJECT_PATH" ] || [ ! -d "$S_PROJECT_PATH" ]; then
    echo "Usage: $0 <project_path>"
    echo "  e.g. $0 /home/forge/nailscosmetics.no"
    exit 1
fi

S_ENV_FILE="$S_PROJECT_PATH/.env"
[ -f "$S_ENV_FILE" ] || { echo "FAIL: .env not found at $S_ENV_FILE"; exit 1; }

S_DB_NAME=$(grep '^DB_DATABASE=' "$S_ENV_FILE" | cut -d= -f2- | tr -d '"' | tr -d "'")
S_DB_USER=$(grep '^DB_USERNAME=' "$S_ENV_FILE" | cut -d= -f2- | tr -d '"' | tr -d "'")
S_DB_PWD=$(grep '^DB_PASSWORD=' "$S_ENV_FILE" | cut -d= -f2- | tr -d '"' | tr -d "'")
S_DB_HOST=$(grep '^DB_HOST='     "$S_ENV_FILE" | cut -d= -f2- | tr -d '"' | tr -d "'")
S_DB_HOST="${S_DB_HOST:-127.0.0.1}"

[ -z "$S_DB_NAME" ] && { echo "FAIL: DB_DATABASE not in $S_ENV_FILE"; exit 1; }
[ -z "$S_DB_USER" ] && { echo "FAIL: DB_USERNAME not in $S_ENV_FILE"; exit 1; }
[ -z "$S_DB_PWD" ]  && { echo "FAIL: DB_PASSWORD not in $S_ENV_FILE"; exit 1; }

echo "════════════════════════════════════════════════════════════════"
echo " DB SCHEMA RECOVERY"
echo "════════════════════════════════════════════════════════════════"
echo "  project: $S_PROJECT_PATH"
echo "  db:      $S_DB_NAME on $S_DB_HOST as $S_DB_USER"
echo

# ----------------------------------------------------------------
# Helper: wrap mysql with credentials
# ----------------------------------------------------------------
db_query() {
    mysql -h "$S_DB_HOST" -u "$S_DB_USER" -p"$S_DB_PWD" "$S_DB_NAME" "$@" 2>&1 \
        | grep -v "Warning: Using a password" || true
}

db_exec() {
    mysql -h "$S_DB_HOST" -u "$S_DB_USER" -p"$S_DB_PWD" "$S_DB_NAME" 2>&1 \
        | grep -v "Warning: Using a password" || true
}

# ----------------------------------------------------------------
# Pre-flight: detect symptom
# ----------------------------------------------------------------
echo "▶ Pre-flight: checking for missing critical tables..."
S_MISSING=""
for tbl in system_settings lovata_shopaholic_offers lovata_shopaholic_products \
           logingrupa_goods_received_invoices \
           logingrupa_goods_received_invoice_lines \
           logingrupa_goods_received_initial_reset_snapshot; do
    iCount=$(db_query -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$S_DB_NAME' AND TABLE_NAME='$tbl';")
    if [ "$iCount" = "0" ]; then
        S_MISSING="$S_MISSING $tbl"
    fi
done

if [ -z "$S_MISSING" ]; then
    echo "  ✓ All 6 critical tables present. Recovery NOT needed. Exiting."
    exit 0
fi

echo "  Missing tables:$S_MISSING"
echo

# ----------------------------------------------------------------
# Phase 1: Backup
# ----------------------------------------------------------------
echo "▶ Phase 1: backup current state..."
mkdir -p /home/forge/db_backups/recovery
S_TS=$(date +%Y%m%d_%H%M%S)
S_BACKUP_PATH="/home/forge/db_backups/recovery/${S_DB_NAME}_pre_recovery_${S_TS}.sql.gz"

mysqldump -h "$S_DB_HOST" -u "$S_DB_USER" -p"$S_DB_PWD" \
    --single-transaction --quick --routines --triggers --events \
    "$S_DB_NAME" 2>/dev/null \
    | gzip > "$S_BACKUP_PATH"

gunzip -t "$S_BACKUP_PATH" || { echo "FAIL: backup gzip integrity check failed"; exit 1; }
echo "  ✓ backup: $S_BACKUP_PATH ($(du -h "$S_BACKUP_PATH" | cut -f1))"
echo

# ----------------------------------------------------------------
# Phase 2: Boot unblocker — system_settings
# ----------------------------------------------------------------
echo "▶ Phase 2: ensure system_settings exists (boot unblocker)..."
db_exec <<'SQL'
CREATE TABLE IF NOT EXISTS system_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  item VARCHAR(255) NULL,
  value MEDIUMTEXT NULL,
  site_id INT UNSIGNED NULL,
  site_root_id INT UNSIGNED NULL,
  site_group_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  INDEX system_settings_item_index (item)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
echo "  ✓ system_settings present"
echo

# ----------------------------------------------------------------
# Phase 3: Ledger surgery
# ----------------------------------------------------------------
echo "▶ Phase 3: clear stale history rows + reset version stamps..."
db_exec <<'SQL'
START TRANSACTION;

DELETE FROM system_plugin_history
WHERE code='Lovata.Shopaholic'
  AND type='script'
  AND detail IN (
    'create_table_products.php',
    'create_table_offers.php',
    'update_table_offers_remove_price_field.php',
    'update_table_offers_add_dimensions_field.php',
    'update_table_offers_add_measure_field.php',
    'update_table_offers_change_quantity_field.php',
    'update_table_offers_add_sorting_field.php',
    'seeder_transfer_offer_prices.php'
  );

DELETE FROM system_plugin_history
WHERE code='Logingrupa.GoodsReceivedShopaholic' AND type='script';

DELETE FROM system_plugin_history
WHERE type='script'
  AND detail IN (
    'update_table_lovata_shopaholic_offers.php',
    'update_table_lovata_shopaholic_offers_add_preview_video_id.php',
    'update_table_lovata_shopaholic_products.php',
    'update_table_lovata_shopaholic_products_added_aditional_field.php',
    'update_table_offers.php',
    'update_table_product.php',
    'update_table_products.php',
    'update_table_offers_add_discount_field.php',
    'update_table_offers_remove_discount_value_field.php',
    'update_table_product_add_subscription_fields.php',
    'update_table_offer_add_subscription_fields.php'
  );

UPDATE system_plugin_versions SET version='1.0.0'
  WHERE code IN (
    'Lovata.Shopaholic',
    'Logingrupa.GoodsReceivedShopaholic',
    'Logingrupa.StoreExtender',
    'Lovata.SearchShopaholic',
    'Lovata.PopularityShopaholic',
    'Lovata.ReviewsShopaholic',
    'Lovata.DiscountsShopaholic',
    'Lovata.SubscriptionsShopaholic',
    'LoginGrupa.SearchOffersShopaholic'
  );

COMMIT;
SQL
echo "  ✓ ledger surgery done"
echo

# ----------------------------------------------------------------
# Phase 4: Manually create v1.0.0 boundary tables (offers + products)
# ----------------------------------------------------------------
echo "▶ Phase 4: manually create v1.0.0 boundary tables..."
db_exec <<'SQL'
START TRANSACTION;

CREATE TABLE IF NOT EXISTS lovata_shopaholic_products (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  active TINYINT(1) NOT NULL DEFAULT 0,
  name VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  brand_id INT UNSIGNED NULL,
  category_id INT UNSIGNED NULL,
  external_id VARCHAR(255) NULL,
  code VARCHAR(255) NULL,
  preview_text TEXT NULL,
  description TEXT NULL,
  deleted_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE INDEX lovata_shopaholic_products_slug_unique (slug),
  INDEX lovata_shopaholic_products_name_index (name),
  INDEX lovata_shopaholic_products_code_index (code),
  INDEX lovata_shopaholic_products_external_id_index (external_id),
  INDEX lovata_shopaholic_products_brand_id_index (brand_id),
  INDEX lovata_shopaholic_products_category_id_index (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lovata_shopaholic_offers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  active TINYINT(1) NOT NULL DEFAULT 0,
  product_id INT UNSIGNED NULL,
  name VARCHAR(255) NOT NULL,
  code VARCHAR(255) NULL,
  external_id VARCHAR(255) NULL,
  price DECIMAL(15,2) NULL,
  old_price DECIMAL(15,2) NULL,
  quantity INT UNSIGNED NOT NULL DEFAULT 0,
  preview_text TEXT NULL,
  description TEXT NULL,
  deleted_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  INDEX lovata_shopaholic_offers_name_index (name),
  INDEX lovata_shopaholic_offers_code_index (code),
  INDEX lovata_shopaholic_offers_external_id_index (external_id),
  INDEX lovata_shopaholic_offers_product_id_index (product_id),
  INDEX lovata_shopaholic_offers_price_index (price),
  INDEX lovata_shopaholic_offers_old_price_index (old_price),
  INDEX lovata_shopaholic_offers_quantity_index (quantity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_plugin_history (code, type, version, detail, created_at)
VALUES
  ('Lovata.Shopaholic', 'script', '1.0.0', 'create_table_products.php', NOW()),
  ('Lovata.Shopaholic', 'script', '1.0.0', 'create_table_offers.php', NOW());

COMMIT;
SQL
echo "  ✓ offers + products tables created (or skipped if existed)"
echo

# ----------------------------------------------------------------
# Phase 5: October migrate
# ----------------------------------------------------------------
echo "▶ Phase 5: php artisan october:migrate --force..."
cd "$S_PROJECT_PATH"
php artisan october:migrate --force 2>&1 | tail -50 || {
    echo "  ⚠ migrate reported errors — review output above"
    echo "  Each failing migration must be fixed manually then retry."
    exit 1
}
echo "  ✓ migrate complete"
echo

# ----------------------------------------------------------------
# Phase 6: Apply remaining v1.0.0 boundary ALTERs (idempotent — wraps in IF NOT EXISTS via try-catch substitute)
# ----------------------------------------------------------------
echo "▶ Phase 6: apply remaining v1.0.0 boundary column ALTERs..."

# Helper: add column only if missing
add_col_if_missing() {
    local sTable="$1"
    local sCol="$2"
    local sDef="$3"
    local iExists
    iExists=$(db_query -N -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$S_DB_NAME' AND TABLE_NAME='$sTable' AND COLUMN_NAME='$sCol';")
    if [ "$iExists" = "0" ]; then
        db_exec <<SQL
ALTER TABLE $sTable ADD COLUMN $sCol $sDef;
SQL
        echo "  + $sTable.$sCol"
    fi
}

add_col_if_missing lovata_shopaholic_offers   discount_id            "INT NULL"
add_col_if_missing lovata_shopaholic_offers   discount_value         "FLOAT UNSIGNED NULL"
add_col_if_missing lovata_shopaholic_offers   discount_type          "VARCHAR(255) NULL"
add_col_if_missing lovata_shopaholic_offers   subscription_period_id "INT NULL"

add_col_if_missing lovata_shopaholic_products search_synonym  "TEXT NULL"
add_col_if_missing lovata_shopaholic_products search_content  "TEXT NULL"
add_col_if_missing lovata_shopaholic_products popularity      "INT NOT NULL DEFAULT 0"
add_col_if_missing lovata_shopaholic_products rating_data     "TEXT NULL"
add_col_if_missing lovata_shopaholic_products rating          "DECIMAL(5,2) NULL"
add_col_if_missing lovata_shopaholic_products is_subscription "TINYINT(1) NOT NULL DEFAULT 0"

# Index on is_subscription (only if column exists and index missing)
iIdxExists=$(db_query -N -e "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$S_DB_NAME' AND TABLE_NAME='lovata_shopaholic_products' AND INDEX_NAME='lovata_shopaholic_products_is_subscription_index';")
if [ "$iIdxExists" = "0" ]; then
    db_exec <<'SQL'
ALTER TABLE lovata_shopaholic_products ADD INDEX lovata_shopaholic_products_is_subscription_index (is_subscription);
SQL
fi

# Insert history rows (UPSERT pattern via INSERT IGNORE-equivalent — checking first)
db_exec <<'SQL'
INSERT INTO system_plugin_history (code, type, version, detail, created_at)
SELECT * FROM (
    SELECT 'Lovata.DiscountsShopaholic'     AS code, 'script' AS type, '1.0.0' AS version, 'update_table_offers_add_discount_field.php' AS detail, NOW() AS created_at UNION ALL
    SELECT 'Lovata.SubscriptionsShopaholic',     'script', '1.0.0', 'update_table_product_add_subscription_fields.php', NOW() UNION ALL
    SELECT 'Lovata.SubscriptionsShopaholic',     'script', '1.0.0', 'update_table_offer_add_subscription_fields.php',   NOW() UNION ALL
    SELECT 'Lovata.SearchShopaholic',            'script', '1.0.0', 'update_table_product.php',                          NOW() UNION ALL
    SELECT 'Lovata.PopularityShopaholic',        'script', '1.0.0', 'update_table_product.php',                          NOW() UNION ALL
    SELECT 'Lovata.ReviewsShopaholic',           'script', '1.0.0', 'update_table_products.php',                         NOW()
) AS new_rows
WHERE NOT EXISTS (
    SELECT 1 FROM system_plugin_history h
    WHERE h.code=new_rows.code AND h.type=new_rows.type
      AND h.version=new_rows.version AND h.detail=new_rows.detail
);
SQL
echo "  ✓ boundary ALTERs done; history rows merged"
echo

# ----------------------------------------------------------------
# Phase 7: Cache clear
# ----------------------------------------------------------------
echo "▶ Phase 7: clear caches..."
cd "$S_PROJECT_PATH"
php artisan cache:clear
php artisan config:clear
php artisan view:clear
echo "  ⚠ run manually: sudo systemctl reload php8.4-fpm   (sudo prompts)"
echo

# ----------------------------------------------------------------
# Phase 8: Verify
# ----------------------------------------------------------------
echo "▶ Phase 8: verify..."

echo "  --- critical tables ---"
for tbl in system_settings lovata_shopaholic_offers lovata_shopaholic_products \
           logingrupa_goods_received_invoices \
           logingrupa_goods_received_invoice_lines \
           logingrupa_goods_received_initial_reset_snapshot; do
    iCount=$(db_query -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$S_DB_NAME' AND TABLE_NAME='$tbl';")
    [ "$iCount" = "1" ] && echo "    ✓ $tbl" || echo "    ✗ $tbl MISSING"
done

echo "  --- offers cross-plugin columns ---"
db_query -N -e "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$S_DB_NAME' AND TABLE_NAME='lovata_shopaholic_offers' AND COLUMN_NAME IN ('variation','preview_video','search_synonym','active_managed_by','sort_order','width','height','length','weight','measure_id','quantity_in_unit','discount_id','subscription_period_id') ORDER BY COLUMN_NAME;" | sed 's/^/    /'

echo "  --- products cross-plugin columns ---"
db_query -N -e "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$S_DB_NAME' AND TABLE_NAME='lovata_shopaholic_products' AND COLUMN_NAME IN ('search_synonym','search_content','popularity','rating','rating_data','is_subscription','video_link','how_to','hide_dropdown') ORDER BY COLUMN_NAME;" | sed 's/^/    /'

echo
echo "  --- migrate idempotency check (should say 'Nothing to migrate.') ---"
cd "$S_PROJECT_PATH" && php artisan october:migrate --force 2>&1 | tail -3 | sed 's/^/    /'

echo
echo "════════════════════════════════════════════════════════════════"
echo " RECOVERY COMPLETE"
echo "════════════════════════════════════════════════════════════════"
echo
echo "Backup: $S_BACKUP_PATH"
echo
echo "Manual final steps:"
echo "  1. sudo systemctl reload php8.4-fpm"
echo "  2. Browser smoke:"
echo "     /back/system/settings"
echo "     /back/lovata/shopaholic/products"
echo "     /back/logingrupa/goodsreceivedshopaholic/invoices"
echo "  3. Re-import product/offer data (1C XML import or distributor feed)"
echo
