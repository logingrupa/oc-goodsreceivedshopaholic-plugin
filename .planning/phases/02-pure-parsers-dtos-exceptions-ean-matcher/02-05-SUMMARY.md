---
phase: 02-pure-parsers-dtos-exceptions-ean-matcher
plan: 05
subsystem: parser
tags: [parser, htm, dom, xpath, fixture-pin, qa-01]
requires:
  - classes/dto/ParsedInvoice.php
  - classes/dto/ParsedLine.php
  - classes/parser/InvoiceNumberResolver.php
  - classes/parser/QuantityNormalizer.php
  - classes/parser/PriceNormalizer.php
  - classes/exception/MalformedHtmException.php
provides:
  - "HtmInvoiceParser::parse(string \$sHtml, string \$sSourceFilename): ParsedInvoice"
  - "Throw-vs-skip decision matrix consumed by Phase 3 ParseAndPersistOrchestrator"
affects:
  - "Phase 3 APPLY-06 orchestrator entry point — calls (new HtmInvoiceParser())->parse(\$sHtml, \$sFilename) once per upload"
tech_stack:
  added: []
  patterns:
    - "Pure HTM-bytes → DTO converter; no DB, no IO beyond input string"
    - "DOMDocument + DOMXPath with case-insensitive translate(@class) defense-in-depth"
    - "Position-indexed TD extraction (class names repeat: R20C2 = EAN + name)"
    - "Throw-vs-skip boundary: file-level fatal = throw, row-level data issue = skipped_rows"
key_files:
  created:
    - classes/parser/HtmInvoiceParser.php
    - tests/unit/Parser/HtmInvoiceParserTest.php
  modified: []
decisions:
  - "MAX_ROWS = 10000 hard cap (T-02-05-02 DoS guard)"
  - "LIBXML_NONET flag passed to loadHTML (T-02-05-01 XXE defense-in-depth)"
  - "EAN_REGEX = /^\\d{13}$/ — strict 13-digit only; 8-digit EAN-8 / internal codes recorded in skipped_rows"
  - "InvoiceNumberResolver invoked BEFORE loadDom — fail-fast on missing-number cheaper than building DOM first"
  - "Synthetic test helper buildSyntheticInvoiceHtml() exercises row-level branches without fixture I/O for edge cases (R21 row class, invalid-EAN skip, decimal-qty bubble)"
metrics:
  duration: "~6 minutes"
  completed_date: "2026-04-29T18:54:00Z"
  task_count: 3
  test_count: 8
  file_count: 2
---

# Phase 2 Plan 05: HtmInvoiceParser Summary

**One-liner:** `HtmInvoiceParser::parse()` converts distributor `.HTM` bytes into a typed `ParsedInvoice` DTO end-to-end with a documented throw-vs-skip decision matrix; pinned to real fixtures via 5 QA-01 sub-tests + 3 round-out invariants.

## Files Created

- `classes/parser/HtmInvoiceParser.php` — 264 lines. `final class HtmInvoiceParser` with one public method `parse()` and four private helpers (`stripBom`, `loadDom`, `extractRows`, `parseOneRow`, `dumpRow`).
- `tests/unit/Parser/HtmInvoiceParserTest.php` — 8 `it()` blocks rolling up the 5 QA-01 sub-tests plus 3 round-out invariants.

## Verbatim Public Signature

```php
namespace Logingrupa\GoodsReceivedShopaholic\Classes\Parser;

final class HtmInvoiceParser
{
    public const int MAX_ROWS = 10000;

    /**
     * @throws MalformedHtmException                                            on libxml fatal OR zero rows extractable
     * @throws \Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvoiceNumberMissingException  bubbled from resolver
     * @throws \Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvalidQuantityException       bubbled from QuantityNormalizer
     */
    public function parse(string $sHtml, string $sSourceFilename): ParsedInvoice;
}
```

## Constants

| Name | Value | Purpose |
|------|-------|---------|
| `MAX_ROWS` | `10000` (public) | Hard-cap on row iteration; bounded-loop DoS guard (T-02-05-02). Real fixtures have <200 rows. |
| `MIN_TD_COUNT` | `10` (private) | Minimum TD count for a usable data row. Below this → row is recorded in `skipped_rows` with reason `insufficient_columns`. |
| `EAN_REGEX` | `/^\d{13}$/` (private) | Strict 13-digit-only validation. Invalid → row goes to `skipped_rows` with reason `invalid_ean`. |
| `ROW_XPATH` | `//tr[contains(translate(@class,'r','R'),'R20') or contains(translate(@class,'r','R'),'R21')]` (private) | Case-insensitive XPath selector. Covers current uppercase fixtures and defends against future format drift to lowercase / mixed case. |

## Throw-vs-Skip Decision Matrix

This matrix is the **locked contract** between Phase 2 parser and Phase 3 orchestrator. The orchestrator must catch the three throw types and treat `skipped_rows` as audit-only data.

