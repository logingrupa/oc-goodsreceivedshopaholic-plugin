---
phase: 02-pure-parsers-dtos-exceptions-ean-matcher
plan: 07
subsystem: qa-gate
tags: [qa-gate, phase-2-complete, hermetic-fixtures, baseline-contract, phpmd-tuning]
requires:
  - all 6 prior Phase 2 plans (02-01 through 02-06)
  - tests/fixtures/invoices/Nr_PRO026712_no_28112024.HTM
  - tests/fixtures/invoices/Nr_PRO029691_no_09072025.HTM
  - tests/fixtures/invoices/Nr_PRO033328_no_13042026.HTM
provides:
  - "Phase 2 shippability evidence: pint-test + phpstan L10 + phpmd + pest all green"
  - "Hermetic-fixture invariant verified (PARSE-07): zero production reads of project storage; all 3 fixtures intact with UTF-8 BOM"
  - "Zero baseline drift: phpstan-baseline.neon sha256 unchanged"
  - "phpmd.xml threshold tune: ExcessiveParameterList 8→10, ShortVariable 4→3 (documented inline)"
affects:
  - "Phase 3 (Apply Layer + Orchestrators) is unblocked — stable Parse → Match foundation in place"
tech_stack:
  added: []
  patterns:
    - "phpmd.xml threshold tuning with inline justification comments (preferred over @SuppressWarnings PHPDoc — phpstan rejects dotted identifiers)"
    - "Hermetic-fixture grep gate as cross-plan integration check"
key_files:
  created:
    - .planning/phases/02-pure-parsers-dtos-exceptions-ean-matcher/02-07-SUMMARY.md
  modified:
    - phpmd.xml
    - classes/dto/ParsedLine.php
decisions:
  - "phpmd violations on ParsedLine.php fixed at config level (phpmd.xml thresholds), NOT via @SuppressWarnings annotation — phpstan rejects PHPDoc tag values containing dots (`PHPMD.ShortVariable`)"
  - "ExcessiveParameterList minimum raised 8 → 10 (PHPMD reports when params >= minimum, so to allow exactly 9 ctor fields we set 10). Cap stays tight: any class with ≥10 ctor params still trips."
  - "ShortVariable minimum lowered 4 → 3 to permit canonical 3-letter domain terms (`ean`, `qty`). 1- and 2-char names still forbidden. Lovata Hungarian convention unaffected ($iCount, $sSlug, $obItem)."
  - "Renames rejected: `ean` → `code` would collide with offers.code (SKU); `qty` → `quantity` adds verbosity without disambiguation gain. Both names match the PRD/captures schema."
  - "Inline PHPDoc note on ParsedLine documents the rationale for the phpmd tune"
metrics:
  duration: "~22 minutes"
  completed_date: "2026-04-29T20:00:00Z"
  task_count: 4
  test_count: 92
  assertion_count: 264
  file_count: 2
---

# Phase 2 Plan 07: Final QA Gate Summary

**One-liner:** Phase 2 final QA gate verifies all 4 sub-gates green (pint + phpstan L10 + phpmd + pest 92/92), 3 hermetic fixtures intact, and zero baseline drift — fixing 3 pre-existing phpmd violations on `ParsedLine.php` (introduced by plan 02-01) at the phpmd.xml threshold level rather than mutating the canonical DTO schema or the phpstan baseline.

## Task Results

| Task | Description                              | Result   | Notes                                                                           |
| ---- | ---------------------------------------- | -------- | ------------------------------------------------------------------------------- |
| 1    | Hermetic-fixture invariants (PARSE-07)   | PASS     | 3 fixtures present; zero production reads of project storage; 3 BOMs intact     |
| 2    | `make all` (full QA pipeline)            | PASS w/ fix | 3 phpmd violations fixed at config level; final run all 4 sub-gates green        |
| 3    | Zero baseline drift                      | PASS     | `phpstan-baseline.neon` sha256 unchanged: `4b3227fa…`. `phpstan.neon` unchanged. |
| 4    | Phase-2 file inventory + smoke summary   | PASS     | 18 production + 11 test files; all strict_types; all final/abstract; zero `else` |

## Hermetic-Fixture Verification (Task 1)

```
$ ls tests/fixtures/invoices/Nr_PRO*.HTM
-rw-rw-r-- 1 forge forge 90802 Apr 29 14:44 tests/fixtures/invoices/Nr_PRO026712_no_28112024.HTM
-rw-rw-r-- 1 forge forge 32368 Apr 29 14:44 tests/fixtures/invoices/Nr_PRO029691_no_09072025.HTM
-rw-rw-r-- 1 forge forge 39223 Apr 29 14:44 tests/fixtures/invoices/Nr_PRO033328_no_13042026.HTM

$ grep -rE "storage/app/uploads/invoices" classes/ tests/ 2>/dev/null
(no output — exit 1, gate passes)

$ for f in tests/fixtures/invoices/Nr_PRO*.HTM; do head -c 3 "$f" | od -An -tx1 | tr -d ' \n'; echo " $f"; done
efbbbf tests/fixtures/invoices/Nr_PRO026712_no_28112024.HTM
efbbbf tests/fixtures/invoices/Nr_PRO029691_no_09072025.HTM
efbbbf tests/fixtures/invoices/Nr_PRO033328_no_13042026.HTM
```

