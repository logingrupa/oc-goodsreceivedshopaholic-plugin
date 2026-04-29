---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: GoodsReceivedShopaholic
status: in_progress
stopped_at: "Phase 4 plan 04-02 COMPLETE (UI-11 closed). New `php artisan goodsreceived:recompute_active_from_stock {--chunk=500}` console command reconciles every non-operator offer's `active` flag from current `quantity` via `ActiveFlagService::reconcileAll`; honours the per-row `active_managed_by='operator'` provenance gate; exits 0 on success, 1 on uncaught Throwable (`Recompute failed: <message>`). Plugin::register() wires the command via registerConsoleCommand per UI-11/D-33. Five deviations logged (4 auto-fixed Rule 1/3, 1 documented as decision): D-04-02-01 ActiveFlagService final removed for boundary-mock support (mirrors D-03-07-01); D-04-02-02 plan-listed instanceof guard dropped (PHPStan L10 instanceof.alwaysTrue — Larastan already narrows app() return); D-04-02-03 progress bar deferred to V2-OPS-04 (reconcileAll exposes only final count, no per-chunk callback hook); D-04-02-04 phpstan.neon paths += console + Makefile phpmd / QA-09 grep-gate scopes extended for symmetry; D-04-02-05 tests capture Artisan::output() once per call (Symfony BufferedOutput one-shot read semantics). 7 new Pest cases (5 RecomputeActiveFromStock + 2 PluginRegistersConsoleCommand source-grep) bring suite to 159/159 (was 152, +7) / 770 assertions (was 730, +40). PHPStan L10 + Pint + PHPMD + QA-09 grep-gate all green. phpstan-baseline.neon SHA UNCHANGED at 4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a. Commits: 3dccc5e (test RED — RecomputeActiveFromStock 5 cases) → d25558a (feat GREEN + 4 deviations) → b4918d7 (test RED — Plugin::register source-grep 2 cases) → 9f8727a (feat GREEN — Plugin::register body). Next: Phase 4 plan 04-03 (Backend controller foundation + audit history list + Invoice attachOne for UI-01 + UI-05 + UI-06 + UI-07)."
last_updated: "2026-04-29T22:21:00.000Z"
progress:
  total_phases: 5
  completed_phases: 3
  total_plans: 23
  completed_plans: 25
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-29)

**Core value:** Backend operators upload distributor `.HTM` delivery receipts; stock is added to matched offers idempotently with per-site automation and auditable history
**Current focus:** Phase 4 — Backend Controller, Upload/Preview/Apply UI, Console (Phase 3 shipped; UI-12 + UI-11 closed via plans 04-01 + 04-02)

## Current Position

Phase: 4 of 5 (Backend Controller, Upload/Preview/Apply UI, Console) — IN PROGRESS (2 of 8 plans complete)
Plan: 04-02 complete (Console command goodsreceived:recompute_active_from_stock for UI-11). Next: 04-03 (Backend controller foundation + audit history list + Invoice attachOne for UI-01 + UI-05 + UI-06 + UI-07).
Status: Plan 04-02 closed UI-11. New `php artisan goodsreceived:recompute_active_from_stock {--chunk=500}` console command reconciles every non-operator offer's `active` flag from current `quantity` via `ActiveFlagService::reconcileAll` while honouring the per-row `active_managed_by='operator'` provenance gate. Exits 0 on success / 1 on uncaught Throwable. Plugin::register() wires the command via `registerConsoleCommand`. Five deviations logged (4 auto-fixed Rule 1/3, 1 documented as decision): D-04-02-01..D-04-02-05. 7 new Pest cases (5 RecomputeActiveFromStock + 2 PluginRegistersConsoleCommand source-grep) bring suite to 159/159 (was 152, +7) / 770 assertions (was 730, +40). PHPStan L10 + Pint + PHPMD + QA-09 grep gate all green. phpstan-baseline.neon SHA `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` UNCHANGED. Commits: `3dccc5e` (test RED) → `d25558a` (feat GREEN + 4 deviations) → `b4918d7` (test RED) → `9f8727a` (feat GREEN).
Last activity: 2026-04-29 — plan 04-02 complete in ~7 min. Plan-level TDD enforced: RED → GREEN sequence verified in git log for both tasks (3dccc5e → d25558a; b4918d7 → 9f8727a). No REFACTOR commit needed — implementation is already minimal (~30 LoC handle() body, max nesting 1, function bodies <70 lines per Tiger-Style).

Progress: [███████████████░] 70%

## Performance Metrics

