---
phase: 03-apply-layer-orchestrators
plan: 07
subsystem: orchestrator
tags: [apply-orchestrator, db-transaction, lockforupdate, idempotency, partial-failure-rollback, post-commit-cache-flush, qa-03, qa-08, apply-07, apply-08, php8.4, pest4]

# Dependency graph
requires:
  - phase: 03-apply-layer-orchestrators
    plan: 02
    provides: ImportAuditService::logApply (vendor-inlined audit; opened final for test seam this plan)
  - phase: 03-apply-layer-orchestrators
    plan: 03
    provides: StockApplyService::apply + flushAffectedCaches + StockApplyOutcome carrier
  - phase: 03-apply-layer-orchestrators
    plan: 04
    provides: ActiveFlagService::reconcile (provenance-aware, settings-gated)
  - phase: 03-apply-layer-orchestrators
    plan: 06
    provides: ParseAndPersistOrchestrator::run + runOverride (used end-to-end by DuplicateInvoiceRejectedTest)
  - phase: 02-pure-parsers-dtos-exceptions-ean-matcher
    provides: ApplyResult DTO (4-int counter bag) + ApplyAlreadyDoneException
  - phase: 01-schema-scaffold-settings-permissions
    provides: Invoice + InvoiceLine models + STATUS_PARSED/STATUS_APPLIED + override_of_invoice_id FK + Settings model
