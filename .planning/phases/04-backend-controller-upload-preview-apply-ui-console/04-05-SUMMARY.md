---
phase: 04-backend-controller-upload-preview-apply-ui-console
plan: 05
subsystem: ui
tags: [backend, ajax, cache-lock, debounce, idempotency, modal, pest, tdd]

# Dependency graph
requires:
  - phase: 03-apply-layer-orchestrators
    provides: ApplyOrchestrator::apply (lockForUpdate + StockApply + ActiveFlag + audit log inside one transaction) + ApplyAlreadyDoneException + ApplyResult DTO
  - phase: 04-backend-controller-upload-preview-apply-ui-console
    provides: Invoices controller + 4 view templates (skeletons) + onUpload/onUpdateLine handlers + TestableInvoices boundary-mock shim (plan 04-04)
provides:
  - onApplyShowConfirm AJAX handler (confirmation modal — total_units / offer_count / unmatched_count)
  - onApply AJAX handler (Cache::lock-debounced apply via ApplyOrchestrator with try/finally release)
  - 2 new partials (_apply_confirm.htm + _apply_already_done.htm) + 2 partials rewritten (_apply_in_progress.htm + _apply_success.htm with lang-keyed copy)
  - 9 lang keys under `apply.*` (confirm/success/already-done/in-progress/button/spinner/validation messages)
  - resolveApplyOrchestrator() protected hook on Invoices controller (boundary-mock seam — mirrors resolveParseOrchestrator)
  - obApplyOrchestratorResolver Closure hook + iApplyOrchestratorResolvedCount counter on TestableInvoices shim
  - APPLY_LOCK_TTL_SECONDS = 60 const + Cache::lock('apply-invoice-{id}', 60) call site (UI-04 / D-13)
affects: [04-06, 04-07, phase-05]

# Tech tracking
tech-stack:
  added:
    - Illuminate\Support\Facades\Cache (Cache::lock primitive — already in Laravel 12 vendor)
    - Illuminate\Support\Facades\DB (DB::raw for COALESCE(override_qty, qty) sum — already in vendor)
  patterns:
    - try { runApplyUnderLock } finally { $obLock->release() } — load-bearing cleanup (T-04-05-02)
    - Inner try/catch for typed ApplyAlreadyDoneException → structured "already done" partial; outer try-finally releases the lock for ALL exception paths (preserves the cleanup contract under any throw)
    - Dual-pin (source-grep + runtime) for lock contracts — mirrors Phase 3 D-03-07-05 LockForUpdateSerializesConcurrentApplyTest pattern; survives test-driver no-op locks
    - Counter-pin via TestableInvoices.iApplyOrchestratorResolvedCount — proves "orchestrator was/was-not invoked" under each branch without facade-mocking app()
    - Boundary-mock support via final removal — D-04-05-01 mirrors D-03-07-01 (ImportAuditService) + D-04-02-01 (ActiveFlagService) precedent

key-files:
  created:
    - controllers/invoices/_partials/_apply_confirm.htm
    - controllers/invoices/_partials/_apply_already_done.htm
    - tests/unit/Controllers/ApplyHandlerTest.php
    - tests/unit/Controllers/ApplyDoubleClickDebounceTest.php
  modified:
    - controllers/Invoices.php (onApplyShowConfirm + onApply + runApplyUnderLock + resolveApplyOrchestrator hook + APPLY_LOCK_TTL_SECONDS const + 4 new use statements: Cache, DB, ApplyAlreadyDoneException, ApplyOrchestrator)
    - controllers/invoices/_partials/_apply_in_progress.htm (lang-keyed copy + structured shape; was scaffold)
    - controllers/invoices/_partials/_apply_success.htm (lang-keyed copy + ApplyResult counters; was scaffold)
    - classes/orchestrator/ApplyOrchestrator.php (final removed for boundary-mock support — D-04-05-01)
    - lang/en/lang.php (+9 keys under apply.*)
    - tests/unit/Controllers/InvoiceUploadTestHelpers.php (obApplyOrchestratorResolver hook + iApplyOrchestratorResolvedCount counter on TestableInvoices)

