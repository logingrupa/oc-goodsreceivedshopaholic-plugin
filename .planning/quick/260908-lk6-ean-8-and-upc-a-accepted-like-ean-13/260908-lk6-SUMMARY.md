---
quick_id: 260908-lk6
status: complete
subsystem: parser, matcher
tags: [ean-8, upc-a, ean-13, regex, goods-received]

key-files:
  modified:
    - classes/parser/HtmInvoiceParser.php
    - classes/match/EanMatcherService.php
    - classes/dto/ParsedLine.php
    - tests/unit/Parser/HtmInvoiceParserTest.php
    - tests/unit/Match/EanMatcherServiceTest.php
    - updates/version.yaml

key-decisions:
  - "Both EAN_REGEX constants are the exact regex from the brief, /^(\\d{8}|\\d{12}|\\d{13})$/. The brief also asked for grep 'd{13}' in classes to return zero; the two remaining hits are those two constants, no old wording survives."
  - "UPC-A is compared as a 12-character string. No zero-padding."
  - "No new constant for the expected_format text. It appears once, inline, next to the exception."

completed: 2026-09-08
---

# Quick Task 260908-lk6: EAN-8 and UPC-A accepted like EAN-13

**Goods receiving now matches the 654 active EAN-8 offers and the 7 active UPC-A offers on .no that the 13-digit rule had excluded.**

## Accomplishments

- `HtmInvoiceParser::EAN_REGEX` and `EanMatcherService::EAN_REGEX` accept 8, 12 or 13 digits. Skip-and-continue in the parser (D-16) and throw-before-query in the matcher are unchanged.
- `InvalidEanException` context `expected_format` now reads `8, 12 or 13 digits`.
- Decision table, constant comments, the `missing_ean_column` comment, the matcher class docblock, its `@throws` line and the `ParsedLine` docblock were rewritten to the new rule.
- Real fixture `Nr_PRO026712_no_28112024.HTM` now yields 141 lines and 9 skipped rows instead of 135 and 15. The six moved rows carry the distributor's EAN-8 codes such as `40092454`.

## Task Commits

1. **Tasks 1 to 3: regex, docs, tests, version bump** - `f0ecb01` (feat)

## Verification

Run on Windows through the Makefile targets with PHP 8.4.15 (`make` is not on this host's PATH, the same five commands were run by hand).

| Gate | Result |
|---|---|
| pint-test | fails on every file with `line_ending` + `method_argument_space`, including untouched ones; LF copies of the five edited files pass clean |
| lint-settings-accessor | rc=0 |
| phpstan level 10 | `[OK] No errors` |
| phpmd | rc=0 (vendor deprecation notices only) |
| pest | 293 passed, 2075 assertions, 28.50s |

`grep -rn 'd{13}' classes` returns the two new regex constants and nothing else.

## Deviations from Plan

None in scope. One environmental note: the first full pest run failed a multi-file upload test because pest loaded `EanMatcherService.php` while it was being edited. A clean rerun and the final gate both pass; the file was not changed between the two.

## Issues Encountered

None.

## Next

Part 2, duplicate offer code resolution, runs as its own quick task.