provides:
  - ApplyOrchestrator — final class with apply() + 3 private helpers (executeInTransaction, assertNotApplied, markInvoiceApplied) + 1 query helper (loadMatchedLines); ONE DB::transaction wrapping the apply unit
  - Concurrency contract: Invoice::lockForUpdate() inside DB::transaction serializes concurrent apply() calls on the same invoice id via row-lock
  - Idempotency contract: status==='applied' check after lock acquisition throws ApplyAlreadyDoneException with rich prior-result context (invoice_id, invoice_number, prior_applied_at, prior_applied_by, prior_stock_added_units)
  - Atomicity contract: StockApply + ActiveFlag.reconcile + Invoice.status flip + audit.logApply ALL inside ONE DB::transaction; partial failure rolls back EVERYTHING (offer.quantity, line.applied, invoice.status)
  - Post-commit cache flush: StockApplyService::flushAffectedCaches called AFTER DB::transaction returns (D-10) — flushing inside the tx would repopulate stores from in-flight stale data
  - Override-reimport additive math (D-12): no special-case logic in the orchestrator; ADD-ON-TOP semantics emerge naturally because StockApply does qty += delta
  - 6 dedicated QA test files: 4 QA-03 (idempotency) + 2 QA-08 (transaction safety) — each in its own Pest file for grep-by-name and CI failure attribution
  - ImportAuditService opened (was final) — boundary class allowed to be subclassed as test seam (PartialFailureRollsBackEverythingTest's FailingAuditService)
affects: [03-08-final-qa-gate, 04-controller-apply-button, 04-controller-override-reimport-apply]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Single-tx apply boundary: DB::transaction(fn(): StockApplyOutcome => $this->executeInTransaction(...)) wraps lockForUpdate + status check + StockApply + ActiveFlag.reconcile + status flip + audit.logApply. The closure returns the outcome; apply() unwraps and runs flushAffectedCaches AFTER the closure returns. T-03-07-02 / T-03-07-04 mitigation."
    - "lockForUpdate-inside-transaction (NOT outside): Invoice::where('id', $iId)->lockForUpdate()->firstOrFail() runs as the FIRST query inside the DB::transaction closure. lockForUpdate outside a tx is a no-op on most drivers (lock releases immediately because no tx holds it). The canonical Laravel pattern is: open tx FIRST, lock INSIDE — lock held until commit/rollback. T-03-07-01 mitigation."
    - "Post-commit cache flush ordering (D-10): flushAffectedCaches called OUTSIDE the closure, AFTER DB::transaction returns. Flushing INSIDE the tx repopulates list stores from in-flight stale data; the only correct ordering is post-commit. Pinned structurally by source-grep ordering (LockForUpdateSerializesConcurrentApplyTest source pin)."
    - "No try/catch around DB::transaction: typed exceptions propagate to the Phase 4 controller. The transaction rolls back automatically; ApplyAlreadyDoneException + any GoodsReceivedException reach the caller cleanly. Tiger-Style fail-fast — log + rethrow happens at the boundary layer (controller / console command), not inside the orchestrator."
    - "Boundary-stub-as-test-seam (Tiger-Style allowance): ImportAuditService is a Log::* facade wrapper (logging boundary). PartialFailureRollsBackEverythingTest extends it with FailingAuditService that throws inside logApply, triggering full rollback. CLAUDE.md's 'no mocking business logic' rule explicitly carves out boundary mocks; the actual StockApply + ActiveFlag run real code against real SQLite tables."
    - "DB::beforeExecuting transactionLevel observation (Laravel instrumentation hook): ActiveFlagInsideSameTransactionAsStockApplyTest records the transactionLevel for every UPDATE on lovata_shopaholic_offers. Both quantity (StockApply) AND active (ActiveFlag) updates run with transactionLevel >= 1 (inside a tx). Regression where ActiveFlag.reconcile runs OUTSIDE the transaction would produce active UPDATE at transactionLevel=0, failing the loop assertion."
    - "Source-level structural pin for SQLite-stripped lock semantics: SQLite's grammar.compileLock returns empty string (Laravel SQLiteGrammar.php:31 — by design; SQLite single-file DBs cannot offer cross-process row locks). So the runtime SQL log NEVER contains 'for update'. The only reliable pin for the lockForUpdate CALL is the source. LockForUpdateSerializesConcurrentApplyTest combines: (1) source grep that ->lockForUpdate() exists inside executeInTransaction, AND (2) runtime ordering — Invoice SELECT precedes any offer UPDATE. Together they prove the contract on every driver."
    - "Typed October Rain Database\\Collection<int, InvoiceLine> via instanceof loop: PHPStan L10 sees the Eloquent magic relation Invoice::lines() as mixed; querying InvoiceLine directly gives a typed Builder<InvoiceLine>, then a foreach + instanceof narrows the rows into a list<InvoiceLine> the October Collection constructor accepts. Same SELECT plan as the magic relation (matched_offer_id index covers the WHERE)."

key-files:
  created:
    - classes/orchestrator/ApplyOrchestrator.php (258 lines / ~95 LoC of code; apply() + 4 private helpers, all <70 lines per Tiger-Style)
    - tests/unit/Orchestrator/ApplyOrchestratorTest.php (92 lines / 1 happy-path Pest case, 13 assertions)
    - tests/unit/Orchestrator/DuplicateInvoiceRejectedTest.php (79 lines / 1 QA-03 case, 11 assertions)
    - tests/unit/Orchestrator/OverrideReimportAddsOnTopTest.php (94 lines / 1 QA-03 case, 7 assertions)
    - tests/unit/Orchestrator/ApplyAlreadyDoneThrowsTest.php (71 lines / 1 QA-03 case, 15 assertions)
    - tests/unit/Orchestrator/LockForUpdateSerializesConcurrentApplyTest.php (158 lines / 2 QA-03 cases, 17 assertions — source pin + runtime ordering pin)
    - tests/unit/Orchestrator/PartialFailureRollsBackEverythingTest.php (109 lines / 1 QA-08 case, 12 assertions; FailingAuditService boundary stub)
    - tests/unit/Orchestrator/ActiveFlagInsideSameTransactionAsStockApplyTest.php (131 lines / 1 QA-08 case, 9 assertions; DB::beforeExecuting hook)
  modified:
    - classes/support/ImportAuditService.php (104 lines — opened from final; added rationale docblock for the test-seam allowance)

key-decisions:
  - "D-03-07-01 (2026-04-29): ImportAuditService opened (final removed). Rationale: the class is a Log::* facade wrapper — a logging boundary. CLAUDE.md's Tiger-Style rule prohibits mocking business logic but explicitly carves out boundary mocks. PartialFailureRollsBackEverythingTest needs a way to throw inside logApply mid-transaction; the cleanest path is FailingAuditService extending the real class. Production code still constructs new ImportAuditService() directly via the orchestrator's default constructor argument; no production subclassing exists or is planned."
  - "D-03-07-02 (2026-04-29): ApplyOrchestrator constructor uses optional null defaults + `??` fallback in body (NOT `new` in parameter defaults). Same rationale as D-03-06-01 from the sibling ParseAndPersistOrchestrator: PHP 8.4's 'new in initializers' RFC + readonly properties hits static-analyzer potholes that the explicit body-assignment form sidesteps. Keeps Larastan + PHPStan L10 clean across PHP 8.3 dev / 8.4 prod."
  - "D-03-07-03 (2026-04-29): InvoiceLine query goes through the model directly (InvoiceLine::where('invoice_id', $iId)->...) — NOT via the Invoice::lines() magic relation. PHPStan L10 sees October's hasMany declaration as mixed, and the project's phpstan.neon comments forbid inline @var. The direct-model query gives a typed Builder<InvoiceLine>; an instanceof loop narrows the rows into a list<InvoiceLine> wrapped in a typed October\\Rain Database\\Collection. Same SELECT plan (the matched_offer_id index covers it), zero PHPStan suppressions."
  - "D-03-07-04 (2026-04-29): No try/catch around DB::transaction in ApplyOrchestrator (in contrast to ParseAndPersistOrchestrator which catches GoodsReceivedException for reject logging). Apply-side has no equivalent need: ApplyAlreadyDoneException is the only orchestrator-thrown plugin exception, and it carries enough context for the controller to render directly. Audit.logApply already ran INSIDE the tx for successful applies; failed applies don't need a separate reject log because the rollback itself is the correct outcome. Tiger-Style fail-fast — let the typed exception propagate."
  - "D-03-07-05 (2026-04-29): LockForUpdateSerializesConcurrentApplyTest uses TWO independent assertions: (1) source-grep that ->lockForUpdate() appears inside executeInTransaction's body, (2) runtime query log that the Invoice SELECT precedes any offer UPDATE. SQLite's compileLock returns empty (Laravel SQLiteGrammar.php — by design), so the textual 'for update' will never appear in executed SQL on the test bootstrap. The source grep is the only reliable pin for the CALL itself; the runtime ordering proves the orchestrator runs the lock-acquiring SELECT first; together they pin the contract on every driver."
  - "D-03-07-06 (2026-04-29): Lang::get('exception.apply_already_done') used directly (no Translator service). Same pattern as ParseAndPersistOrchestrator's DuplicateInvoiceException construction. The Lang facade is the canonical October CMS i18n entry point; the lang key already exists in lang/en/lang.php (line 42)."

patterns-established:
  - "Single-tx apply boundary owned by orchestrator (NOT controller). Controller stays thin: calls orchestrator->apply(invoiceId, userId), catches ApplyAlreadyDoneException for UX, propagates other exceptions. The atomicity contract lives in the orchestrator, not the caller."
  - "lockForUpdate-INSIDE-DB::transaction serializer pattern: open tx first, lock inside. Reusable for any future orchestrator that needs cross-request serialization on a row (e.g., InitialResetService's one-shot guard if it grows multi-step)."
  - "Post-commit cache flush ordering: flushAffectedCaches OUTSIDE the closure. Reusable: any future apply orchestrator that touches Lovata Shopaholic stores must follow the same ordering or stale-cache regression follows."
  - "Boundary-class openness for test seams: ImportAuditService non-final, justified by docblock. Other Phase 3 boundary classes (Log/Queue/Mail wrappers) follow this rule when test doubles are needed."
  - "DB::beforeExecuting transactionLevel observation: a non-mocking instrumentation hook for transaction-safety contracts. Reusable for any future test that needs to prove 'X runs inside the same tx as Y' without mocking the actual services."

requirements-completed: [APPLY-07, APPLY-08, QA-03, QA-08]

# Metrics
duration: ~50min
completed: 2026-04-29
---

# Phase 3 Plan 07: ApplyOrchestrator Summary

**ApplyOrchestrator with Invoice::lockForUpdate inside DB::transaction wrapping StockApply + ActiveFlag.reconcile + status flip + audit.logApply, post-commit cache flush, ADD-ON-TOP override semantics — 6 dedicated QA test files (4 QA-03 idempotency + 2 QA-08 transaction safety) all green.**

## Performance

- **Duration:** ~50 min
- **Started:** 2026-04-29T20:35Z
- **Completed:** 2026-04-29T21:26Z
- **Tasks:** 3 (atomic per-task commits)
- **Files modified:** 9 (1 orchestrator created, 7 test files created, 1 audit service opened)

## Accomplishments

- ApplyOrchestrator (`classes/orchestrator/ApplyOrchestrator.php`) — 258 lines, ~95 LoC of executable code. apply() public method + 4 private helpers (executeInTransaction, assertNotApplied, loadMatchedLines, markInvoiceApplied), all <70 lines per Tiger-Style. Single `DB::transaction` wraps lockForUpdate + status check + StockApply + ActiveFlag.reconcile + status flip + audit.logApply. flushAffectedCaches AFTER tx commits.
- 7 Pest test files in `tests/unit/Orchestrator/` covering APPLY-07, APPLY-08 (apply-side), QA-03 (4 idempotency invariants), QA-08 (2 transaction-safety invariants). 8 it() cases / 84 assertions total — all green on first full pass after RED→GREEN cycle.
- 4 of 16 Phase 3 requirements closed by this plan: APPLY-07, APPLY-08, QA-03, QA-08. Plan 03-08 (final QA gate) opens with all phase requirements ready for green-light verification.
- No PHPStan baseline drift (still empty: `phpstan-baseline.neon` SHA `4b3227fa…` unchanged from before plan 03-07).

## Task Commits

Each task was committed atomically:

1. **Task 1: Build ApplyOrchestrator with lockForUpdate + tx + post-commit flush (APPLY-07/APPLY-08)** — `28369a3` (feat)
2. **Task 2: ApplyOrchestrator happy-path test + 4 QA-03 tests** — `f9c1e7f` (test)
3. **Task 3: 2 QA-08 transaction-safety tests + open ImportAuditService for test seam** — `8f00bfd` (test)

**Plan metadata commit:** _pending — created with this SUMMARY_

_Note: Tasks 1+2+3 were each `tdd="true"` per the plan; the orchestrator (Task 1) was implemented + verified via PHPStan/Pint/PHPMD (no test in Task 1 itself per the plan structure — the tests landed in Tasks 2 and 3 to keep one Pest file per QA-named contract for grep-by-name clarity)._

## Files Created/Modified

### Created

- `classes/orchestrator/ApplyOrchestrator.php` (258 lines) — apply-side orchestrator with single-tx boundary, lockForUpdate, post-commit cache flush
- `tests/unit/Orchestrator/ApplyOrchestratorTest.php` (92 lines, 1 case) — happy path: stock additive, line markers, invoice flip, ApplyResult shape
- `tests/unit/Orchestrator/DuplicateInvoiceRejectedTest.php` (79 lines, 1 case) — QA-03: parse → apply → re-parse → DuplicateInvoiceException end-to-end
- `tests/unit/Orchestrator/OverrideReimportAddsOnTopTest.php` (94 lines, 1 case) — QA-03 / D-12: 10 → 15 → 20 additive (no decrement-then-reapply)
- `tests/unit/Orchestrator/ApplyAlreadyDoneThrowsTest.php` (71 lines, 1 case) — QA-03 / D-24 step 2: status=applied → ApplyAlreadyDoneException with rich prior context
- `tests/unit/Orchestrator/LockForUpdateSerializesConcurrentApplyTest.php` (158 lines, 2 cases) — QA-03 / D-24 step 1: source-pin + runtime-ordering pin (SQLite strips `for update`, so source grep is the only reliable pin for the call itself)
- `tests/unit/Orchestrator/PartialFailureRollsBackEverythingTest.php` (109 lines, 1 case) — QA-08 / D-25: FailingAuditService stub triggers rollback; offer.quantity, line.applied, invoice.status all revert
- `tests/unit/Orchestrator/ActiveFlagInsideSameTransactionAsStockApplyTest.php` (131 lines, 1 case) — QA-08 / D-15: DB::beforeExecuting hook records transactionLevel for both StockApply quantity UPDATE and ActiveFlag active UPDATE; both >= 1 (inside tx)

### Modified

- `classes/support/ImportAuditService.php` — opened from `final class` to `class` with rationale docblock; required for the FailingAuditService boundary-stub seam used in PartialFailureRollsBackEverythingTest. Tiger-Style boundary-mock allowance per CLAUDE.md.

## Verification Output

### Grep checks (code-call counts only, excluding docblock prose)

```
$ grep -nE '(DB::transaction\(|->lockForUpdate\(|->flushAffectedCaches\(|Settings::get\()' classes/orchestrator/ApplyOrchestrator.php
125:        $obOutcome = DB::transaction(
132:        $this->obStockApply->flushAffectedCaches($obOutcome->affected_offer_ids);
146:        $obInvoice = Invoice::where('id', $iInvoiceId)->lockForUpdate()->firstOrFail();
```

- `DB::transaction(` (call): **1** ✓
- `->lockForUpdate(` (call): **1** ✓
- `->flushAffectedCaches(` (call): **1** ✓
- `Settings::get(` (anywhere): **0** ✓ (orchestrator never reads Settings directly; flow is via SettingsAccessor inside ActiveFlagService — QA-09 invariant preserved)

### make all output

```
$ make pint-test            → {"result":"pass"}
$ make lint-settings-accessor → QA-09 grep gate: PASS
$ make analyse              → 31 files / 0 errors (PHPStan L10)
$ make phpmd                → 0 violations (Lovata ruleset)
$ make test                 → 145 passed (708 assertions) in 7.42s
```

### Plan-specific test run

```
$ vendor/bin/pest --filter='ApplyOrchestrator|DuplicateInvoiceRejected|OverrideReimportAddsOnTop|ApplyAlreadyDoneThrows|LockForUpdateSerializesConcurrentApply|PartialFailureRollsBackEverything|ActiveFlagInsideSameTransactionAsStockApply'

  PASS  ApplyAlreadyDoneThrowsTest          ✓ 1 case
  PASS  ApplyOrchestratorTest               ✓ 1 case
  PASS  DuplicateInvoiceRejectedTest        ✓ 1 case
  PASS  LockForUpdateSerializesConcurrentApplyTest  ✓ 2 cases
  PASS  OverrideReimportAddsOnTopTest       ✓ 1 case
  PASS  PartialFailureRollsBackEverythingTest      ✓ 1 case
  PASS  ActiveFlagInsideSameTransactionAsStockApplyTest  ✓ 1 case

  Tests: 8 passed (84 assertions)
```

### PHPStan baseline integrity

```
phpstan-baseline.neon SHA before plan 03-07: 4b3227fa…b9b530a
phpstan-baseline.neon SHA after  plan 03-07: 4b3227fa…b9b530a   (UNCHANGED)
```

## Test Counts

| File                                               | Cases | Assertions |
| -------------------------------------------------- | ----: | ---------: |
| ApplyOrchestratorTest.php                          |     1 |         13 |
| DuplicateInvoiceRejectedTest.php                   |     1 |         11 |
| OverrideReimportAddsOnTopTest.php                  |     1 |          7 |
| ApplyAlreadyDoneThrowsTest.php                     |     1 |         15 |
| LockForUpdateSerializesConcurrentApplyTest.php     |     2 |         17 |
| PartialFailureRollsBackEverythingTest.php          |     1 |         12 |
| ActiveFlagInsideSameTransactionAsStockApplyTest.php |    1 |          9 |
| **Plan 03-07 total**                               | **8** |     **84** |

### Cumulative Phase 3 test counts (through plan 03-07)

```
$ vendor/bin/pest --configuration plugins/logingrupa/goodsreceivedshopaholic/phpunit.xml
Tests: 145 passed (708 assertions)
Duration: 7.42s
```

Plan 03-07 added 8 cases / 84 assertions to the prior baseline (137 cases / 624 assertions through plan 03-06). All Phase 3 plans 01-07 contribute to this 145-case cumulative count.

## Decisions Made

See frontmatter `key-decisions` for the full list. Six decisions captured:

- **D-03-07-01** Open ImportAuditService for boundary-stub test seam (Tiger-Style allowance)
- **D-03-07-02** Constructor null-default + `??` fallback (PHPStan-friendly form)
- **D-03-07-03** InvoiceLine query through the model (typed Builder, no inline @var)
- **D-03-07-04** No try/catch around DB::transaction (apply has no reject-log need)
- **D-03-07-05** LockForUpdate test: source pin + runtime ordering pin (SQLite strips `for update`)
- **D-03-07-06** Lang::get for exception message (canonical October i18n entry point)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] LockForUpdateSerializesConcurrentApplyTest: SQLite strips `for update` from compiled SQL**

