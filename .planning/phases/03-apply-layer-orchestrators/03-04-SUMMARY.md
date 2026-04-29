---
phase: 03-apply-layer-orchestrators
plan: 04
subsystem: apply-layer
tags: [active-flag, provenance, savequietly, settings-accessor, idempotent, chunkbyid, qa-05, apply-03, apply-04, php8.4, pest4]

# Dependency graph
requires:
  - phase: 03-apply-layer-orchestrators
    plan: 01
    provides: SettingsAccessor (autoDeactivateOnZero / autoActivateOnStock memoized getters; D-01)
  - phase: 03-apply-layer-orchestrators
    plan: 03
    provides: ApplyTestCase hermetic schema base + StockApplyOutcome::affected_offer_ids contract
  - phase: 01-schema-scaffold-settings-permissions
    provides: lovata_shopaholic_offers.active_managed_by additive column (Phase 1 plugin migration)
provides:
  - ActiveFlagService — provenance-aware active-flag reconciler (final class, instance not static)
  - reconcile(list<int>): void — typical Apply-orchestrator entry point
  - reconcileAll(int $iChunkSize=500): int — UI-11 console command entry point (Phase 4)
  - 4-cell decision matrix asserted via 4 dedicated Pest cases (QA-05 part A)
  - SkipsManuallyDeactivatedOfferTest — operator-provenance gate (QA-05 part B)
  - ApplyTestCase schema extension (system_settings table) — reusable by 03-05 InitialResetService
affects: [03-05-initialreset, 03-07-apply-orch, 04-console-recompute-active-from-stock]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Provenance-aware reconcile pattern: per-row check at the FIRST line of reconcileSingleOffer (operator → no-op early return), per-query WHERE filter in reconcileAll for defense-in-depth"
    - "Pure 4-cell decision matrix: decideTargetState() returns ?bool — decoupled from idempotency check (which lives in reconcileSingleOffer) so the D-13 truth table is reasoned about in isolation"
    - "Idempotency by construction: target-state comparison short-circuits BEFORE save; second reconcile of same ids fires SELECT only (no UPDATE)"
    - "chunkById (NOT chunk): offset-shift safe under mid-iteration updates (T-03-04-03 DoS mitigation)"
    - "Settings DRY: zero `Settings::get(` literals in the file; QA-09 grep gate green by construction"
    - "is_scalar narrowing helper for PHPStan L10: managedByOperator() guards against `mixed` Eloquent magic-property access without inline @var or @phpstan-ignore"

key-files:
  created:
    - classes/apply/ActiveFlagService.php (207 lines — final class with reconcile + reconcileAll + 3 private helpers)
    - tests/unit/Apply/ActiveFlagServiceTest.php (206 lines — 8 Pest cases / 4-cell matrix + idempotency + reconcileAll + operator-skip-at-query)
    - tests/unit/Apply/SkipsManuallyDeactivatedOfferTest.php (51 lines — QA-05 dedicated provenance gate, 1 case)
  modified:
    - tests/unit/Apply/ApplyTestCase.php (+12 lines: system_settings table create/drop — required by Settings::set in tests)

key-decisions:
  - "D-03-04-01 (2026-04-29): is_scalar narrowing helper for active_managed_by column read. PHPStan L10 sees the Eloquent magic property as `mixed`; `strval()` requires scalar input. Project rules forbid inline @var / @phpstan-ignore. The `managedByOperator()` private helper applies `is_scalar()` to narrow `mixed` → scalar, then strval() satisfies the L10 strict-type rule. Defensive: column DDL is `string(16) default 'system'` so the false-branch is never hit at runtime; the guard is a static-analysis formality."
  - "D-03-04-02 (2026-04-29): chunkById signature uses Illuminate\\Database\\Eloquent\\Collection (NOT October\\Rain\\Database\\Collection) because Larastan's stub for Builder::chunkById types the closure parameter as Eloquent\\Collection<int, Model>. The instanceof Offer guard inside the closure narrows back to typed Offer for reconcileSingleOffer; the WHERE clause guarantees the runtime type."
  - "D-03-04-03 (2026-04-29): Defense-in-depth provenance skip — operator-managed offers excluded BOTH at the per-row gate (reconcile path) AND at the WHERE clause (reconcileAll path). Either alone is sufficient for correctness; both together survive a future regression where one path's logic is silently changed."
  - "D-03-04-04 (2026-04-29): Reused 03-03's universalObjectCratesClasses + intval/strval pattern for Lovata Offer attribute reads. No new phpstan.neon edits this plan — the Phase 3 plan 03-03 stub + crate registration covers all Phase 3 services."
  - "D-03-04-05 (2026-04-29): ApplyTestCase schema extension (system_settings table) — added in this plan's RED gate so all Phase 3 Apply-layer tests that drive Settings via `Settings::set` work. Reusable by 03-05 (InitialResetService consumes SettingsAccessor::allowInitialReset) and 03-07 (ApplyOrchestrator consumes settings indirectly via the services it composes)."