All test references to `fixtures/invoices/` resolve via `__DIR__.'/../../fixtures/invoices/'` — hermetic invariant intact.

## Phase-2 File Inventory (Task 4)

### Production code (18 files)

**DTO (4 files, plan 02-01):**
- `classes/dto/ApplyResult.php`
- `classes/dto/MatchedLine.php`
- `classes/dto/ParsedInvoice.php`
- `classes/dto/ParsedLine.php`

**Exception (9 files, plan 02-02):**
- `classes/exception/ApplyAlreadyDoneException.php`
- `classes/exception/DuplicateInvoiceException.php`
- `classes/exception/GoodsReceivedException.php` (abstract base)
- `classes/exception/InitialResetNotAllowedException.php`
- `classes/exception/InvalidEanException.php`
- `classes/exception/InvalidQuantityException.php`
- `classes/exception/InvoiceNumberMissingException.php`
- `classes/exception/MalformedHtmException.php`
- `classes/exception/OperatorOverridesActiveFlagException.php`

**Parser (4 files, plans 02-03, 02-04, 02-05):**
- `classes/parser/HtmInvoiceParser.php`
- `classes/parser/InvoiceNumberResolver.php`
- `classes/parser/PriceNormalizer.php`
- `classes/parser/QuantityNormalizer.php`

**Match (1 file, plan 02-06):**
- `classes/match/EanMatcherService.php`

### Test code (11 files)

- `tests/unit/Dto/ApplyResultTest.php`
- `tests/unit/Dto/MatchedLineTest.php`
- `tests/unit/Dto/ParsedInvoiceTest.php`
- `tests/unit/Dto/ParsedLineTest.php`
- `tests/unit/Exception/ExceptionContextTest.php`
- `tests/unit/Exception/ExceptionHierarchyTest.php`
- `tests/unit/Match/EanMatcherServiceTest.php`
- `tests/unit/Parser/HtmInvoiceParserTest.php`
- `tests/unit/Parser/InvoiceNumberResolverTest.php`
- `tests/unit/Parser/PriceNormalizerTest.php`
- `tests/unit/Parser/QuantityNormalizerTest.php`

### Code-shape gates

| Gate                                                | Result | Notes                                                  |
| --------------------------------------------------- | ------ | ------------------------------------------------------ |
| Strict-types coverage (every prod file)             | PASS   | All 18 files declare `strict_types=1` (D-35)           |
| Final-class gate                                    | PASS   | 17 `final` / `final readonly` + 1 `abstract` base      |
| No-`else` hygiene (D-37 max-nesting)                 | PASS   | Zero `else` keywords in `classes/`                     |

## Final `make all` Output (last 10 lines)

```
   PASS  Plugins\logingrupa\goodsreceivedshopaholic\tests\unit\TearDownFlushesSingletonsTest
  ✓ it declares a protected flushPluginSingletons(): void method on the… 0.04s
  ✓ it invokes flushPluginSingletons() from within tearDown()            0.01s
  ✓ it invokes flushPluginSingletons() BEFORE parent::tearDown() (corre… 0.02s
  ✓ it flushPluginSingletons body is empty in Phase 1 (Phases 2/3 will…  0.02s

  Tests:    92 passed (264 assertions)
  Duration: 1.67s
```

Sub-gate breakdown:
- **pint-test** — `{"result":"pass"}`
- **phpstan** — `[OK] No errors` (level 10, 23 files scanned)
- **phpmd** — silent (no violations, exit 0)
- **pest** — 92 passed (264 assertions), 1.67s

## PHPStan Baseline Diff

```
$ git diff --exit-code phpstan-baseline.neon
EXIT=0

$ git diff --exit-code phpstan.neon
EXIT=0

$ sha256sum phpstan-baseline.neon
4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a  phpstan-baseline.neon
```

Baseline sha256 identical to start of Phase 2. Zero drift.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Three pre-existing phpmd violations on `classes/dto/ParsedLine.php`**

- **Found during:** Task 2 (`make all` first run)
- **Issue:** plan 02-01 introduced `ParsedLine` DTO with 9 ctor params and 3-letter field names (`ean`, `qty`); plan 02-05's executor noted these but did not fix them. They were latent because the per-plan gates exercised before 02-07 happened to skip phpmd on this file in some interactive sequences. Plan 02-07's full `make all` surfaced them:
  ```
  ParsedLine.php:28  ExcessiveParameterList  9 params (limit 8)
  ParsedLine.php:30  ShortVariable           $ean (limit 4)
  ParsedLine.php:33  ShortVariable           $qty (limit 4)
  ```
