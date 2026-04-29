---
phase: 02-pure-parsers-dtos-exceptions-ean-matcher
plan: 03
subsystem: parser-normalizers

tags: [php84, parser, normalizer, stock-write-guard, decimal-comma, regex-validation, log-warning, audit-only, phpstan-level-10, pest, hungarian-notation]

# Dependency graph
requires:
  - phase: 02-pure-parsers-dtos-exceptions-ean-matcher
    plan: 02
    provides: GoodsReceivedException base + InvalidQuantityException typed subclass (used by QuantityNormalizer throw site)
  - phase: 01-schema-scaffold-settings-permissions
    provides: GoodsReceivedTestCase (Pest 4 / PHPUnit 12 base) + lang/en/lang.php exception.invalid_quantity key
provides:
  - "Logingrupa\\GoodsReceivedShopaholic\\Classes\\Parser\\QuantityNormalizer — final class with single static parseQuantity(string, array): int (throws InvalidQuantityException on bad input)"
  - "Logingrupa\\GoodsReceivedShopaholic\\Classes\\Parser\\PriceNormalizer — final class with single static parsePrice(?string): ?float (audit-only, never throws)"
  - "Stock-write guard: closes the silent-corruption path (int) '5,12' === 5 BEFORE Eloquent's setQuantityAttribute clamp"
  - "23 Pest tests (31 assertions): 13 QuantityNormalizer cases + 10 PriceNormalizer cases"
  - "QA-02 RejectsDecimalQuantityTest covered by QuantityNormalizerTest::it('rejects decimal-comma quantity')"
affects:
  - 02-04-invoice-number-resolver (sibling normalizer in same parser/ namespace)
  - 02-05-htm-parser (HtmInvoiceParser will call QuantityNormalizer::parseQuantity() per R20/R21 row qty cell + PriceNormalizer::parsePrice() per price column)
  - 02-06-ean-matcher (downstream consumer of ParsedLine which carries qty: int + ?float prices)
  - 03-stock-apply (StockApplyService writes only QuantityNormalizer-validated qty values)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Pure static helper class in classes/parser/ — final, no instance state, no DI, no facades on the class itself"
    - "Strict regex /^\\d+$/ as the qty validation gate (rejects decimal-comma, decimal-period, scientific notation, signs, spaces, all in one expression)"
    - "Decimal-comma → decimal-period normalization via str_replace before regex check on prices"
    - "Forensic context arg pattern: optional `array $arContext = []` second parameter merged with the normalizer's own `['raw' => ...]` before throw — caller can attach `['row_index' => 7]` etc."
    - "Audit-only lenient contract: PriceNormalizer never throws; non-numeric input returns null + Log::warning with raw value in array context (T-02-03-05 log-injection-safe)"

key-files:
  created:
    - "classes/parser/QuantityNormalizer.php"
    - "classes/parser/PriceNormalizer.php"
    - "tests/unit/Parser/QuantityNormalizerTest.php"
    - "tests/unit/Parser/PriceNormalizerTest.php"
  modified: []

key-decisions:
  - "Two separate classes (not one bundled `Normalizer` with two methods) — single-responsibility, smaller blast radius for changes, distinct contracts (throw vs lenient) deserve distinct types"
  - "`final class` on both — Tiger-Style: tree leaves; subclassing a parser would invite override of the validation regex which is the security boundary"
  - "Static methods (not instance) — these helpers are pure functions over input strings; no state, no DI seam needed; mocking unnecessary because behavior is deterministic"
  - "Caller-supplied `$arContext` merge order: caller keys override the normalizer's own keys — but `'raw'` is the normalizer's key and callers should not shadow it; documented expectation, not enforced (Tiger-Style: trust the caller within the module boundary)"
  - "PriceNormalizer's failure path emits Log::warning with `['raw' => $sRaw]` in the context array, not spliced into the message string — log-injection guard (T-02-03-05) inherits from the framework's array serialization"
  - "Used `(string) \\Lang::get(...)` cast on the throw-site message to satisfy PHPStan level 10 (Lang::get's return type is `array<string,mixed>|string`); avoids a baseline entry"
  - "Both normalizer functions are <30 lines, max nesting 1 (early returns + guard clauses) — D-37 conformance verified by inspection"