patterns-established:
  - "Pure-helper decision matrix: decideTargetState(Offer, bool, bool): ?bool — null sentinel for 'no change requested'. Clear precedence: deactivate-on-zero outranks activate-on-stock when both are on, but with mutually-exclusive qty conditions (qty<=0 vs qty>0) the precedence is observationally moot."
  - "Provenance gate as the FIRST check inside reconcileSingleOffer — short-circuits BEFORE any settings lookup. The operator flag is the load-bearing safety contract; reading settings would cost nothing here but ordering matters for code review (the gate stares back at the next reader)."
  - "Settings::set + Settings::clearInternalCache + SettingsAccessor::flush triple inside the test helper applySettings() — the SettingModel caches the loaded row per process; clearInternalCache forces a re-read after the set, then flush() drops the SettingsAccessor memo so the service reads fresh values."

requirements-completed: [APPLY-03, APPLY-04, QA-05]

# Metrics
duration: 5min
completed: 2026-04-29
---

# Phase 3 Plan 04: ActiveFlagService Summary

**ActiveFlagService final class — provenance-aware active-flag reconciler honoring SettingsAccessor toggles, with operator-managed offers skipped at TWO layers (per-row gate + WHERE filter), idempotent on repeat reconciles, chunked iteration in reconcileAll. 9 Pest cases / 48 assertions covering the full 4-cell matrix + idempotency + reconcileAll + the QA-05 SkipsManuallyDeactivatedOfferTest.**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-04-29T20:36:20Z
- **Completed:** 2026-04-29T20:41:23Z
- **Tasks:** 3 (Task 1: service; Task 2: matrix tests; Task 3: SkipsManuallyDeactivated test) — combined into one TDD RED→GREEN cycle since all three artifacts share fate
- **Files created:** 3
- **Files modified:** 1 (ApplyTestCase.php +12 lines for system_settings table)

## Accomplishments

- `ActiveFlagService::reconcile(list<int>): void` — typical Apply path. Cheap pre-gate (both settings off → return without SELECT). Otherwise one batched `whereIn` fetch + per-row provenance + matrix decision.
- `ActiveFlagService::reconcileAll(int $iChunkSize=500): int` — Phase 4 console-command entry point. `chunkById` (offset-shift safe). WHERE-level operator exclusion. Returns count of touched offers.
- Pure `decideTargetState()` helper — 4-cell matrix decoupled from idempotency check (which lives in reconcileSingleOffer).
- 8 Pest cases in ActiveFlagServiceTest.php covering:
  - 4-cell matrix (deactivate-on/off × activate-on/off) — 4 cases plus a 5th that asserts BOTH settings off is a complete no-op for both qty=0 and qty>0 offers in a single test.
  - Idempotency proven via query-count delta: first reconcile = SELECT + UPDATE (≥ 2 queries); second reconcile = SELECT only (1 query).
  - reconcileAll chunked iteration: 7 offers, chunkSize=3, returns count==4 (the zero-qty offers).
  - reconcileAll WHERE-filter operator skip: operator-locked offer NEVER hydrated.
- 1 Pest case in SkipsManuallyDeactivatedOfferTest.php (QA-05 dedicated): provenance gate at per-row level even when settings would otherwise toggle.
- ApplyTestCase extended with `system_settings` table (reusable by 03-05 / 03-07).
- `make all` green: 127 tests / 511 assertions / 3.86s. phpstan-baseline.neon SHA unchanged.

## Task Commits

Each task committed atomically following the TDD gate sequence:

1. **RED — failing tests for ActiveFlagService matrix + idempotency + operator-skip + QA-05** — `abcf000` (test) — 9 tests fail with "Class ActiveFlagService not found"; ApplyTestCase regression-clean (12/12 prior tests pass)
2. **GREEN — ActiveFlagService implementation** — `f380aba` (feat) — 9/9 pass / 48 assertions