key-decisions:
  - "D-04-05-01: `final` keyword removed from `Logingrupa\\GoodsReceivedShopaholic\\Classes\\Orchestrator\\ApplyOrchestrator` to enable a failing-orchestrator subclass test that pins the `try { ... } finally { $obLock->release(); }` lock-release contract (T-04-05-02). Mirrors D-03-07-01 (ImportAuditService) + D-04-02-01 (ActiveFlagService) precedent. The class behaves as if final at the production-code boundary (`@internal` PHPDoc note); subclassing is sanctioned ONLY for unit-test failing-orchestrator shims used to pin boundary-layer cleanup contracts. Production code never subclasses — `app(ApplyOrchestrator::class)` always resolves the leaf class via `resolveApplyOrchestrator()` (the new protected hook on the controller)."
  - "D-04-05-02: COALESCE(override_qty, qty) DB::raw used in onApplyShowConfirm total-units math instead of an in-memory `Collection::sum` over hydrated InvoiceLine objects. Reason: matches StockApplyService's per-line read order (Phase 3 plan 03-03 — `override_qty ?? qty`) AND avoids the N+1 hydration cost of a Collection iteration. The DB engine evaluates COALESCE in the same SUM expression that runs against the matched_offer_id index — single SELECT, single sum aggregate."
  - "D-04-05-03: Inner try/catch around `$obOrchestrator->apply()` is for typed `ApplyAlreadyDoneException` ONLY — every other exception (RuntimeException, ModelNotFoundException, etc.) propagates with the lock still released by the outer `finally`. The handler's contract is: success ⇒ apply_success partial; idempotency violation ⇒ apply_already_done partial; ANY other failure ⇒ exception propagates to October's AJAX dispatcher (which renders a 500 in production). Pinned by 'onApply releases lock in finally even when ApplyOrchestrator throws' test that injects a synthetic RuntimeException and asserts (a) the exception propagates AND (b) Cache::lock(...)->get() succeeds afterwards (lock was released)."
  - "D-04-05-04: New `runApplyUnderLock` private helper extracted from onApply body. Original plan listed the inner try/catch inline inside onApply; extracting keeps the public handler under the 70-line cap (Tiger-Style §4) AND makes the try/finally semantics readable at the call site. Helper returns the array<string, mixed> response; outer onApply just acquires the lock + delegates + releases. The `try { } finally { }` pair is at the call boundary — visually 5 lines apart, structurally one stack frame, semantically the lock-release happens regardless of how runApplyUnderLock returns/throws."
  - "D-04-05-05: Dual-pin pattern (source-grep + runtime) for the Cache::lock contract. Source-grep pins (4 cases) survive any cache driver where Cache::lock is a no-op (e.g. `array` driver no-ops always succeed; nothing to assert at runtime). Runtime pins (2 cases) cover the actual short-circuit behavior + counter-pin via iApplyOrchestratorResolvedCount when the driver DOES support locking; the runtime arm uses `markTestSkipped` if the test bootstrap's driver no-ops. Together: file-level grep + runtime pins pin the contract on every driver. Mirrors Phase 3 D-03-07-05 LockForUpdateSerializesConcurrentApplyTest dual-pin precedent."
  - "D-04-05-06: TestableInvoices shim extended with apply-side resolver hook (`obApplyOrchestratorResolver`) + counter (`iApplyOrchestratorResolvedCount`) — symmetric mirror of the existing parse-side hook from plan 04-04. Counter-pin proves the orchestrator was NEVER invoked under the lock-not-acquired branch (the load-bearing assertion for D-13 fail-fast). Hook is read via `resolveApplyOrchestrator` override that delegates to the closure when set OR falls back to `parent::resolveApplyOrchestrator()` (which uses `app(ApplyOrchestrator::class)`)."

