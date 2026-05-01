---
phase: 06-pass-3-variation-ean-matcher
plan: 02
subsystem: match
type: execute
tags: [interface, dto, type-only, contract, phpstan-l10]
status: complete
completed_date: 2026-05-01
duration_minutes: 4
tasks_completed: 2
files_created:
  - classes/match/MatchStrategy.php
files_modified:
  - classes/dto/MatchedLine.php
requires:
  - classes/dto/ParsedLine.php
  - classes/dto/MatchedLine.php
provides:
  - "MatchStrategy interface — chain-stage contract for Plans 03/04 matcher implementations"
  - "MatchedLine.match_strategy widened union with 'variation' literal"
affects:
  - "Plan 06-03 (OfferCodeMatcher + ProductCodeSingleOfferMatcher implementations) — can now `implements MatchStrategy`"
  - "Plan 06-04 (VariationMatcher implementation) — can now `implements MatchStrategy` and emit `'variation'` strategy"
  - "Plan 06-05 (EanMatcherService chain runner refactor) — can now consume MatchStrategy[] and concatenate list<MatchedLine>"
tech_stack_added: []
patterns:
  - "Interface-driven chain-stage pipeline (Strategy pattern)"
  - "Monotonic union widening (PHPStan-safe — narrower 3-literal returns remain valid against widened 4-literal contract)"
key_files_created:
  - "classes/match/MatchStrategy.php — declares public function match(array \\$arUnmatched): array, PHPStan-narrowed via @param list<ParsedLine> + @return list<MatchedLine>"
key_files_modified:
  - "classes/dto/MatchedLine.php — @property-read + @param both widened to 'offer_code'|'product_code_single_offer'|'variation'|'none'; class-level prose updated to document Phase 6 / D-25-update"
decisions:
  - "Runtime parameter type stays `string` (not enum, not literal-string class const) — preserves D-26 SQLite varchar storage portability and matches the existing 3-literal pattern"
  - "PHPStan @param/@property-read docblock narrowing only — runtime stays Hungarian-prefixed string"
  - "No DB migration needed — `match_strategy varchar(32)` already accommodates `'variation'`"
metrics:
  phpstan_level: 10
  phpstan_errors: 0
  phpstan_baseline_sha: 4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a
  pint_result: pass
  phpmd_result: pass
  tests_passed: 130
  tests_failed_preexisting: 125
  tests_failed_introduced_by_plan: 0
commits:
  - sha: 4494643
    message: "feat(06-02): widen MatchedLine.strategy union with 'variation' literal (MATCH-09)"
  - sha: e1fcae4
    message: "feat(06-02): add MatchStrategy chain-stage interface (MATCH-05)"
requirements_completed:
  - MATCH-05
  - MATCH-09
---

# Phase 6 Plan 02: MatchStrategy interface + MatchedLine union widening — Summary

Added the chain-stage `MatchStrategy` interface (`match(list<ParsedLine>): list<MatchedLine>`)
and widened `MatchedLine.match_strategy` union with the new `'variation'` literal so Wave 2
matcher classes (Plans 03/04) can implement the contract under PHPStan L10 with zero baseline
changes.

## What changed

### `classes/dto/MatchedLine.php` (modified — docblock-only)

- **Class-level `@property-read`** widened from
  `'offer_code'|'product_code_single_offer'|'none'` to
  `'offer_code'|'product_code_single_offer'|'variation'|'none'`.
- **Constructor `@param`** widened identically.
- **Class-level prose** appended with a Phase 6 / D-25-update paragraph documenting
  the new `'variation'` literal and noting that `match_strategy varchar(32)` already
  fits — no migration shipped.
- **Runtime signature unchanged** — `string $match_strategy` remains. The union is
  enforced at PHPStan level only, consistent with the pre-existing 3-literal pattern
  (D-26 SQLite portability).

### `classes/match/MatchStrategy.php` (new)

- `declare(strict_types=1);` at the top.
- Namespace: `Logingrupa\GoodsReceivedShopaholic\Classes\Match`.
- Imports `ParsedLine`, `MatchedLine`.
- Single public method: `match(array $arUnmatched): array`, PHPStan-narrowed via
  `@param list<ParsedLine>` + `@return list<MatchedLine>`.
