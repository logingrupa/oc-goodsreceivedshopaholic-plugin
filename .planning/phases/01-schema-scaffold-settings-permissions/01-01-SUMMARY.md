---
phase: 01-schema-scaffold-settings-permissions
plan: 01
subsystem: database
tags: [migrations, schema, october-cms, eloquent, ddl, idempotency, foreign-keys, audit-trail]

# Dependency graph
requires:
  - phase: 00-bootstrap
    provides: composer scaffold; updates/version.yaml with 1.0.0 block; rector.php skipping updates/
provides:
  - 3 plugin tables (logingrupa_goods_received_invoices, _invoice_lines, _initial_reset_snapshot) with InnoDB engine and idempotent up/down guards
  - DB-layer idempotency contract: UNIQUE(invoice_number) on logingrupa_goods_received_invoices (D-04)
  - Self-referential override audit trail: override_of_invoice_id with nullOnDelete (D-11)
  - Cascade delete from invoices to lines and snapshot (D-12); preserves audit when offers/products deleted by NOT linking them via FK
  - EAN stored as string(13) preserving leading zeros (D-05) — integer storage permanently forbidden
  - Cross-driver enum-as-string portability: status string(32), match_strategy string(32), active_managed_by string(16) — no ENUM column anywhere (D-08, D-09, D-10)
  - ADDITIVE column-add on lovata_shopaholic_offers.active_managed_by (default 'system') with grep-gated zero row-mutation guarantee (T-01-01 mitigation)
  - updates/version.yaml 1.0.1 block registering all 4 migrations in dependency order
affects:
  - 01-02 (Eloquent models — Invoice/InvoiceLine/InitialResetSnapshot wrap these tables; @property blocks must mirror column types here)
  - 01-05 (Settings model — independent table, but multisite plumbing reads same DB)
  - 01-06 (Plugin permissions — independent of schema, just runs after october:up)
  - 02-* (Parser/matcher — write to invoices/invoice_lines via Orchestrator)
  - 03-* (Apply layer — reads/writes invoice/lines/snapshot rows, reads offers.active_managed_by)
  - 04-* (Backend UI — list/edit views over these tables)
  - 05-OPS-05 (`make all` / phpstan level 10 must keep migrations excluded via rector.php skip + phpstan paths)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "October Rain v4 migration idiom: Schema::hasTable + Schema::hasColumn idempotent guards in up(), reverse guards in down()"
    - "Cross-driver enum portability: enum-typed application values stored as string(N) columns (status, match_strategy, active_managed_by) — SQLite has no native ENUM"
    - "EAN-as-string preservation: barcode columns are string(13), never integer — leading zeros must survive round-trips and match operations"
    - "Audit-preserving FK strategy: cascade ONLY parent→child within plugin (invoices→lines, invoices→snapshot); deliberately omit FK from matched_offer_id/matched_product_id/offer_id/prior_product_id to Shopaholic core tables so prior matches survive offer/product deletion"
    - "Self-FK with nullOnDelete: override_of_invoice_id preserves override history when prior invoice deleted (NOT cascade)"
    - "ADDITIVE-only Shopaholic-table extension: column-add with default value backfills existing rows at storage-engine layer; zero application-code row mutation (enforced by file-level grep gate)"
    - "Lovata updates/ convention: omit declare(strict_types=1) — rector.php skips updates/, matches existing Lovata Shopaholic core migrations"
    - "version.yaml dependency ordering: parent table (invoices) listed first; FK-dependent tables (lines, snapshot) follow; independent extension migration (offers) ordered last for clarity"

key-files:
  created:
    - updates/create_logingrupa_goods_received_invoices_table.php
    - updates/create_logingrupa_goods_received_invoice_lines_table.php
    - updates/create_logingrupa_goods_received_initial_reset_snapshot_table.php
    - updates/update_lovata_shopaholic_offers_add_active_managed_by.php
  modified:
    - updates/version.yaml

