---
phase: 02-pure-parsers-dtos-exceptions-ean-matcher
plan: 06
subsystem: matcher
tags: [matcher, ean, batch-query, qa-02, security, sql]
requires:
  - classes/dto/MatchedLine.php
  - classes/dto/ParsedLine.php
  - classes/exception/InvalidEanException.php
  - lovata_shopaholic_offers (table — Lovata core)
  - lovata_shopaholic_products (table — Lovata core)
provides:
  - "EanMatcherService::matchBatch(list<string>): array<string, MatchResult>"
  - "EanMatcherService::buildMatchedLines(list<ParsedLine>, MatchMap): list<MatchedLine>"
  - "Two-query budget contract — pinned by DB::queryLog count assertion"
affects:
  - "Phase 3 APPLY-06 ParseAndPersistOrchestrator — calls matchBatch() once per parsed invoice + buildMatchedLines() once to wrap parser output for StockApplyService consumption"
tech_stack:
  added: []
  patterns:
    - "Eloquent whereIn parameterized IN-clause (PDO bind variables — SQL injection mitigation T-02-06-01)"
    - "Correlated addSelect subquery for single-offer lookup — keeps Pass 2 to ONE round-trip"
    - "Has-relation guard `has('offer', '=', 1)` for single-offer constraint without join"
    - "Defense-in-depth EAN_REGEX guard at matcher boundary (pre-DB validation)"
    - "Hermetic minimal SQLite schema in test setUp() — works around Lovata.Shopaholic SQLite migration incompatibility"
key_files:
  created:
    - classes/match/EanMatcherService.php
    - tests/unit/Match/EanMatcherServiceTest.php
  modified: []
decisions:
  - "Pass 2 uses correlated `addSelect` subquery (NOT `with('offer:id,product_id')` eager-load) — eager-load issues a 2nd round-trip which would break the 2-query budget"
  - "Subquery uses `whereColumn('product_id', 'lovata_shopaholic_products.id')->limit(1)` — `has('offer','=',1)` already constrains to exactly-one-offer products, LIMIT 1 is defense-in-depth"
  - "EAN_REGEX is `/^\\d{13}$/` strict — non-13-digit input throws InvalidEanException BEFORE any DB query (T-02-06-01)"
  - "matchBatch result map key is the EAN string verbatim — no int-cast anywhere; QA-02 PreservesLeadingZeroEanTest pins this"
  - "Test bootstrap uses hermetic minimum schema (id/code/product_id/sort_order) instead of full Lovata migration — see Deviations section"
  - "saveQuietly() in test seeds — bypasses Lovata Offer afterSave price-table lookups absent from hermetic schema"
metrics:
  duration: "~12 minutes"
  completed_date: "2026-04-29T19:30:00Z"
  task_count: 3
  test_count: 13
  file_count: 2
---

# Phase 2 Plan 06: EanMatcherService Summary

**One-liner:** `EanMatcherService::matchBatch()` resolves a batch of EAN strings to offer ids in EXACTLY two SQL round-trips (offer.code WHERE IN, then product.code WHERE IN with correlated single-offer subquery), preserving leading-zero EANs as strings and rejecting non-13-digit input with `InvalidEanException` BEFORE touching the DB.

## Files Created

- `classes/match/EanMatcherService.php` — 267 lines. `final class EanMatcherService` in `Logingrupa\GoodsReceivedShopaholic\Classes\Match`. Two public methods + three private helpers.
- `tests/unit/Match/EanMatcherServiceTest.php` — 338 lines. 13 `it()` blocks covering the full matcher contract.

## Verbatim Public Signatures

```php
namespace Logingrupa\GoodsReceivedShopaholic\Classes\Match;

final class EanMatcherService
{
    private const string EAN_REGEX = '/^\d{13}$/';

    /**
     * @param  list<string>  $arEans
     * @return array<string, array{matched_offer_id: int|null, match_strategy: 'offer_code'|'product_code_single_offer'|'none'}>
     *
     * @throws InvalidEanException
     */
    public function matchBatch(array $arEans): array;

    /**
     * @param  list<ParsedLine>  $arLines
     * @param  array<string, array{matched_offer_id: int|null, match_strategy: string}>  $arMatchMap
     * @return list<MatchedLine>
     */
    public function buildMatchedLines(array $arLines, array $arMatchMap): array;
}
```

