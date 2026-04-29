---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: GoodsReceivedShopaholic
status: in_progress
stopped_at: "Phase 3 COMPLETE (all 8 plans, all 16 requirements APPLY-01..10 + QA-03/04/05/06/08/09 closed). 03-08 final QA gate verified `make all` green: 145/145 tests passed (708 assertions / 7.65s Pest / 8.91s wall-clock), PHPStan L10 clean, Pint clean, PHPMD clean, lint-settings-accessor clean (Settings::get( appears in EXACTLY 1 file: classes/support/SettingsAccessor.php). phpstan-baseline.neon sha256 UNCHANGED at 4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a (Phase 3 introduced ZERO new baseline-suppressed errors — every L10 finding fixed at source via stubs + is_scalar narrowing helpers + instanceof loops + explicit body-assignment over PHP 8.4 new-in-initializer; no @var / @phpstan-ignore added). Phase 3 cumulative artifacts: 8 production files (1570 LoC), 13 new test files, 53 new Pest cases (92→145), 444 new assertions (264→708), 6 new Decisions logged D-03-01-01..D-03-07-06 (28 entries total Phase 3). 16-row REQ closure table all Y in 03-08-SUMMARY.md cross-referencing each REQ to a concrete test pin or grep gate. QA-04 cache flush concretely measured: 200-line apply = 401 actual queries (≤500 budget) / 4 actual list-store cache flushes (≤5 contract) — anti-pattern guard (per-line ->save() regression would explode to ≥1600 flushes) locked by Mockery shouldHaveReceived spies. Next: Phase 4 plan 04-01 kickoff (Backend Controller, Upload/Preview/Apply UI, Console)."
last_updated: "2026-04-29T22:00:00.000Z"
progress:
  total_phases: 5
  completed_phases: 3
  total_plans: 23
  completed_plans: 23
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-29)

**Core value:** Backend operators upload distributor `.HTM` delivery receipts; stock is added to matched offers idempotently with per-site automation and auditable history
**Current focus:** Phase 3 — Apply Layer + Orchestrators (Phase 2 shipped)

## Current Position

Phase: 3 of 5 (Apply Layer + Orchestrators) — COMPLETE
Plan: 03-08 complete (8 of 8 Phase 3 plans). Wave 1 (03-01 + 03-02) DONE; Wave 2 (03-03 StockApply + 03-04 ActiveFlag + 03-05 InitialReset) DONE; Wave 3 (03-06 ParseAndPersistOrchestrator + 03-07 ApplyOrchestrator) DONE; Wave 4 (03-08 final QA gate) DONE. ALL 16 Phase 3 requirements CLOSED (APPLY-01..10 + QA-03/04/05/06/08/09).
Status: Phase 3 final QA gate (03-08) ran the full toolchain on a fresh invocation: `make all` exit 0 in 8.91s wall-clock; 145/145 Pest cases pass (708 assertions / 7.65s); PHPStan L10 clean (31/31 files, [OK] No errors); Pint clean ({"result":"pass"}); PHPMD clean (silent success); QA-09 grep gate green ("GATE OK"). phpstan-baseline.neon SHA UNCHANGED at `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` — Phase 3 introduced ZERO new baseline-suppressed errors. Every L10 finding fixed at source via `phpstan-stubs/Singleton.stub` for upstream October Rain Singleton trait (D-03-03-03), `is_scalar`-narrowing helpers for `mixed` Eloquent attribute reads (D-03-04-01), `instanceof` loops for typed October Rain collection narrowing (D-03-07-03), and explicit body-assignment over PHP 8.4 `new in initializer` (D-03-06-01 / D-03-07-02). No `@var` / `@phpstan-ignore` / inline suppressions added. 03-08-SUMMARY.md ships the 16-row closure table cross-referencing each REQ to a concrete test pin or grep gate; QA-04 concrete measurement table (401 queries / 4 list-store flushes for 200-line apply); per-plan timing table (Phase 3 total ~96 min); test count growth across phases. REQUIREMENTS.md traceability flipped: 16 Phase 3 entries marked `Closed (2026-04-29) — plan NN-NN`. ROADMAP.md Progress table updated: Phase 3 row marked Complete (2026-04-29). Next: Phase 4 plan 04-01 kickoff (Backend Controller, Upload/Preview/Apply UI, Console).
Last activity: 2026-04-29 — plan 03-08 complete in ~5 min (metadata-only commit). Zero deviations: plan was a no-code QA gate, `make all` already green from 03-07 close, this plan re-ran toolchain for fresh-invocation contract lock + captured concrete numbers + wrote 4 doc artifacts.

Progress: [██████████████] 65%

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

**Recent Trend:**

- Last 5 plans: 03-08 (5m), 03-07 (50m), 03-06 (5m), 03-05 (8m), 03-04 (5m)
- Trend: 03-08 closed cleanly at the predicted ~5m envelope (no-code QA gate). The 03-07 spike (50m) was the Phase 3 outlier (largest plan by files modified + 3 in-flight deviations + L10 generic-tracking through October Rain Database\Collection); every other Phase 3 plan landed in the 5-12m band. Phase 3 close metric: 8 plans in ~96 min total / ~12 min average. Phase 4 expected to be UI-heavy (multi-file upload + preview screen + apply confirmation modal + audit history list); duration TBD pending plan-phase decomposition.

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
Stopped at: Phase 3 COMPLETE — all 8 plans closed (03-01..03-08), all 16 requirements satisfied (APPLY-01..10 + QA-03/04/05/06/08/09). 03-08 final QA gate: `make all` exit 0 in 8.91s; 145/145 Pest cases / 708 assertions / 7.65s; PHPStan L10 clean (31/31 files); Pint clean; PHPMD clean; QA-09 grep gate green. phpstan-baseline.neon SHA UNCHANGED at `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` (Phase 3 introduced ZERO new baseline-suppressed errors). 03-08-SUMMARY.md ships 16-row REQ closure table + QA-04 concrete measurement (401 queries / 4 list-store flushes for 200-line apply) + Phase 3 cumulative artifacts (8 production files, 1570 LoC; 13 new test files; 53 new Pest cases / 444 new assertions). REQUIREMENTS.md traceability flipped (16 Phase 3 entries Closed). ROADMAP.md Phase 3 row Complete (2026-04-29). Next: Phase 4 plan 04-01 kickoff — Backend Controller (UI-01..12 + QA-10), 13 requirements; planner should run `/gsd-discuss-phase` if no Phase 4 CONTEXT.md exists, else `/gsd-plan-phase`.
Resume file: `.planning/phases/03-apply-layer-orchestrators/03-08-SUMMARY.md`

## UAT Items Pending (from Phase 1 — defer to milestone completion)
- Run `php artisan october:up` on a dev/staging server, confirm 3 plugin tables + offers.active_managed_by column appear with default 'system'
- Load Backend → Settings → Goods Received, confirm 4 toggles render, persist on submit
- Load Backend → Users → Roles → Edit role, confirm 4 split permissions appear under tab
- Toggle on .no, confirm value does NOT change on .lv or .lt (per-server DB multisite isolation)