- **Found during:** Task 2 (5 QA-03 tests)
- **Issue:** Plan called for the test to grep `for update` from the runtime query log. SQLite's grammar.compileLock returns empty string by design (single-file DBs cannot offer cross-process row locks); the `for update` fragment NEVER appears in executed SQL on SQLite. Test failed with `expecting null not to be null` on the lockIndex search.
- **Fix:** Split into TWO independent assertions in the same test file: (1) source-grep that `->lockForUpdate()` appears inside `executeInTransaction`'s body (the only reliable pin for the CALL itself on SQLite), (2) runtime query log that the SELECT on `logingrupa_goods_received_invoices` precedes any UPDATE on `lovata_shopaholic_offers` (proves order-of-execution contract regardless of grammar). Together these pin the contract on every driver.
- **Files modified:** tests/unit/Orchestrator/LockForUpdateSerializesConcurrentApplyTest.php (split 1 case into 2 cases — source pin + runtime pin)
- **Verification:** Both cases pass; 17 assertions total
- **Committed in:** `f9c1e7f` (Task 2 commit)

**2. [Rule 3 - Blocking] ImportAuditService was `final`, blocking the FailingAuditService extension required for QA-08 PartialFailureRollsBackEverythingTest**

- **Found during:** Task 3 (QA-08 partial-failure rollback test)
- **Issue:** Plan task 3 spec assumed FailingAuditService could `extends ImportAuditService`. PHP fatal: `Class FailingAuditService cannot extend final class`. Without the extension seam, the QA-08 test cannot inject a controlled exception inside the apply transaction.
- **Fix:** Removed `final` from ImportAuditService class declaration; added rationale docblock noting the class is a logging boundary (Log::* facade wrapper) and the open-for-extension allowance is restricted to test-double seams. Production code still constructs `new ImportAuditService()` directly via the orchestrator's default constructor argument — no production subclassing exists or is planned. CLAUDE.md's Tiger-Style "no mocking business logic" rule explicitly carves out boundary mocks; logging-boundary stubs qualify.
- **Files modified:** classes/support/ImportAuditService.php (1 line: `final class` → `class`; +7 lines rationale docblock)
- **Verification:** PHPStan L10 clean; Pint pass; PartialFailureRollsBackEverythingTest passes (12 assertions)
- **Committed in:** `8f00bfd` (Task 3 commit)

