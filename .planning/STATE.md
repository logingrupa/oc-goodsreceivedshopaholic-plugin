---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: GoodsReceivedShopaholic
status: in_progress
stopped_at: Phase 3 plan 03-06 COMPLETE (APPLY-06, APPLY-08 parse-side closed — ParseAndPersistOrchestrator final class with run(string, string, int): Invoice + runOverride(string, string, int, int): Invoice + 6 private helpers (all <70 lines per Tiger-Style); ONE DB::transaction wrapper around parse → dup-check → persist Invoice → batch-match → persist InvoiceLine rows; Invoice::lockForUpdate() race-safe duplicate detection inside the tx (T-03-06-01); reject-log-after-rollback contract via try/catch OUTSIDE DB::transaction so logReject records the failure outcome, not a half-committed state (T-03-06-03); override invoice gets override_of_invoice_id pointer + 'PRO<num>-OVR-<priorId>' suffix to satisfy UNIQUE index (D-26..D-27); ZERO Offer:: / Settings::get( references — stock writes deferred to plan 03-07; 5 Pest cases / 27 new assertions covering happy path, duplicate detection, override-reimport, parse-failure rollback, and post-rollback reject logging). Next: Phase 3 Wave 3 — plan 03-07 (ApplyOrchestrator: lockForUpdate idempotent guard + ONE tx wrapping StockApplyService::apply + ActiveFlagService::reconcile + status flip + audit; flushAffectedCaches AFTER commit; QA-03 + QA-08 idempotency + transaction-safety tests) + 03-08 (final make-all gate). ApplyOrchestrator runs UNCHANGED on override invoices — additive D-12 add-on-top semantics emerge naturally from running normal apply on a fresh invoice that happens to point at a prior.
last_updated: "2026-04-29T21:08:00.000Z"
progress:
  total_phases: 5
  completed_phases: 2
  total_plans: 23
  completed_plans: 21
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-29)

**Core value:** Backend operators upload distributor `.HTM` delivery receipts; stock is added to matched offers idempotently with per-site automation and auditable history
**Current focus:** Phase 3 — Apply Layer + Orchestrators (Phase 2 shipped)

## Current Position

Phase: 3 of 5 (Apply Layer + Orchestrators) — IN PROGRESS
Plan: 03-06 complete (6 of 8 Phase 3 plans). Wave 1 (03-01 + 03-02) DONE; Wave 2 (03-03 StockApply + 03-04 ActiveFlag + 03-05 InitialReset) DONE; Wave 3 in progress: 03-06 ParseAndPersistOrchestrator DONE, 03-07 ApplyOrchestrator next, 03-08 final QA gate after.
Status: APPLY-06 closed (parse orchestrator), APPLY-08 parse-side closed (override-reimport entry point). `ParseAndPersistOrchestrator` final class ships with `run(string, string, int): Invoice` + `runOverride(string, string, int, int): Invoice` + 6 private helpers (all <70 lines per Tiger-Style). ONE `DB::transaction` wrapper inside `runWithStrategy` routes both public entry points through the same parse → dup-check → persist Invoice → batch-match → persist InvoiceLine rows + audit sequence; the only branch is `assertNotDuplicate` (skipped in MODE_OVERRIDE) and the persisted invoice number / override pointer. `Invoice::where('invoice_number', $sNumber)->lockForUpdate()->first()` inside the tx serializes concurrent uploads of the same number on the row lock — second upload waits for first to commit, then sees the new row + throws `DuplicateInvoiceException` with prior_invoice_id / prior_applied_at / prior_applied_by context (T-03-06-01 mitigation). Reject-log-after-rollback contract: try/catch wraps `DB::transaction`, NOT inside it; on any GoodsReceivedException (parse/match/dup), tx rolls back → catch fires `ImportAuditService::logReject` with $obException context + source_filename + mode → re-throw (T-03-06-03 mitigation; logging inside tx would record nothing committed but suggest it did). Override invoice gets `override_of_invoice_id=$iPriorInvoiceId` + `invoice_number='<orig>-OVR-<priorId>'` suffix to satisfy UNIQUE index while prior keeps canonical number; add-on-top semantics (D-12) emerge naturally — ApplyOrchestrator runs UNCHANGED on override invoices. ZERO `Offer::` / `Settings::get(` references — stock writes are 03-07 ApplyOrchestrator's concern, settings reads gated through SettingsAccessor (QA-09). 5 new Pest cases / 27 assertions covering happy path (21-line fixture → Invoice@status=parsed + 21 InvoiceLine rows), duplicate detection ($arContext shape pinned), override-reimport (suffix verified, 2 invoices total), parse-failure rollback (Invoice::count()===0 after MalformedHtmException), and post-rollback reject logging (Log::shouldHaveReceived('warning') spy with source_filename + mode='normal'). `make all` green: 137/624 tests, 7.05s. phpstan-baseline.neon SHA unchanged (`bbf4a55d…`).
Last activity: 2026-04-29 — plan 03-06 complete in ~5 min (TDD: RED 630bbb8 → GREEN 751d837, no REFACTOR needed). Three deviations all auto-fixed: (1) [Rule 3 — Blocking] PHPStan return-type narrowing on `Invoice::create()` — Larastan types `Model::create` as `Eloquent\Model`, doesn't satisfy typed `Invoice` return without inline @var (forbidden by phpstan.neon rules per D-03-03-05). Resolution: switched to `new Invoice() + save()` form. (2) [Rule 3 — Blocking] DateTimeImmutable → Carbon assign.propertyType — ParsedInvoice's `invoice_date` is DateTimeImmutable|null (pure DTO contract); Invoice's `$dates` maps to Carbon. Resolution: `Carbon::instance($obParsed->invoice_date)` bridge at persistInvoice — DTO layer stays Carbon-free, model layer stays Carbon-typed. (3) [Rule 1 — Bug] Test fixture filename — plan suggested `'broken.HTM'`, but InvoiceNumberResolver runs FIRST inside parser.parse() and would throw InvoiceNumberMissingException, masking the MalformedHtmException the test wants. Resolution: filenames updated to `'Nr_PRO000001_no_01012026.HTM'` so resolver's filename fallback succeeds and failure surfaces at row-extraction step. Function lengths: 6 private helpers, max 39 lines (persistInvoice), all <70 line Tiger-Style invariant intact.

