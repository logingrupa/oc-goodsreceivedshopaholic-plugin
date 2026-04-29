---
phase: 03-apply-layer-orchestrators
plan: 02
subsystem: testing
tags: [audit, logging, log-facade, structured-context, correlation-id, uuid7, vendor-inline, apply-10, php8.4, pest4, laravel12]

# Dependency graph
requires:
  - phase: 02-pure-parsers-dtos-exceptions-ean-matcher
    provides: ParsedInvoice DTO (invoice_number, source_filename, lines, skipped_rows fields)
  - phase: 02-pure-parsers-dtos-exceptions-ean-matcher
    provides: ApplyResult DTO (units_added, offers_touched, lines_applied, lines_skipped fields)
  - phase: 01-schema-scaffold-settings-permissions
    provides: GoodsReceivedTestCase + Pest 4 / PHPUnit 12 harness
provides:
  - ImportAuditService — vendor-inlined ~50-80 LoC audit log service (4 public methods)
  - Canonical event taxonomy: apply / parse / reject / initial_reset
  - Per-call uuid v7 correlation_id for joinable audit trails
  - Established pattern: Log facade as boundary (spied in tests, not mocked as business logic)
affects: [03-05-initialreset, 03-06-parseandpersist-orch, 03-07-apply-orch, 04-backend-controller]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Vendor-inline audit service pattern: NO soft-dep on sibling plugin (D-14); ~100 LoC ceiling enforced via build-helper extraction (one-liner public methods delegating to private build*Context() helpers)"
    - "Structured-context logging via Laravel Log facade: fixed string-literal message + json_encoded array context — PSR-3 formatter handles control-char escaping (T-03-02-01 mitigation inherited)"
    - "Service-controlled keys merged LAST in array_merge for logReject() — caller-supplied context cannot overwrite event/reason/correlation_id"
    - "uuid v7 correlation IDs (Str::uuid7 — Laravel 12) — time-ordered so audit lines remain temporally sortable when grepped or shuffled"

key-files:
  created:
    - classes/support/ImportAuditService.php
    - tests/unit/Support/ImportAuditServiceTest.php
  modified: []

key-decisions:
  - "D-03-02-01 (2026-04-29): Fresh correlation_id PER CALL (not per-orchestrator-run). Cross-call threading deferred to keep ≤100 LoC ceiling per D-04. Future enhancement: optional ctor param for shared id when downstream join is required (T-03-02-04 acceptance documented in class docblock)."
  - "D-03-02-02 (2026-04-29): logReject array_merge order — service-controlled keys (event/reason/correlation_id) merged AFTER caller context so they cannot be overwritten by caller-supplied keys with the same name. Defensive coding for future caller hygiene drift."
  - "D-03-02-03 (2026-04-29): Removed runtime method_exists fallback for Str::uuid7 — Laravel 12 ALWAYS ships it (PHPStan L10 narrowed-type error). The phpstan-error catch is the proof; v4 fallback is dead code in this dependency stack."

patterns-established:
  - "Audit boundary singleton: instance-based (constructor-less, stateless), `new ImportAuditService()` per orchestrator. NO service container registration — DI overhead at zero, instances cheap."
  - "Test pattern for Log facade: \\Log::spy() in beforeEach + \\Log::shouldHaveReceived('info|warning')->withArgs(closure) capturing both message + context. The Log facade is a boundary, not business logic — spying on it complies with the project Tiger-Style rule (no mocking business logic)."
  - "Threat-model-as-docblock: each service's class PHPDoc enumerates the threat IDs it mitigates (T-03-02-01..04). The threat register lives in the PLAN.md; the class doc anchors WHICH code handles WHICH threat for grep-back during audits."

requirements-completed: [APPLY-10]

# Metrics
duration: 5min
completed: 2026-04-29
---

# Phase 3 Plan 02: ImportAuditService Summary

**Vendor-inlined ~65-LoC structured-context audit log service routing 4 event types (apply/parse/reject/initial_reset) through Laravel's Log facade with per-call uuid v7 correlation_id — closes APPLY-10 with no soft-dep on ExtendShopaholic (D-14).**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-04-29T20:06:23Z
- **Completed:** 2026-04-29T20:11:00Z (approx)
- **Tasks:** 1 of 1
- **Files modified:** 2 (created)

## Accomplishments