**Velocity:**

- Total plans completed: 0
- Average duration: —
- Total execution time: 0 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|---|---|---|---|
| Phase 2 | 7 (02-01..02-07) | ~120m | ~17m |
| Phase 2 Plan 02-07 | 1 (final QA gate) | ~22m | ~22m |
| Phase 3 Plan 03-01 | 1 (SettingsAccessor + QA-09) | ~6m | ~6m |
| Phase 3 Plan 03-02 | 1 (ImportAuditService + APPLY-10) | ~5m | ~5m |
| Phase 3 Plan 03-03 | 1 (StockApplyService + APPLY-01/02 + QA-04) | ~12m | ~12m |
| Phase 3 Plan 03-04 | 1 (ActiveFlagService + APPLY-03/04 + QA-05) | ~5m | ~5m |
| Phase 3 Plan 03-05 | 1 (InitialResetService + APPLY-05 + QA-06) | ~8m | ~8m |
| Phase 3 Plan 03-06 | 1 (ParseAndPersistOrchestrator + APPLY-06 + APPLY-08 parse-side) | ~5m | ~5m |
| Phase 3 Plan 03-07 | 1 (ApplyOrchestrator + APPLY-07 + APPLY-08 apply-side + QA-03 + QA-08) | ~50m | ~50m |
| Phase 3 Plan 03-08 | 1 (final QA gate — make all green; 16 REQs Closed) | ~5m | ~5m |
| **Phase 3 total** | **8 plans (03-01..03-08)** | **~96m** | **~12m** |
| Phase 4 Plan 04-01 | 1 (Plugin boot self-check + parseIniSize for UI-12) | ~5m | ~5m |
| Phase 4 Plan 04-02 | 1 (Console command goodsreceived:recompute_active_from_stock for UI-11) | ~7m | ~7m |

**Recent Trend:**

- Last 5 plans: 04-02 (7m), 04-01 (5m), 03-08 (5m), 03-07 (50m), 03-06 (5m)
- Trend: 04-02 landed slightly above the 5m envelope (7m) due to 4 in-flight deviations (final-removal for boundary mock, instanceof guard drop, phpstan paths extension, BufferedOutput one-shot capture) — none architectural, all auto-fixed under Rules 1+3, zero PHPStan baseline drift. Phase 4 bootstrap pair (04-01 + 04-02) now closed at ~12m total; 04-03..04-08 will be the UX-heavy plans (backend controller + multi-file upload + preview screen + apply confirmation modal + override-and-reimport + audit history + permission tests + final QA gate).

*Updated after each plan completion*

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table. Recent (carried into v1.0):

