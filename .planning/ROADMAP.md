# Roadmap: GoodsReceivedShopaholic

**Milestone:** v1.0
**Created:** 2026-04-29
**Granularity:** coarse (5 phases)
**Total v1 requirements:** 56 (8 SCHEMA + 7 PARSE + 2 MATCH + 10 APPLY + 12 UI + 6 OPS + 11 QA)
**Coverage:** 56 / 56 mapped (100%)

## Overview

Five-phase journey from empty scaffold to a production-grade backend GRN import plugin. Phase 1 locks the persistence + permissions + Settings contract (everything else hangs off these). Phase 2 builds the pure, deterministic parse + match pipeline against real `.HTM` fixtures. Phase 3 implements all stock-mutating services + orchestrators with one transaction boundary, batched cache flush, and provenance-aware active-flag reconcile. Phase 4 ships the operator-facing backend controller, multi-file upload, preview/apply UX, override-and-reimport flow, and the reconcile console command. Phase 5 hardens for release: lang packs, README/runbook, multi-site smoke, public Composer publish, full `make all` green. QA requirements cross-cut: each is assigned to the phase where the production code under test ships.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Schema, Scaffold, Settings, Permissions** - Lock the persistence contract, Settings model with Multisite, 4 split permissions, lang scaffold, fixtures
- [x] **Phase 2: Pure Parsers, DTOs, Exceptions, EAN Matcher** - HTM string -> ParsedInvoice DTO; two-query EAN match; zero IO outside input
- [x] **Phase 3: Apply Layer + Orchestrators** - StockApply, ActiveFlag, InitialReset, ParseAndPersist + Apply orchestrators in one transaction with batched cache flush
- [ ] **Phase 4: Backend Controller, Upload/Preview/Apply UI, Console** - Operator-facing multi-file upload, preview, override-and-reimport, audit history, recompute console
- [ ] **Phase 5: Ops, Lang, Polish, Public Release** - Full lang packs, README + runbook, multi-site verification, public Composer publish, make all green

## Phase Details

### Phase 1: Schema, Scaffold, Settings, Permissions
**Goal**: Lock the persistence contract, Settings model with Multisite isolation, split permissions, lang scaffold, and hermetic test fixtures so every downstream service has a stable foundation
**Depends on**: Nothing (first phase)
**Requirements**: SCHEMA-01, SCHEMA-02, SCHEMA-03, SCHEMA-04, SCHEMA-05, SCHEMA-06, SCHEMA-07, SCHEMA-08, QA-07, QA-11
**Success Criteria** (what must be TRUE):
  1. `php artisan october:up` creates `logingrupa_goods_received_invoices`, `logingrupa_goods_received_invoice_lines`, `logingrupa_goods_received_initial_reset_snapshot` tables and adds `lovata_shopaholic_offers.active_managed_by` column with default `system`
  2. Backend Settings page at `Settings -> GoodsReceivedShopaholic` exposes 4 toggles (`enabled`, `auto_deactivate_on_zero`, `auto_activate_on_stock`, `allow_initial_reset`) and persists per-site (verified by `IsMultisiteAwareTest` and `MultisiteContextSwitchClearsCacheTest`)
  3. 4 split permissions (`upload_invoices`, `apply_invoices`, `override_invoices`, `run_initial_reset`) appear in Backend Users -> Roles UI and gate `BackendAuth::userHasAccess()`
  4. `GoodsReceivedTestCase::tearDown()` flushes model event listeners + every singleton's `flush()` so cross-test bleed is impossible (asserted by Pest run with `--parallel` exhibiting no order-dependent failures)
  5. EAN columns are STRING(13) preserving leading zeros; `Invoice.invoice_number` UNIQUE index enforces idempotency at DB layer