Progress: [██████████▒] 46%

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

**Recent Trend:**

- Last 5 plans: 03-06 (5m), 03-05 (8m), 03-04 (5m), 03-03 (12m), 03-02 (5m)
- Trend: 03-05 took 8m vs the 5m cadence of 03-04 — three Larastan/PHPStan-L10 deviations stacked in a single GREEN cycle (the magic-accessor false positive on `$obOffer->product`, then two cascade issues from the fix). All three were Rule-3 blocking and resolved by ONE structural change: per-chunk Product hydration via whereIn instead of the magic accessor. Net positive (avoided N+1 anyway), but the realization arrived only after the second PHPStan run. The TDD cycle was clean (RED a4166ff → GREEN 4390e4a, no REFACTOR); query-log assertions in ChunkedNotSingleStatementTest add 3.5s to the test suite (1500-offer SQLite seed dominates) — acceptable for the contract proof.

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
Stopped at: Phase 3 plan 03-06 COMPLETE — ParseAndPersistOrchestrator (APPLY-06 + APPLY-08 parse-side) shipped; final class with run(string, string, int): Invoice + runOverride(string, string, int, int): Invoice + 6 private helpers (all <70 lines per Tiger-Style); ONE DB::transaction wrapper; Invoice::lockForUpdate() race-safe duplicate detection inside the tx (T-03-06-01); reject-log-after-rollback contract via try/catch OUTSIDE DB::transaction wrapper (T-03-06-03); override invoice gets override_of_invoice_id pointer + 'PRO<num>-OVR-<priorId>' suffix to satisfy UNIQUE index; new+save (NOT Model::create) for typed Invoice return; Carbon::instance() bridge for DateTimeImmutable→Carbon at persistence boundary; ZERO Offer:: / Settings::get( references; 5 new Pest cases / 27 assertions covering happy path / duplicate / override / rollback / post-rollback reject log; `make all` green (137/624 tests / 7.05s); phpstan-baseline.neon SHA unchanged. Wave 3 plan 03-07 (ApplyOrchestrator) unblocked — all Wave 2 services shipped (StockApplyService + ActiveFlagService + InitialResetService) and the parse-side orchestrator that produces parsed Invoices is in place; 03-07 only needs to compose the existing apply layer with Invoice::lockForUpdate idempotency + DB::transaction + post-commit cache flush.
Resume file: `.planning/phases/03-apply-layer-orchestrators/03-06-SUMMARY.md`

## UAT Items Pending (from Phase 1 — defer to milestone completion)
- Run `php artisan october:up` on a dev/staging server, confirm 3 plugin tables + offers.active_managed_by column appear with default 'system'
- Load Backend → Settings → Goods Received, confirm 4 toggles render, persist on submit
- Load Backend → Users → Roles → Edit role, confirm 4 split permissions appear under tab
- Toggle on .no, confirm value does NOT change on .lv or .lt (per-server DB multisite isolation)
