# Phase 1: Schema, Scaffold, Settings, Permissions - Context

**Gathered:** 2026-04-29
**Status:** Ready for planning
**Mode:** Auto (smart-discuss `--auto`) — infrastructure phase with locked requirements

<domain>
## Phase Boundary

Lock the persistence contract, Settings model with Multisite isolation, split permissions, lang scaffold, and hermetic test fixtures so every downstream service has a stable foundation.

**In scope:**
- Migrations creating `logingrupa_goods_received_invoices`, `logingrupa_goods_received_invoice_lines`, `logingrupa_goods_received_initial_reset_snapshot`
- Migration extending `lovata_shopaholic_offers` with `active_managed_by` enum column
- Eloquent models: `Invoice`, `InvoiceLine`, `InitialResetSnapshot`, `Settings`
- Backend permissions registered (4 split: upload/apply/override/run_initial_reset)
- Settings YAML + multisite interface
- `lang/{en,lv,no,ru}/lang.php` scaffolded with stub keys (full populate deferred to OPS-04)
- 3 hermetic HTM fixtures copied to `tests/fixtures/invoices/`
- `GoodsReceivedTestCase::tearDown()` hardening (singleton flush + model event flush)

**Out of scope:**
- Parser, matcher, apply services (Phase 2/3)
- Backend controller, upload UI, console command (Phase 4)
- Full lang translations (OPS-04, Phase 5)

</domain>

<spec_lock>
## Locked Requirements (REQUIREMENTS.md)

Phase 1 reqs are already specified in `<project_root>/.planning/REQUIREMENTS.md` and CANNOT be re-litigated:

- **SCHEMA-01..08** — Full column lists + types + indexes + permission keys (lines 22-29)
- **QA-07** — `IsMultisiteAwareTest`, `MultisiteContextSwitchClearsCacheTest`
- **QA-11** — `tearDown()` flushes model event listeners + every singleton's `flush()`

**Locked decisions D11-D15** (PROJECT.md Key Decisions, REQUIREMENTS.md preamble):
- **D11** — GitHub repo: PUBLIC
- **D12** — Override-and-reimport = ADD-ON-TOP semantics (no diff, no `content_hash`, no decrement)
- **D13** — GRN owns `offer.quantity`; user disables 1C XML qty import out-of-band
- **D14** — Vendor-inline `ImportAuditService`; NO soft-dep on ExtendShopaholic
- **D15** — `Settings` extends `System\Models\SettingModel` directly + manually implements `MultisiteInterface` + `MultisiteHelperTrait` (NOT `Lovata\Toolbox\Models\CommonSettings`)

</spec_lock>

<decisions>
## Implementation Decisions

### Migration File Naming (October convention)
- **D-01:** New tables use `create_<table_name>_table.php` (October convention).
  - `updates/create_logingrupa_goods_received_invoices_table.php`
  - `updates/create_logingrupa_goods_received_invoice_lines_table.php`
  - `updates/create_logingrupa_goods_received_initial_reset_snapshot_table.php`
- **D-02:** Column-add migration uses `update_<table>_add_<column>.php` (project convention seen in `plugins/lovata/shopaholic/updates/`).
  - `updates/update_lovata_shopaholic_offers_add_active_managed_by.php`
- **D-03:** All four migration files registered in `updates/version.yaml` under version `1.0.1` (current `1.0.0` is the scaffold initialization).