patterns-established:
  - "Parser-helper authoring template: `<?php declare(strict_types=1);` → namespace `Logingrupa\\GoodsReceivedShopaholic\\Classes\\Parser` → docblock pinning the throw/return contract → `final class <Name>` → single public static method with explicit return type"
  - "TDD gate sequence per task: test commit (RED — Class not found error confirms missing) → impl commit (GREEN — all assertions pass)"
  - "`\\Log::spy()` + `\\Log::shouldHaveReceived('warning')` for negative-path assertions on lenient normalizers (Laravel facade test helper, works under October re-export)"

threat-model-coverage:
  - "T-02-03-01 (silent stock corruption from decimal-comma): mitigated by /^\\d+$/ regex, asserted by QuantityNormalizerTest::it('rejects decimal-comma quantity')"
  - "T-02-03-02 (zero/negative qty invariant): mitigated by `<=0` guard, asserted by tests for '0' and '-3'"
  - "T-02-03-03 (DoS via long input): accepted — PCRE bounded-time + upstream upload size limit (Phase 4)"
  - "T-02-03-04 (info disclosure of raw cell value): accepted — line is not PII, already covered in 02-02 threat model"
  - "T-02-03-05 (log injection on PriceNormalizer warning): mitigated by passing raw value in array context, framework JSON serialization escapes control chars"

requirements-completed: [PARSE-05, PARSE-06, QA-02]

# Metrics
metrics:
  duration: "~8 minutes (2 TDD cycles × ~3 min + QA gate + summary)"
  tasks: 3
  tests-added: 23
  files-created: 4
  files-modified: 0
  completed: 2026-04-29
---

# Phase 2 Plan 03: Quantity & Price Normalizers Summary

**One-liner:** Two pure static helpers — `QuantityNormalizer` rejects decimal-comma qty (`'5,12'`) at the parser boundary BEFORE Eloquent's silent `(int)` clamp would corrupt `offer.quantity` to 5, and `PriceNormalizer` (audit-only, never throws) converts decimal-comma price strings to floats with `Log::warning` on bad input.

## What Was Built

### Production Code

1. **`classes/parser/QuantityNormalizer.php`** (PARSE-05)
   - `final class` in namespace `Logingrupa\GoodsReceivedShopaholic\Classes\Parser`
   - Verbatim signature:
     ```php
     /**
      * @param array<string, mixed> $arContext
      * @throws InvalidQuantityException
      */
     public static function parseQuantity(string $sRaw, array $arContext = []): int
     ```
   - Validation regex: `/^\d+$/` against `trim($sRaw)`
   - `<= 0` post-cast guard for the int-overflow / underflow corner
   - Throws `InvalidQuantityException` with `array_merge(['raw' => $sRaw], $arContext)` for the malformed-input path and `array_merge(['raw' => $sRaw, 'parsed' => $iQty], $arContext)` for the zero/negative path
   - 22 lines total in the public method body

2. **`classes/parser/PriceNormalizer.php`** (PARSE-06)
   - `final class` in namespace `Logingrupa\GoodsReceivedShopaholic\Classes\Parser`
   - Verbatim signature:
     ```php
     public static function parsePrice(?string $sRaw): ?float
     ```
   - Validation regex (post comma→period normalization): `/^-?\d+(\.\d+)?$/`
   - Never throws — non-numeric input returns null AND emits `\Log::warning` with `['raw' => $sRaw, 'reason' => 'non-numeric or multiple decimal markers']`
   - Accepts: `null`, `''`, whitespace-only → null. Decimal-comma, decimal-period, zero, negative → float.
   - 18 lines total in the public method body

### Tests

3. **`tests/unit/Parser/QuantityNormalizerTest.php`** — 13 cases / 20 assertions
   - Positive: `'5'` → 5; `'  42 '` → 42 (trim)
   - Throws: `'5,12'`, `'5.12'`, `'0'`, `'-3'`, `''`, `'   '`, `'abc'`, `'5e2'`
   - Context: raw value attached; caller-supplied keys merged; parsed value attached on zero/neg path
   - **QA-02 RejectsDecimalQuantityTest:** `it('rejects decimal-comma quantity (QA-02 RejectsDecimalQuantityTest)')` — green