## Two-Query Budget Assertion Mechanism

`tests/unit/Match/EanMatcherServiceTest.php` uses `DB::enableQueryLog()` + `count(DB::getQueryLog())`:

```php
\DB::flushQueryLog();
\DB::enableQueryLog();

$arResult = (new EanMatcherService())->matchBatch([
    '4752307000097', '4752307000165', '4752307000200', '4752307000300', '4752307000400',
]);

expect(count(\DB::getQueryLog()))->toBe(2);
```

The test seeds 3 matchable rows + 2 unmatched. With 5 input EANs, the matcher MUST issue exactly 2 SQL statements:

1. **Pass 1:** `SELECT id, code FROM lovata_shopaholic_offers WHERE code IN (?, ?, ?, ?, ?) AND deleted_at IS NULL ORDER BY sort_order ASC`
2. **Pass 2:** `SELECT id, code, (SELECT id FROM lovata_shopaholic_offers WHERE product_id = lovata_shopaholic_products.id AND deleted_at IS NULL ORDER BY sort_order ASC LIMIT 1) AS matched_offer_id FROM lovata_shopaholic_products WHERE code IN (?, ?) AND (SELECT count(*) FROM lovata_shopaholic_offers WHERE product_id = ... AND deleted_at IS NULL) = 1`

A complementary 1-query test asserts that when ALL EANs match in Pass 1, Pass 2 is skipped entirely (returns 1, not 2).

A 0-query test asserts that empty input array returns `[]` without touching the DB.

## Strategy Decision Matrix

| Found in                                  | match_strategy               |
|-------------------------------------------|------------------------------|
| `offers.code` (Pass 1)                    | `offer_code`                 |
| `products.code` with exactly 1 offer (P2) | `product_code_single_offer`  |
| `products.code` with 0 or ≥2 offers (P2)  | `none`                       |
| nowhere                                   | `none`                       |

`match_strategy='none'` → `matched_offer_id=null` always (MATCH-02 partial-match never throws).

## QA-02 PreservesLeadingZeroEanTest

```php
seedOffer($obProduct->id, '0000000012345');
$arResult = (new EanMatcherService())->matchBatch(['0000000012345']);

expect($arResult)->toHaveKey('0000000012345');
expect(array_keys($arResult)[0])->toBe('0000000012345'); // STRING, not int 12345
expect($arResult['0000000012345']['match_strategy'])->toBe('offer_code');
```

The `array_keys()` identity check is the load-bearing assertion: PHP normally
auto-converts numeric strings to int when used as array keys, but
`'0000000012345'` is preserved as a string because it's not "decimal-int
representable" (leading zeros). If implementation drift int-casts anywhere,
the key becomes `12345` and the test fails.

## Threat-Model Mitigations Implemented

| Threat ID | Mitigation in code | Test that pins it |
|-----------|--------------------|--------------------|
| T-02-06-01 (SQL injection) | Eloquent `whereIn` with PDO binds + EAN_REGEX guard rejects non-13-digit input BEFORE query is built | "throws InvalidEanException for non-13-digit input BEFORE issuing any DB query (T-02-06-01)" |
| T-02-06-04 (silent leading-zero strip) | EAN flows through `whereIn` and result map keys verbatim — no int-cast | "preserves leading-zero EAN as STRING throughout (QA-02 PreservesLeadingZeroEanTest)" |
| T-02-06-02 (timing oracle) | Accepted (publicly observable from storefront) | n/a |
| T-02-06-03 (DoS via large IN list) | Accepted — bounded upstream by Phase 2 parser MAX_ROWS=10000 cap and Phase 4 controller upload size cap | n/a |

## PHPStan Ignore Comments Added

Three `@phpstan-ignore-next-line property.notFound` comments on Lovata model property accesses inside the matcher:

1. `$obRow->code` (Offer row in Pass 1) — line 159 in service file
2. `$obRow->id` (Offer row in Pass 1) — line 161
3. `$obRow->matched_offer_id` (Product row in Pass 2 — set by `addSelect` correlated subquery; not declared on Product model PHPDoc) — line 213
4. `$obRow->code` (Product row in Pass 2) — line 222