patterns-established:
  - "Cache::lock + try/finally release: outer try/finally around the ENTIRE post-acquire body releases the lock under ANY exception path; inner try/catch handles typed exceptions structurally. Defense-in-depth — typed exceptions reach the structured surface (apply_already_done partial); unknown exceptions propagate to the framework boundary, but the lock is ALWAYS released."
  - "Source-grep + counter-pin contract testing: source-grep ASSERTS the literal token (lock key shape, TTL constant, finally keyword) appears in the controller source; counter-pin via shim resolver-call counter ASSERTS the runtime path took/skipped the orchestrator call. Together: file-level + runtime pins survive driver no-ops + future refactors that silently re-key/move the lock."
  - "DB::raw COALESCE in aggregate sums: matches per-line read precedence (`override_qty ?? qty`) in a single SELECT instead of hydrating a Collection. Reusable for any UI surface that previews aggregate stock numbers under operator-edited override metadata."

requirements-completed: [UI-04]

# Metrics
duration: 6min
completed: 2026-04-29
---

# Phase 4 Plan 05: onApply + onApplyShowConfirm + Cache::lock Debounce Summary

**onApply AJAX handler ships UI-04: confirmation modal listing total units / offer count / unmatched lines, then Cache::lock(`apply-invoice-{id}`, 60)-debounced execution via ApplyOrchestrator with try/finally release; ApplyAlreadyDoneException renders structured already-done surface.**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-04-29T23:30:13Z (approximate; ~1 min before RED commit)
- **Completed:** 2026-04-29T23:36:51Z (Task 2 commit)
- **Tasks:** 2 (Task 1 onApplyShowConfirm + onApply with TDD; Task 2 dual-pin debounce contract test)

## Accomplishments

