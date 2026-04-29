---
phase: 1
phase_name: Schema, Scaffold, Settings, Permissions
verified_date: 2026-04-29
status: human_needed
must_haves_score: 38/38
roadmap_success_criteria: 5/5 verified (operator confirmation pending for backend UI surface)
overrides_applied: 0
human_verification:
  - test: "Run `php artisan october:up` on a clean DB and confirm 3 plugin tables created + active_managed_by column added to lovata_shopaholic_offers with default 'system'"
    expected: "All 4 migrations execute idempotently; re-run is a no-op"
    why_human: "Migrations were not executed during verification; SQLite-in-memory tests do not exercise the migration runner end-to-end (autoMigrate=false in test base)"
  - test: "Open Backend → Settings and confirm a 'Goods Received' entry appears under the Shopaholic settings tab, exposing 4 toggles (enabled, auto_deactivate_on_zero, auto_activate_on_stock, allow_initial_reset)"
    expected: "Page loads, toggles render via switch widget, defaults are OFF, lang labels resolve in EN"
    why_human: "Backend YAML form widget rendering requires October backend boot; static analysis confirms registration shape and lang-key wiring but cannot confirm the rendered DOM"
  - test: "Open Backend → Settings → Administrators → Roles and confirm 4 permissions appear under the 'Goods Received' tab: upload_invoices, apply_invoices, override_invoices, run_initial_reset"
    expected: "All 4 entries visible, grouped under one tab heading, with comment text rendered from lang keys"
    why_human: "registerPermissions() is statically correct but UI-surface verification requires a live backend session"
  - test: "On nailscosmetics.no/.lv/.lt, change a toggle value on one site; confirm the same toggle on the other two sites is unchanged"
    expected: "Per-server DB isolation: each site stores its own row, no cross-site bleed"
    why_human: "Multisite isolation across separate physical servers cannot be exercised by SQLite-in-memory unit tests; QA-07 trait/scope presence is asserted, but cross-server isolation is operational"
---

# Phase 1: Schema, Scaffold, Settings, Permissions — Verification Report

**Phase Goal:** Lock the persistence contract, Settings model with Multisite isolation, split permissions, lang scaffold, and hermetic test fixtures so every downstream service has a stable foundation.
**Verified:** 2026-04-29
**Status:** human_needed
**Re-verification:** No — initial verification

## Summary

Phase 1 ships a complete and well-structured foundation: 4 migrations (3 plugin tables + 1 ADDITIVE column on `lovata_shopaholic_offers`), 4 Eloquent models with PHPStan-level-10-clean docblocks, 4 lang files (EN populated; lv/no/ru byte-identical EN stubs awaiting Phase 5/OPS-04), Settings + fields.yaml + registerSettings(), 4 split permissions, 3 hermetic HTM fixtures, an extended test base with `flushPluginSingletons()` lifecycle hook, and 12 passing tests. The full QA gate runs green: `phpstan analyse --level=10` reports 0 errors, `pint --test` passes, all 12 Pest tests pass, `phpstan-baseline.neon` is locked at 33 bytes (no waivers added). One intentional, fully-documented deviation from REQUIREMENTS.md SCHEMA-06/D15 was made: the literal class names cited in REQUIREMENTS.md (`Lovata\Toolbox\Classes\Interfaces\MultisiteInterface`, `Lovata\Toolbox\Traits\Helpers\MultisiteHelperTrait`) do not exist in the codebase; the implementation uses `October\Rain\Database\Traits\Multisite` — the same trait `Lovata\Toolbox\Models\CommonSettings` itself uses internally for Settings multisite isolation, satisfying D15's spirit (extend `SettingModel` directly + deliver per-site isolation). The deviation is captured in plan 01-05 narrative and 01-05-SUMMARY.md `key-decisions`. All static and automated criteria are satisfied; status is `human_needed` only because runtime confirmation of backend UI rendering, multi-server multisite isolation, and live migration execution cannot be exercised by unit tests.

## Success Criteria

### SC-1: Migrations create 3 plugin tables + active_managed_by column on offers

**Status:** PASS