**Plans**: 8 plans
  - [x] 01-01-PLAN.md - Migrations: create 3 plugin tables + ADDITIVE column-add on lovata_shopaholic_offers + register in version.yaml 1.0.1
  - [x] 01-02-PLAN.md - Eloquent models: Invoice + InvoiceLine + InitialResetSnapshot (PHPStan level 10 @property blocks, Validation trait, relations)
  - [x] 01-03-PLAN.md - Lang scaffold: en/lv/no/ru lang.php files (extend EN with permission/exception/validation; lv/no/ru EN-stubs per D-19)
  - [x] 01-04-PLAN.md - Test fixtures: copy 3 representative HTM files from production uploads to tests/fixtures/invoices/ (hermetic per D-21)
  - [x] 01-05-PLAN.md - Settings model + fields.yaml + Plugin::registerSettings() (Multisite trait per D15 reconciliation)
  - [x] 01-06-PLAN.md - Plugin::registerPermissions() with 4 split permissions (upload/apply/override/run_initial_reset)
  - [x] 01-07-PLAN.md - Test base extension: flushPluginSingletons() hook + QA-07 multisite tests + QA-11 teardown lifecycle test
  - [x] 01-08-PLAN.md - Final QA gate: make all (pint-test + analyse + phpmd + test) green; phpstan-baseline.neon unchanged