| Condition | Outcome | Recovery in Phase 3 orchestrator |
|-----------|---------|----------------------------------|
| libxml fatal error | throw `MalformedHtmException` (with `arContext.libxml_errors`) | abort transaction, surface to operator |
| Zero R20/R21 rows extracted (no skipped, no lines) | throw `MalformedHtmException` (with `arContext.reason = 'no_rows_extracted'`) | abort transaction, surface to operator |
| Invoice number missing in body AND filename | throw `InvoiceNumberMissingException` (bubbles from `InvoiceNumberResolver`) | abort transaction, surface to operator |
| Decimal / zero / negative qty in any row | throw `InvalidQuantityException` (bubbles from `QuantityNormalizer`) | abort transaction, surface to operator |
| Row has < 10 TDs (e.g., footer/totals row with `colspan`) | append to `skipped_rows[]` with `reason: 'insufficient_columns'`, continue | persist as audit log line, do NOT touch stock |
| EAN cell not exactly 13 digits (8-digit EAN-8, internal code, empty) | append to `skipped_rows[]` with `reason: 'invalid_ean'`, continue | persist as audit log line, do NOT touch stock |
| Unparseable price cell (e.g., `1.234.567,89`) | parsed line carries `unit_price=null` etc.; row still emitted | persist line normally; price fields are audit-only |
| Non-element node in DOMNodeList (defensive PHPStan-level-10 path) | append to `skipped_rows[]` with `reason: 'non_element_row'`, continue | persist as audit log line |

## Real-Fixture Pin Truths

The test file pins these exact counts; format drift triggers test failure (operator visibility — brittle by design per QA-01 success criterion).

| Fixture | grep R20+R21 | Lines emitted | Skipped rows | Skip reasons |
|---------|--------------|---------------|--------------|--------------|
| `Nr_PRO033328_no_13042026.HTM` | 25 | 21 | 4 | 2× `insufficient_columns` (footer rows with `colspan`), 2× `invalid_ean` (empty cell) |
| `Nr_PRO026712_no_28112024.HTM` | 154 | 135 | 19 | 19× `invalid_ean` (8-digit internal codes like `40092454`) |

`Nr_PRO033328_no_13042026.HTM` first line: `row_index=1, ean=4752307000097, qty=5, unit=PCE, unit_price=3.86`.

## QA-01 Test Coverage

All 5 QA-01 sub-tests are explicitly named via `it()` descriptions matching the QA-01 catalogue:

1. **HandlesUnquotedAttributesTest** — real PRO033328 fixture parses despite `<TR CLASS=R20>` UPPERCASE UNQUOTED attribute syntax.
2. **StripsBomBeforeParseTest** — synthetic double-BOM input still parses cleanly (`ltrim` byte-oriented charlist removes repeated BOMs in one call).
3. **HandlesBothR20AndR21RowsTest** — split into 2 sub-cases: synthetic minimal HTML asserting both classes extract, plus real PRO026712 fixture (147 R20 + 7 R21 = 154 rows).
4. **HandlesCRLFLineEndingsTest** — fixture parsed twice (once as CRLF, once with `\r\n` → `\n` substitution), asserts identical line count + first EAN.
5. **RejectsMalformedHtmTest** — minimal HTML body without R20/R21 rows yields `MalformedHtmException` with `arContext.reason = 'no_rows_extracted'`.

Plus two non-QA-01 round-out tests:
- **Invalid-EAN skip** (D-16 lenient): row with `'123'` (3-digit) EAN appears in `skipped_rows` with reason `invalid_ean`; parser does NOT throw.
- **Decimal-qty bubble-through** (T-02-05-05 mitigation proof): row with `qty='5,12'` causes `QuantityNormalizer::parseQuantity()` to throw `InvalidQuantityException`; parser does NOT catch.

## Threat-Model Coverage

| Threat ID | Mitigation in shipped code |
|-----------|---------------------------|
| T-02-05-01 (XXE) | `LIBXML_NONET` flag passed to `DOMDocument::loadHTML` (line 132). Verified by `grep -q LIBXML_NONET classes/parser/HtmInvoiceParser.php`. |
| T-02-05-02 (DoS / unbounded rows) | `MAX_ROWS = 10000` constant + `if (++$iRowCount > self::MAX_ROWS) break` in `extractRows()` (lines 168-170). |
| T-02-05-05 (silent qty corruption) | `QuantityNormalizer::parseQuantity()` invoked uncaught in `parseOneRow()` (line 251); throws bubble to caller. Test `it('throws InvalidQuantityException on decimal qty in a row')` asserts this contract. |
| T-02-05-06 (invalid EAN) | `EAN_REGEX = /^\d{13}$/` validation in `parseOneRow()` (line 233) BEFORE constructing `ParsedLine`. Failure → `Log::warning` + `skipped_rows` entry. EanMatcherService (plan 02-06) re-checks at its boundary for defense-in-depth. |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 — Bug] Pin-test row count expectation mismatch**
- **Found during:** Task 2 (test execution after implementation)
- **Issue:** Initial test asserted `count(\$obParsed->lines) === 25` for PRO033328 and `=== 154` for PRO026712 (raw R20+R21 grep counts). The parser correctly skips footer/totals rows with colspan TDs and rows with non-13-digit EANs (8-digit internal codes), so actual emitted line counts are 21 and 135 respectively.
- **Fix:** Updated assertions to `lines === 21, skipped_rows === 4` for PRO033328 and `lines + skipped_rows === 154` (invariant) plus `lines === 135` for PRO026712. Comment in test file documents the breakdown.
- **Files modified:** `tests/unit/Parser/HtmInvoiceParserTest.php`
- **Commit:** `8284475` (folded into the GREEN commit before separate commit cycle)