Evidence:
- `updates/create_logingrupa_goods_received_invoices_table.php` line 31: `Schema::create('logingrupa_goods_received_invoices', ...)` with InnoDB engine, idempotent `Schema::hasTable` guard, full column list per SCHEMA-01.
- `updates/create_logingrupa_goods_received_invoice_lines_table.php` line 32: `Schema::create('logingrupa_goods_received_invoice_lines', ...)` with all SCHEMA-02 columns, `cascadeOnDelete()` FK to invoices.
- `updates/create_logingrupa_goods_received_initial_reset_snapshot_table.php` line 31: `Schema::create('logingrupa_goods_received_initial_reset_snapshot', ...)` with SCHEMA-03 columns, `$timestamps = false` story (only `created_at` column).
- `updates/update_lovata_shopaholic_offers_add_active_managed_by.php` line 42-43: `Schema::table(self::TABLE_NAME, ...)` adds `active_managed_by` `string(16)` with `default('system')` after `active`. ADDITIVE-only — no row mutation, no UPDATE statements.
- `updates/version.yaml` 1.0.1 block lists all 4 migrations in dependency order: invoices → invoice_lines → initial_reset_snapshot → update_offers.
- `php -l` clean on all 4 files. Class names match filenames (October Rain v4 requirement).

Note: Migration execution itself is a human verification item — see `human_verification[0]`.

### SC-2: Settings page exposes 4 toggles, persists per-site

**Status:** PASS

Evidence:
- `models/Settings.php` line 26-46: `class Settings extends SettingModel` with `use Multisite;`, `SETTINGS_CODE = 'logingrupa_goodsreceivedshopaholic_settings'`, `$settingsFields = 'fields.yaml'`, `protected $propagatable = []`.
- `models/settings/fields.yaml` lines 6-29: 4 fields, all `type: switch`, all `default: false`. Python YAML parse confirms structure: `len(fields)==4, all switch, all default false`.
- `Plugin.php` line 98-110: `registerSettings()` returns 1 entry under category `lovata.shopaholic::lang.tab.settings`, class `Settings::class`, `#[\Override]` attribute present.
- `tests/unit/Models/SettingsIsMultisiteAwareTest.php`: 5 PASS — verifies trait usage, empty `$propagatable`, `extends SettingModel`, SETTINGS_CODE constant, fields.yaml binding.
- `tests/unit/Models/MultisiteContextSwitchClearsCacheTest.php`: 3 PASS — verifies `MultisiteScope` registered as global query scope, `initializeMultisite` boots, `Settings::instance()` memoizes per process.
- Lang keys for all 4 fields and their `*_comment` siblings exist in `lang/en/lang.php` lines 12-21.

**Documented deviation (intentional, accepted):** D15 cites a `MultisiteInterface` and `MultisiteHelperTrait` from `Lovata\Toolbox`. Codebase verification (during plan 01-05) showed those literal class names do not exist. The implementation uses `October\Rain\Database\Traits\Multisite` — the trait that `Lovata\Toolbox\Models\CommonSettings` itself uses internally for Settings multisite isolation. D15's spirit (extend `SettingModel` directly + deliver per-site isolation, do NOT inherit from `CommonSettings`) is satisfied. Documented in plan 01-05 `<objective>` and 01-05-SUMMARY.md `key-decisions`.

Backend UI rendering itself is a human verification item — see `human_verification[1]` and `human_verification[3]`.

### SC-3: 4 split permissions in Roles UI, gate BackendAuth::userHasAccess

**Status:** PASS (static)

Evidence:
- `Plugin.php` line 60-88: `registerPermissions()` returns 4 entries with codes `logingrupa.goodsreceived.upload_invoices`, `logingrupa.goodsreceived.apply_invoices`, `logingrupa.goodsreceived.override_invoices`, `logingrupa.goodsreceived.run_initial_reset`. Each has `label`, `tab`, `comment`, `order`. `#[\Override]` attribute present.
- All label/comment/tab references resolve to `lang/en/lang.php` `permission.*` keys (lines 26-36).
- All 4 permissions share one tab (`logingrupa.goodsreceivedshopaholic::lang.permission.tab` → "Goods Received") so they group together.
- `php -l Plugin.php` clean.

UI surface verification (Roles picker rendering) is a human verification item — see `human_verification[2]`.

### SC-4: tearDown() flushes model events + plugin singletons

**Status:** PASS