- D11 (2026-04-29): GitHub repo PUBLIC
- D12 (2026-04-29): Override-and-reimport = ADD-ON-TOP (no diff preview, no decrement-then-reapply)
- D13 (2026-04-29): GRN owns `offer.quantity`; user disables 1C XML qty import out-of-band
- D14 (2026-04-29): Vendor-inline `ImportAuditService` (~50-80 LoC); no soft-dep on ExtendShopaholic
- D15 (2026-04-29): `Settings` extends `System\Models\SettingModel` directly + manually implements `MultisiteInterface` + `MultisiteHelperTrait`
- D-PHPMD-02-07 (2026-04-29): phpmd.xml thresholds tuned for canonical DTO domain terms — `ExcessiveParameterList` 8→10 (allows ParsedLine's 9-field invoice-row schema), `ShortVariable` 4→3 (allows `ean`/`qty` industry-standard terms); `@SuppressWarnings` PHPDoc rejected — phpstan parse-errors on dotted identifiers
- D-03-01-01 (2026-04-29): `SettingsAccessor` is the SOLE caller of `Settings::get(`. Atomic 4-key bulk-fill memoization on first read caps per-request DB reads at 4 regardless of apply-loop size. `flush()` is invoked from `GoodsReceivedTestCase::flushPluginSingletons()` BEFORE `parent::tearDown()` (T-03-01-01 mitigation).
- D-03-01-02 (2026-04-29): QA-09 enforced by dual gates — `make lint-settings-accessor` Makefile target AND `SettingsAccessorIsSoleConsumerOfSettingsGetTest` Pest mirror. Both fail on identical conditions; either alone is sufficient; both together survive Makefile drift / removed CI steps (T-03-01-02 mitigation).
- D-03-01-03 (2026-04-29): Hermetic schema setUp pattern (mirror of `EanMatcherTestCase`) reused — abstract `SettingsAccessorTestCase` extending `GoodsReceivedTestCase` creates a minimal `system_settings` table (id, item, value, site_id, site_root_id, site_group_id) in setUp + drops in tearDown. Sidesteps the SQLite "drop indexed column" failure of full Lovata.Shopaholic module migrations.
- D-03-01-04 (2026-04-29): Phase-1 `TearDownFlushesSingletonsTest` "body is empty" assertion replaced with Phase-3 "body contains SettingsAccessor::flush()" assertion. Phase 1 had self-documented this swap at lines 52-54 of that test as expected-on-Phase-2/3-population.
- D-03-02-01 (2026-04-29): ImportAuditService correlation_id is FRESH per call (not per-orchestrator-run). Cross-call threading (parse↔apply join) deferred to keep ≤100 LoC ceiling per D-04. Future enhancement: optional ctor param when downstream join is required (T-03-02-04 acceptance documented in class docblock).
- D-03-02-02 (2026-04-29): logReject array_merge order — service-controlled keys (event/reason/correlation_id) merged AFTER caller context so they cannot be overwritten by caller-supplied keys with the same name. Defensive coding for future caller hygiene drift.
- D-03-02-03 (2026-04-29): Removed runtime method_exists fallback for Str::uuid7 — Laravel 12 ALWAYS ships it (PHPStan L10 narrowed-type error proved it). The v4 fallback is dead code in this dep stack; class docblock simplified accordingly.
- D-03-03-01 (2026-04-29): StockApplyService::apply uses group-by-offer pre-pass + batched `Offer::whereIn(array_keys($arDeltas))->get()->keyBy('id')` fetch — collapses N finds into 1 SELECT. Per-offer saveQuietly + per-line saveQuietly (audit precision). 200-line apply lands at 401 queries / 4 list-store flushes — locked by tests.
- D-03-03-02 (2026-04-29): Cache-flush dispatch uses leaf-singleton calls (`OfferActiveListStore::instance()->clear()` etc.) instead of `OfferListStore::instance()->active->clear()`. Required because `AbstractListStore::__get` returns mixed; PHPStan L10 cannot dereference. Observationally identical at runtime — `addToStoreList` registers the SAME singleton in both paths via the leaf class's own `instance()` factory. Test spies inject via reflection on each leaf class's `static::$instance` slot.
- D-03-03-03 (2026-04-29): `phpstan-stubs/Singleton.stub` introduces sanctioned PHPStan stub for the upstream October Rain `Singleton` trait, giving its `instance()` method its missing `@return static` annotation. NOT @var or @phpstan-ignore suppression (those are explicitly forbidden by the project's analyzer rules). Side benefit: every other Singleton trait user across the codebase types cleanly going forward.
- D-03-03-04 (2026-04-29): `StockApplyOutcome` is the realized D-29 tuple decision — `final readonly class` carrier with `ApplyResult $result` + `list<int> $affected_offer_ids`. Locks the contract; downstream plans 03-04 (ActiveFlagService) and 03-07 (ApplyOrchestrator) consume both fields directly without destructuring ambiguity.
- D-03-03-05 (2026-04-29): Lovata `Offer` + `Product` models registered as `universalObjectCratesClasses` in phpstan.neon. Suppresses Eloquent magic-property errors WITHOUT inline @var. Combined with `intval(...)` wrappers on every attribute read so PHPStan L10 accepts the int conversions.
- D-03-03-06 (2026-04-29): `ApplyTestCase` abstract base for Phase 3 Apply-layer tests reuses the Phase 2 plan 02-06 hermetic schema pattern. Manual `Schema::create` for `lovata_shopaholic_{products,offers}` + `logingrupa_goods_received_{invoices,invoice_lines}`. tearDown drops all 4. Reusable by 03-04 / 03-05 / 03-07.
- D-03-04-01 (2026-04-29): `is_scalar`-narrowing helper `managedByOperator(Offer): bool` for `active_managed_by` column reads. PHPStan L10 sees the Eloquent magic property as `mixed`; `strval()` requires scalar input. Project rules forbid inline `@var` / `@phpstan-ignore` / "type casts to silence errors". The helper guards with `is_scalar()` then `strval()` — pure static-analysis formality (column DDL is `string(16) default 'system'`).
- D-03-04-02 (2026-04-29): `chunkById` closure parameter typed as `Illuminate\\Database\\Eloquent\\Collection` (NOT `October\\Rain\\Database\\Collection`). Larastan's `Builder::chunkById` stub declares the closure parameter as `Eloquent\\Collection<int, Model>`; PHPStan L10 cannot accept a contravariant override. The `instanceof Offer` guard inside the closure narrows back to typed Offer for `reconcileSingleOffer`; the WHERE clause guarantees Offer rows at runtime.
- D-03-04-03 (2026-04-29): Defense-in-depth provenance skip in ActiveFlagService — operator-managed offers excluded BOTH at the per-row gate (reconcile path) AND at the WHERE clause (reconcileAll path). Either alone is sufficient for correctness; both together survive a future regression where one path's logic is silently changed.
- D-03-04-04 (2026-04-29): ActiveFlagService idempotency check (`(bool) $obOffer->active === $bTarget` short-circuit before save) lives INSIDE `reconcileSingleOffer`, NOT inside `decideTargetState`. Keeps the 4-cell matrix logic decoupled from the save short-circuit so D-13 truth-table reasoning stays pure. Asserted via query-count delta test: first reconcile = SELECT + UPDATE; second reconcile = SELECT only.
- D-03-04-05 (2026-04-29): `ApplyTestCase` extended with `system_settings` table create/drop in setUp/tearDown. Required by tests that drive plugin Settings via `Settings::set` (which writes through SettingModel + Multisite trait). Reusable by 03-05 (InitialResetService consumes `SettingsAccessor::allowInitialReset`) and 03-07 (ApplyOrchestrator composes services that read settings). Reflects D-03-03-06 forecast.
- D-03-06-01 (2026-04-29): Constructor uses optional null defaults + `??` body fallback (NOT `new HtmInvoiceParser()` as parameter default). Plan suggested `new` defaults; PHP 8.4 'new in initializers' RFC supports it, but combining with `readonly` properties hits a static-analyzer pothole. The `?HtmInvoiceParser $obParser = null` form + body assignment keeps Larastan + PHPStan L10 clean across PHP 8.3 dev / 8.4 prod and remains testable.
- D-03-06-02 (2026-04-29): Invoice persistence uses `new Invoice() + save()` (NOT `Invoice::create([...])`). Larastan types `Model::create` as `Eloquent\Model`, doesn't satisfy typed `Invoice` return without inline `@var` (forbidden by phpstan.neon project rules per D-03-03-05). The new+save form returns a typed Invoice and fires the same model events. Net cost: 9 extra lines per persistInvoice; net benefit: zero PHPStan suppressions.
- D-03-06-03 (2026-04-29): DateTimeImmutable → Carbon bridge via `Carbon::instance($obParsed->invoice_date)` at the persistence boundary. ParsedInvoice's `invoice_date` is DateTimeImmutable|null (pure DTO contract — no Carbon dep in parser layer); Invoice's $dates maps to Carbon. Bridge lives in persistInvoice() so DTO layer stays Carbon-free and model layer stays Carbon-typed.
- D-03-06-04 (2026-04-29): Override invoice_number gets '-OVR-<priorId>' suffix (NOT shared canonical number). The UNIQUE index on invoice_number forbids two rows with the same number; the suffix satisfies the index while override_of_invoice_id provides the FK pointer. Prior invoice keeps its canonical number; override is a derived label.
- D-03-06-05 (2026-04-29): Test fixture for parse-failure tests uses filename 'Nr_PRO000001_no_01012026.HTM' (NOT plain 'broken.HTM'). InvoiceNumberResolver runs FIRST inside parser.parse() — without a PRO-number in body OR filename, it throws InvoiceNumberMissingException, masking the MalformedHtmException the test wants. Filename PRO-number unblocks the resolver so the failure surfaces at the row-extraction step. Both exceptions extend GoodsReceivedException, so the catch + reject log works either way.
- D-03-06-06 (2026-04-29): Single DB::transaction call site is the wrapper around doParseAndPersist; assertNotDuplicate's lockForUpdate runs INSIDE that wrapper (not its own tx). Two transactions would defeat the point — the row lock would release between them, opening a TOCTOU window where a concurrent upload could insert + apply between the dup-check and the new Invoice persist. Single-tx-with-lockForUpdate is the correct pattern.
- D-03-07-01 (2026-04-29): ImportAuditService opened (final removed). Rationale: the class is a Log::* facade wrapper — a logging boundary. CLAUDE.md's Tiger-Style rule prohibits mocking business logic but explicitly carves out boundary mocks. PartialFailureRollsBackEverythingTest needs a way to throw inside logApply mid-transaction; the cleanest path is FailingAuditService extending the real class. Production code still constructs new ImportAuditService() directly via the orchestrator's default constructor argument; no production subclassing exists or is planned. Rationale captured in class docblock.
- D-03-07-02 (2026-04-29): ApplyOrchestrator constructor uses optional null defaults + `??` body fallback (NOT `new` in parameter defaults). Same rationale as D-03-06-01 from sibling ParseAndPersistOrchestrator: PHP 8.4 'new in initializers' RFC + readonly properties hits static-analyzer potholes that the explicit body-assignment form sidesteps. Keeps Larastan + PHPStan L10 clean across PHP 8.3 dev / 8.4 prod.
- D-03-07-03 (2026-04-29): InvoiceLine query goes through the model directly (`InvoiceLine::where('invoice_id', $iId)->...`) — NOT via the `Invoice::lines()` magic relation. PHPStan L10 sees October's hasMany declaration as mixed; project's phpstan.neon comments forbid inline @var. Direct-model query gives typed Builder<InvoiceLine>; `loadMatchedLines` helper uses an instanceof loop to narrow rows into `list<InvoiceLine>` wrapped in a typed `October\Rain Database\Collection<int, InvoiceLine>`. Same SELECT plan (matched_offer_id index covers the WHERE), zero PHPStan suppressions.
- D-03-07-04 (2026-04-29): No try/catch around DB::transaction in ApplyOrchestrator (in contrast to ParseAndPersistOrchestrator which catches GoodsReceivedException for reject logging). Apply-side has no equivalent need: ApplyAlreadyDoneException is the only orchestrator-thrown plugin exception, and it carries enough context for the controller to render directly. Audit.logApply already ran INSIDE the tx for successful applies; failed applies don't need a separate reject log because the rollback itself is the correct outcome. Tiger-Style fail-fast — let the typed exception propagate.
- D-03-07-05 (2026-04-29): LockForUpdateSerializesConcurrentApplyTest uses TWO independent assertions: (1) source-grep that ->lockForUpdate() appears inside executeInTransaction's body, (2) runtime query log that the Invoice SELECT precedes any offer UPDATE. SQLite's compileLock returns empty (Laravel SQLiteGrammar.php — by design; SQLite single-file DBs cannot offer cross-process row locks), so the textual `for update` will never appear in executed SQL on the test bootstrap. The source grep is the only reliable pin for the CALL itself; runtime ordering proves the orchestrator runs the lock-acquiring SELECT first; together they pin the contract on every driver.
- D-03-07-06 (2026-04-29): Lang::get('exception.apply_already_done') used directly (no Translator service). Same pattern as ParseAndPersistOrchestrator's DuplicateInvoiceException construction. The Lang facade is the canonical October CMS i18n entry point; the lang key already exists in lang/en/lang.php (line 42).
- D-04-01-01 (2026-04-29): ini_set runtime injection abandoned for boot-self-check tests. max_file_uploads + upload_max_filesize are PHP_INI_PERDIR — runtime ini_set() returns false in CLI/PHPUnit on this host (verified empirically). The plan's listed Plugin-subclass fallback was rejected because it only addresses upload_max_filesize (which routes through parseIniSize) and leaves max_file_uploads (which uses ini_get directly) untestable. Reshape: read live ini values once per test, derive whether each warning is expected, pin Mockery expectation conditionally. Same 7 it() blocks, same UI-12 acceptance, but driven by actual host config rather than synthetic injection.
- D-04-01-02 (2026-04-29): parseIniSize accepts case-insensitive K/M/G suffixes. PHP's ini-syntax docs treat suffix case as canonical-uppercase, but real php.ini files in the wild ship '10m' / '512k' — accepting lowercase is correct safety-first behaviour for a self-check helper that runs at boot. T-04-01-01 (NEVER throw, never crash boot) demands the helper bias toward "accept the input, return a useful number" over "be a strict validator".
- D-04-02-01 (2026-04-29): ActiveFlagService `final` removed for boundary-mock support. Mirror of D-03-07-01 (ImportAuditService). The service is a service-layer boundary called via `app(ActiveFlagService::class)` IoC; the new `RecomputeActiveFromStockTest` exit-1-on-failure case needs to inject a failing service, and subclass + override is the cleanest portable path (Mockery cannot mock final classes by default). Production code never subclasses — `app()` always resolves the leaf class. Class docblock notes both the boundary-mock rationale and the production-code invariant.
- D-04-02-02 (2026-04-29): Plan-listed `instanceof ActiveFlagService` defensive guard dropped from `RecomputeActiveFromStock::handle()`. Larastan's `app()` extension already narrows the return type, so the guard hits PHPStan L10's `instanceof.alwaysTrue` rule (verified empirically). The Throwable catch immediately after the resolve already covers BindingResolutionException — the only realistic IoC failure mode. Net effect: same safety, simpler code.
- D-04-02-03 (2026-04-29): Symfony progress bar deferred from RecomputeActiveFromStock console command. ActiveFlagService::reconcileAll chunked-iterates internally and returns only a final touched-count; no per-chunk callback hook exists. Wrapping a Symfony progress bar around a single call that returns only on completion would be UX theatre — the bar would jump from 0% to 100% in one tick. Operator's receipt is the `Reconciled N offers (chunk=K).` info line on success. Adding a per-chunk callback to ActiveFlagService::reconcileAll(int, ?Closure) is a Phase 5 V2 enhancement (V2-OPS-04) if operator feedback warrants it.
- D-04-02-04 (2026-04-29): Static-analysis surface extended to `console/`. `phpstan.neon` paths now include `console` alongside `classes / components / models / Plugin.php`; `Makefile` `phpmd` target's path list also extended; `lint-settings-accessor` (QA-09) grep-gate path lists also extended for symmetry. Without this change PHPStan would silently skip the new `console/RecomputeActiveFromStock.php` file (verified: 31 → 32 files analysed after the path addition). Future plans that introduce new top-level production directories MUST add the directory to all three scopes (phpstan paths, phpmd paths, QA-09 grep) for analyser symmetry.
- D-04-02-05 (2026-04-29): RecomputeActiveFromStockTest captures `\Artisan::output()` once per call into `$sOutput` instead of calling it multiple times. Symfony's `BufferedOutput` (which Artisan uses internally during testing) has one-shot read semantics — after the buffer is drained, subsequent reads return empty. Verified empirically: 2/5 tests failed initially with empty-output assertions until the capture-once pattern was applied. Same pattern as `Console\Tester\CommandTester::getDisplay()`. Future console-command tests should follow the capture-once pattern.

### Pending Todos

None yet.

### Blockers/Concerns

None. All 5 open questions (OQ1-OQ5) resolved during requirements phase.

## Deferred Items

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| *(none)* | | | |

## Session Continuity

Last session: 2026-04-29
Stopped at: Phase 4 plan 04-02 COMPLETE (UI-11 closed). New `php artisan goodsreceived:recompute_active_from_stock {--chunk=500}` console command reconciles every non-operator offer's `active` flag from current `quantity` via `ActiveFlagService::reconcileAll`; honours the per-row `active_managed_by='operator'` provenance gate; exits 0 on success, 1 on uncaught Throwable. Plugin::register() wires the command via `registerConsoleCommand`. Five deviations logged (4 auto-fixed Rule 1/3, 1 documented as decision): D-04-02-01 ActiveFlagService final removed for boundary-mock support; D-04-02-02 plan-listed instanceof guard dropped (PHPStan L10 instanceof.alwaysTrue); D-04-02-03 progress bar deferred to V2-OPS-04 (no per-chunk callback hook); D-04-02-04 phpstan + phpmd + QA-09 grep-gate scopes extended to console/; D-04-02-05 tests capture Artisan::output() once per call. 7 new Pest cases (5 RecomputeActiveFromStock + 2 PluginRegistersConsoleCommand source-grep) bring suite to 159/159 (was 152, +7) / 770 assertions (was 730, +40). PHPStan L10 + Pint + PHPMD + QA-09 grep-gate all green. phpstan-baseline.neon SHA UNCHANGED at 4b3227fa…91530a. Commits: 3dccc5e (test RED) → d25558a (feat GREEN + 4 deviations) → b4918d7 (test RED) → 9f8727a (feat GREEN). Next: Phase 4 plan 04-03 (Backend controller foundation + audit history list + Invoice attachOne for UI-01 + UI-05 + UI-06 + UI-07).
Resume file: `.planning/phases/04-backend-controller-upload-preview-apply-ui-console/04-02-SUMMARY.md`

## UAT Items Pending (from Phase 1 — defer to milestone completion)
- Run `php artisan october:up` on a dev/staging server, confirm 3 plugin tables + offers.active_managed_by column appear with default 'system'
- Load Backend → Settings → Goods Received, confirm 4 toggles render, persist on submit
- Load Backend → Users → Roles → Edit role, confirm 4 split permissions appear under tab
- Toggle on .no, confirm value does NOT change on .lv or .lt (per-server DB multisite isolation)
