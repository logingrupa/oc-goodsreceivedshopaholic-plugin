---
phase: 03-apply-layer-orchestrators
plan: 06
subsystem: orchestrator
tags: [parse-orchestrator, db-transaction, lockforupdate, override-reimport, duplicate-detection, apply-06, apply-08, php8.4, pest4]

# Dependency graph
requires:
  - phase: 03-apply-layer-orchestrators
    plan: 02
    provides: ImportAuditService::logParse + logReject (vendor-inlined audit)
  - phase: 03-apply-layer-orchestrators
    plan: 03
    provides: ApplyTestCase hermetic schema base (lovata_shopaholic_offers + products + system_settings + invoices/invoice_lines)
  - phase: 02-pure-parsers-dtos-exceptions-ean-matcher
    provides: HtmInvoiceParser::parse + EanMatcherService::matchBatch + ParsedInvoice/MatchedLine DTOs + DuplicateInvoiceException + MalformedHtmException + InvoiceNumberMissingException
  - phase: 01-schema-scaffold-settings-permissions
    provides: Invoice + InvoiceLine models + STATUS_PARSED/STATUS_APPLIED constants + override_of_invoice_id self-ref FK
provides:
  - ParseAndPersistOrchestrator — final class with run() + runOverride() + 6 private helpers; ONE DB::transaction wrapper
  - Duplicate-detection contract: Invoice::where('invoice_number')->lockForUpdate() race-safe gate; throws DuplicateInvoiceException when prior status='applied'
  - Override-reimport contract: runOverride() creates new Invoice with override_of_invoice_id pointer + '<orig>-OVR-<priorId>' suffix; never throws DuplicateInvoiceException
  - Reject-log-after-rollback contract: try/catch outside DB::transaction so logReject records the failure outcome, not a half-committed state
  - DateTimeImmutable → Carbon bridge at the persistence boundary
  - 5 QA test cases (happy path, duplicate, override, rollback, post-rollback reject log)
affects: [03-07-apply-orch, 03-08-final-qa-gate, 04-controller-upload-screen, 04-controller-override-reimport]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Single-tx orchestrator boundary: parse → dup-check → persist Invoice → batch-match → persist lines all inside ONE DB::transaction(fn(): Invoice => ...). The controller stays thin — only marshals input + handles exceptions. Asserted by 'parse failure rolls back the transaction' test (Invoice::count() === 0 after malformed HTM throw)."
    - "lockForUpdate as race serializer: Invoice::where('invoice_number', $sNumber)->lockForUpdate()->first() inside the tx blocks concurrent uploads of the same number on the row lock. Second upload waits for first to commit, then sees the new row + throws DuplicateInvoiceException. T-03-06-01 mitigation."
    - "Reject-log AFTER rollback: try/catch wraps DB::transaction, NOT inside it. Order: tx fails → rollback → catch GoodsReceivedException → logReject → re-throw. Logging inside the tx would be rolled back along with the failed work — the audit trail would record nothing. Asserted by Log::shouldHaveReceived('warning') spy after MalformedHtmException."
    - "Mode strategy with shared transactional unit: runWithStrategy(string $sMode, ?int $iPriorInvoiceId) routes both run() and runOverride() through the SAME doParseAndPersist sequence. The only branch is the dup-check (skipped in MODE_OVERRIDE) + the persisted invoice number (suffixed in MODE_OVERRIDE). Avoids dual transaction wrappers + dual reject paths."
    - "Override invoice_number suffix '<orig>-OVR-<priorId>': prior invoice keeps its canonical number (e.g. PRO033328); override is a derived label (PRO033328-OVR-7). The suffix satisfies the UNIQUE index at the DB level while override_of_invoice_id provides the FK pointer. Add-on-top semantics (D-12) emerge naturally — ApplyOrchestrator runs UNCHANGED on the override invoice (it sees a fresh 'parsed' row with new lines, writes additively)."
    - "Constructor-body defaults (NOT new in parameter defaults): readonly properties initialized in __construct body via ?? new ... fallback. Cleaner across PHP 8.3 dev / 8.4 prod and avoids the readonly conflict with new-in-default-value."
    - "DateTimeImmutable → Carbon::instance() bridge: ParsedInvoice's invoice_date is DateTimeImmutable|null (pure DTO contract); Invoice's $dates array maps invoice_date to Carbon. Carbon::instance(DateTimeInterface) returns Carbon, satisfying PHPStan's assign.propertyType check without inline @var."
    - "new+save explicitly (NOT Model::create) for typed Invoice return: Larastan types Model::create as Eloquent\\Model, which doesn't satisfy the typed Invoice return without inline @var (forbidden by phpstan.neon project rules). new Invoice() + save() keeps the typed return + fires the same model events."