Evidence:
- `tests/GoodsReceivedTestCase.php` lines 58-64: `tearDown()` calls `$this->flushModelEventListeners()`, then `$this->flushPluginSingletons()`, then `parent::tearDown()`. Order is correct (flush before parent teardown).
- `tests/GoodsReceivedTestCase.php` lines 100-103: `flushPluginSingletons(): void` exists, `protected`, body intentionally empty (Phase 1 contract per D-22 — singletons arrive in Phase 2/3).
- `tests/unit/TearDownFlushesSingletonsTest.php`: 4 PASS — asserts method exists/protected/void, called from tearDown, called BEFORE parent::tearDown via strpos, body is empty (pinning the Phase-1 contract; Phase 2 will fail this test by design as a refactor signal).

Parallel-execution non-flakiness (the literal "no order-dependent failures with `--parallel`" claim) is provable only by executing under `--parallel`. The lifecycle structure is correct; cross-test bleed via singleton state is impossible because no singletons exist yet (the hook is wired but empty). When Phase 2/3 add singletons, they plug into the existing hook.

### SC-5: EAN STRING(13); invoice_number UNIQUE

**Status:** PASS

Evidence:
- `updates/create_logingrupa_goods_received_invoices_table.php` line 34: `$obTable->string('invoice_number', 64)->unique();` — UNIQUE constraint at DB layer per D-04.
- `updates/create_logingrupa_goods_received_invoice_lines_table.php` line 37: `$obTable->string('ean', 13);` — STRING storage preserving leading zeros per D-05. Index added line 51.
- `models/InvoiceLine.php` line 62: validation rule `'ean' => 'required|string|max:13'`.
- `models/Invoice.php` line 69: validation rule `'invoice_number' => 'required|string|max:64'`.

## Must-Haves Coverage

Aggregated from all 8 plan frontmatter `must_haves.truths` blocks (38 distinct truths).

| Plan | Truths | Verified | Notes |
|------|--------|---------:|-------|
| 01-01 | 5 | 5/5 | All artifacts present; idempotent guards in `up()` and `down()`; FK cascades correct; UNIQUE enforced |
| 01-02 | 7 | 7/7 | 3 models exist, instantiable, with full @property docblocks; Validation trait + relations present; phpstan level 10 clean |
| 01-03 | 6 | 6/6 | 4 lang files md5-identical (`0a60d4650fb962977cafd023b01cfe0a`); EN populated with all required blocks |
| 01-04 | 5 | 5/5 | 3 fixtures cmp-identical to production source; .gitkeep present |
| 01-05 | 5 | 5/5 | Settings with Multisite trait; 4 switches default OFF; registerSettings under Shopaholic category — Backend UI rendering deferred to human verification |
| 01-06 | 5 | 5/5 | 4 split permissions registered; lang-key wiring verified — UI surface deferred to human verification |
| 01-07 | 6 | 6/6 | flushPluginSingletons hook in place; 12/12 Pest tests pass |
| 01-08 | 7 | 7/7 | `make all` green; phpstan-baseline.neon byte-identical (33 bytes); 0 waivers added |
| **Total** | **38** | **38/38** | |

## Requirements Coverage

| REQ-ID | Description | Status | Evidence |
|--------|-------------|--------|----------|
| SCHEMA-01 | Invoice header migration with UNIQUE invoice_number | SATISFIED | `updates/create_logingrupa_goods_received_invoices_table.php` matches column list/types/indexes/UNIQUE |
| SCHEMA-02 | Invoice lines migration with EAN string(13), match_strategy, FK cascade | SATISFIED | `updates/create_logingrupa_goods_received_invoice_lines_table.php` has all columns and `cascadeOnDelete()` FK |
| SCHEMA-03 | Initial reset snapshot migration with full prior-state capture | SATISFIED | `updates/create_logingrupa_goods_received_initial_reset_snapshot_table.php` captures offer + product prior state |
| SCHEMA-04 | ADDITIVE column-add `active_managed_by` enum on offers | SATISFIED | `updates/update_lovata_shopaholic_offers_add_active_managed_by.php` adds `string(16)` default `'system'`; ADDITIVE — no row writes |
| SCHEMA-05 | Eloquent models for Invoice, InvoiceLine, InitialResetSnapshot with @property + Validation + relations | SATISFIED | All 3 models in `models/` with full docblocks; phpstan level 10 clean |
| SCHEMA-06 | Settings extends SettingModel, multisite, fields.yaml with 4 switches | SATISFIED with documented deviation | Uses `October\Rain\Database\Traits\Multisite` (the trait `CommonSettings` itself uses) instead of REQUIREMENTS.md's literal `MultisiteInterface`/`MultisiteHelperTrait` because those literal class names do not exist in the codebase. D15's spirit (extend SettingModel direct, deliver per-site isolation, do NOT inherit from CommonSettings) is fully satisfied. Deviation explicitly reasoned in plan 01-05 |
| SCHEMA-07 | 4 split backend permissions registered | SATISFIED | `Plugin::registerPermissions()` returns the 4 codes per spec |
| SCHEMA-08 | Lang scaffold en/lv/no/ru with stub keys | SATISFIED | All 4 files md5-identical with all required blocks (plugin/settings/field/menu/permission/exception/validation) |
| QA-07 | IsMultisiteAwareTest + MultisiteContextSwitchClearsCacheTest | SATISFIED | Both tests exist, all 8 it() blocks pass |
| QA-11 | tearDown flushes model events + singletons | SATISFIED | `flushPluginSingletons()` hook wired before `parent::tearDown()`; `TearDownFlushesSingletonsTest` (4 it()) all pass |