- **Fix attempt 1 — REJECTED:** Inline `@SuppressWarnings(PHPMD.ShortVariable)` PHPDoc annotation. PHPStan rejected with `phpDoc.parseError`: dotted identifier `PHPMD.ShortVariable` is not valid PHPDoc tag-value syntax.
- **Fix attempt 2 — APPLIED:** Tune phpmd.xml thresholds (config-level exception is explicitly allowed by plan 02-07 failure protocol: *"If rule too strict, document exception sparingly in `phpmd.xml`"*).
  - `ExcessiveParameterList` minimum: `8 → 10` (PHPMD reports when params >= minimum, so to allow exactly 9 ctor fields the threshold is 10. Cap stays tight: any class with ≥10 ctor params still trips.)
  - `ShortVariable` minimum: `4 → 3` (permits canonical 3-letter domain terms `ean`, `qty`. 1- and 2-char names like `$x`, `$ab`, `$id` still forbidden; Lovata Hungarian convention unaffected.)
- **Files modified:** `phpmd.xml`, `classes/dto/ParsedLine.php` (PHPDoc rationale note only — no code change)
- **Commit:** `69fee5f`
- **Why root-cause not field rename:**
  - `ean` is GS1 industry standard for the 13-digit barcode; renaming to `code` collides with `offers.code` (the SKU column) — would generate ambiguity in matcher logic.
  - `qty` mirrors Shopaholic `offers.quantity` semantics; expanding to `quantity` is verbose without disambiguation gain.
  - Both names appear in `@property-read` PHPDoc and PROJECT.md as the canonical schema. Renaming would touch 4 production files + 5 test files for cosmetic compliance.
  - DTO-by-construction has many fields (9 here matches the invoice row schema). `ExcessiveParameterList` rule is calibrated for service classes, not value types.

### Authentication Gates

None encountered — no external services touched in this plan.

## TDD Gate Compliance

This plan has `type: execute` (not `type: tdd`). No RED/GREEN/REFACTOR commits required. The deviation fix (commit `69fee5f`) is correctly typed `fix(02-07)` (config tune resolves a phpmd rule mismatch — bug fix, not new behavior).

## Threat Flags

None. This plan modifies only test-fixture references, project metadata, and one config threshold; introduces no new network endpoints, auth paths, file access patterns, or schema changes at trust boundaries.

## Phase 2 Status: SHIPPABLE

The 11 Phase 2 requirements are now satisfied:

| Req      | Description                                                                | Plan  | Status |
| -------- | -------------------------------------------------------------------------- | ----- | ------ |
| PARSE-01 | 4 readonly DTOs                                                            | 02-01 | DONE   |
| PARSE-02 | 8 typed exceptions + abstract base                                         | 02-02 | DONE   |
| PARSE-03 | HtmInvoiceParser                                                           | 02-05 | DONE   |
| PARSE-04 | InvoiceNumberResolver                                                      | 02-04 | DONE   |
| PARSE-05 | QuantityNormalizer                                                         | 02-03 | DONE   |
| PARSE-06 | PriceNormalizer                                                            | 02-03 | DONE   |
| PARSE-07 | 3 hermetic fixtures present + hermetic invariant verified                  | 02-07 | DONE   |
| MATCH-01 | EanMatcherService 2-query batch                                            | 02-06 | DONE   |
| MATCH-02 | Unmatched lines yield match_strategy='none'                                | 02-06 | DONE   |
| QA-01    | 5 real-fixture pin tests                                                   | 02-05 | DONE   |
| QA-02    | Decimal-qty rejection + leading-zero EAN preservation                      | 02-03, 02-06 | DONE |

Phase 3 (Apply Layer + Orchestrators) can begin against this stable foundation.

## Self-Check: PASSED

Created files:
- FOUND: `.planning/phases/02-pure-parsers-dtos-exceptions-ean-matcher/02-07-SUMMARY.md`

Deviation-fix commit:
- FOUND: `69fee5f` (`fix(02-07): tune phpmd thresholds for canonical DTO domain terms`)

Hermetic fixtures:
- FOUND: `tests/fixtures/invoices/Nr_PRO026712_no_28112024.HTM`
- FOUND: `tests/fixtures/invoices/Nr_PRO029691_no_09072025.HTM`
- FOUND: `tests/fixtures/invoices/Nr_PRO033328_no_13042026.HTM`

Baseline integrity:
- VERIFIED: `phpstan-baseline.neon` sha256 = `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` (unchanged from Phase 2 start)

`make all` smoke run:
- VERIFIED: 4/4 sub-gates green; 92/92 tests passed (264 assertions)
