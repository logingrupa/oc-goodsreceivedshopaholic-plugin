---
phase: 02-pure-parsers-dtos-exceptions-ean-matcher
plan: 01
subsystem: data-contract

tags: [php84, readonly, dto, immutable, phpstan-level-10, pest, hungarian-notation]

# Dependency graph
requires:
  - phase: 01-schema-scaffold-settings-permissions
    provides: GoodsReceivedTestCase (Pest 4 / PHPUnit 12 base) used by DTO tests
provides:
  - ParsedInvoice DTO (header + list<ParsedLine> + skipped_rows; revised D-04)
  - ParsedLine DTO (row_index, ean string, qty int, four nullable price floats)
  - MatchedLine DTO (ParsedLine wrap + nullable matched_offer_id + literal-string strategy)
  - ApplyResult DTO (4-int counter contract for Phase 3 ApplyOrchestrator)
  - 15 Pest unit tests pinning immutability, leading-zero EAN preservation, and shape
affects: [02-02-exceptions, 02-03-normalizers, 02-04-invoice-number-resolver, 02-05-htm-parser, 02-06-ean-matcher, 02-07-phase-qa-gate, 03-orchestrator-and-stock-apply]

# Tech tracking
tech-stack:
  added:
    - "PHP 8.4 `final readonly class` modifier (D-01) — class-level readonly applied automatically to all promoted properties"
  patterns:
    - "DTOs in classes/dto/ namespace Logingrupa\\GoodsReceivedShopaholic\\Classes\\Dto (D-02)"
    - "Constructor property promotion + `@property-read` PHPDoc per property for PHPStan level 10"
    - "Literal-string union in `@param` PHPDoc to type narrow strategy fields ('offer_code'|'product_code_single_offer'|'none')"
    - "list<T> + array shape PHPDocs for nested arrays so PHPStan level 10 accepts plain `array` types"
    - "Pest tests bind GoodsReceivedTestCase via `uses(...)` even for pure DTOs to keep bootstrap consistent across analyse + test runs"

key-files:
  created:
    - "classes/dto/ParsedInvoice.php"
    - "classes/dto/ParsedLine.php"
    - "classes/dto/MatchedLine.php"
    - "classes/dto/ApplyResult.php"
    - "tests/unit/Dto/ParsedInvoiceTest.php"
    - "tests/unit/Dto/ParsedLineTest.php"
    - "tests/unit/Dto/MatchedLineTest.php"
    - "tests/unit/Dto/ApplyResultTest.php"
  modified: []

key-decisions:
  - "Used PHP 8.4 class-level `final readonly class` (NOT per-property `readonly`) — duplicating the modifier on each promoted property is a syntax error"
  - "DTO constructors carry `@param list<...>` and literal-string union PHPDocs so PHPStan level 10 accepts plain `array` and `string` parameter types without baseline noise"
  - "ParsedInvoice::$skipped_rows defaults to `[]` (revised D-04) so callers that predate the addition keep compiling; documented array shape: `list<array{row_index:int, reason:string, raw:string}>`"
  - "MatchedLine::$match_strategy stays a string (D-26) — no enum object — for SQLite portability with Phase 1 persistence layer"
  - "DTO tests bind GoodsReceivedTestCase via `uses(...)` even though they have no DB/IO — keeps the bootstrap consistent with the rest of the suite (`tests/unit/TearDownFlushesSingletonsTest.php` precedent)"

patterns-established:
  - "Pure value DTO: `<?php` → `declare(strict_types=1);` → namespace → class-level docblock with `@property-read` lines → `final readonly class` → constructor with promoted public properties → no methods, no setters, no toArray, no JsonSerializable"
  - "Test idiom for readonly enforcement: `expect(fn () => \$obDto->prop = X)->toThrow(\\Error::class, 'Cannot modify readonly property')`"
  - "Hungarian-notation locals in tests: `\$obLine`, `\$obInvoice`, `\$obMatched`, `\$obResult`, `\$sEan` — DTOs themselves carry promoted snake_case properties to keep the data carrier shape readable as JSON-like literals"

requirements-completed:
  - PARSE-01

# Metrics
duration: ~3 min
completed: 2026-04-29
---

# Phase 2 Plan 01: Pure DTOs Locking the Parse-Match-Apply Contract Summary