- `onApplyShowConfirm` AJAX handler renders confirmation modal listing exact numbers (total_units via COALESCE(override_qty, qty), offer_count, unmatched_count) before the operator commits the apply. Permission-gated (`apply_invoices`).
- `onApply` AJAX handler acquires `Cache::lock('apply-invoice-{id}', 60)` and refuses double-click double-apply (returns `apply_in_progress` partial when lock held). Runs ApplyOrchestrator under try/finally so any throw still releases the lock (T-04-05-02). Catches `ApplyAlreadyDoneException` and renders structured `apply_already_done` partial with prior-result context.
- 2 new partials (`_apply_confirm.htm` + `_apply_already_done.htm`); 2 partials rewritten with lang-keyed copy (`_apply_in_progress.htm` + `_apply_success.htm`).
- 9 new `apply.*` lang keys (confirm/success/already-done/in-progress titles + helps + button + spinner + validation messages).
- ApplyOrchestrator opened from `final` for failing-orchestrator subclass test (D-04-05-01 mirrors D-03-07-01 + D-04-02-01).
- Dual-pin debounce contract (source-grep + runtime) — 4 source-grep pins (`APPLY_LOCK_TTL_SECONDS = 60`, `apply-invoice-`, `Cache::lock(`, `->release()` + `finally`) + 2 runtime pins (lock-not-acquired skips orchestrator; control case proves lock isn't trivially blocking).
- TestableInvoices shim extended with apply-side resolver hook + counter (mirror of parse-side from plan 04-04 D-04-04-02).
- 15 new Pest cases (9 ApplyHandlerTest + 6 ApplyDoubleClickDebounceTest) bring suite to 201/201 (was 186/186, +15) / 937 assertions (was 880, +57). PHPStan L10 clean, Pint clean, PHPMD clean, QA-09 grep gate pass, phpstan-baseline.neon SHA `4b3227fa…91530a` UNCHANGED. `make all` green.

## Task Commits

Each task was committed atomically following plan-level TDD (RED → GREEN sequence):

1. **Task 1 RED:** `9354cc4` — `test(04-05): add failing tests for onApply + onApplyShowConfirm AJAX handlers` (9 it() cases pin UI-04 / D-11..D-15)
2. **Task 1 GREEN:** `1272cbb` — `feat(04-05): implement onApply + onApplyShowConfirm AJAX handlers (UI-04 / D-11..D-15)` (controller + 4 partials + lang + ApplyOrchestrator final-removal)
3. **Task 2 (test-pin):** `607e3d5` — `test(04-05): pin Cache::lock double-click debounce contract (D-13 / T-04-05-01)` (6 it() cases — 4 source-grep + 2 runtime — pin D-13 contract)

_Note: Task 2 ships test-only assertions that pin the existing implementation from Task 1 GREEN. No additional production code is needed — the contract was already shipped in Task 1; Task 2 adds the dual-pin discipline that survives future refactors._

## Files Created/Modified

- **`controllers/Invoices.php`** — added `onApplyShowConfirm` + `onApply` + `runApplyUnderLock` + `resolveApplyOrchestrator` hook + `APPLY_LOCK_TTL_SECONDS` const; 4 new use statements (Cache, DB, ApplyAlreadyDoneException, ApplyOrchestrator).
- **`classes/orchestrator/ApplyOrchestrator.php`** — `final` removed; `@internal` docblock note added explaining the boundary-mock rationale (D-04-05-01).
- **`controllers/invoices/_partials/_apply_confirm.htm`** (new) — confirmation modal listing total_units / offer_count / unmatched_count + override-mode warning + Apply now button (`data-request="onApply"`).
- **`controllers/invoices/_partials/_apply_already_done.htm`** (new) — structured already-applied surface displaying prior_applied_at / prior_applied_by / prior_stock_added_units from ApplyAlreadyDoneException.arContext.
- **`controllers/invoices/_partials/_apply_in_progress.htm`** (rewritten) — lang-keyed copy + help text for the lock-not-acquired branch.
- **`controllers/invoices/_partials/_apply_success.htm`** (rewritten) — lang-keyed copy + ApplyResult counters (units_added / offers_touched / lines_applied / lines_skipped).
- **`lang/en/lang.php`** — +9 keys under `apply.*`.
- **`tests/unit/Controllers/ApplyHandlerTest.php`** (new) — 9 it() cases pin UI-04 / D-11..D-15.
- **`tests/unit/Controllers/ApplyDoubleClickDebounceTest.php`** (new) — 6 it() cases pin D-13 / T-04-05-01 (4 source-grep + 2 runtime).
- **`tests/unit/Controllers/InvoiceUploadTestHelpers.php`** — TestableInvoices shim extended with `obApplyOrchestratorResolver` hook + `iApplyOrchestratorResolvedCount` counter.

## Decisions Made

See key-decisions in frontmatter (D-04-05-01..D-04-05-06). Highlights:

- **D-04-05-01:** ApplyOrchestrator opened from `final` for failing-orchestrator subclass test pinning the finally-release contract (T-04-05-02). Mirrors D-03-07-01 + D-04-02-01 precedent.
- **D-04-05-02:** COALESCE(override_qty, qty) DB::raw in confirmation modal total — matches StockApplyService's `override_qty ?? qty` per-line read order (Phase 3 plan 03-03) AND avoids N+1 hydration.
- **D-04-05-03:** Inner try/catch is for `ApplyAlreadyDoneException` ONLY; all other exceptions propagate with the lock still released by the outer finally.
- **D-04-05-04:** `runApplyUnderLock` private helper extracted from `onApply` body — keeps the public handler under the 70-line cap; semantics are still single-stack-frame from the lock acquire/release pair.
- **D-04-05-05:** Dual-pin pattern (source-grep + runtime) for Cache::lock contract — survives test-driver no-ops AND future refactors. Mirrors Phase 3 D-03-07-05.
- **D-04-05-06:** TestableInvoices shim extended with apply-side resolver hook + counter — symmetric mirror of plan 04-04's parse-side hook.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] ApplyOrchestrator opened from `final`**

- **Found during:** Task 1 RED (test compilation failure)
- **Issue:** Plan listed: "ApplyOrchestrator may also need to be opened from `final` for stub injection (similar to ImportAuditService in plan 03-07). Check if currently `final`; if so, document and remove with rationale." Empirically: yes, it was `final`, and the failing-orchestrator subclass test (`onApply releases lock in finally even when ApplyOrchestrator throws`) cannot extend it.
- **Fix:** Removed `final` keyword from `classes/orchestrator/ApplyOrchestrator.php`. Added `@internal` PHPDoc note explaining the boundary-mock rationale (D-04-05-01) and pinning the production-code invariant (`app(ApplyOrchestrator::class)` always resolves the leaf class).
- **Files modified:** `classes/orchestrator/ApplyOrchestrator.php`
- **Verification:** PHPStan L10 clean (no inheritance violations); 9 ApplyHandlerTest cases green including the failing-orchestrator throw-injection case.
- **Committed in:** `1272cbb` (Task 1 GREEN)

**2. [Rule 2 - Missing critical] `runApplyUnderLock` helper extraction**

- **Found during:** Task 1 GREEN (Tiger-Style §4 function-length review)
- **Issue:** The plan's listed `onApply` body inlined the inner try/catch around `$obOrchestrator->apply()` directly inside the outer try/finally. With permission gate + invoice_id validation + user-id resolution + lock acquire + lock-not-acquired branch + try/catch/finally, the function lands at ~50 lines but reads as densely-nested control flow.
- **Fix:** Extracted the inner try/catch into a `runApplyUnderLock(int $iInvoiceId, int $iUserId): array` private helper. Outer `onApply` just acquires the lock + delegates + releases. Semantics unchanged: the `try { runApplyUnderLock(...) } finally { $obLock->release(); }` pair is one stack frame; the lock release happens regardless of how the helper returns/throws.
- **Files modified:** `controllers/Invoices.php`
- **Verification:** PHPStan L10 clean; all 9 ApplyHandlerTest cases pass including T-04-05-02 (failing orchestrator → lock released).
- **Committed in:** `1272cbb` (Task 1 GREEN)

**3. [Rule 2 - Missing critical] `resolveApplyOrchestrator` protected hook for boundary-mock seam**

- **Found during:** Task 1 GREEN (test seam design)
- **Issue:** The plan's listed `onApply` body called `app(ApplyOrchestrator::class)` directly, which works for production but couples the handler to the IoC binding for tests. The TestableInvoices shim's parse-side resolver hook precedent (plan 04-04 D-04-04-02) showed the cleaner pattern.
- **Fix:** Added `protected function resolveApplyOrchestrator(): ApplyOrchestrator { return app(ApplyOrchestrator::class); }` and called it from `runApplyUnderLock`. The shim subclass overrides this hook to install tracking doubles via the `obApplyOrchestratorResolver` Closure + `iApplyOrchestratorResolvedCount` counter (Task 2 D-04-05-06).
- **Files modified:** `controllers/Invoices.php`, `tests/unit/Controllers/InvoiceUploadTestHelpers.php`
- **Verification:** Counter-pin proves orchestrator was NEVER invoked under the lock-not-acquired branch (D-13 fail-fast contract); orchestrator IS invoked under happy-path control case.
- **Committed in:** `1272cbb` (Task 1 GREEN — controller hook) + `607e3d5` (Task 2 — shim hook + counter)

**4. [Rule 1 - Bug] Test assertion swapped from English-message check to lang-key fragment check**

- **Found during:** Task 1 GREEN (test failure on `onApplyShowConfirm throws when invoice_id does not exist`)
- **Issue:** Test asserted `expect((string) $obException->getContents()['message'])->toContain('99999')` against the resolved English `Invoice #99999 not found.`. Lang::get returns the key path `logingrupa.goodsreceivedshopaholic::lang.apply.invoice_not_found` under the SQLite-in-memory unit-bootstrap (translations not loaded — D-04-04-07 precedent). Test was correctly RED but for the wrong reason.
- **Fix:** Swapped the assertion to `expect(strtolower($message))->toContain('invoice_not_found')` — pins the unique key fragment that survives both the unit-bootstrap (returns the key) AND production runtime (returns the resolved message, which is a separate concern covered by integration tests in Phase 5).
- **Files modified:** `tests/unit/Controllers/ApplyHandlerTest.php`
- **Verification:** Test green; comment in the test explains the lang-key fallback per D-04-04-07.
- **Committed in:** `1272cbb` (Task 1 GREEN — test + impl in same atomic commit per TDD-discipline)

**5. [Rule 2 - Missing critical] `markTestSkipped` fallback for cache-driver no-op locks**

- **Found during:** Task 1 GREEN (lock-not-acquired runtime case design)
- **Issue:** The runtime "lock cannot be acquired" test depends on the cache driver honoring lock semantics. Some test bootstrap configurations use the `array` driver, where `Cache::lock()->get()` is a no-op (always succeeds) — the `if (! $bHeld)` branch never fires, and the runtime arm cannot prove the contract.
- **Fix:** Wrapped the runtime arm with `if (! $bHeld) { $this->markTestSkipped(...); }` — the source-grep pins (Task 2) become authoritative when the runtime arm cannot run. Same pattern in both `ApplyHandlerTest::onApply renders apply_in_progress partial when Cache::lock cannot be acquired` and `ApplyDoubleClickDebounceTest::runtime: second concurrent onApply call`.
- **Files modified:** `tests/unit/Controllers/ApplyHandlerTest.php`, `tests/unit/Controllers/ApplyDoubleClickDebounceTest.php`
- **Verification:** Tests pass green (locking IS supported under the current test bootstrap — `markTestSkipped` is the documented fallback path for future drift).
- **Committed in:** `1272cbb` (ApplyHandlerTest) + `607e3d5` (ApplyDoubleClickDebounceTest)

**6. [Rule 1 - Bug] Pint auto-fix: anonymous-class style + new-with-parentheses**

- **Found during:** Task 1 GREEN + Task 2 (Pint --test failure)
- **Issue:** Pint flagged anonymous class declarations (`new class extends ApplyOrchestrator` → `new class () extends ApplyOrchestrator {`) and the `class_definition` style.
- **Fix:** Ran `vendor/bin/pint` to auto-fix; verified `--test` clean afterwards.
- **Files modified:** `tests/unit/Controllers/ApplyHandlerTest.php`, `tests/unit/Controllers/ApplyDoubleClickDebounceTest.php`
- **Verification:** `make pint-test` clean; tests still green.
- **Committed in:** `1272cbb` + `607e3d5` (Pint fixes folded into the same atomic commits)

---

**Total deviations:** 6 auto-fixed (1 Rule 3 blocking, 3 Rule 2 missing-critical, 2 Rule 1 bugs).
**Impact on plan:** All 6 deviations address either Tiger-Style invariants (function length, fail-fast under any throw path) or test-discipline correctness (key-fragment assertions over English-message coupling, dual-pin patterns surviving driver swaps, Pint style consistency). Zero scope creep — every change traces to UI-04 / D-11..D-15 acceptance.

## Issues Encountered

None — all 6 deviations were caught by the TDD RED → GREEN cycle, the analyser pipeline (`make all`), or the plan's own pre-flight key_facts. Each had a clear fix and was committed atomically with its task.

## Self-Check: PASSED

All claims verified:
- Created files exist: `controllers/invoices/_partials/_apply_confirm.htm` (FOUND), `controllers/invoices/_partials/_apply_already_done.htm` (FOUND), `tests/unit/Controllers/ApplyHandlerTest.php` (FOUND), `tests/unit/Controllers/ApplyDoubleClickDebounceTest.php` (FOUND).
- Commits exist: `9354cc4` (FOUND), `1272cbb` (FOUND), `607e3d5` (FOUND).
- Test suite: 201/201 green / 937 assertions; PHPStan L10 clean; Pint clean; PHPMD clean; QA-09 grep gate pass; phpstan-baseline.neon SHA `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` UNCHANGED.

## Next Phase Readiness

- UI-04 closed. Phase 4 has 4 of 8 requirements remaining: UI-08 (initial-reset checkbox), UI-10 (override-and-reimport UX), QA-10 (4 permission gate tests), and the Phase 4 final QA gate.
- Plan 04-06 (UI-08 + UI-10 — initial-reset + override-and-reimport handlers) ships next. Will reuse the TestableInvoices shim + `obApplyOrchestratorResolver` hook + counter pattern established here for any apply-orchestrator-adjacent assertions.
- Plan 04-07 (QA-10 permission gate tests) will consolidate per-action permission tests for `upload_invoices` (covered in 04-04), `apply_invoices` (covered in 04-05), `override_invoices` + `run_initial_reset` (will be covered after 04-06 ships).
- No blockers; no carried decisions.

---
*Phase: 04-backend-controller-upload-preview-apply-ui-console*
*Completed: 2026-04-29*