Reason: Lovata Shopaholic models lack IDE-helper PHPDoc for `code` (and the
correlated `matched_offer_id` is not a real column — set by the subquery at
runtime). Columns are verified at the DB layer via index existence in the
hermetic test schema. Per CLAUDE.md PHPStan policy: "Do not baseline" — these
are inline ignores with rationale, not baseline entries.

`make analyse` reports `[OK] No errors`. `phpstan-baseline.neon` is unchanged
(`git diff --exit-code phpstan-baseline.neon` exits 0).

## Test Coverage (13 it-blocks)

| # | Test | Pins |
|---|------|------|
| 1 | issues exactly TWO queries regardless of batch size (MATCH-01 proof) | 2-query budget contract |
| 2 | issues exactly ONE query when all EANs match via offer_code | Pass 2 skipped when not needed |
| 3 | preserves leading-zero EAN as STRING throughout | QA-02 PreservesLeadingZeroEanTest |
| 4 | returns offer_code strategy for direct offer match | Pass 1 happy path |
| 5 | returns product_code_single_offer when product matches with 13-digit EAN code and has exactly one offer | Pass 2 happy path + single-offer guard |
| 6 | returns none when product matches but has multiple offers | Single-offer guard rejects |
| 7 | returns none for fully unmatched EAN | MATCH-02 partial-match never throws |
| 8 | throws InvalidEanException for non-13-digit input BEFORE issuing any DB query | T-02-06-01 mitigation |
| 9 | throws InvalidEanException for input containing letters | EAN_REGEX coverage |
| 10 | throws InvalidEanException for input that is too long (14 digits) | EAN_REGEX coverage |
| 11 | returns empty result for empty input array (zero queries, no throw) | Zero-input invariant |
| 12 | buildMatchedLines wraps ParsedLines with match-map entries preserving order | Helper ordering invariant |
| 13 | buildMatchedLines treats missing map entries as match_strategy=none | Helper defensive default |

## Deviations from Plan

### [Rule 3 - Blocking issue] Test schema bootstrap workaround

**Found during:** Task 2 (test execution)
**Issue:** The plan's D-33 specified `protected $autoMigrate = true;` to bootstrap Lovata Shopaholic offers/products tables in SQLite-in-memory. Auto-migration fails on `update_table_offers_remove_price_field` with:

```
SQLSTATE[HY000]: General error: 1 error in index lovata_shopaholic_offers_price_index after drop column: no such column: price
```

Root cause: SQLite cannot drop a column that has an attached index, and Lovata's upstream migration calls `dropColumn(['price', 'old_price'])` without first calling `dropIndex()`. The bug is in vendor code (`plugins/lovata/shopaholic/updates/update_table_offers_remove_price_field.php`), reproducible on any test that runs `migrateModules()` + `migrateCurrentPlugin()` for a plugin that requires Lovata.Shopaholic.

**Fix:** Hermetic minimum schema in `EanMatcherTestCase::setUp()` — creates only the columns the matcher actually reads (`id`, `code`, `product_id`, plus October trait essentials `sort_order`, `name`, `slug`, `active`, timestamps, soft-deletes). Drops both tables in `tearDown()`. Decoupled from Lovata's full schema.

Side effect: had to use `saveQuietly()` in test seed helpers (`seedOffer`, `seedProduct`) because Lovata Offer's `afterSave` queries `lovata_shopaholic_prices` (intentionally absent from hermetic schema). The matcher only ever issues SELECT against `code`/`id`/`product_id`, so quiet save is functionally equivalent to a full save for its testing needs.

**Files modified:** `tests/unit/Match/EanMatcherServiceTest.php` (test setUp/tearDown methods + seedProduct/seedOffer helpers)
**Commit:** `50cf41c` (RED — test infrastructure) + `107c912` (GREEN — verified working)

### [Rule 1 - Bug] Plan's stated 2-query implementation actually issued 3 queries

**Found during:** Task 1 first GREEN run
**Issue:** Plan D-25 specified `Product::whereIn('code', ...)->has('offer','=',1)->with('offer:id,product_id')->get(['id', 'code'])` and asserted "exactly TWO queries". But Eloquent's `with(...)` issues an additional eager-load query, making the actual count THREE (offer pass + product pass + offer eager-load). The 2-query test would have failed with the plan's verbatim implementation.