REFACTOR pass: not committed — code minimal at GREEN. The 207-line file is dominated by PHPDoc threat-model + decision-record documentation (Tiger-Style "doc the why"); the methods themselves are well within the 70-line cap (longest: `reconcileAll` at 22 LoC; `reconcile` at 16 LoC).

**Plan metadata commit:** _(forthcoming after this SUMMARY.md is written)_

## Files Created/Modified

- `classes/apply/ActiveFlagService.php` (207 lines) — `final class ActiveFlagService` with `reconcile()` + `reconcileAll()` + 3 private helpers (`reconcileSingleOffer`, `managedByOperator`, `decideTargetState`).
- `tests/unit/Apply/ActiveFlagServiceTest.php` (206 lines) — 8 Pest cases covering matrix + idempotency + reconcileAll + WHERE-filter operator skip.
- `tests/unit/Apply/SkipsManuallyDeactivatedOfferTest.php` (51 lines) — 1 Pest case (QA-05 part B) — per-row provenance gate via reconcile() path.
- `tests/unit/Apply/ApplyTestCase.php` (+12 lines) — `system_settings` table create/drop in setUp/tearDown so tests can drive `Settings::set` writes.

## QA Gate Results

| Gate | Result | Notes |
|------|--------|-------|
| `make pint-test` | pass | `{"result":"pass"}` |
| `make lint-settings-accessor` | exit 0 | no offenders |
| `make analyse` (PHPStan L10 + Larastan) | clean | `[OK] No errors` across 28 paths; baseline SHA unchanged |
| `make phpmd` | clean | no new violations |
| `make test` (Pest 4) | 127 passed / 511 assertions | up from 118/463 in plan 03-03 (+9 cases / +48 assertions this plan) |
| `make all` total | exit 0 | 3.86s |

## phpstan-baseline.neon SHA

| When | SHA-256 |
|------|---------|
| Before plan 03-04 | `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` |
| After plan 03-04  | `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` |

**Unchanged** — the new code raised zero new baseline entries. Larastan + PHPStan L10 see the apply layer as fully typed.

## Verification Output

### Required + forbidden patterns

```
$ wc -l classes/apply/ActiveFlagService.php
207

$ grep -c 'Settings::get(' classes/apply/ActiveFlagService.php
0                                          # QA-09 grep gate (must be 0)

$ grep -c 'SettingsAccessor::' classes/apply/ActiveFlagService.php
6                                          # uses the accessor (must be ≥ 2)

$ grep -c 'saveQuietly' classes/apply/ActiveFlagService.php
4                                          # 1 in docblock + 1 in reconcileSingleOffer; remainder in PHPDoc text (must be ≥ 1)

$ grep -E '\->save\(\)' classes/apply/ActiveFlagService.php | grep -v 'saveQuietly' | wc -l
0                                          # forbidden: ->save() per task done criteria
```

### 4-cell matrix outcome (test output)

```
✓ matrix cell (deactivate=on, activate=on): qty=0+active=true → active=false+managed_by=plugin
✓ matrix cell (deactivate=on, activate=on): qty>0+active=false → active=true+managed_by=plugin
✓ matrix cell (deactivate=off, activate=on): qty=0+active=true → unchanged (deactivate disabled)
✓ matrix cell (deactivate=on, activate=off): qty>0+active=false → unchanged (activate disabled)
✓ matrix cell (deactivate=off, activate=off): no changes regardless of qty/active
✓ is idempotent — second reconcile with same ids does not write again
✓ reconcileAll iterates in chunks and returns count of touched offers
✓ reconcileAll excludes operator-managed offers via WHERE filter at query level
✓ skips offer with active_managed_by=operator even when autoActivateOnStock would otherwise activate
```

| Setting (deact, act) | qty=0+active=true | qty>0+active=false | qty=0+active=false (already in target) | qty>0+active=true (already in target) |
|---|---|---|---|---|
| (on, on) | active=false, mb=plugin | active=true, mb=plugin | unchanged (idempotent) | unchanged (idempotent) |
| (on, off) | active=false, mb=plugin | unchanged | unchanged | unchanged |
| (off, on) | unchanged | active=true, mb=plugin | unchanged | unchanged |
| (off, off) | unchanged | unchanged | unchanged | unchanged |