key-decisions:
  - "invoice_number is string(64) UNIQUE — DB-engine enforces idempotency BEFORE application logic runs (D-04, success criterion 5)"
  - "EAN stored as string(13) (not integer/bigint) preserves leading zeros — integer storage would silently strip 0123456789012 → 123456789012 enabling match-confusion (T-01-06)"
  - "status, match_strategy, active_managed_by stored as string(N) not native ENUM — SQLite portability for in-memory test runs (D-08, D-09, D-10)"
  - "override_of_invoice_id self-FK uses nullOnDelete (NOT cascade) — preserves override audit trail when prior invoice deleted (D-11, T-01-05)"
  - "FKs cascadeOnDelete only invoices→lines and invoices→snapshot — same plugin owns both ends, deletion semantics safe (D-12)"
  - "NO FK from matched_offer_id / matched_product_id / offer_id / prior_product_id to Shopaholic core tables — preserves audit history when offers/products subsequently deleted by upstream (D-12 spirit)"
  - "active_managed_by migration is ADDITIVE-only — file contains zero UPDATE / DB::statement / Eloquent save tokens (verified by grep gate, T-01-01 mitigation)"
  - "Migrations omit declare(strict_types=1) per Lovata updates/ convention — confirmed rector.php skips updates/ directory and Lovata Shopaholic core migrations also omit it"
  - "Snapshot table uses single created_at timestamp (no timestamps()) — snapshot rows are write-once by design"
  - "Down migration on offers extension drops index BEFORE column (MySQL ordering requirement on some versions)"
  - "Docblock on offers extension migration was reworded to remove literal UPDATE / DB::statement tokens so the threat-mitigation grep gate passes against the entire file (not just code lines)"

patterns-established:
  - "Migration idempotency: every up() short-circuits via Schema::hasTable; column-add migrations also short-circuit via Schema::hasColumn — october:up is replayable as a no-op"
  - "Migration class naming: PascalCase mirroring snake_case filename (October Rain v4 requirement) — never deviate"
  - "ADDITIVE-only Shopaholic extension: when extending a core table, column-add with default ONLY; never UPDATE existing rows from a migration; backfill via column default at storage-engine layer"
  - "Audit-FK split: FK cascade for plugin-owned parent/child; NO FK for cross-plugin references where deletion of the referenced row should leave audit rows intact"
  - "version.yaml dependency ordering: list parent tables before child tables; group plugin-owned migrations contiguously; place core-table extensions last for visual clarity"

requirements-completed:
  - SCHEMA-01
  - SCHEMA-02
  - SCHEMA-03
  - SCHEMA-04

# Metrics
duration: 4min
completed: 2026-04-29
---

# Phase 01 Plan 01: Schema Lock — Migrations Summary

**Locked the persistence contract for GoodsReceivedShopaholic with 3 plugin tables (invoices, invoice lines, initial-reset snapshot) plus an ADDITIVE active_managed_by column on lovata_shopaholic_offers; UNIQUE(invoice_number) gives DB-layer idempotency, EAN-as-string(13) preserves leading zeros, and a grep-gated zero-row-mutation guarantee mitigates the threat of corrupting Shopaholic-owned data.**

## Performance

- **Duration:** ~4 min (238s wall clock)
- **Started:** 2026-04-29T14:50:26Z
- **Completed:** 2026-04-29T14:54:24Z
- **Tasks:** 5
- **Files created:** 4 (all under `updates/`)
- **Files modified:** 1 (`updates/version.yaml`)

## Accomplishments

