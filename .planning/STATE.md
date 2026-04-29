---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: GoodsReceivedShopaholic
status: in_progress
stopped_at: Phase 3 plan 03-05 COMPLETE (APPLY-05, QA-06 closed — InitialResetService final class with reset(Invoice): void two-gate guard (SettingsAccessor::allowInitialReset() + Invoice::initial_reset_applied one-shot DB exists() check, reason-tagged exception context 'settings_disabled' | 'already_applied') + snapshot-before-write contract via Offer::chunkById(500) → per-chunk Product whereIn hydration → batched InitialResetSnapshot::insert (3 INSERTs / 1500 offers); chunked saveQuietly mutation (1500 individual offer UPDATEs prove no DB::statement bypass); ApplyTestCase extended with logingrupa_goods_received_initial_reset_snapshot table). Next: Phase 3 Wave 3 — plan 03-06 (ParseAndPersistOrchestrator) + 03-07 (ApplyOrchestrator) + 03-08 (final QA gate); both 03-06 and 03-07 depend on Wave 2 services (now all shipped: 03-03 StockApply + 03-04 ActiveFlag + 03-05 InitialReset).
last_updated: "2026-04-29T20:54:27.000Z"
progress:
  total_phases: 5
  completed_phases: 2
  total_plans: 23
  completed_plans: 20
---

# Project State

## Project Reference

See: `.planning/PROJECT.md` (updated 2026-04-29)

**Core value:** Backend operators upload distributor `.HTM` delivery receipts; stock is added to matched offers idempotently with per-site automation and auditable history
**Current focus:** Phase 3 — Apply Layer + Orchestrators (Phase 2 shipped)

## Current Position

Phase: 3 of 5 (Apply Layer + Orchestrators) — IN PROGRESS
Plan: 03-05 complete (5 of 8 Phase 3 plans). Wave 1 (03-01 + 03-02) DONE; Wave 2 (03-03 StockApply + 03-04 ActiveFlag + 03-05 InitialReset) DONE. Next: Wave 3 — 03-06 (ParseAndPersistOrchestrator) + 03-07 (ApplyOrchestrator) + 03-08 (final QA gate). Both 03-06 and 03-07 depend on Wave 2 services (all shipped); 03-08 is the final make-all gate.
Status: APPLY-05, QA-06 closed. `InitialResetService` final class ships with `reset(Invoice): void` + 7 private helpers (all <70 lines per Tiger-Style). Two-gate guard in `assertAllowed()`: cheap-first ordering (memo'd `SettingsAccessor::allowInitialReset()` → DB-once `Invoice::where('initial_reset_applied', true)->exists()`); reason-tagged exception context `$arContext['reason']` ∈ {'settings_disabled', 'already_applied'} so Phase 4 controller can render distinct error UX per cause. Snapshot-before-write contract enforced via program order: `snapshotAllOffers()` returns BEFORE `zeroAllOffers()` begins. Per-chunk Product hydration via `whereIn` (NOT `$obOffer->product` magic accessor — Larastan can't see October's array-style $belongsTo on Lovata models, AND the whereIn approach collapses N product fetches per chunk into ONE statement). chunkById(500) on EVERY pass — snapshot, zero, deactivate. Per-row saveQuietly contract enforced by 1500-offer test asserting 1500 individual offer UPDATEs (NOT one bulk UPDATE) — proves no DB::statement bypass. InitialResetSnapshot::insert batched 3 INSERTs for 1500 rows — O(catalog/500) audit-trail rows. ApplyTestCase extended with logingrupa_goods_received_initial_reset_snapshot table — durable for any future plan that exercises the snapshot. 5 new Pest cases / 78 assertions. `make all` green: 132/589 tests, 8.54s. phpstan-baseline.neon SHA unchanged (`4b3227fa…`).
Last activity: 2026-04-29 — plan 03-05 complete in ~8 min (TDD: RED a4166ff → GREEN 4390e4a, no REFACTOR needed). Three deviations all auto-fixed (Rule 3 — Blocking PHPStan L10 issues): (1) larastan.relationExistence false positive on `$obOffer->product` magic accessor → switched to per-chunk Product whereIn hydration (net positive: avoids N+1 too); (2) function.alreadyNarrowedType on is_numeric($obOffer->product_id) → trusted Lovata's docblock `@property int $product_id`, removed redundant guards (kept defensive zero-check); (3) instanceof.alwaysTrue on $obProduct instanceof Product (consequence of fix 1) → inlined the row builder to use the array<int, bool> map directly. File grew to 281 LoC (target was ≤200) — entirely the Larastan workaround helper; per-method <70 line invariant intact.

Progress: [██████████] 43%

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

**Recent Trend:**

- Last 5 plans: 03-05 (8m), 03-04 (5m), 03-03 (12m), 03-02 (5m), 03-01 (6m)
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
Stopped at: Phase 3 plan 03-04 COMPLETE — ActiveFlagService (APPLY-03 + APPLY-04 + QA-05) shipped; final class with reconcile(list<int>): void + reconcileAll(int $iChunkSize=500): int (Phase 4 console command UI-11 entry point); provenance gate at TWO layers — per-row at FIRST line of reconcileSingleOffer (operator → no-op early return) AND WHERE-clause filter in reconcileAll (defense-in-depth, T-03-04-01); pure decideTargetState() 4-cell matrix with null sentinel; idempotent (second reconcile fires SELECT only — no UPDATE; proven via query-count delta test); chunkById offset-shift safe (T-03-04-03 DoS mitigation); managedByOperator() is_scalar-narrowing helper for PHPStan L10 mixed→string conversion without inline @var/@phpstan-ignore; ApplyTestCase extended with system_settings table — durable for 03-05 InitialReset + 03-07 ApplyOrchestrator; 9 new Pest cases / 48 assertions; `make all` green (127/511 tests, 3.86s); phpstan-baseline.neon SHA unchanged. Wave 2 plan 03-05 (InitialResetService) unblocked — schema base ready; SettingsAccessor::allowInitialReset getter present.
Resume file: `.planning/phases/03-apply-layer-orchestrators/03-04-SUMMARY.md`

## UAT Items Pending (from Phase 1 — defer to milestone completion)
- Run `php artisan october:up` on a dev/staging server, confirm 3 plugin tables + offers.active_managed_by column appear with default 'system'
- Load Backend → Settings → Goods Received, confirm 4 toggles render, persist on submit
- Load Backend → Users → Roles → Edit role, confirm 4 split permissions appear under tab
- Toggle on .no, confirm value does NOT change on .lv or .lt (per-server DB multisite isolation)