4. **`tests/unit/Parser/PriceNormalizerTest.php`** — 10 cases / 11 assertions
   - Null / empty / whitespace → null
   - Decimal-comma `'3,86'` → 3.86; decimal-period `'3.86'` → 3.86; `'0,00'` → 0.0; `'-1,50'` → -1.5
   - `'abc'` → null + `Log::shouldHaveReceived('warning')->once()`
   - `'1.234.567,89'` (multi-decimal) → null
   - Whitespace trim: `'  19,30  '` → 19.3

## Locked Contract for Plan 02-05 (`HtmInvoiceParser`)

**QuantityNormalizer:** ALWAYS throws `InvalidQuantityException` on bad input. Parser MUST wrap each row's qty cell call in either:
- a try/catch that records the row in `ParsedInvoice::skipped_rows` (preferred — boundary-layer lenient parse per D-15), OR
- let the exception propagate (only acceptable for whole-file aborts)

**PriceNormalizer:** NEVER throws. Returns null on missing/invalid input. Parser must accept null prices in `ParsedLine::unit_price`/`discount`/`line_price`/`total` (already typed as `?float` in the DTO per plan 02-01 D-04). The framework's `Log::warning` already records the forensic trail — parser need not re-log.

**Calling pattern from plan 02-05:**
```php
$iQty = QuantityNormalizer::parseQuantity($sQtyCell, ['row_index' => $iRowIndex]);
$fUnitPrice = PriceNormalizer::parsePrice($sUnitPriceCell);
```

## Deviations from Plan

None — plan executed exactly as written. The plan listed 9 cases per task; final test counts came in at 13 and 10 (a few extra context-assertion cases for QuantityNormalizer + a whitespace-trim positive case for PriceNormalizer to round out edges).

## Verification Results

| Gate | Command | Result |
|------|---------|--------|
| Syntax | `php -l classes/parser/QuantityNormalizer.php` | No syntax errors detected |
| Syntax | `php -l classes/parser/PriceNormalizer.php` | No syntax errors detected |
| Unit tests | `pest --filter='QuantityNormalizer'` | 13 passed (20 assertions) |
| Unit tests | `pest --filter='PriceNormalizer'` | 10 passed (11 assertions) |
| Full suite | `pest` (whole plugin) | 60 passed (155 assertions) — no regressions |
| Style | `make pint-test` | `{"result":"pass"}` |
| Static analysis | `make analyse` (PHPStan level 10) | `[OK] No errors` |
| Baseline | `git diff --exit-code phpstan-baseline.neon` | unchanged |

## Decisions Made

1. **Two classes, not one bundled `Normalizer`** — distinct contracts (throw vs. lenient) deserve distinct types.
2. **`final class` modifier on both** — the validation regex IS the security boundary; subclassing would let downstream code override it.
3. **`(string)` cast on `\Lang::get(...)`** — Lang::get returns `array<string,mixed>|string`; cast keeps the file PHPStan-level-10 clean without a baseline entry.
4. **PriceNormalizer warning, not silence, on non-numeric input** — Tiger-Style: even lenient modules must leave a forensic trail. Operator gets a Log entry without import abort.
5. **Caller-context merged in front of `'raw'` key in `array_merge`** — `'raw'` survives even if caller passes `['raw' => 'something_else']` because of the order; documented as the convention for plan 02-05 to follow.

## Commits

| # | Type | Hash | Subject |
|---|------|------|---------|
| 1 | test | `4619bb1` | test(02-03): add failing tests for QuantityNormalizer (QA-02) — RED |
| 2 | feat | `c0813d8` | feat(02-03): implement QuantityNormalizer (PARSE-05) — GREEN |
| 3 | test | `2da59b1` | test(02-03): add failing tests for PriceNormalizer (PARSE-06) — RED |
| 4 | feat | `7bf0bb1` | feat(02-03): implement PriceNormalizer (PARSE-06, audit-only) — GREEN |

## Self-Check: PASSED

- `classes/parser/QuantityNormalizer.php` — FOUND
- `classes/parser/PriceNormalizer.php` — FOUND
- `tests/unit/Parser/QuantityNormalizerTest.php` — FOUND
- `tests/unit/Parser/PriceNormalizerTest.php` — FOUND
- Commit `4619bb1` — FOUND
- Commit `c0813d8` — FOUND
- Commit `2da59b1` — FOUND
- Commit `7bf0bb1` — FOUND
- 23 new Pest assertions green; 60 total in plugin (zero regressions)
- PHPStan level 10 clean; baseline unchanged; Pint clean