key-files:
  created:
    - classes/orchestrator/ParseAndPersistOrchestrator.php (368 lines / 191 LoC of code; 6 private helpers all <70 lines per Tiger-Style)
    - tests/unit/Orchestrator/ParseAndPersistOrchestratorTest.php (181 lines / 5 Pest cases)
  modified: []

key-decisions:
  - "D-03-06-01 (2026-04-29): Constructor uses optional null defaults + `??` fallback in body, NOT `new HtmInvoiceParser()` as parameter default. Plan suggested `new` defaults; PHP 8.4 'new in initializers' RFC supports this, but combining it with `readonly` properties hits a static-analyzer pothole. The `?HtmInvoiceParser $obParser = null` form + body assignment keeps Larastan + PHPStan L10 clean across PHP 8.3 dev / 8.4 prod and remains testable (callers pass explicit instances)."
  - "D-03-06-02 (2026-04-29): Invoice persistence uses `new Invoice() + save()` (NOT `Invoice::create([...])`). Larastan types `Model::create` as `Eloquent\\Model`, which doesn't satisfy the typed `Invoice` return without inline `@var` (forbidden by phpstan.neon project rules per the comments around D-03-03-05). The new+save form returns a typed Invoice and fires the same model events. Net loss: 9 extra lines per persistInvoice; net gain: zero PHPStan suppressions."
  - "D-03-06-03 (2026-04-29): DateTimeImmutable → Carbon bridge via `Carbon::instance($obParsed->invoice_date)` at the persistence boundary. ParsedInvoice's `invoice_date` is DateTimeImmutable|null (pure DTO contract — no Carbon dep in the parser layer); Invoice's $dates maps invoice_date to Carbon. The conversion lives in persistInvoice() so the DTO layer stays Carbon-free and the model layer stays Carbon-typed."
  - "D-03-06-04 (2026-04-29): Override invoice_number gets '-OVR-<priorId>' suffix (NOT shared canonical number). The UNIQUE index on invoice_number forbids two rows with the same number; the suffix satisfies the index while override_of_invoice_id provides the FK pointer. Prior invoice keeps its canonical number; override is a derived label. Asserted by 'runOverride creates a NEW invoice' test (expects 'PRO033328-OVR-1' for prior id 1)."
  - "D-03-06-05 (2026-04-29): Test fixture for parse-failure tests uses filename 'Nr_PRO000001_no_01012026.HTM' (NOT plain 'broken.HTM'). The plan's example used 'broken.HTM' as filename, but InvoiceNumberResolver runs FIRST inside parser.parse() — without a PRO-number in the filename, it throws InvoiceNumberMissingException, masking the 'no R20 rows' MalformedHtmException the test wants to assert. Filename PRO-number unblocks the resolver so the failure surfaces at the row-extraction step (the failure boundary plan 03-06 contracts on). Both exceptions extend GoodsReceivedException, so the catch + reject log works either way; the test simply documents the precise failure path."
  - "D-03-06-06 (2026-04-29): Single DB::transaction call site is the wrapper around doParseAndPersist; assertNotDuplicate's lockForUpdate runs INSIDE that wrapper (not its own tx). Two transactions would defeat the point — the row lock would release between them, opening a TOCTOU window where a concurrent upload could insert + apply between the dup-check and the new Invoice::create. Single-tx-with-lockForUpdate is the correct pattern."

patterns-established:
  - "Single-tx orchestrator boundary owned by orchestrator (NOT controller). Controller stays thin: marshals input + handles exceptions. Reusable by 03-07 ApplyOrchestrator (different tx body but same boundary ownership)."
  - "Reject-log-after-rollback contract: try/catch wraps DB::transaction, NOT inside it. Logging post-rollback ensures the audit trail records the failure outcome, not a half-committed state."
  - "Mode strategy private dispatch: public methods (run/runOverride) are thin entry points routing through a shared private runWithStrategy. Branch only on the minimal differences (dup-check skip + invoice_number suffix). Avoids duplication of tx wrapper + reject path."
  - "DateTimeImmutable → Carbon::instance bridge at the persistence boundary: pure DTOs stay Carbon-free; model layer stays Carbon-typed."

requirements-completed: [APPLY-06, APPLY-08]

# Metrics
duration: 5min
completed: 2026-04-29
---

# Phase 3 Plan 06: ParseAndPersistOrchestrator Summary

**ParseAndPersistOrchestrator final class — upload-side orchestrator that wraps parse → duplicate-check → persist Invoice@status=parsed → batch-match → persist InvoiceLine rows in ONE DB::transaction. Includes runOverride() explicit entry point (D-26) for override-reimport: new Invoice with override_of_invoice_id pointer + 'PRO<num>-OVR-<priorId>' suffix to satisfy UNIQUE index. Race-safe duplicate detection via Invoice::lockForUpdate() inside the transaction. Reject-log-after-rollback contract via try/catch outside DB::transaction wrapper. 5 Pest cases / 27 new assertions covering happy path, duplicate detection, override-reimport, parse-failure rollback, and post-rollback reject logging. Stock writes deferred to plan 03-07 ApplyOrchestrator — this orchestrator NEVER touches offer.quantity.**