- 3 plugin tables created with InnoDB engine and idempotent Schema::hasTable guards: `logingrupa_goods_received_invoices`, `logingrupa_goods_received_invoice_lines`, `logingrupa_goods_received_initial_reset_snapshot`.
- DB-layer idempotency contract enforced: `invoice_number string(64)->unique()` rejects duplicate uploads at the engine layer, before application logic runs (success criterion 5 satisfied).
- 17 columns on the invoice header table covering audit lifecycle (parsed → applied), counters (total/matched/unmatched lines, stock_added_units), provenance (applied_by_user_id, parsed_at, applied_at), and override chain (override_of_invoice_id self-FK with nullOnDelete).
- 15 columns on the invoice lines table including EAN-as-string(13) (D-05 — preserves leading zeros), unsignedInteger qty (D-06), decimal(12,4) audit-only unit_price (D-07), match_strategy string(32) default 'none' (D-08), and 6 indexes for filter/lookup speed.
- 7 columns on the initial-reset snapshot table — write-once with single `created_at` (no `updated_at`, no `timestamps()`).
- ADDITIVE column-add `lovata_shopaholic_offers.active_managed_by` (string(16), default 'system'): zero row-mutation tokens in the file (`UPDATE`, `DB::statement`, `Offer::`, `->update(`, `->save(` all absent — threat-mitigation grep gate passes).
- `updates/version.yaml` 1.0.1 block registers all 4 migrations in dependency order; Symfony YAML parser confirms the file parses correctly.
- All 4 migration files pass `php -l` syntax check.
- Cross-driver portability honored: zero native ENUM columns; status, match_strategy, and active_managed_by all stored as `string(N)` for SQLite test compatibility.
- No `declare(strict_types=1);` in migration files — matches Lovata `updates/` convention; confirmed `rector.php` skips `__DIR__ . '/updates'`.

## Task Commits

Each task committed atomically (per task, not per file):

1. **Task 1: Create invoice header table migration** — `6657fa7` (feat)
2. **Task 2: Create invoice lines table migration** — `9aed278` (feat)
3. **Task 3: Create initial-reset snapshot table migration** — `c874e86` (feat)
4. **Task 4: Create ADDITIVE column-add migration on lovata_shopaholic_offers** — `a4e59a8` (feat)
5. **Task 5: Register migrations in version.yaml under 1.0.1** — `d155fda` (feat)

_Plan-metadata commit (this SUMMARY.md) follows separately._

## Files Created/Modified

- `updates/create_logingrupa_goods_received_invoices_table.php` (created) — Header table; 17 columns; UNIQUE(invoice_number); 4 indexes; self-FK override_of_invoice_id with nullOnDelete; status/initial_reset_applied/notes columns; 67 lines.
- `updates/create_logingrupa_goods_received_invoice_lines_table.php` (created) — Lines table; 15 columns; ean string(13) indexed; qty unsignedInteger; unit_price decimal(12,4) nullable; match_strategy string(32); FK invoice_id cascadeOnDelete; 67 lines.
- `updates/create_logingrupa_goods_received_initial_reset_snapshot_table.php` (created) — Snapshot table; 7 columns; write-once `created_at` only; FK invoice_id cascadeOnDelete; 56 lines.
- `updates/update_lovata_shopaholic_offers_add_active_managed_by.php` (created) — ADDITIVE column-add on Shopaholic-owned `lovata_shopaholic_offers`; string(16) default 'system' after `active`; index on column; idempotent up/down via Schema::hasTable + Schema::hasColumn; threat-mitigation gate passes; 63 lines.
- `updates/version.yaml` (modified, +6 lines) — Existing 1.0.0 block preserved unchanged; new 1.0.1 block registers 4 migrations in dependency order (invoices → invoice_lines → initial_reset_snapshot → offers extension).

## Verification Results

End-of-plan verification block (`<verification>` from PLAN.md) ran cleanly:

| Check | Result |
|------|--------|
| `php -l` on all 4 migrations | All "No syntax errors detected" |
| `updates/*.php` file presence | 4 new files present, version.yaml updated |
| `grep -c "1.0.1" updates/version.yaml` | 1 (>= 1 required) |
| `grep -c "\.php$" updates/version.yaml` | 4 migration filenames listed |
| Threat-mitigation grep gate on offers extension | Clean — zero matches for `(UPDATE\|DB::statement\|Offer::\|->update(\|->save()` |
| EAN-as-string check | `string('ean', 13)` present in lines migration |
| UNIQUE on invoice_number | `string('invoice_number', 64)->unique()` present in header migration |
| Self-FK uses `nullOnDelete()` (NOT cascade) | Confirmed in header migration |
| `cascadeOnDelete()` on lines + snapshot FKs | Confirmed in both child migrations |
| No `declare(strict_types=1);` in `updates/` | Confirmed — matches Lovata convention |
| Symfony YAML parse of version.yaml | Successful — both 1.0.0 and 1.0.1 keys parsed as strings |