**3. [Rule 1 - Bug] Source-grep ordering test: `lockForUpdate` first appearance is in the class docblock (line 24), not the call site**

- **Found during:** Task 2 (LockForUpdateSerializesConcurrentApplyTest source pin)
- **Issue:** Initial test used `strpos($sSource, 'lockForUpdate()')` — found the docblock occurrence at byte 979, which is BEFORE `executeInTransaction` declaration at byte 7361. The ordering assertion `lockPos > execPos` failed.
- **Fix:** Changed to `strpos($sSource, '->lockForUpdate()', $iExecPos)` — searches starting AFTER the executeInTransaction declaration so docblock prose is excluded. Same fix applied to the `flushAffectedCaches` ordering check (use `->flushAffectedCaches(` and search after `apply(` declaration).
- **Files modified:** tests/unit/Orchestrator/LockForUpdateSerializesConcurrentApplyTest.php (refined source-grep offsets)
- **Verification:** Both source-pin assertions pass; ordering proven (apply() body has flushAffectedCaches AFTER DB::transaction call AND BEFORE executeInTransaction declaration)
- **Committed in:** `f9c1e7f` (Task 2 commit)

---

**Total deviations:** 3 auto-fixed (2 Rule 1 bugs in tests, 1 Rule 3 blocking change to ImportAuditService)
**Impact on plan:** All three were necessary for correctness. The lockForUpdate SQLite-strip discovery is a useful documented constraint for future plans (any test that wants to grep `for update` on SQLite will hit the same wall). The ImportAuditService open-for-extension is justified by the boundary-stub allowance and bounded by the rationale docblock — no scope creep into other final classes.

