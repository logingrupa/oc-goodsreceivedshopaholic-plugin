---
phase: 02-pure-parsers-dtos-exceptions-ean-matcher
plan: 04
subsystem: parser
tags: [parser, resolver, exception, fixtures]
requires:
  - classes/exception/InvoiceNumberMissingException.php (plan 02-02)
  - tests/fixtures/invoices/Nr_PRO*.HTM (plan 01-04)
  - lang/{en,lv,no,ru}/lang.php exception.invoice_number_missing key (plan 01-03)
provides:
  - classes/parser/InvoiceNumberResolver.php (static resolver: body-then-filename → tuple)
  - InvoiceNumberResolver::resolve() static API consumed by plan 02-05 HtmInvoiceParser
affects:
  - Phase 2 plan 02-05 (HtmInvoiceParser will call resolve($sHtmlBody, $sSourceFilename) once)
  - Phase 4 controller (catches InvoiceNumberMissingException → operator-friendly lang message)
tech_stack:
  added: []
  patterns:
    - "Two-tier resolver: authoritative source first, structured fallback second, throw on total miss"
    - "PCRE Unicode-safe matching (`u` flag) on tag-stripped + entity-decoded text"
    - "Overflow-date guard via getLastErrors().warning_count (createFromFormat carry-over fix)"
key_files:
  created:
    - classes/parser/InvoiceNumberResolver.php
    - tests/unit/Parser/InvoiceNumberResolverTest.php
  modified: []
decisions:
  - "Body marker regex `(?:Invoice|Faktura|Rechnung)\\s*(?:No|Nr|nr)[^\\w\\d]*([A-Z]{0,4}\\d{4,})` covers EN/NO/DE label variants — verified against all 3 hermetic fixtures"
  - "strip_tags + html_entity_decode + collapse-whitespace handles real-fixture split-TD layout where label and value live in two different cells with `&nbsp;` between SPANs"
  - "Filename always parsed (even when body wins) so country_code + invoice_date populate ParsedInvoice header without a second filename parse"
  - "DateTimeImmutable::createFromFormat overflow guard (warning_count > 0 → null) prevents `99999999` from silently rolling to year 10007"
  - "No `else` keyword — early-return idiom enforced (D-37); max nesting 1 level"
requirements_closed:
  - PARSE-04
metrics:
  duration: "2m 52s"
  completed_date: "2026-04-29"
  tasks: 3
  tests_added: 11
  assertions_added: 34
---

# Phase 2 Plan 04: InvoiceNumberResolver Summary

**One-liner:** Body-marker-first resolver (EN/NO/DE label variants) with `Nr_PRO<num>_<country>_<DDMMYYYY>.HTM` filename fallback, throws `InvoiceNumberMissingException` on total miss; pinned green against all 3 hermetic fixtures.

## What Changed

Created two new files implementing the canonical invoice-number resolution boundary for the goods-received import pipeline:

- **`classes/parser/InvoiceNumberResolver.php`** — final class with single public static method `resolve(string $sHtmlBody, string $sFilename): array`. Body resolution wins over filename (T-02-04-01 silent-rename guard); filename is parsed in both branches so `country_code` and `invoice_date` are always populated when the filename matches the canonical pattern.
- **`tests/unit/Parser/InvoiceNumberResolverTest.php`** — 11 Pest 4 tests / 34 assertions covering the 3 real fixtures + 8 boundary cases.

## Public API (locked for plan 02-05)

```php
namespace Logingrupa\GoodsReceivedShopaholic\Classes\Parser;

final class InvoiceNumberResolver
{
    /**
     * @return array{
     *     invoice_number: string,
     *     country_code: ?string,
     *     invoice_date: ?\DateTimeImmutable,
     *     resolved_via: 'body'|'filename'
     * }
     *
     * @throws InvoiceNumberMissingException  when neither body nor filename yields a number
     */
    public static function resolve(string $sHtmlBody, string $sFilename): array;
}
```

### Return-tuple shape (verbatim)

| Key              | Type                       | Source                                                                                |
| ---------------- | -------------------------- | ------------------------------------------------------------------------------------- |
| `invoice_number` | `string`                   | `strtoupper` of body capture OR filename `PRO\d+` capture                              |
| `country_code`   | `?string`                  | `strtolower` of filename group 2 (`'no'`/`'lv'`/`'lt'`); `null` if filename mismatches |
| `invoice_date`   | `?\DateTimeImmutable`      | `createFromFormat('!dmY', ...)` of filename group 3; `null` if no filename match       |
| `resolved_via`   | `'body'` &#124; `'filename'` | `'body'` when body marker hit; `'filename'` only when body missed and filename matched |

### Regex patterns (verbatim — for plan 02-05 cross-reference)

```php
// Body marker — covers EN/NO/DE label variants, Unicode-safe, no nested quantifiers
private const BODY_MARKER_REGEX = '/(?:Invoice|Faktura|Rechnung)\s*(?:No|Nr|nr)[^\w\d]*([A-Z]{0,4}\d{4,})/iu';

// Filename pattern — anchored both ends; captures (1) PRO+digits, (2) country, (3) DDMMYYYY
private const FILENAME_REGEX = '/^Nr_(PRO\d+)_([A-Za-z]{2,3})_(\d{8})\.HTM$/i';
```