(`mb` = `active_managed_by`)

## Decisions Made

- **D-03-04-01 — `is_scalar`-narrowing helper for `active_managed_by` reads.** PHPStan L10 sees the Eloquent magic property as `mixed`. `strval()` requires scalar input; `(string)` cast is forbidden by the project's "no type casts to silence errors" rule. The `managedByOperator(Offer): bool` private helper guards with `is_scalar()` then `strval()` — pure static-analysis formality (column DDL is `string(16) default 'system'`), but satisfies L10 without inline @var.
- **D-03-04-02 — Closure type for `chunkById` is `Illuminate\Database\Eloquent\Collection` not `October\Rain\Database\Collection`.** Larastan's stub for `Builder::chunkById` types the parameter as `Eloquent\Collection<int, Model>`. The `instanceof Offer` guard inside the closure narrows back to typed Offer for `reconcileSingleOffer`; the WHERE clause guarantees the runtime type.
- **D-03-04-03 — Defense-in-depth provenance skip.** Operator-managed offers are excluded BOTH at the per-row gate (reconcile path) AND at the WHERE clause (reconcileAll path). Either alone is sufficient for correctness; both together survive a future regression where one path's logic is silently changed.
- **D-03-04-04 — Reused 03-03's `universalObjectCratesClasses` + `intval()`/`strval()` pattern.** No new `phpstan.neon` edits this plan — the Phase 3 plan 03-03 stub + crate registration covers all Phase 3 services. Net effect: 03-04 GREEN gate added 1 file (the service) and 0 config changes.
- **D-03-04-05 — `ApplyTestCase` extended with `system_settings` table.** Required by tests that call `Settings::set` (which writes through SettingModel + Multisite trait). Reusable by 03-05 (InitialResetService consumes `SettingsAccessor::allowInitialReset`) and 03-07 (ApplyOrchestrator composes services that read settings). Reflects D-03-03-06 forecast: "Reusable by 03-04 / 03-05 / 03-07."

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] ApplyTestCase missing `system_settings` table — `Settings::set` writes failed with "no such table"**
- **Found during:** RED gate — initial test run failed with `SQLSTATE[HY000]: General error: 1 no such table: system_settings`.
- **Issue:** Plan 03-03 created `ApplyTestCase` with the four offer/product/invoice tables but did NOT include `system_settings`. Plan 03-04 tests drive plugin Settings via `Settings::set(...)`, which writes to `system_settings` through the SettingModel + Multisite trait.
- **Fix:** Extended `ApplyTestCase::setUp()` to create the `system_settings` table with exactly the columns October's SettingModel touches (`id`, `item`, `value`, `site_id`, `site_root_id`, `site_group_id`) — same hermetic-slice pattern already established in `SettingsAccessorTestCase`. tearDown drops it.
- **Files modified:** `tests/unit/Apply/ApplyTestCase.php` (+12 lines)
- **Verification:** All 9 new tests + the 12 prior 03-03 tests pass; no regressions.
- **Committed in:** `abcf000` (RED gate — schema add bundled with the test files that need it)

**2. [Rule 3 — Blocking] PHPStan L10: `chunkById` closure parameter type mismatch (`October\Rain\Database\Collection` vs `Illuminate\Database\Eloquent\Collection`)**
- **Found during:** GREEN gate `make analyse` after first implementation pass.
- **Issue:** Plan's prescribed `function (DbCollection $obChunk)` triggered `argument.type` because Larastan's stub for `Builder::chunkById` declares the closure parameter as `Eloquent\Collection<int, Model>` (the upstream Laravel type), not October Rain's subclass. PHPStan L10 cannot accept the contravariant override.
- **Fix:** Type the closure parameter as `Illuminate\Database\Eloquent\Collection`; iterate; `instanceof Offer` guard narrows to typed Offer for the helper call. The WHERE clause guarantees Offer rows at runtime; the guard is a static-analysis formality with a defensive `continue` branch.
- **Files modified:** `classes/apply/ActiveFlagService.php` (use statement + closure signature + instanceof block)
- **Verification:** `make analyse` → `[OK] No errors`. All 9 tests still pass; the new instanceof branch is covered by the `reconcileAll iterates in chunks` test which asserts the touched count.
- **Committed in:** `f380aba` (GREEN gate)