**Fix:** Replaced eager-load with correlated `addSelect` subquery:

```php
->select(['id', 'code'])
->addSelect([
    'matched_offer_id' => Offer::select('id')
        ->whereColumn('product_id', 'lovata_shopaholic_products.id')
        ->limit(1),
])
```

The correlated subquery resolves the offer id INSIDE the same SQL statement as the product lookup — net one round-trip for Pass 2, total two for matchBatch. Behavior is semantically identical (returns offer_id for products with exactly one offer); plan contract preserved, test now passes truthfully.

**Files modified:** `classes/match/EanMatcherService.php` (private `lookupProductsWithSingleOffer` method)
**Commit:** `107c912` (GREEN — implementation aligns with documented 2-query budget)

### [Rule 2 - Critical correctness] phpmd ShortVariable on `$iId`

**Found during:** Task 3 QA gate run (extended phpmd check)
**Issue:** Initial implementation used `$iId` (3 chars), tripping phpmd's `ShortVariable` minimum-length-4 rule. Phase 2 plan 02-06 does not gate on phpmd (deferred to plan 02-07), but the warning is a regression introduced by this plan.

**Fix:** Renamed `$iId` → `$iOfferId` (more descriptive too).
**Files modified:** `classes/match/EanMatcherService.php` (private `lookupOffersByCode` method)
**Commit:** `ee90f09`

### Plan style choice — `Offer::whereIn` vs `Offer::query()->whereIn`

**Found during:** Task 1 verify gate
**Issue:** Plan's task verify grep was `grep -q 'Offer::whereIn'` (literal). My initial implementation used `Offer::query()->whereIn(...)` (semantically equivalent). The grep would have failed the verify check.

**Fix:** Switched to the literal `Offer::whereIn(...)` and `Product::whereIn(...)` form to match plan's intent. Functionally identical (both return a Builder).

**Files modified:** `classes/match/EanMatcherService.php` (Pass 1 + Pass 2 entry points)
**Commit:** Folded into `107c912`

## QA Gate Results (this plan only)

| Gate | Result |
|------|--------|
| `make pint-test` | `{"result":"pass"}` |
| `make analyse` (PHPStan level 10) | `[OK] No errors` |
| `git diff --exit-code phpstan-baseline.neon` | exit 0 (unchanged) |
| `vendor/bin/pest --filter='EanMatcher'` | 13 passed (40 assertions), 0.35s |
| Full plugin test suite | 92 passed (264 assertions) |
| `make phpmd` | Pre-existing 3 ParsedLine warnings (out of scope, deferred to plan 02-07); no new warnings from this plan |

## Self-Check: PASSED

**Files exist:**

```
$ ls -la classes/match/EanMatcherService.php tests/unit/Match/EanMatcherServiceTest.php
```

- FOUND: `classes/match/EanMatcherService.php` (267 lines)
- FOUND: `tests/unit/Match/EanMatcherServiceTest.php` (338 lines)

**Commits exist:**

- FOUND: `50cf41c` (RED tests)
- FOUND: `107c912` (GREEN service + test fixes)
- FOUND: `ee90f09` (refactor: phpmd compliance)

**Functional checks:**

- All 13 plan-specified test cases run green
- 2-query budget asserted by `DB::enableQueryLog()` count
- QA-02 leading-zero EAN preservation pinned by `array_keys()` identity check
- InvalidEanException throws BEFORE any DB query (zero-query-log assertion)
- `make analyse` clean; `phpstan-baseline.neon` unchanged

**Plan contract met:**

- `EanMatcherService::matchBatch(array<int, string> $arEans): array<string, array{matched_offer_id: ?int, match_strategy: string}>` — IMPLEMENTED
- Exactly TWO DB queries (one when all match in Pass 1; zero on empty input) — PROVEN
- Pass 1: `Offer::whereIn('code', $arEans)` — PRESENT (line 155)
- Pass 2: `Product::whereIn('code', $arUnmatched)->has('offer', '=', 1)` — PRESENT (lines 198-200)
- Leading-zero EANs preserved as STRING — VERIFIED via array_keys identity check