## Threat Model Coverage

The plan's `<threat_model>` (T-01-01..06) was honored throughout:

| Threat ID | Disposition | How Mitigated |
|-----------|-------------|---------------|
| T-01-01 (Tampering on offers table) | mitigate | ADDITIVE-only constraint enforced by file-level grep gate; column default backfills existing rows at storage-engine layer; zero application-code row mutation in the migration. Docblock was reworded to remove literal forbidden tokens so the gate passes against the entire file. |
| T-01-02 (DoS via october:up) | accept | All migrations short-circuit via `Schema::hasTable` / `Schema::hasColumn` — replayable as no-op. October's transaction wrapping handles failure rollback. |
| T-01-03 (Information disclosure) | accept | No PII columns; `applied_by_user_id` is a backend-only FK to `backend_users`; no new unauthenticated attack surface. |
| T-01-04 (Repudiation) | mitigate | UNIQUE(invoice_number) blocks silent duplicate-apply; `applied_by_user_id` + `applied_at` + `parsed_at` + `override_of_invoice_id` + snapshot table form a non-repudiable audit chain. |
| T-01-05 (Privilege elevation via override chain) | mitigate | `override_of_invoice_id` self-FK uses `nullOnDelete()` not cascade — adversarial chained deletion cannot erase override history. |
| T-01-06 (Spoofing via EAN integer truncation) | mitigate | EAN stored as `string(13)` not integer — leading zeros preserved; barcode-collision attack via integer narrowing is structurally impossible. |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Reworded threat-mitigation docblock to pass grep gate**
- **Found during:** Task 4 acceptance verification.
- **Issue:** Task 4 acceptance criterion #6 (`! grep -E '(UPDATE|DB::statement|Offer::|->update\(|->save\()' <file>` — exit 0 means none found) failed because the file's `THREAT MITIGATION` docblock contained a sentence using the literal tokens "UPDATE" and "DB::statement" to *describe* what was forbidden. The grep matches the entire file, not just code lines, so the docblock matched. The criterion's intent is "no row-mutation calls"; the docblock was a description, not a call.
- **Fix:** Reworded the docblock to use natural-language phrasing ("No row-mutation statements, no raw SQL writes, no Eloquent saves are permitted in this file") that conveys the same prohibition without using the literal forbidden token strings. Code body unchanged. Re-ran the grep gate — clean.
- **Files modified:** `updates/update_lovata_shopaholic_offers_add_active_managed_by.php` (docblock only)
- **Commit:** `a4e59a8` (Task 4 commit; rewording happened pre-commit, so it ships in the same commit)
- **Rule rationale:** Rule 3 (blocking) — the gate exists to catch dangerous code patterns; rewording the docblock satisfies the gate's letter and spirit without altering code semantics. No architectural change.

### Out-of-scope discoveries

None.

## Known Stubs

None. All 4 migration files are complete schema definitions with no placeholders, TODOs, or deferred-content markers. The schema is the deliverable.

## Self-Check: PASSED

**Files claimed as created — all confirmed present on disk:**
- `updates/create_logingrupa_goods_received_invoices_table.php` — FOUND
- `updates/create_logingrupa_goods_received_invoice_lines_table.php` — FOUND
- `updates/create_logingrupa_goods_received_initial_reset_snapshot_table.php` — FOUND
- `updates/update_lovata_shopaholic_offers_add_active_managed_by.php` — FOUND

**Commits claimed — all confirmed in git log:**
- `6657fa7` (Task 1) — FOUND
- `9aed278` (Task 2) — FOUND
- `c874e86` (Task 3) — FOUND
- `a4e59a8` (Task 4) — FOUND
- `d155fda` (Task 5) — FOUND

(Self-check verification commands executed in the wrap-up step of this plan; results inlined here.)