## Test Coverage

11 tests, 34 assertions, all green:

| # | Test                                                                                | Asserts                                                                  |
| - | ----------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| 1 | extracts canonical number from body of all three real fixtures (data-driven × 3)    | `invoice_number` = `PRO026712`/`PRO029691`/`PRO033328`, `resolved_via` = `body` |
| 2 | extracts PRO033328 from real fixture body even with empty filename                  | body win + null country/date                                             |
| 3 | populates country_code and invoice_date from filename even when body wins           | filename metadata still extracted on body win                            |
| 4 | falls back to filename when body has no marker                                      | `resolved_via` = `filename`, full tuple                                  |
| 5 | lowercases country code from uppercase filename                                     | `'NO'` → `'no'`                                                          |
| 6 | throws InvoiceNumberMissingException when body and filename both miss               | `arContext` flags                                                        |
| 7 | throws when filename date is malformed (overflow guard)                             | `99999999` → throw, not year-10007 silent drift                          |
| 8 | throws when filename pattern does not match at all                                  | `'invoice.htm'` → throw                                                  |
| 9 | strips path components via basename in arContext                                    | full path → just filename in arContext                                   |

## QA Gates

| Gate            | Status     | Notes                                                          |
| --------------- | ---------- | -------------------------------------------------------------- |
| `php -l`        | clean      | both new files                                                 |
| `make pint-test`| pass       | `{"result":"pass"}`                                            |
| `make analyse`  | pass       | PHPStan level 10 + Larastan, no errors, no baseline change     |
| baseline diff   | unchanged  | `git diff --exit-code phpstan-baseline.neon`                   |
| Plan tests      | 11/11 pass | `--filter='InvoiceNumberResolver'`                             |
| Full plugin suite | 71/71 pass | no regressions in Phase 1 / 02-01..03 tests                  |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] DateTimeImmutable overflow tolerance**

- **Found during:** Task 1 implementation review
- **Issue:** Plan behavior list said "filename `Nr_PRO000001_no_99999999.HTM` plus empty body → throws (date 99/99/9999 fails createFromFormat)". In reality, `\DateTimeImmutable::createFromFormat('!dmY', '99999999')` succeeds via month/day carry-over and returns year 10007. Without an explicit guard, malformed dates would silently propagate into `ParsedInvoice.invoice_date`.
- **Fix:** Added `getLastErrors()->warning_count > 0` check after `createFromFormat`. Any non-zero warning count returns `null` from `parseFilenameMeta`, which then falls through to throw.
- **Files modified:** `classes/parser/InvoiceNumberResolver.php`
- **Commit:** `313d3b0`

**2. [Rule 1 - Bug] PHPStan level-10 type narrowing on getLastErrors**

- **Found during:** Task 3 QA gate
- **Issue:** PHPStan level 10 flagged `($arErrors['warning_count'] ?? 0)` because the array shape stub guarantees the key always exists.
- **Fix:** Direct array access `$arErrors['warning_count']`. The `$arErrors !== false` short-circuit before it covers the documented PHP behavior of `getLastErrors()` returning `false` when `createFromFormat` itself returns `false` (i.e., we never reach this line on the failed path).
- **Files modified:** `classes/parser/InvoiceNumberResolver.php`
- **Commit:** `35a81b8`

## Threat-Model Coverage Confirmation

| Threat ID       | Disposition | Verified By                                                                                          |
| --------------- | ----------- | ---------------------------------------------------------------------------------------------------- |
| T-02-04-01      | mitigate    | Test 3 ("populates country_code...even when body wins") — body authority preserved over filename     |
| T-02-04-02      | accept      | Regex has no nested quantifiers / no overlapping alternations — PCRE worst-case linear in input      |
| T-02-04-03      | mitigate    | Test 6 + Test 9 — `arContext` carries only `source_filename` (basename'd) plus boolean tried-flags   |
| T-02-04-04      | accept      | Test 7 — overflow date forces throw (no retry/loop)                                                  |

## Commits

| Hash      | Type    | Description                                                                  |
| --------- | ------- | ---------------------------------------------------------------------------- |
| `313d3b0` | feat    | implement InvoiceNumberResolver with body-then-filename fallback             |
| `ba53cb6` | test    | pin InvoiceNumberResolver against 3 real HTM fixtures                        |
| `35a81b8` | fix     | drop redundant null-coalesce on getLastErrors warning_count (PHPStan L10)    |

## Self-Check: PASSED

- `classes/parser/InvoiceNumberResolver.php` exists ✓
- `tests/unit/Parser/InvoiceNumberResolverTest.php` exists ✓
- Commits `313d3b0`, `ba53cb6`, `35a81b8` present in `git log` ✓
- All 11 plan tests pass; full plugin suite 71/71 green ✓
- Pint + PHPStan + baseline gates green ✓