## Performance

- **Duration:** ~5 min (RED → GREEN cycle, no REFACTOR needed)
- **Started:** 2026-04-29T21:03:05Z
- **Completed:** 2026-04-29T21:08:00Z
- **Tasks:** 2 (Task 1 service + Task 2 tests — combined into a single TDD RED→GREEN cycle since service and tests share fate per plan 03-05 precedent)
- **Files created:** 2
- **Files modified:** 0 (no ApplyTestCase changes — Phase 1 invoice + invoice_lines tables already in place from 03-03)

## Accomplishments

### Task 1: ParseAndPersistOrchestrator (APPLY-06, APPLY-08)

Final class with 2 public methods + 6 private helpers, ALL functions <70 lines per Tiger-Style:

| Function | Lines | Role |
|----------|-------|------|
| `__construct(?HtmInvoiceParser, ?EanMatcherService, ?ImportAuditService)` | 8 | Optional injection with body-default fallback |
| `run(string, string, int): Invoice` | 9 | Normal upload entry — routes to runWithStrategy(MODE_NORMAL) |
| `runOverride(string, string, int, int): Invoice` | 13 | Override entry — routes to runWithStrategy(MODE_OVERRIDE) |
| `runWithStrategy(string, string, int, string, ?int): Invoice` | 29 | Try/catch tx wrapper; logReject AFTER rollback |
| `doParseAndPersist(string, string, int, string, ?int): Invoice` | 22 | The transactional unit |
| `assertNotDuplicate(string): void` | 24 | Invoice::lockForUpdate() race-safe gate |
| `persistInvoice(ParsedInvoice, int, string, ?int): Invoice` | 39 | new+save (NOT Model::create — typed return) |
| `matchAllLines(ParsedInvoice): list<MatchedLine>` | 6 | EanMatcherService::matchBatch + buildMatchedLines |
| `persistLines(int, list<MatchedLine>): void` | 25 | Batched InvoiceLine::insert (single statement) |
| `updateInvoiceCounters(Invoice, list<MatchedLine>): void` | 16 | saveQuietly counter update |

### Task 2: Tests (5 Pest cases)

| Test | Asserts |
|------|---------|
| `persists Invoice@status=parsed and 21 InvoiceLine rows for a valid HTM (APPLY-06 happy path)` | Invoice + 21 lines persisted; invoice_number=PRO033328; first line ean=4752307000097, qty=5, match_strategy=none, applied=false |
| `throws DuplicateInvoiceException when invoice_number is already applied (APPLY-06 dup check)` | Throws with $arContext.invoice_number / prior_invoice_id / prior_applied_at / prior_applied_by; only prior invoice persists (tx rolled back) |
| `runOverride creates a NEW invoice with override_of_invoice_id pointer, NO duplicate-exception (APPLY-08 D-27)` | New invoice has override_of_invoice_id=$obPrior->id, status=parsed, invoice_number='PRO033328-OVR-1'; 2 invoices total |
| `parse failure rolls back the transaction — no Invoice or InvoiceLine row remains (D-22 atomicity)` | MalformedHtmException; Invoice::count()===0; InvoiceLine::count()===0 |
| `logs reject via ImportAuditService AFTER tx rollback on parse failure (boundary contract)` | Log::shouldHaveReceived('warning') with message='goodsreceived.reject', source_filename, mode='normal', event='reject'; Invoice::count()===0 |

## Verification Gates

| Gate | Expected | Actual | Status |
|------|----------|--------|--------|
| `grep -cE 'DB::transaction\(' classes/orchestrator/ParseAndPersistOrchestrator.php` (code only) | 1 | 1 | PASS |
| `grep -cE 'lockForUpdate\(' classes/orchestrator/ParseAndPersistOrchestrator.php` (code only) | 1 | 1 | PASS |
| `grep -c 'Settings::get(' classes/orchestrator/ParseAndPersistOrchestrator.php` | 0 | 0 | PASS |
| `grep -E 'Offer::' classes/orchestrator/ParseAndPersistOrchestrator.php` (code only) | 0 | 0 | PASS |
| `grep -cE 'logParse\(\|logReject\(' classes/orchestrator/ParseAndPersistOrchestrator.php` | ≥2 | 2 | PASS |
| `make test` | green | 137/624 (+5 new) in 7.05s | PASS |
| `make analyse` (PHPStan L10) | clean | clean | PASS |
| `make pint-test` | clean | clean | PASS |
| `make phpmd` | clean | clean | PASS |
| `make lint-settings-accessor` | clean | clean | PASS |
| `make all` | green | green | PASS |
| phpstan-baseline.neon SHA | unchanged | bbf4a55d54322b3c8a229b8fcfba636c | PASS (unchanged) |