**2. [Rule 1 — PHPStan level-10 type errors]**
- **Found during:** Task 3 (QA gate `make analyse`)
- **Issue 1:** `DOMXPath::query()` iterates `DOMNodeList<DOMNameSpaceNode|DOMNode>` per PHPStan 1.11 stubs — `parseOneRow(DOMNode)` parameter type was too narrow.
- **Fix 1:** Broadened `parseOneRow` parameter to `DOMNameSpaceNode|DOMNode` with a runtime `instanceof DOMNode` guard; non-`DOMNode` cases recorded in `skipped_rows` with reason `non_element_row` (defensive — never observed in real fixtures).
- **Issue 2:** `\$arTds[6] ?? null` etc. on positions 6..9 — PHPStan correctly proved the offsets are guaranteed because the `MIN_TD_COUNT = 10` guard short-circuits earlier.
- **Fix 2:** Dropped `?? null` from positions 6..9 in the `ParsedLine` construction; added comment explaining the guarantee.
- **Files modified:** `classes/parser/HtmInvoiceParser.php`
- **Commit:** `52da9dd`

**3. [Rule 1 — pint method_argument_space]**
- **Found during:** Task 3 (`make pint-test` after Task 2 commit)
- **Issue:** `sprintf` call with 16 chained `\$arSpec[...]` arguments lined out to ≥120 chars; pint demands one argument per line at that length.
- **Fix:** `make pint` auto-fixed it (one argument per line).
- **Files modified:** `tests/unit/Parser/HtmInvoiceParserTest.php`
- **Commit:** `52da9dd`

### Out-of-Scope Findings (deferred)

- `phpmd` reports 3 pre-existing warnings on `classes/dto/ParsedLine.php`: `ExcessiveParameterList` (9 params on `__construct`) and 2× `ShortVariable` (`\$ean`, `\$qty`). Confirmed pre-existing via `git log --oneline classes/dto/ParsedLine.php` (last touch: `cbf8653 feat(02-01)`). Not introduced by this plan; out of scope per executor scope-boundary rule. Will be revisited in plan 02-07 (full QA gate sweep).

## QA Gate Results (this plan)

| Gate | Command | Result |
|------|---------|--------|
| Style (Pint) | `make pint-test` | **pass** |
| Static analysis | `make analyse` (PHPStan level 10 + Larastan) | **OK — no errors** |
| Baseline | `git diff --exit-code phpstan-baseline.neon` | **unchanged** |
| Tests (this plan) | `vendor/bin/pest --filter=HtmInvoiceParser` | **8 passed (35 assertions)** |
| Tests (full plugin) | `vendor/bin/pest` | **79 passed (224 assertions)** |

`make all` (which adds `phpmd`) is deferred to plan 02-07 per the plan's own scope.

## Commits

- `418e8fb` test(02-05): add failing tests for HtmInvoiceParser (5 QA-01 + skip + bubble)
- `8284475` feat(02-05): implement HtmInvoiceParser with BOM strip + LIBXML_NONET + MAX_ROWS
- `52da9dd` fix(02-05): satisfy PHPStan level-10 on DOMNodeList iteration + PriceNormalizer args

## TDD Gate Compliance

- RED: `418e8fb` (test commit, 8 failing assertions)
- GREEN: `8284475` (feat commit, all 8 tests pass)
- REFACTOR: `52da9dd` (fix commit folding PHPStan/Pint adjustments; tests still pass)

Sequence valid.

## Self-Check: PASSED

- [x] `classes/parser/HtmInvoiceParser.php` exists (`final class`, `LIBXML_NONET`, `MAX_ROWS`, `ltrim` BOM, case-insensitive XPath)
- [x] `tests/unit/Parser/HtmInvoiceParserTest.php` exists (8 `it()` blocks)
- [x] Commit `418e8fb` present in `git log`
- [x] Commit `8284475` present in `git log`
- [x] Commit `52da9dd` present in `git log`