- `ImportAuditService` final class with 4 public log methods (`logApply`, `logParse`, `logReject`, `logInitialReset`) + 3 private helpers (`buildApplyContext`, `buildParseContext`, `correlationId`)
- Routes through `Illuminate\Support\Facades\Log::info` / `Log::warning` with structured-context arrays — host project's configured channels (file / stack / sentry) pick up audit lines automatically
- Each entry carries canonical `event` key (apply / parse / reject / initial_reset) + fresh uuid v7 `correlation_id` (time-ordered for sortable audit trails)
- 6 Pest cases / 130 assertions — all passing
- File line count: **96 raw / 65 code** (target ≤100 raw per D-04 ceiling — satisfied)
- PHPStan level 10 clean, PHPMD clean, Pint clean, full `make all` pipeline green: 106/106 tests, 407 assertions, 1.87s

## Task Commits

Each task was committed atomically following the TDD gate sequence:

1. **Task 1 RED — failing tests for ImportAuditService** — `758f1f3` (test)
2. **Task 1 GREEN — ImportAuditService implementation** — `189d090` (feat)

REFACTOR pass: not committed — code already minimal (65 LoC, no duplication, methods are one-liners delegating to build helpers).

**Plan metadata commit:** _(forthcoming after this SUMMARY.md is written)_

## Files Created/Modified

- `classes/support/ImportAuditService.php` — vendor-inlined audit log service (final class, 96 lines, 65 code lines, 4 public log methods + 3 private helpers)
- `tests/unit/Support/ImportAuditServiceTest.php` — 6 Pest cases asserting Log::info/warning routing, context-shape matching, correlation_id format/freshness, logReject merge semantics

## Decisions Made

- **D-03-02-01:** Fresh `correlation_id` per call. Cross-call threading (parse↔apply join) deferred to keep ≤100 LoC; orchestrators that later need shared ids will receive an optional ctor param. Documented in class docblock + threat register T-03-02-04.
- **D-03-02-02:** `logReject` `array_merge` order — service-controlled keys merged LAST so caller-supplied context cannot overwrite `event`/`reason`/`correlation_id`. Defensive against future caller-hygiene drift.
- **D-03-02-03:** Removed runtime `method_exists(Str::class, 'uuid7')` fallback. Laravel 12 ALWAYS ships uuid7 (PHPStan L10 narrowed-type error proved it). The v4 fallback was dead code in this dep stack.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Removed dead-code v4 uuid fallback to satisfy PHPStan level 10**
- **Found during:** Task 1 (GREEN verification — `make analyse`)
- **Issue:** Plan-prescribed `if (method_exists(Str::class, 'uuid7')) { ... } return Str::uuid()` triggered PHPStan L10 `function.alreadyNarrowedType` error: "Call to function method_exists() with 'Illuminate\\Support\\Str' and 'uuid7' will always evaluate to true." Laravel 12 unconditionally ships uuid7, so the v4 branch is unreachable.
- **Fix:** Reduced `correlationId()` to `return (string) Str::uuid7();` and updated the class docblock to drop the "v4 fallback" mention. Plan also requested a runtime grep verification (`grep 'public static function uuid7' .../Str.php`) which DID confirm the method is present at line 1936 — that pre-check was honored before edit; the deviation is the dead-branch deletion, not a method-availability mistake.
- **Files modified:** `classes/support/ImportAuditService.php` (5 lines deleted, class docblock 1-line edit)
- **Verification:** `make analyse` (25/25 paths) → `[OK] No errors`. All 6 tests still pass — the regex still matches uuid v7 (8-4-4-4-12 hex form is identical to v4).
- **Committed in:** `189d090` (GREEN gate commit — same Task 1, no separate fix commit since the fix was discovered before the GREEN was committed)

**2. [Rule 3 — Blocking] Trimmed PHPDoc to fit ≤100 LoC ceiling**
- **Found during:** Task 1 (GREEN verification — `wc -l` check against done criterion)
- **Issue:** Initial implementation was 128 raw lines (65 code lines). Plan's `done` block specifies "File line count ≤ 100 (verify: `wc -l` ≤ 100)". The threat-model docblock was the inflated portion.
- **Fix:** Compressed the class PHPDoc from 16 lines to 9 lines, the `logReject` PHPDoc from 6 lines to 3 lines, the `correlationId` PHPDoc from 5 lines to 1 line. Threat-mitigation references (T-03-02-01..04) preserved. End state: 96 raw / 65 code lines.
- **Files modified:** `classes/support/ImportAuditService.php` (PHPDoc-only edits)
- **Verification:** `wc -l` → 96. PHPStan L10 still clean. All 6 tests still pass.
- **Committed in:** `189d090` (GREEN gate commit — same Task 1)