- Hungarian-prefix parameter (`$arUnmatched`).
- Class-level docblock locks the implementer contract: `final` (Tiger-Style: no
  subclassing) + `#[\Override]` decoration of `match()`.

## Verification

| Gate           | Result                                                                |
| -------------- | --------------------------------------------------------------------- |
| `make pint-test` | `{"result":"pass"}` (PSR-12 clean)                                  |
| `make analyse`   | `[OK] No errors` — 35/35 files analysed under PHPStan L10           |
| `make phpmd`     | clean (Lovata ruleset, 0 violations)                                |
| `make test`      | 125 failed, 130 passed — **delta from baseline = 0 new failures**   |
| `phpstan-baseline.neon` SHA | `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` (pinned, unchanged) |

### Acceptance grep audit

| Check                                                                                  | Expected | Actual |
| -------------------------------------------------------------------------------------- | -------- | ------ |
| `grep -F -c "'offer_code'\|'product_code_single_offer'\|'variation'\|'none'"` in MatchedLine.php | 2        | 2      |
| `grep -F -c "'offer_code'\|'product_code_single_offer'\|'none'"` in MatchedLine.php (old)        | 0        | 0      |
| `grep -c '^interface MatchStrategy$'` in MatchStrategy.php                             | 1        | 1      |
| `grep -c 'declare(strict_types=1);'` in MatchStrategy.php                              | 1        | 1      |
| `grep -c 'namespace Logingrupa\\GoodsReceivedShopaholic\\Classes\\Match;'` in MatchStrategy.php  | 1        | 1      |
| `grep -F -c 'public function match(array $arUnmatched): array;'` in MatchStrategy.php  | 1        | 1      |
| `grep -c '@param  list<ParsedLine>'` in MatchStrategy.php                              | 1        | 1      |
| `grep -c '@return list<MatchedLine>'` in MatchStrategy.php                             | 1        | 1      |

## Deviations from Plan

None — plan executed exactly as written.

## Threat model coverage

- **T-06-02-01 (T — Tampering, MatchedLine union widening, accept):** Widening the
  string union is monotonic — every existing 3-literal value remains valid; no
  consumer can produce a now-invalid value. PHPStan L10 enforces narrower-into-wider
  type compatibility at compile time; the existing `EanMatcherService` 3-literal
  output continues to type-check against the widened 4-literal contract (verified
  by `make analyse` 0 errors with 35 files including the new interface).
- **T-06-02-02 (E — Elevation of privilege, MatchStrategy interface, accept):**
  Interface declares no scoped permissions / auth checks. Authorization handled
  upstream at the orchestrator + controller boundaries. Match strategies are
  internal value-resolvers.

## Pre-existing test failures (out of scope)

`make test` reports 125 failed / 130 passed — identical to the baseline measured
in `.planning/phases/06-pass-3-variation-ean-matcher/deferred-items.md` by
Plan 06-01. Two distinct root causes (MySQL identifier-length collision when the
test-bootstrap fails to force SQLite, and `Mockery` facade-set
`BadMethodCallException` in `GoodsReceivedTestCase::flushModelEventListeners()`)
are infra drift unrelated to Plan 06-02 surface area.

Per executor SCOPE BOUNDARY rule + plan contract instruction
(`do NOT re-investigate; treat as scope-boundary deferred`) these failures are
not auto-fixed inline. They remain logged in `deferred-items.md` for a future
OPS-fix plan.

## Self-Check: PASSED

- `classes/match/MatchStrategy.php`: FOUND.
- `classes/dto/MatchedLine.php`: FOUND (modified).
- Commit `4494643`: FOUND in `git log`.
- Commit `e1fcae4`: FOUND in `git log`.
- `phpstan-baseline.neon` SHA `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a`:
  PINNED (unchanged from start of plan).
- `make pint-test`: pass. `make analyse`: 0 errors. `make phpmd`: clean.
- Test delta from baseline: 0 new failures.