## Issues Encountered

- PHPStan L10 wrestled with the October Rain Database\\Collection<int, InvoiceLine> generic — initial wrap-in-DbCollection attempt failed because the Collection constructor accepts `array<int, mixed>`, not `list<InvoiceLine>`. Resolved via the helper-method pattern (`loadMatchedLines(int $iInvoiceId): DbCollection` with explicit instanceof loop + typed list + `@var` for the local). The local `@var` form is allowed (the SDK rule prohibits inline `@var` for OVERRIDING inferred types, not for declaring new locals).
- Test file order: ApplyTestCase.php is required by all tests in `tests/unit/Apply/` AND by the orchestrator tests. Used `require_once __DIR__.'/../Apply/ApplyTestCase.php';` before `uses(ApplyTestCase::class)` — same pattern as ParseAndPersistOrchestratorTest from plan 03-06.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

**Plan 03-08 (final QA gate):** ALL 16 Phase 3 requirements should now be CLOSED:

- APPLY-01..10 (closed across plans 03-01 through 03-07)
- QA-03, QA-04, QA-05, QA-06, QA-08, QA-09 (closed across plans 03-01, 03-03, 03-04, 03-05, 03-07)

Plan 03-08 opens with a green-light verification + baseline integrity check + cross-plan integration smoke. No new code expected; the baseline SHA must remain `4b3227fa…b9b530a` through plan 03-08.

## Self-Check: PASSED

- `classes/orchestrator/ApplyOrchestrator.php` exists ✓
- `tests/unit/Orchestrator/ApplyOrchestratorTest.php` exists ✓
- `tests/unit/Orchestrator/DuplicateInvoiceRejectedTest.php` exists ✓
- `tests/unit/Orchestrator/OverrideReimportAddsOnTopTest.php` exists ✓
- `tests/unit/Orchestrator/ApplyAlreadyDoneThrowsTest.php` exists ✓
- `tests/unit/Orchestrator/LockForUpdateSerializesConcurrentApplyTest.php` exists ✓
- `tests/unit/Orchestrator/PartialFailureRollsBackEverythingTest.php` exists ✓
- `tests/unit/Orchestrator/ActiveFlagInsideSameTransactionAsStockApplyTest.php` exists ✓
- Commit `28369a3` (Task 1 feat) ✓
- Commit `f9c1e7f` (Task 2 test) ✓
- Commit `8f00bfd` (Task 3 test) ✓

---
*Phase: 03-apply-layer-orchestrators*
*Completed: 2026-04-29*