### Phase 2: Pure Parsers, DTOs, Exceptions, EAN Matcher
**Goal**: Convert any real `.HTM` distributor file into a typed `ParsedInvoice` DTO and resolve every line's EAN to an offer (or null) deterministically, with zero side effects outside DB reads
**Depends on**: Phase 1
**Requirements**: PARSE-01, PARSE-02, PARSE-03, PARSE-04, PARSE-05, PARSE-06, PARSE-07, MATCH-01, MATCH-02, QA-01, QA-02
**Success Criteria** (what must be TRUE):
  1. `HtmInvoiceParser->parse(string)` correctly extracts every `<TR class="R20|R21">` row from all 3 hermetic fixtures in `tests/fixtures/invoices/` (handles UTF-8 BOM, CRLF, unquoted `CLASS=R20`, malformed input)
  2. `InvoiceNumberResolver` resolves invoice number from HTM body first; falls back to filename pattern `Nr_PRO<num>_<country>_<DDMMYYYY>.HTM`; throws `InvoiceNumberMissingException` when neither yields a number
  3. `QuantityNormalizer::parseQuantity('5,12')` throws `InvalidQuantityException` (decimal qty rejected BEFORE Eloquent's silent int-clamp); `PreservesLeadingZeroEanTest` confirms EAN string preservation
  4. `EanMatcherService::matchBatch($arEans)` issues exactly TWO DB queries (offer.code WHERE IN; product.code WHERE IN with single-offer guard) regardless of input size; returns `array<ean, MatchResult>`
  5. Unmatched EANs yield `match_strategy='none'` rows queryable via `WHERE matched_offer_id IS NULL`; partial-match invoices never throw
  6. All parser/matcher tests are hermetic (no reads outside `tests/`) and run green under PHPStan level 10

**Plans**: 7 plans
  - [x] 02-01-PLAN.md - DTOs: ParsedInvoice + ParsedLine + MatchedLine + ApplyResult (final readonly classes; locks Phase 2/3 data contract) *(closed 2026-04-29)*
  - [x] 02-02-PLAN.md - Exceptions: abstract GoodsReceivedException + 8 typed subclasses (polymorphic catch + log-injection-safe context) *(closed 2026-04-29)*
  - [x] 02-03-PLAN.md - Normalizers: QuantityNormalizer (rejects decimal qty BEFORE Eloquent int-clamp) + PriceNormalizer (audit-only) *(closed 2026-04-29)*
  - [x] 02-04-PLAN.md - InvoiceNumberResolver: body-marker regex + filename pattern fallback + throw on miss *(closed 2026-04-29)*
  - [x] 02-05-PLAN.md - HtmInvoiceParser: DOMDocument + XPath + BOM strip + LIBXML_NONET; 5 QA-01 real-fixture pin tests *(closed 2026-04-29)*
  - [x] 02-06-PLAN.md - EanMatcherService: exactly TWO queries (offer.code → product.code single-offer); QA-02 leading-zero EAN preservation *(closed 2026-04-29)*
  - [x] 02-07-PLAN.md - Phase 2 final QA gate: make all (pint-test + phpstan level 10 + phpmd + pest); baseline unchanged *(closed 2026-04-29 — fixed 3 pre-existing phpmd violations on ParsedLine.php at config level; baseline sha unchanged)*

### Phase 3: Apply Layer + Orchestrators
**Goal**: Apply parsed invoices to live stock idempotently inside a single DB transaction with provenance-aware active-flag reconcile, batched cache flush, and one-shot baseline reset; ALL Settings reads go through `SettingsAccessor`
**Depends on**: Phase 2
**Requirements**: APPLY-01, APPLY-02, APPLY-03, APPLY-04, APPLY-05, APPLY-06, APPLY-07, APPLY-08, APPLY-09, APPLY-10, QA-03, QA-04, QA-05, QA-06, QA-08, QA-09
**Success Criteria** (what must be TRUE):
  1. Applying a 200-line invoice triggers <= N batched cache flushes (NOT 1200+); asserted by `Apply200LinesTriggersBatchedFlushNotPerSaveTest` — proves `saveQuietly` + post-commit batched `OfferListStore` flush works
  2. Applying the same invoice twice succeeds the first time and throws `ApplyAlreadyDoneException` the second time with prior result returned; concurrent Apply clicks serialized via `Invoice::lockForUpdate()` (asserted by `LockForUpdateSerializesConcurrentApplyTest`)
  3. ActiveFlagService 4-cell matrix (deactivate-on-zero on/off x activate-on-stock on/off) plus `SkipsManuallyDeactivatedOfferTest` (operator-set offers untouched via `active_managed_by='operator'`) all green
  4. `InitialResetService` requires `allow_initial_reset=true` AND no prior reset; snapshots ALL offers + products to `goods_received_initial_reset_snapshot` BEFORE writing; runs chunked (`Offer::chunk(500)` + `saveQuietly`); restorable to exact prior state
  5. Override-and-reimport (D12) creates new `Invoice` row with `override_of_invoice_id` pointer and applies new lines ADDITIVELY on top of prior apply (asserted by `OverrideReimportAddsOnTopTest`)
  6. CI grep gate (`make lint:settings-accessor`) confirms `Settings::get(` appears ONLY in `classes/support/SettingsAccessor.php` — DRY enforced by `SettingsAccessorIsSoleConsumerOfSettingsGetTest`
  7. Partial failure inside ApplyOrchestrator transaction rolls back EVERYTHING (stock writes + line.applied flags + invoice.status); asserted by `PartialFailureRollsBackEverythingTest` and `ActiveFlagInsideSameTransactionAsStockApplyTest`

**Plans**: 8 plans
  - [x] 03-01-PLAN.md - SettingsAccessor + DRY grep gate (APPLY-09 + QA-09); hooks SettingsAccessor::flush into TestCase teardown *(closed 2026-04-29 — APPLY-09 + QA-09 closed; dual-gate enforcement (Makefile + Pest mirror); flushPluginSingletons() first body line populated)*
  - [x] 03-02-PLAN.md - ImportAuditService (~50-80 LoC vendor-inlined; APPLY-10 / D-14); 4 log methods (apply/parse/reject/initial_reset) *(closed 2026-04-29 — APPLY-10 closed; final class with 4 public log methods + 3 private helpers; uuid v7 correlation_id; 96 raw / 65 code lines within ≤100 LoC ceiling per D-04; 6 tests / 130 assertions; PHPStan L10 clean)*
  - [x] 03-03-PLAN.md - StockApplyService (saveQuietly + post-commit batched cache flush; APPLY-01 + APPLY-02 + QA-04 200-line cache-flush counter test) *(closed 2026-04-29 — APPLY-01 + APPLY-02 + QA-04 closed; final class StockApplyService with group-by-offer pre-pass + batched whereIn fetch + saveQuietly per UNIQUE offer; flushAffectedCaches public API for orchestrator post-commit batched flush; StockApplyOutcome final readonly carrier (D-29 tuple decision); leaf-singleton dispatch + phpstan-stubs/Singleton.stub for L10 typing without inline @var; 200-line apply 401 queries / 4 list-store flushes; 12 tests / 56 assertions; make all green: 118/463)*
  - [x] 03-04-PLAN.md - ActiveFlagService (provenance-aware reconcile + reconcileAll chunked; APPLY-03 + APPLY-04 + QA-05 4-cell matrix + SkipsManuallyDeactivated) *(closed 2026-04-29 — APPLY-03 + APPLY-04 + QA-05 closed; final class ActiveFlagService with reconcile(list<int>) + reconcileAll(int $iChunkSize=500): int; provenance gate at TWO layers (per-row in reconcileSingleOffer + WHERE-clause in reconcileAll); pure 4-cell decideTargetState() helper; idempotent (second reconcile fires SELECT only); chunkById offset-shift safe; managedByOperator() is_scalar-narrowing helper for PHPStan L10 mixed→string conversion without inline @var; ApplyTestCase extended with system_settings table for downstream 03-05/03-07 reuse; 9 Pest cases / 48 assertions; make all green: 127/511 tests / 3.86s; phpstan-baseline.neon SHA unchanged)
  - [x] 03-05-PLAN.md - InitialResetService (one-shot + snapshot-before-write + chunked; APPLY-05 + 5 QA-06 tests) *(closed 2026-04-29 — APPLY-05 + QA-06 closed; final class InitialResetService::reset(Invoice): void with two-gate guard (SettingsAccessor::allowInitialReset() + Invoice::initial_reset_applied one-shot DB exists() check, reason-tagged exception context); snapshot-before-write contract via Offer::chunkById(500) + per-chunk Product whereIn hydration → batched InitialResetSnapshot::insert (3 INSERTs for 1500 offers); chunked saveQuietly mutation (1500 individual offer UPDATEs prove no DB::statement bypass); ApplyTestCase extended with logingrupa_goods_received_initial_reset_snapshot table for the snapshot-before-write tests; 5 Pest cases / 78 assertions; make all green: 132/589 tests / 8.54s; phpstan-baseline.neon SHA unchanged)
  - [x] 03-06-PLAN.md - ParseAndPersistOrchestrator + runOverride (parse → dup-check → persist → match → write lines; APPLY-06 + APPLY-08 parse-side) *(closed 2026-04-29 — APPLY-06 + APPLY-08 parse-side closed; final class ParseAndPersistOrchestrator with run() + runOverride() + 6 private helpers; ONE DB::transaction wrapper; Invoice::lockForUpdate() race-safe duplicate detection (T-03-06-01); reject-log-after-rollback contract via try/catch outside DB::transaction (T-03-06-03); override invoice gets override_of_invoice_id pointer + 'PRO<num>-OVR-<priorId>' suffix to satisfy UNIQUE index; new+save (NOT Model::create) for typed Invoice return; Carbon::instance() bridge for DateTimeImmutable→Carbon at persistence boundary; 5 Pest cases / 27 assertions covering happy path / duplicate / override / rollback / post-rollback reject log; make all green: 137/624 tests / 7.05s; phpstan-baseline.neon SHA unchanged)
  - [x] 03-07-PLAN.md - ApplyOrchestrator (lockForUpdate + 1 transaction + post-commit flush; APPLY-07 + APPLY-08 apply-side + 4 QA-03 tests + 2 QA-08 tests) *(closed 2026-04-29 — APPLY-07 + APPLY-08 apply-side + QA-03 + QA-08 closed; final class ApplyOrchestrator::apply(int, int): ApplyResult + 4 private helpers (executeInTransaction, assertNotApplied, loadMatchedLines, markInvoiceApplied) all <70 lines; ONE DB::transaction wrapping Invoice::lockForUpdate() + status check + StockApplyService::apply + ActiveFlagService::reconcile + Invoice.status='applied' flip + ImportAuditService::logApply; flushAffectedCaches AFTER commit per D-10; ApplyAlreadyDoneException with rich prior-result context (invoice_id, invoice_number, prior_applied_at, prior_applied_by, prior_stock_added_units); override-reimport ADD-ON-TOP (D-12) emerges naturally without special-case logic; SQLite strips `for update` from compiled SQL by design (Laravel SQLiteGrammar) so LockForUpdateSerializesConcurrentApplyTest combines source-grep pin + runtime-ordering pin; ImportAuditService opened (was final) for boundary-stub test seam justified by docblock; 7 test files / 8 it() cases / 84 assertions; make all green: 145/708 tests / 7.42s; phpstan-baseline.neon SHA unchanged)
  - [x] 03-08-PLAN.md - Phase 3 final QA gate: make all green; phpstan-baseline.neon SHA unchanged; REQUIREMENTS.md / STATE.md / ROADMAP.md updated *(closed 2026-04-29 — `make all` exit 0 in 8.91s; 145/145 tests / 708 assertions / 7.65s Pest; PHPStan L10 clean (31/31); Pint clean; PHPMD clean; QA-09 grep gate green; phpstan-baseline.neon SHA UNCHANGED at 4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a — Phase 3 introduced zero new baseline-suppressed errors; 16-row REQ closure table all Y; QA-04 concrete measurement 401 queries / 4 list-store flushes for 200-line apply; Phase 3 cumulative: 8 production files / 1570 LoC / 13 new test files / 53 new Pest cases / 444 new assertions / 6 new Decisions D-03-01-01..D-03-07-06)
**UI hint**: yes

### Phase 4: Backend Controller, Upload/Preview/Apply UI, Console
**Goal**: Backend operators can upload one-or-many `.HTM` files, preview matched/unmatched lines, optionally override per-line qty, click Apply with confirmation modal, view audit history with original-file archive, and run the reconcile console command
**Depends on**: Phase 3
**Requirements**: UI-01, UI-02, UI-03, UI-04, UI-05, UI-06, UI-07, UI-08, UI-09, UI-10, UI-11, UI-12, QA-10
**Success Criteria** (what must be TRUE):
  1. Operator with `apply_invoices` permission uploads N `.HTM` files in a single submission and lands on a preview screen showing matched/unmatched lines, `match_strategy` column, and editable `override_qty` / `override_reason` per line
  2. Apply button triggers October backend AJAX (`data-request="onApply"`), shows confirmation modal with total units + offer count + unmatched count, and is debounced via `Cache::lock('apply-invoice-{id}', 60)` against double-click double-apply
  3. Re-upload of an already-applied invoice number is detected BEFORE parse cost is incurred and shows reject screen with prior-apply timestamp, applying user, units added per offer, and an Override checkbox requiring typed `OVERRIDE` confirmation
  4. Initial-reset checkbox is visible only when `Settings.allow_initial_reset=true` and requires the operator to type literal `RESET` plus see pre-mutation snapshot count before Apply enables
  5. Audit history list view (Settings menu, NOT main nav) lists invoices ordered by `applied_at DESC` with status / counts / applied_by; per-invoice detail view shows lines + unmatched queue + per-import metric panel + downloadable original `.HTM`
  6. `php artisan goodsreceived:recompute_active_from_stock` reconciles all offers chunked (500), honors `active_managed_by='operator'` skip, prints progress bar, exits 0 on success
  7. All 4 permissions enforced at controller boundary (`RequiresApplyPermissionTest`, `RequiresUploadPermissionTest`, `RequiresOverridePermissionTest`, `RequiresInitialResetPermissionTest` all green)
  8. Plugin boot logs a warning if PHP `max_file_uploads<20` or `upload_max_filesize<10M`

**Plans**: 8 plans
  - [x] 04-01-PLAN.md - Plugin boot self-check + parseIniSize() helper (UI-12)
  - [x] 04-02-PLAN.md - Console command goodsreceived:recompute_active_from_stock (UI-11) *(closed 2026-04-29 — final class RecomputeActiveFromStock extends Illuminate\Console\Command, signature `goodsreceived:recompute_active_from_stock {--chunk=500}`, handle() returns 0 on success / 1 on Throwable; Plugin::register() wires registerConsoleCommand for UI-11/D-33; ActiveFlagService final removed for boundary-mock support (D-04-02-01 mirrors D-03-07-01); progress bar deferred to V2-OPS-04 per D-04-02-03; phpstan.neon + Makefile scopes extended to console/ for L10 + phpmd + QA-09 grep-gate symmetry; 7 new Pest cases (5 RecomputeActiveFromStock + 2 PluginRegistersConsoleCommand source-grep) bring suite to 159/159 (was 152, +7) / 770 assertions (+40); make all green; phpstan-baseline.neon SHA UNCHANGED at 4b3227fa…91530a)
  - [x] 04-03-PLAN.md - Backend controller foundation + audit history list + Invoice attachOne (UI-01 + UI-05 + UI-06 + UI-07) *(closed 2026-04-29 — final class Invoices extends Backend\Classes\Controller with ListController + FormController + RelationController behaviors; class-level `$requiredPermissions = ['logingrupa.goodsreceived.upload_invoices']` enforces D-02 loose gate (per-action gates land in 04-04..04-06); `Plugin::registerSettings()` returns 2 entries — existing `goodsreceived-settings` (Settings model form) + new `goodsreceived-invoices` (controller URL, permission-gated by upload_invoices, D-04 alternative); audit history list view orders Invoices by `applied_at DESC` with status + country_code group filters; Invoice model gains `attachOne['original_file' => System\Models\File::class]` (UI-06 / D-28); 3 controller YAMLs + 7 view templates (skeletons for 04-04..04-06 onUpload/onApply/_reject/_apply_in_progress/_apply_success bodies) + 3 model-shape YAMLs (models/invoice/columns.yaml + models/invoice/fields.yaml + models/invoiceline/columns.yaml); one deviation auto-fixed (Rule 2): D-04-03-01 phpstan.neon paths += controllers + Makefile phpmd / QA-09 grep-gate scopes += controllers (mirrors plan 04-02 D-04-02-04 — every production directory under static-analysis L10 + PHPMD + QA-09 defense-in-depth); 7 new Pest structural-contract cases (1 file: InvoicesControllerStructureTest) bring suite to 166/166 (was 159, +7) / 791 assertions (was 770, +21); PHPStan L10 + Pint + PHPMD + QA-09 grep gate all green; phpstan-baseline.neon SHA UNCHANGED at 4b3227fa…91530a; plan-level TDD enforced for Task 2: RED → GREEN sequence in git log (656e00d → 8c5607f); UI-05 / UI-06 / UI-07 fully closed; UI-01 partially closed (per-action permission gates land in 04-04..04-06 with QA-10 dedicated tests))
  - [ ] 04-04-PLAN.md - onUpload + onUpdateLine + pre-parse duplicate detection (UI-02 + UI-03 + UI-07 + UI-09)
  - [ ] 04-05-PLAN.md - onApply + Cache::lock debounce + confirmation modal (UI-04)
  - [ ] 04-06-PLAN.md - Override-and-reimport + initial-reset typed gates (UI-08 + UI-10)
  - [ ] 04-07-PLAN.md - 4 permission gate tests (QA-10)
  - [ ] 04-08-PLAN.md - Phase 4 final QA gate; baseline unchanged
**UI hint**: yes

### Phase 5: Ops, Lang, Polish, Public Release
**Goal**: Plugin is installable from a public GitHub repo, fully translated, documented with operator runbook, verified working on .no/.lv/.lt, and passes the full QA gate (`make all`) with PHPStan level 10 zero errors and Pest coverage >=85%
**Depends on**: Phase 4
**Requirements**: OPS-01, OPS-02, OPS-03, OPS-04, OPS-05, OPS-06
**Success Criteria** (what must be TRUE):
  1. `composer require logingrupa/oc-goodsreceived-plugin` succeeds against a clean OctoberCMS 4 + Lovata Shopaholic install pulling from PUBLIC GitHub repo `logingrupa/oc-goodsreceived-plugin`
  2. README documents installation, the 4 settings, the 4 permissions, override semantics (D12), the GRN-canonical-quantity dependency on disabling 1C XML quantity import (D13), the InitialReset runbook, and `Log::*` troubleshooting key map
  3. `lang/{en,lv,no,ru}/lang.php` is fully populated for every user-facing string (no hardcoded English in YAMLs, controllers, partials); RainLab.Translate compatible
  4. `make all` green: `pint-test` clean, `phpstan analyse --level=10` 0 errors (no new baseline entries), `phpmd` 0 violations on classes/components/models/Plugin.php, `pest --coverage --min=85` all green
  5. Manual smoke on .no, .lv, .lt staging (or dev parity) confirms multi-site Settings isolation: changing a toggle on .no does NOT change it on .lv or .lt (per-server DB)
  6. PROJECT.md Key Decisions table reflects D11-D15 outcomes resolved 2026-04-29

**Plans**: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 1 -> 2 -> 3 -> 4 -> 5

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Schema, Scaffold, Settings, Permissions | 8/8 | Complete | 2026-04-29 |
| 2. Pure Parsers, DTOs, Exceptions, EAN Matcher | 7/7 | Complete | 2026-04-29 |
| 3. Apply Layer + Orchestrators | 8/8 | Complete | 2026-04-29 |
| 4. Backend Controller, Upload/Preview/Apply UI, Console | 3/8 | In progress | - |
| 5. Ops, Lang, Polish, Public Release | 0/TBD | Not started | - |