**3. [Rule 3 — Blocking] PHPStan L10: `(string) $obOffer->active_managed_by` cast on magic property**
- **Found during:** GREEN gate `make analyse` (same pass as #2).
- **Issue:** Eloquent magic-property access returns `mixed` per `universalObjectCratesClasses`. The `(string)` cast triggers `cast.string`. Project rules forbid `@var`, `@phpstan-ignore`, and "type casts just to silence errors". `strval()` (the StockApplyService pattern via `intval()`) requires scalar input; cannot accept `mixed` directly.
- **Fix:** Extracted a private `managedByOperator(Offer): bool` helper that applies `is_scalar()` to narrow `mixed` → scalar, then `strval()` satisfies L10. Added explanatory PHPDoc noting this is a static-analysis formality, not a runtime branch we expect to hit (column DDL is `string(16) default 'system'`).
- **Files modified:** `classes/apply/ActiveFlagService.php` (added `managedByOperator()` helper, replaced inline cast)
- **Verification:** `make analyse` → `[OK] No errors`. Behavior identical at runtime (the false branch never fires for column-typed string data).
- **Committed in:** `f380aba` (GREEN gate)

**4. [Rule 1 — Bug] QA-09 grep gate caught a literal `Settings::get(` token inside a docblock comment**
- **Found during:** GREEN gate `make lint-settings-accessor` (the QA-09 grep gate) — caught the threat-register entry "Settings::get( bypass" in the class docblock as a violation.
- **Issue:** The grep gate treats ANY occurrence of the literal token `Settings::get(` as a violation, including inside PHPDoc threat documentation. The docblock had used "Settings::get( bypass" descriptively.
- **Fix:** Rephrased the docblock entry from "Settings::get( bypass" to "direct SettingModel read bypass" — same semantic content, no false-positive token.
- **Files modified:** `classes/apply/ActiveFlagService.php` (threat docblock entry T-03-04-02)
- **Verification:** `make lint-settings-accessor` → exit 0. Docblock still describes the threat clearly.
- **Committed in:** `f380aba` (GREEN gate)

---

**Total deviations:** 4 auto-fixed (3 Rule 3 — Blocking, 1 Rule 1 — Bug).
**Impact on plan:** No scope creep. Three deviations were correctness/criterion satisfiers triggered by PHPStan L10 + QA-09 strictness; the schema add (#1) is a NET POSITIVE for the project — every future Apply-layer test that needs Settings now works without per-test schema add. The QA-09 self-trip (#4) reinforces that the grep gate is doing its job: even doc strings are policed.

## Issues Encountered

- **None requiring escalation.** All four auto-fixes resolved within ~3 min total during GREEN gate iteration. The pattern is established now: any future Apply-layer service that reads Lovata model attributes via Eloquent magic properties will need the `intval()`/`strval()` + `is_scalar()` narrowing pattern. ApplyTestCase's `system_settings` table is now durable for 03-05 and 03-07.

## Threat Surface Update

All three mitigations from `<threat_model>` now active:
- T-03-04-01 (Tampering — plugin overrides operator deactivation) — mitigated. `reconcileSingleOffer()` short-circuits at the FIRST line if `active_managed_by==='operator'` (no settings check, no qty check, no save). Asserted by SkipsManuallyDeactivatedOfferTest.
- T-03-04-02 (Tampering — direct SettingModel read bypass) — mitigated. Service uses `SettingsAccessor::*` exclusively; QA-09 grep gate (Makefile + Pest mirror) enforces at CI time. `grep -c 'Settings::get(' classes/apply/ActiveFlagService.php` returns 0.
- T-03-04-03 (DoS via reconcileAll memory) — mitigated. `reconcileAll` uses `chunkById($iChunkSize=500)`; never holds more than 500 Offer rows in memory regardless of total offer count.
- T-03-04-04 (Repudiation — lost active flag history) — accepted. Audit log fires once per Apply via ImportAuditService (plan 03-02). Operator can grep `active_managed_by` column for last-known provenance.
- T-03-04-05 (Information disclosure — soft-deleted offers in Offer::whereIn) — accepted. Eloquent's softDeletes scope excludes `deleted_at IS NOT NULL` by default; soft-deleted offers correctly skipped.

No new threat surface introduced. The `is_scalar` narrowing helper is a pure type-system construct with no runtime semantic difference.

## Note for Downstream Plans (03-07 ApplyOrchestrator)

- **Canonical orchestrator call:** Inside the `DB::transaction(function() use ($obInvoice) { ... })` block, after `StockApplyService::apply` returns `StockApplyOutcome`, call:
  ```php
  (new ActiveFlagService())->reconcile($obStockOutcome->affected_offer_ids);
  ```
  — same transaction (QA-08 ActiveFlagInsideSameTransactionAsStockApplyTest contract).
- **No cache flush from ActiveFlagService.** Cache flushes are centralized in `StockApplyService::flushAffectedCaches($arOfferIds)` called by orchestrator AFTER commit (D-10). ActiveFlagService never touches caches; it is safe to invoke from inside the transaction.
- **Settings checks are cheap.** The two-call pattern (`SettingsAccessor::autoDeactivateOnZero()` + `SettingsAccessor::autoActivateOnStock()`) is memoized after first call per request; the orchestrator does not need to pass settings into the service.

## Note for Downstream Plans (03-05 InitialResetService)

- **ApplyTestCase `system_settings` table is now durable.** InitialResetService tests that drive `SettingsAccessor::allowInitialReset` via `Settings::set` will work without any further schema edits.
- **`is_scalar` narrowing pattern is established.** If InitialResetService reads any Eloquent magic-property string column, mirror `managedByOperator()` shape: helper + `is_scalar()` + `strval()`.

## Next Phase Readiness

- **Phase 3 Wave 2 plan 03-05 (InitialResetService) unblocked** — schema base ready; SettingsAccessor::allowInitialReset getter present; `make all` green.
- **Phase 3 Wave 3 plan 03-07 (ApplyOrchestrator) partially unblocked** — Stock + ActiveFlag services both ready; awaits 03-05 (InitialReset) and 03-06 (orchestrator wave-2 deps if any).
- **Phase 4 UI-11 (`goodsreceived:recompute_active_from_stock` console command) entry point ready** — calls `(new ActiveFlagService())->reconcileAll()` and reports the returned int touched-count to the operator.
- **No blockers.**

## TDD Gate Compliance

- **RED gate:** `abcf000` (`test(03-04): add failing tests for ActiveFlagService (RED)`) — 9/9 new tests fail with "Class … not found"; 12/12 prior 03-03 tests still pass (regression-clean ApplyTestCase schema add).
- **GREEN gate:** `f380aba` (`feat(03-04): implement ActiveFlagService (APPLY-03 / APPLY-04 / QA-05 GREEN)`) — 9/9 pass / 48 assertions.
- **REFACTOR gate:** N/A (code minimal at GREEN; no duplication; methods within Tiger-Style 70-line caps).

Plan-level TDD type was `tdd="true"` on all three tasks; gates committed in correct order.

## Self-Check: PASSED

Verified at completion:

- ✓ `classes/apply/ActiveFlagService.php` exists (207 lines)
- ✓ `tests/unit/Apply/ActiveFlagServiceTest.php` exists (206 lines)
- ✓ `tests/unit/Apply/SkipsManuallyDeactivatedOfferTest.php` exists (51 lines)
- ✓ `tests/unit/Apply/ApplyTestCase.php` modified (+12 lines: system_settings table)
- ✓ Commit `abcf000` (RED gate) found in `git log --oneline`
- ✓ Commit `f380aba` (GREEN gate) found in `git log --oneline`
- ✓ `make all` exit 0 — all gates green (pint, lint-settings-accessor, analyse, phpmd, test)
- ✓ 127/127 tests pass (511 assertions total — up from 118/463)
- ✓ `phpstan-baseline.neon` SHA unchanged (`4b3227fa…`)
- ✓ `grep -c 'Settings::get(' classes/apply/ActiveFlagService.php` returns 0
- ✓ `grep -c 'SettingsAccessor::' classes/apply/ActiveFlagService.php` returns 6 (≥ 2 required)
- ✓ `grep -c 'saveQuietly' classes/apply/ActiveFlagService.php` returns 4 (≥ 1 required)
- ✓ `grep -E '\->save\(\)' classes/apply/ActiveFlagService.php | grep -v saveQuietly | wc -l` returns 0

---
*Phase: 03-apply-layer-orchestrators*
*Plan: 04 (ActiveFlagService — APPLY-03 / APPLY-04 / QA-05)*
*Completed: 2026-04-29*