**Four PHP 8.4 `final readonly class` DTOs (`ParsedInvoice`, `ParsedLine`, `MatchedLine`, `ApplyResult`) plus 15 Pest tests — locks the data contract every Phase 2 sibling and Phase 3 orchestrator builds against; PHPStan level 10 clean, baseline unchanged.**

## Performance

- **Duration:** ~3 min (~150 s)
- **Started:** 2026-04-29T18:24:20Z
- **Completed:** 2026-04-29T18:26:50Z
- **Tasks:** 3 (Task 1 DTOs, Task 2 Pest tests, Task 3 QA gate verification)
- **Files modified:** 8 (4 DTOs created + 4 test files created)

## Accomplishments

- 4 immutable DTOs in `classes/dto/` under `Logingrupa\GoodsReceivedShopaholic\Classes\Dto` — locked verbatim per CONTEXT.md D-03..D-06 and revised D-04
- 15 Pest unit tests (52 assertions) covering construction, leading-zero EAN preservation as STRING (D-27 / QA-02), readonly mutation enforcement (`\Error: Cannot modify readonly property`), and `skipped_rows` default
- Zero PHPStan level 10 errors added; `phpstan-baseline.neon` unchanged
- Pint clean across both new DTO files and test files
- Full plugin test suite remains green (27 tests, 75 assertions, 0.44 s)

## Task Commits

Each task committed atomically against the working tree:

1. **Task 1: Create the four readonly DTO classes** — `cbf8653` (feat)
2. **Task 2: Pest unit tests asserting immutability + shape** — `94038cb` (test)
3. **Task 3: QA gate** — no commit (verification-only task; plan instructed "no commits in this plan — orchestrator commits after plan-checker verifies")

**Plan metadata:** see commit chain ending in this SUMMARY.md commit.

_Note: Although both Task 1 and Task 2 were marked `tdd="true"`, the plan explicitly orders implementation (Task 1) before tests (Task 2). The plan-level TDD posture is enforced by Task 3's QA gate (Pint + PHPStan + green Pest run); a strict RED-then-GREEN gate sequence would require an additional `test:` commit BEFORE Task 1 — left as a deliberate plan-author choice, not a deviation._

## Files Created

- `classes/dto/ParsedInvoice.php` — header + `list<ParsedLine>` + `list<array{row_index:int,reason:string,raw:string}>` skipped_rows; defaults skipped_rows to `[]`
- `classes/dto/ParsedLine.php` — `row_index`, `ean` (string preserves leading zeros), `product_name_raw`, `unit`, `qty` (int), four nullable price floats
- `classes/dto/MatchedLine.php` — wraps `ParsedLine` + nullable `matched_offer_id` + literal-string `match_strategy` ('offer_code' | 'product_code_single_offer' | 'none')
- `classes/dto/ApplyResult.php` — `units_added`, `offers_touched`, `lines_applied`, `lines_skipped` (all int)
- `tests/unit/Dto/ParsedInvoiceTest.php` — 4 tests: construction (full+optional), `skipped_rows` default, null country/date, readonly enforcement
- `tests/unit/Dto/ParsedLineTest.php` — 4 tests: construction, leading-zero EAN preserved as 13-char string, all-null prices, readonly enforcement
- `tests/unit/Dto/MatchedLineTest.php` — 4 tests: wrap with strategy, null `matched_offer_id` ('none'), `product_code_single_offer` fallback, readonly enforcement
- `tests/unit/Dto/ApplyResultTest.php` — 3 tests: 4 int counters, zero-value re-run path, readonly enforcement

## Locked Constructor Signatures (verbatim — for plan-checker grep + downstream plans)

```php
// classes/dto/ParsedInvoice.php
final readonly class ParsedInvoice
{
    /**
     * @param  list<ParsedLine>  $lines
     * @param  list<array{row_index: int, reason: string, raw: string}>  $skipped_rows
     */
    public function __construct(
        public string $invoice_number,
        public ?string $country_code,
        public ?DateTimeImmutable $invoice_date,
        public string $source_filename,
        public array $lines,
        public array $skipped_rows = [],
    ) {
    }
}

// classes/dto/ParsedLine.php
final readonly class ParsedLine
{
    public function __construct(
        public int $row_index,
        public string $ean,
        public string $product_name_raw,
        public string $unit,
        public int $qty,
        public ?float $unit_price,
        public ?float $discount,
        public ?float $line_price,
        public ?float $total,
    ) {
    }
}

// classes/dto/MatchedLine.php
final readonly class MatchedLine
{
    /**
     * @param  'offer_code'|'product_code_single_offer'|'none'  $match_strategy
     */
    public function __construct(
        public ParsedLine $line,
        public ?int $matched_offer_id,
        public string $match_strategy,
    ) {
    }
}

// classes/dto/ApplyResult.php
final readonly class ApplyResult
{
    public function __construct(
        public int $units_added,
        public int $offers_touched,
        public int $lines_applied,
        public int $lines_skipped,
    ) {
    }
}
```