## Deviations from Plan

### Auto-fixed Issues (Rules 1-3)

**1. [Rule 3 - Blocking] PHPStan return-type narrowing on Invoice::create()**

- **Found during:** Task 1 GREEN gate (`make analyse`)
- **Issue:** `Invoice::create([...])` returns `Eloquent\Model` per Larastan stubs — does not satisfy the typed `Invoice` return without inline `@var` (forbidden by phpstan.neon project rules per D-03-03-05).
- **Fix:** Switched to `new Invoice() + save()` form. Returns the typed Invoice naturally. Net cost: 9 extra lines per persistInvoice; net benefit: zero PHPStan suppressions.
- **Files modified:** classes/orchestrator/ParseAndPersistOrchestrator.php (persistInvoice helper)
- **Commit:** part of 751d837 (GREEN commit)

**2. [Rule 3 - Blocking] DateTimeImmutable → Carbon assign.propertyType**

- **Found during:** Task 1 GREEN gate (`make analyse`)
- **Issue:** ParsedInvoice's `invoice_date` is DateTimeImmutable|null (pure DTO contract — no Carbon dep in parser layer); Invoice's `$dates` maps invoice_date to Carbon. Direct assignment violates PHPStan L10 assign.propertyType check.
- **Fix:** Added `Carbon::instance($obParsed->invoice_date)` bridge at persistInvoice. Carbon::instance accepts any DateTimeInterface → returns Carbon. The DTO layer stays Carbon-free and the model layer stays Carbon-typed; the conversion lives at the persistence boundary.
- **Files modified:** classes/orchestrator/ParseAndPersistOrchestrator.php (persistInvoice helper)
- **Commit:** part of 751d837 (GREEN commit)

**3. [Rule 1 - Bug] Test fixture filename for parse-failure cases**

- **Found during:** Task 2 RED→GREEN transition
- **Issue:** Plan suggested `'broken.HTM'` filename for the parse-failure tests with body `'<html>broken</html>'`. But `InvoiceNumberResolver` runs FIRST inside `HtmInvoiceParser::parse()` — without a PRO-number in either the body OR the filename, it throws `InvoiceNumberMissingException`, masking the `MalformedHtmException` the test wants to assert.
- **Fix:** Updated test fixture filenames to `'Nr_PRO000001_no_01012026.HTM'` / `'Nr_PRO000002_no_01012026.HTM'` so the resolver's filename fallback succeeds and the failure surfaces at the row-extraction step (the failure boundary plan 03-06 actually contracts on). Both exceptions extend GoodsReceivedException so the catch + reject log works either way.
- **Files modified:** tests/unit/Orchestrator/ParseAndPersistOrchestratorTest.php (last 2 it() blocks)
- **Commit:** part of 751d837 (GREEN commit)

### Architectural Decisions (no plan deviation — recorded for downstream context)

- See key-decisions D-03-06-01..06 in frontmatter.

## TDD Gate Compliance

| Gate | Commit | Status |
|------|--------|--------|
| RED  | 630bbb8 — `test(03-06): add failing tests for ParseAndPersistOrchestrator (RED)` | PASS (5 tests fail with class not found, as expected) |
| GREEN | 751d837 — `feat(03-06): implement ParseAndPersistOrchestrator (APPLY-06 / APPLY-08 GREEN)` | PASS (137/624 tests pass; baseline unchanged) |
| REFACTOR | not needed | n/a |

## Note for Plan 03-07 ApplyOrchestrator

Per plan output spec — the override invoice has `override_of_invoice_id` set; **ApplyOrchestrator runs UNCHANGED on override invoices**. The additive D-12 add-on-top semantics emerge naturally from running normal apply on a fresh invoice that happens to point at a prior — no special-case logic needed in ApplyOrchestrator. The override is just "another parsed invoice that the operator chose to apply"; the FK pointer is for audit-trail joins, not for runtime behavior changes.

This means plan 03-07 contracts can stay focused on the apply-side concerns (StockApplyService composition + ActiveFlagService reconcile + Invoice::lockForUpdate idempotent guard) without a runOverrideApply() variant.

## Self-Check: PASSED

- `classes/orchestrator/ParseAndPersistOrchestrator.php`: FOUND
- `tests/unit/Orchestrator/ParseAndPersistOrchestratorTest.php`: FOUND
- Commit `630bbb8` (RED): FOUND in git log
- Commit `751d837` (GREEN): FOUND in git log
- All 5 tests pass; PHPStan/Pint/PHPMD clean; baseline SHA unchanged.