## Decisions Coverage (D-01..D-22)

Spot-check of context-defined implementation decisions; all 22 traced to artifact:

| ID | Decision | Honored? | Evidence |
|----|----------|----------|----------|
| D-01 | Migration filenames use `create_<table>_table.php` | Yes | 3 create_*_table.php files present |
| D-02 | Column-add migration uses `update_<table>_add_<column>.php` | Yes | `update_lovata_shopaholic_offers_add_active_managed_by.php` |
| D-03 | All 4 migrations under version 1.0.1 | Yes | `version.yaml` 1.0.1 block lists exactly the 4 files |
| D-04 | invoice_number string(64) UNIQUE | Yes | line 34 of invoices migration |
| D-05 | EAN string(13), index | Yes | line 37 + 51 of lines migration |
| D-06 | qty unsignedInteger | Yes | line 39 of lines migration |
| D-07 | unit_price decimal(12,4) nullable | Yes | line 40 of lines migration |
| D-08 | match_strategy string(32) | Yes | line 43 of lines migration |
| D-09 | status string(32) | Yes | line 39 of invoices migration |
| D-10 | active_managed_by string(16) default 'system' | Yes | line 43 of update_offers migration |
| D-11 | override_of_invoice_id nullable, self-FK nullOnDelete | Yes | line 48 + 57-59 of invoices migration |
| D-12 | cascadeOnDelete only invoice→lines and invoice→snapshot; no FKs to upstream offer/product | Yes | inspected; invoice_id FKs cascade; matched_offer_id/matched_product_id are bare integers |
| D-13 | Settings extends SettingModel directly | Yes | `Settings.php` line 26 — extends `SettingModel`, not `CommonSettings` |
| D-14 | 4 switches in fields.yaml, all default OFF, lang keys | Yes | fields.yaml verified by Python YAML parse |
| D-15 | registerSettings under Shopaholic category | Yes | `Plugin.php` line 104 — `'category' => 'lovata.shopaholic::lang.tab.settings'` |
| D-16 | 4 permissions under one tab | Yes | `Plugin.php` registerPermissions() — all 4 share `permission.tab` key |
| D-17 | Each permission has lang label | Yes | `lang/en/lang.php` permission.* block (lines 26-36) |
| D-18 | EN extends with permission/exception/validation; lv/no/ru EN-stubs | Yes | All 4 files md5-identical; all 7 blocks present |
| D-19 | Translations deferred to OPS-04 | Yes | Captured in summaries; lv/no/ru hold EN values |
| D-20 | 3 fixtures spanning 18-month timeline | Yes | 2024-11-28, 2025-07-09, 2026-04-13 fixture files present |
| D-21 | Tests are HERMETIC | Yes | Fixtures live under `tests/fixtures/invoices/`; test code references this path only |
| D-22 | flushPluginSingletons hook empty in Phase 1 | Yes | `GoodsReceivedTestCase.php` line 100-103; pinned by `TearDownFlushesSingletonsTest` |

**Note on D-15 vs SCHEMA-06 D15 (decision letter collision):** CONTEXT.md uses D-13/D-14/D-15 to refer to local Phase-1 implementation decisions; REQUIREMENTS.md uses D11-D15 for project-wide locked decisions. These are distinct numberings and both are honored. The Settings reconciliation note above addresses the project-wide D15.