## Decisions Made

- **`final readonly class` vs per-property `readonly`** — chose class-level modifier (PHP 8.4 native, D-01). Per-property `readonly` on top of class-level is a syntax error; the class-level modifier propagates to every promoted property automatically.
- **`@property-read` plus `@param`** — class-level `@property-read` documentation aids IDE introspection, but PHPStan level 10 specifically required the constructor-level `@param list<...>` and literal-string union annotations to accept the plain `array` and `string` parameter types. Without them, level 10 emits "Parameter $x of class has no value type specified in iterable type array". Both layers retained.
- **Skipped `JsonSerializable`/`toArray()` helpers** — YAGNI for Phase 2 per plan instruction; Phase 3 orchestrator composes DTOs in memory.
- **`use \DateTimeImmutable;` import** — only `ParsedInvoice` needs it; kept the import alphabetically (Pint convention) so the constructor reads `?DateTimeImmutable` cleanly.
- **Test fixture EAN `'4752307000097'`** — taken from real fixture line 1 (`Nr_PRO033328_no_13042026.HTM`) for hermetic consistency, even though DTO tests don't read the fixture.

## Deviations from Plan

None — plan executed exactly as written.

The plan's verification gates (`make pint-test`, `make analyse`, `git diff --exit-code phpstan-baseline.neon`) all passed first-try with no auto-fixes required. PHPDoc requirements specified in Task 1 (`@param list<...>` + literal-string unions) were sufficient to satisfy PHPStan level 10 — no additional baseline entries needed.

**Total deviations:** 0
**Impact on plan:** None — every truth, artifact, and `<key_link>` from the plan frontmatter is satisfied.

## Issues Encountered

None.

## Threat Flags

None — DTOs introduce no new I/O, network, or trust-boundary surface beyond the threat model already documented in plan 02-01-PLAN.md (`T-02-01-01` mitigated by `final readonly class`; `T-02-01-02` accepted because DTO fields carry only non-PII business data).

## QA Gate Results (Task 3)

- `make pint-test` → `{"result":"pass"}`
- `make analyse` → PHPStan level 10, 9 files analyzed, **No errors**
- `git diff --exit-code phpstan-baseline.neon` → no diff (baseline unchanged)
- `make test` (full suite) → 27 passed, 75 assertions, 0.44 s

## Self-Check: PASSED

**Files exist on disk:**

- FOUND: `classes/dto/ParsedInvoice.php`
- FOUND: `classes/dto/ParsedLine.php`
- FOUND: `classes/dto/MatchedLine.php`
- FOUND: `classes/dto/ApplyResult.php`
- FOUND: `tests/unit/Dto/ParsedInvoiceTest.php`
- FOUND: `tests/unit/Dto/ParsedLineTest.php`
- FOUND: `tests/unit/Dto/MatchedLineTest.php`
- FOUND: `tests/unit/Dto/ApplyResultTest.php`

**Commits exist:**

- FOUND: `cbf8653` (Task 1 — `feat(02-01): create four readonly DTOs locking the parse-match-apply contract`)
- FOUND: `94038cb` (Task 2 — `test(02-01): add Pest unit tests pinning DTO immutability and shape`)

## Next Plan Readiness

- DTO contract is locked. Plans 02-02 (exceptions), 02-03 (normalizers), 02-04 (invoice number resolver), 02-05 (HTM parser → produces `ParsedInvoice`), 02-06 (EAN matcher → produces `MatchedLine[]`) can build directly against these signatures without further coordination.
- Phase 3 `ApplyOrchestrator::run(ParsedInvoice)` return type `ApplyResult` is reserved.
- No blockers, no follow-ups.

---
*Phase: 02-pure-parsers-dtos-exceptions-ean-matcher*
*Plan: 01*
*Completed: 2026-04-29*