### Schema Types & Indexes
- **D-04:** `Invoice.invoice_number`: `string(64)` UNIQUE INDEX. Sufficient for `Nr_PRO<num>_<country>_<DDMMYYYY>` plus override suffix room. UNIQUE enforces idempotency at DB layer (per success criterion 5).
- **D-05:** `InvoiceLine.ean`: `string(13)` (NOT integer/bigInteger). Preserves leading zeros. Driver maps to VARCHAR(13) on MySQL, TEXT on SQLite. Index added on `ean` column for batch lookup speed.
- **D-06:** `InvoiceLine.qty`: `unsignedInteger`. Distributor delivery qty is always positive integer (decimal qty rejected by `QuantityNormalizer` in Phase 2 BEFORE reaching Eloquent — guards `setQuantityAttribute` silent int-clamp).
- **D-07:** `InvoiceLine.unit_price`: `decimal(12,4)` nullable. Audit-only, never written to stock. Same precision as Shopaholic core price columns.
- **D-08:** `InvoiceLine.match_strategy`: enum stored as `string(32)` with values `offer_code`, `product_code_single_offer`, `none`. October migration uses `string(32)` (cross-driver portable; SQLite has no native enum).
- **D-09:** `Invoice.status`: enum stored as `string(32)` with values `parsed`, `applied`, `failed`, `rejected_duplicate`. Same rationale.
- **D-10:** `lovata_shopaholic_offers.active_managed_by`: `string(16)` default `'system'` with values `system`, `operator`, `plugin`. Migration is ADDITIVE — never modifies existing rows; default applies to backfill via column default.
- **D-11:** `Invoice.override_of_invoice_id`: nullable `unsignedBigInteger`, FK to self with `nullOnDelete()` (don't cascade — keep override history).
- **D-12:** All FKs use `cascadeOnDelete()` ONLY for invoice → lines and invoice → snapshot relationships. NO cascade from offer/product to invoice_line (preserve audit history when offers/products deleted).

### Settings Model (per D15)
- **D-13:** `models/Settings.php` extends `System\Models\SettingModel` directly. Class declaration:
  ```php
  class Settings extends \System\Models\SettingModel implements \Lovata\Toolbox\Classes\Interfaces\MultisiteInterface
  {
      use \Lovata\Toolbox\Traits\Helpers\MultisiteHelperTrait;
      public $settingsCode = 'logingrupa_goodsreceivedshopaholic_settings';
      public $settingsFields = 'fields.yaml';
  }
  ```
- **D-14:** `models/settings/fields.yaml` defines 4 switches: `enabled`, `auto_deactivate_on_zero`, `auto_activate_on_stock`, `allow_initial_reset`. All defaults OFF. All labels via lang keys (`logingrupa.goodsreceivedshopaholic::lang.field.<key>`).
- **D-15:** Settings menu registered in `Plugin::registerSettings()` under `category` = `CATEGORY_SHOP` (Shopaholic group) so it lands beside other Lovata settings.

### Permissions (4 split per SCHEMA-07)
- **D-16:** `Plugin::registerPermissions()` returns 4 permissions under tab `logingrupa.goodsreceivedshopaholic::lang.plugin.name`:
  - `logingrupa.goodsreceived.upload_invoices` — Upload `.HTM` files
  - `logingrupa.goodsreceived.apply_invoices` — Run Apply (writes stock)
  - `logingrupa.goodsreceived.override_invoices` — Override-and-reimport duplicate (D12 flow)
  - `logingrupa.goodsreceived.run_initial_reset` — Trigger one-shot baseline reset
- **D-17:** Each permission has its label key in `lang/en/lang.php` under `permission.*`.

### Lang Scaffold (SCHEMA-08)
- **D-18:** Existing `lang/en/lang.php` nested structure preserved (`plugin/settings/field/menu`). Add new top-level subsections: `permission`, `exception`, `validation`. EN populated; lv/no/ru receive identical keys with EN values stubbed (not translated this phase).
- **D-19:** Translation work (full populate of lv/no/ru) deferred to Phase 5 (OPS-04).

### Test Fixtures (PARSE-07 prep)
- **D-20:** Copy 3 representative `.HTM` files from `<project_root>/storage/app/uploads/invoices/` to `tests/fixtures/invoices/`. Selection spans timeline:
  - `Nr_PRO026712_no_28112024.HTM` — earliest (2024-11-28)
  - `Nr_PRO029691_no_09072025.HTM` — mid-2025
  - `Nr_PRO033328_no_13042026.HTM` — latest (2026-04-13)
  Spread captures any format drift across the distributor's 18-month window.
- **D-21:** Tests are HERMETIC — parser tests in Phase 2 NEVER read outside `tests/`. The `<project_root>/storage/app/uploads/invoices/` directory is for production uploads only; never read by tests.

### Test Base (QA-11)
- **D-22:** `tests/GoodsReceivedTestCase::tearDown()` already flushes model event listeners (existing scaffold). Phase 1 EXTENDS it to also call each plugin singleton's `flush()` method. Singletons known at this phase: none (Stores arrive in Phase 2/3); add hook now so Phase 2/3 singletons just plug in. Implement as `flushPluginSingletons(): void` invoked from `tearDown()` BEFORE `parent::tearDown()`.

### Claude's Discretion
- Exact ordering of `Plugin::boot()` and `Plugin::register()` registrations
- Whether to use `string(32)` literally or a `Schema::enum` driver call (planner picks based on October Rain v4 API surface)
- Whether `MultisiteHelperTrait` requires additional `boot{TraitName}` magic — planner reads `Lovata\Toolbox\Traits\Helpers\MultisiteHelperTrait` source to confirm
- Migration ordering (e.g., create offers table extension migration before or after invoice tables — irrelevant since no FK from offers extension to invoices)
- PHPDoc `@property` block formatting style for PHPStan level 10 (verbose vs minimal — both pass)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Locked Specs
- `.planning/PROJECT.md` — Vision, engineering quality bar, architecture preview, D1-D10 locked decisions
- `.planning/REQUIREMENTS.md` — All 56 v1 reqs; SCHEMA-01..08 + QA-07 + QA-11 are this phase's contract; D11-D15 preamble
- `.planning/ROADMAP.md` — Phase 1 success criteria (5 items)
- `plugins/logingrupa/goodsreceivedshopaholic/CLAUDE.md` — Plugin-local conventions, Hungarian notation, test base, idempotency contract

### Project-Level Conventions
- `<project_root>/CLAUDE.md` — Hungarian notation, Tiger-Style rules, October CMS v4 patterns, namespace `Logingrupa` (not `LoginGrupa`)

### Reference Plugins (QA + structural standards)
- `plugins/logingrupa/postnordshippingshopaholic/` — QA reference standard (PHPStan level 10, Pest 4, Pint, PHPMD, Rector configured)
- `plugins/logingrupa/extendshopaholic/` — XML import patterns; useful for migration shape comparison only (we are NOT extending it per D14)

### Lovata Toolbox Multisite Plumbing
- `plugins/lovata/toolbox/classes/interfaces/MultisiteInterface.php` (or wherever it lives — planner verifies path)
- `plugins/lovata/toolbox/traits/helpers/MultisiteHelperTrait.php` (path TBD)
- October's `System\Models\SettingModel` source

### Migration Pattern References
- `plugins/lovata/shopaholic/updates/version.yaml` — version manifest format
- `plugins/lovata/shopaholic/updates/create_offers_table.php` — table creation idiom
- `plugins/lovata/shopaholic/models/Offer.php` — `quantity`, `code` field shapes (target of stock writes)

### Sample Data (production source — DO NOT read in tests)
- `<project_root>/storage/app/uploads/invoices/Nr_PRO*.HTM` (15 samples) — copy 3 to `tests/fixtures/invoices/` per D-20

### QA Tooling Configuration
- `plugins/logingrupa/goodsreceivedshopaholic/phpstan.neon` — level 10 config
- `plugins/logingrupa/goodsreceivedshopaholic/phpmd.xml` — Lovata thresholds
- `plugins/logingrupa/goodsreceivedshopaholic/pint.json` — PSR-12 + ordered imports
- `plugins/logingrupa/goodsreceivedshopaholic/Makefile` — `make all` gate

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `tests/GoodsReceivedTestCase.php` — base test case already exists with `flushModelEventListeners()`, October bootstrap path resolution, plugin code guess. Extend (don't replace) to add singleton flush hook (D-22).
- `lang/en/lang.php` — already scaffolded with `plugin/settings/field/menu` nested keys. Extend with `permission/exception/validation`.
- `Plugin.php` — minimal scaffold with `pluginDetails()` + empty `boot()`. Extend with `registerPermissions()`, `registerSettings()`, eventual `boot()` Event::subscribe() lines (no subscribers in Phase 1 — all are Phase 2+).
- `composer.json` — already declares `lovata/toolbox-plugin ^2.2` and `lovata/shopaholic-plugin ^1.32` deps. Sufficient for Phase 1.
- `phpstan.neon`, `phpmd.xml`, `pint.json`, `rector.php`, `phpunit.xml`, `Makefile` — all scaffolded by initial commit; QA gates run.

### Established Patterns (from project CLAUDE.md + reference plugins)
- **Hungarian notation everywhere** — `$obItem`, `$arList`, `$iCount`, `$sSlug`, `$bIsActive`, `$fPrice` (per project CLAUDE.md)
- **`declare(strict_types=1);`** at top of every PHP file (Rector enforces; phpstan level 10 catches)
- **`#[\Override]`** on parent overrides (already used in `Plugin.php`)
- **PSR-2 + Lovata exceptions** via `phpcs.xml` (project root); plugin uses Pint instead with PSR-12
- **Plugin namespace** `Logingrupa\GoodsReceivedShopaholic` (NOT `LoginGrupa`) — already correct in scaffold
- **Migration class name** matches filename (October Rain v4 requirement); `up()` and `down()` both implemented
- **Eloquent models** extend `\October\Rain\Database\Model` (or `\Model` aliased), use `Validation` trait
- **Plugin events** registered via `Event::subscribe(HandlerClass::class)` in `Plugin::boot()` (Phase 2+ — none here)

### Integration Points
- `version.yaml` registers all migrations in execution order
- `Plugin::registerPermissions()` exposed in Backend Users → Roles UI
- `Plugin::registerSettings()` adds entry under Settings → category
- `Settings::get()` calls (Phase 2+) — Phase 1 just provides the model; SettingsAccessor wrapper arrives in Phase 3 (APPLY-09)
- `lovata_shopaholic_offers.active_managed_by` consumed by `ActiveFlagService` in Phase 3 (APPLY-03)

</code_context>

<specifics>
## Specific Ideas

- **Reference plugin to mirror:** `plugins/logingrupa/postnordshippingshopaholic/` is the QA-bar reference. Mirror its directory structure, PHPStan/Pint config posture, test naming conventions.
- **Hermetic tests:** Per CLAUDE.md, "HTM parser tests: pin against `<project_root>/storage/app/uploads/invoices/Nr_PRO*.HTM` fixtures (copy to `tests/fixtures/` to keep tests hermetic)". Phase 1 PERFORMS the copy; Phase 2 USES the copies.
- **No subclassing upstream models** (project CLAUDE.md) — `lovata_shopaholic_offers` extension is via migration ADD COLUMN + `Plugin::boot()` `Model::extend()` if dynamic methods needed (Phase 3 territory).
- **Migration ADDITIVE** — column-add migration on `lovata_shopaholic_offers` MUST NOT touch any existing column or row. Default value `'system'` applies to backfill via column default; no `UPDATE` statement in the migration.

</specifics>

<deferred>
## Deferred Ideas

- **Full translation populate** of lv/no/ru lang files — Phase 5 (OPS-04)
- **`SettingsAccessor` wrapper class** + DRY grep gate — Phase 3 (APPLY-09 + QA-09)
- **`ImportAuditService` vendor-inline (~50-80 LoC)** — Phase 3 (APPLY-10)
- **Plugin boot self-check** for `max_file_uploads` / `upload_max_filesize` — Phase 4 (UI-12)
- **Console command `goodsreceived:recompute_active_from_stock`** — Phase 4 (UI-11)
- **Backend controller, list/form/relation behaviors** — Phase 4 (UI-01..10)
- **README + runbook** — Phase 5 (OPS-01)
- **PROJECT.md decisions table update D11-D15 outcomes** — Phase 5 (OPS-02)

</deferred>

---

*Phase: 01-schema-scaffold-settings-permissions*
*Context gathered: 2026-04-29 (autonomous mode)*
*Smart-discuss `--auto`: 22 decisions captured across 7 areas; 5 items at Claude's discretion*