## Anti-Patterns Found

None of consequence. Spot-checked Phase 1 source files for TODO/FIXME/placeholder strings, empty handlers, console-log-only implementations, or hardcoded empties that flow to user-visible output. The `flushPluginSingletons()` empty body in `tests/GoodsReceivedTestCase.php` is intentional and pinned by a test (`TearDownFlushesSingletonsTest`); it is documented as a Phase-1 placeholder per D-22. Not a stub — the contract is explicit and tested.

## Behavioral Spot-Checks (Step 7b)

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Pest test suite passes for Phase 1 | `vendor/bin/pest tests/unit --no-coverage` | 12 passed (23 assertions) | PASS |
| PHPStan level 10 clean | `vendor/bin/phpstan analyse --configuration=plugins/.../phpstan.neon` | `[OK] No errors` | PASS |
| Pint PSR-12 clean | `vendor/bin/pint plugins/.../ --config=plugins/.../pint.json --test` | `{"result":"pass"}` | PASS |
| `phpstan-baseline.neon` is the locked 33-byte sentinel | `wc -c phpstan-baseline.neon` | `33 phpstan-baseline.neon` | PASS |
| All produced PHP files lint clean | `php -l` over 12 files | All clean | PASS |
| fields.yaml structurally correct | Python yaml.safe_load + assertions | 4 switches, all default false | PASS |
| version.yaml registers all 4 migrations under 1.0.1 | Python yaml parse | 1.0.1 block lists 4 files in correct order | PASS |
| 3 hermetic fixtures byte-identical to production source | `cmp` against `storage/app/uploads/invoices/Nr_PRO*.HTM` | All 3 silent (identical) | PASS |
| 4 lang files structurally identical | `md5sum` | All 4 = `0a60d4650fb962977cafd023b01cfe0a` | PASS |
| Migration class names match filenames | `grep ^class updates/*.php` | All 4 PascalCase classnames map to snake_case filenames | PASS |
| Last 8 commits in git log map to plans 01-05..01-08 | `git log --oneline -20` | Confirmed: feat(01-05) → docs(01-08) sequence with QA cleanup commits | PASS |

## Gaps

None blocking goal achievement. The phase is materially complete and the QA gate is green.

One narrative item worth highlighting (NOT a gap):
- **REQUIREMENTS.md SCHEMA-06 wording vs implementation:** REQUIREMENTS.md says "implements MultisiteInterface + uses MultisiteHelperTrait." The actual implementation uses `October\Rain\Database\Traits\Multisite` because the literal Toolbox classes named in REQUIREMENTS.md do not exist in the codebase. This was recognized and reasoned during planning (plan 01-05 `<objective>` paragraph), accepted as a "paper artifact correction" rather than a scope reduction, validated by tests, and documented in 01-05-SUMMARY.md `key-decisions`. If product wants the literal class names enforced, they need to be created upstream in Lovata.Toolbox first, which is out of this plugin's scope. No action requested; flagging for transparency.

## Human Verification Required

The 4 items in frontmatter `human_verification` are operational checks that cannot be programmatically verified from a unit-test environment:

1. **Run migrations on a real DB.** SQLite-in-memory test base has `autoMigrate=false`; the migrations have idempotent guards but their actual DDL execution against MySQL has not been observed during this verification.

2. **Confirm Backend → Settings → Goods Received page renders.** The lang/YAML/registration plumbing is statically correct; only a backend session can confirm October's form widget renders 4 toggles correctly with EN labels resolved.

3. **Confirm Backend → Roles tab shows the 4 grouped permissions.** Static evidence is complete; UI surface needs eyes.

4. **Cross-server multisite isolation smoke.** SQLite-in-memory cannot replicate the .no/.lv/.lt physical-server-and-DB topology; per-site settings isolation across the three production hosts must be confirmed manually (this is also formally listed as a Phase 5 OPS-06 acceptance criterion).

These items do NOT block Phase 2 from starting — Phase 2 is parser/matcher work that does not exercise the backend UI or migration runner. The four human checks should be folded into the Phase 5 multi-site smoke runbook (OPS-06) where they will be performed naturally as part of release acceptance.

---

*Verified: 2026-04-29*
*Verifier: Claude (gsd-verifier)*