---

**Total deviations:** 2 auto-fixed (both Rule 3 — Blocking, both PHPStan/`done`-criterion satisfiers)
**Impact on plan:** Both fixes were correctness/criterion satisfiers, NOT scope creep. The plan's prescribed `correlationId()` body would have failed PHPStan L10 at the gate; the trim was needed to meet the explicit `done` line-count ceiling.

## Issues Encountered

None — beyond the two auto-fixed deviations above. The TDD cycle ran cleanly: RED (6/6 tests fail with "Class not found"), GREEN (6/6 tests pass with 130 assertions), no REFACTOR needed.

## Verification Output (recorded for downstream reference)

### make all pipeline (final state — exit 0)

- **pint-test:** `{"result":"pass"}`
- **lint-settings-accessor:** QA-09 grep gate clean (Settings::get( appears only in classes/support/SettingsAccessor.php)
- **analyse:** PHPStan level 10 — `[OK] No errors` across 25 paths
- **phpmd:** No violations on `classes/`, `components/`, `models/`, `Plugin.php`
- **test:** 106 passed (407 assertions) / Duration 1.87s

### Laravel 12 Str::uuid7 availability check

```
$ grep -n 'public static function uuid7' vendor/laravel/framework/src/Illuminate/Support/Str.php
1936:    public static function uuid7($time = null)
```

Available — D-03-02-03 confirmed. Plugin pinned against Laravel 12 via `october/all ^4.0` → `laravel/framework ^12.0`.

### Example log line shape — `logApply`

```php
Log::info('goodsreceived.apply', [
    'event' => 'apply',
    'invoice_id' => 42,
    'units_added' => 50,
    'offers_touched' => 10,
    'lines_applied' => 8,
    'lines_skipped' => 2,
    'applied_by' => 7,
    'correlation_id' => '019050aa-7ec2-7c41-a18c-f3a9b2d4e5f6',
]);
```

JSON-context output (after PSR-3 formatter):

```
[2026-04-29 20:11:00] testing.INFO: goodsreceived.apply {"event":"apply","invoice_id":42,"units_added":50,"offers_touched":10,"lines_applied":8,"lines_skipped":2,"applied_by":7,"correlation_id":"019050aa-7ec2-7c41-a18c-f3a9b2d4e5f6"}
```

Operators can grep audit trails by `correlation_id` (uuid v7 — time-ordered prefix means lexicographic sort = temporal sort) or `invoice_id`.

## TDD Gate Compliance

- **RED gate:** `758f1f3` (`test(03-02): add failing tests for ImportAuditService (APPLY-10 RED)`) — 6/6 tests failed with "Class … not found"
- **GREEN gate:** `189d090` (`feat(03-02): add ImportAuditService for structured audit logging (APPLY-10 GREEN)`) — 6/6 tests pass / 130 assertions
- **REFACTOR gate:** N/A (code minimal at GREEN — no refactor needed)

Plan-level TDD type was `tdd="true"` on the single task; both gates committed in correct order.

## Next Phase Readiness

- **Phase 3 Wave 2 unblocked.** Plans 03-03 (StockApplyService), 03-04 (ActiveFlagService), 03-05 (InitialResetService) can now construct `new ImportAuditService()` and call the 4 log methods directly.
- **Phase 3 Wave 3 (orchestrators) — partial readiness.** Plan 03-06 (ParseAndPersistOrchestrator) and 03-07 (ApplyOrchestrator) will instantiate the service inside their `DB::transaction(...)` boundaries; correlation_id is per-call, not threaded across calls (deferred per D-03-02-01).
- **No blockers.**

## Self-Check: PASSED

Verifications run before writing this SUMMARY:

- ✓ `classes/support/ImportAuditService.php` exists (96 lines, 65 code lines)
- ✓ `tests/unit/Support/ImportAuditServiceTest.php` exists (200 lines)
- ✓ Commit `758f1f3` exists in `git log` (RED gate)
- ✓ Commit `189d090` exists in `git log` (GREEN gate)
- ✓ `make all` exit 0 — all gates green (pint, lint-settings-accessor, analyse, phpmd, test)
- ✓ 106/106 tests pass (407 assertions) — 6 new ImportAuditService tests added without breaking the prior 100

---
*Phase: 03-apply-layer-orchestrators*
*Plan: 02 (ImportAuditService — APPLY-10)*
*Completed: 2026-04-29*
