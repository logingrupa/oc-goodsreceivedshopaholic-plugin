# Phase 2: Pure Parsers, DTOs, Exceptions, EAN Matcher - Context

**Gathered:** 2026-04-29
**Status:** Ready for planning
**Mode:** Auto (smart-discuss `--auto`)

<domain>
## Phase Boundary

Convert any real `.HTM` distributor file into a typed `ParsedInvoice` DTO and resolve every line's EAN to an offer (or null) deterministically, with **zero side effects outside DB reads**.

**In scope:**
- 4 readonly DTOs in `classes/dto/`: `ParsedInvoice`, `ParsedLine`, `MatchedLine`, `ApplyResult`
- 8 typed exceptions in `classes/exception/` extending `GoodsReceivedException`
- `HtmInvoiceParser` (pure — DOM-based, handles `<TR CLASS=R20>` unquoted, BOM, CRLF)
- `InvoiceNumberResolver` (body → filename fallback → throw)
- `QuantityNormalizer` (rejects decimal qty BEFORE Eloquent silent int-clamp)
- `PriceNormalizer` (decimal-comma → float, audit-only)
- `EanMatcherService` (TWO-query batch: offer.code WHERE IN, then product.code WHERE IN with single-offer guard)
- 3 hermetic fixtures (already in `tests/fixtures/invoices/` from Phase 1)
- 6+ Pest tests (PARSE-* unit tests + cross-cutting QA-01/QA-02)

**Out of scope:**
- Persistence (Phase 3 ParseAndPersistOrchestrator wraps parser+matcher in transaction)
- Stock writes (Phase 3 StockApplyService)
- ActiveFlag toggle (Phase 3)
- Backend controller (Phase 4)

</domain>

<spec_lock>
## Locked Requirements (REQUIREMENTS.md)

Phase 2 reqs are LOCKED:
- **PARSE-01..07** — DTOs, exceptions, parser, resolver, normalizers, fixtures (lines 33-39)
- **MATCH-01, MATCH-02** — EanMatcherService (lines 43-44)
- **QA-01** — Real-fixture pin tests: `HandlesUnquotedAttributesTest`, `StripsBomBeforeParseTest`, `HandlesBothR20AndR21RowsTest`, `HandlesCRLFLineEndingsTest`, `RejectsMalformedHtmTest` (line 85)
- **QA-02** — Stock-write guard tests: `RejectsDecimalQuantityTest`, `PreservesLeadingZeroEanTest`, `Offer setQuantityAttribute clamp test boundary` (line 86)

</spec_lock>

<decisions>
## Implementation Decisions

### DTO Design (PARSE-01)
- **D-01:** PHP 8.4 `final readonly class` for all 4 DTOs (immutable by language guarantee, not just convention).
- **D-02:** All DTOs in `classes/dto/` (matches Lovata/Logingrupa convention `classes/<role>/`). Namespace `Logingrupa\GoodsReceivedShopaholic\Classes\Dto`.
- **D-03:** `ParsedInvoice` shape:
  ```php
  final readonly class ParsedInvoice {
      public function __construct(
          public string $invoice_number,           // resolved from body or filename
          public ?string $country_code,             // 'no' from filename
          public ?\DateTimeImmutable $invoice_date, // parsed from filename DDMMYYYY
          public string $source_filename,           // original .HTM filename
          /** @var list<ParsedLine> */
          public array $lines,
      ) {}
  }
  ```
- **D-04:** `ParsedLine` shape:
  ```php
  final readonly class ParsedLine {
      public function __construct(
          public int $row_index,
          public string $ean,              // STRING(13) preserves leading zeros
          public string $product_name_raw,
          public string $unit,             // 'PCE'
          public int $qty,                 // strictly positive integer
          public ?float $unit_price,       // audit-only, never written
          public ?float $discount,
          public ?float $line_price,
          public ?float $total,
      ) {}
  }
  ```
- **D-05:** `MatchedLine` wraps `ParsedLine` + match result:
  ```php
  final readonly class MatchedLine {
      public function __construct(
          public ParsedLine $line,
          public ?int $matched_offer_id,
          public string $match_strategy, // 'offer_code' | 'product_code_single_offer' | 'none'
      ) {}
  }
  ```
- **D-06:** `ApplyResult` summary DTO (used by Phase 3 — define shape now to lock contract):
  ```php
  final readonly class ApplyResult {
      public function __construct(
          public int $units_added,
          public int $offers_touched,
          public int $lines_applied,
          public int $lines_skipped,
      ) {}
  }
  ```

### Exceptions (PARSE-02)
- **D-07:** Base abstract `GoodsReceivedException` extends `\RuntimeException`. All 8 typed exceptions extend it.
- **D-08:** Exception classes in `classes/exception/` (matching `classes/dto/` pattern):
  - `GoodsReceivedException` (abstract base)
  - `InvoiceNumberMissingException`
  - `DuplicateInvoiceException`
  - `InvalidEanException`
  - `InvalidQuantityException`
  - `ApplyAlreadyDoneException`
  - `InitialResetNotAllowedException`
  - `OperatorOverridesActiveFlagException`
  - `MalformedHtmException`
- **D-09:** Each exception accepts structured context array as second constructor arg (for `Log::error()` context-array import audit). Pattern:
  ```php
  public function __construct(
      string $sMessage,
      public readonly array $arContext = [],
      ?\Throwable $obPrevious = null,
  ) {
      parent::__construct($sMessage, 0, $obPrevious);
  }
  ```
- **D-10:** Exception lang keys live under `lang.exception.*` (already scaffolded in Phase 1 lang structure). Each exception has `<key>_message` template; constructor formats using `Lang::get()`.

### HTM Parser (PARSE-03)
- **D-11:** Use `DOMDocument::loadHTML()` with `libxml_use_internal_errors(true)` (suppress malformed warnings — capture via `libxml_get_errors()` for `MalformedHtmException`).
- **D-12:** BOM strip BEFORE loadHTML: `ltrim($sHtml, "\xEF\xBB\xBF")`. Real fixtures confirmed UTF-8 BOM present.
- **D-13:** Real fixture format (verified against `Nr_PRO033328_no_13042026.HTM`):
  - Data rows: `<TR CLASS=R20>` (UPPERCASE, UNQUOTED — DOMDocument handles this).
  - Each row has `<TD CLASS="R20C0">...<TD CLASS="R20C9">` columns. **Two TDs share `class="R20C2"`** (EAN + name) — disambiguate by POSITION not class.
  - Column positions (0-indexed):
    - 0: empty span (skip)
    - 1: row index
    - 2: EAN (first R20C2)
    - 3: product name (second R20C2)
    - 4: unit ('PCE')
    - 5: qty
    - 6: unit_price (decimal-comma)
    - 7: discount
    - 8: line_price
    - 9: total
- **D-14:** Use XPath: `//tr[contains(translate(@class,'r','R'),'R20') or contains(translate(@class,'r','R'),'R21')]`. The `translate()` handles case-insensitivity defensively even though current fixtures are uppercase.
- **D-15:** For each matching row, get TD children, position-index them, validate count >= 10. If less: skip row + log to `Log::warning()` with row content (boundary-layer lenient parse — partial-match invoices never throw per success criterion 5).
- **D-16:** EAN validation: trim whitespace, regex `/^\d{13}$/`. If fail → continue to next row + log skip reason. Do NOT throw `InvalidEanException` from parser (boundary-layer lenient). The matcher receives raw string; only `whereIn` query handles match-or-null.
  - **Counter-decision:** EAN that's NOT exactly 13 digits is malformed data — but per Tiger-Style "fail fast at function boundaries", parser is the boundary for HTM bytes. Decision: parser logs + skips invalid EAN rows (does not throw), records skip in `ParsedInvoice` via a separate `skipped_rows` array (added to ParsedInvoice DTO).
  - **REVISED D-04:** Add `public array $skipped_rows` to `ParsedInvoice` (list of `['row_index' => int, 'reason' => string, 'raw' => string]`).
- **D-17:** Whole HTM is malformed (e.g., no `<TR>` at all, libxml fatal errors) → throw `MalformedHtmException`. Parser must distinguish "skip this row" (lenient) from "this whole file is unparseable" (fail).

### Invoice Number Resolver (PARSE-04)
- **D-18:** Body resolution: search `<TR>` rows for invoice number marker. Real fixtures: invoice number appears as a header field — exact pattern needs grep on fixture (likely embedded near the date/seller block). Implementation strategy: regex `/(?:Faktura|Invoice|Rechnung)[^\d]*(\d{6,})/i` against full text content of `<TBODY>`. If no match, fall through to filename.
- **D-19:** Filename resolution: regex `/^Nr_PRO(\d+)_(\w+)_(\d{8})\.HTM$/i` against `basename($sFilepath)`. Captures (1) invoice number, (2) country code, (3) DDMMYYYY date.
- **D-20:** Both fail → throw `InvoiceNumberMissingException` with context array `['source_filename' => $sBasename, 'tried_body' => true, 'tried_filename' => true]`.

### Quantity & Price Normalizers (PARSE-05, PARSE-06)
- **D-21:** `QuantityNormalizer::parseQuantity(string $sRaw): int` — trim, validate `/^\d+$/`. Reject `5,12` and `5.12` (decimal qty). Reject `0` and negative (qty must be positive — Tiger-Style `qty > 0` invariant). Throws `InvalidQuantityException`. Decimal-comma string `5,12` does NOT match `\d+` → throws.
- **D-22:** `PriceNormalizer::parsePrice(?string $sRaw): ?float` — null/empty → null. Replace comma → period, validate `/^-?\d+\.?\d*$/`, cast to float. Used for unit_price/discount/line_price/total only — NEVER for qty.
- **D-23:** Both normalizers are pure static methods on instantiable classes (not facades) so they're injectable for testing.

### EAN Matcher (MATCH-01, MATCH-02)
- **D-24:** `EanMatcherService::matchBatch(array $arEans): array` — input list of EAN strings, output map `<ean, MatchResult>`.
- **D-25:** Implementation = exactly TWO queries (no JOIN, no per-line lookup):
  ```php
  // Query 1: offer-code matches
  $arOfferRows = Offer::whereIn('code', $arEans)->get(['id', 'code', 'product_id']);
  // index by code
  $arUnmatched = array_diff($arEans, $arOfferRows->pluck('code')->all());
  // Query 2: product-code matches WITH single-offer guard
  $arProductRows = Product::whereIn('code', $arUnmatched)
      ->has('offer', '=', 1)
      ->with('offer:id,product_id')
      ->get(['id', 'code']);
  ```
- **D-26:** Output map shape: each EAN → `['matched_offer_id' => int|null, 'match_strategy' => 'offer_code'|'product_code_single_offer'|'none']`.
- **D-27:** EAN is STRING throughout — never cast to int. Eloquent `whereIn` with strings preserves leading-zero semantics.
- **D-28:** `Offer::whereIn` query exact column: `lovata_shopaholic_offers.code`. `Product::whereIn` query exact column: `lovata_shopaholic_products.code`. Verify column existence in Phase 2 plan via `\Schema::hasColumn` before issuing query.

### Settings Access (cross-cutting)
- **D-29:** Phase 2 services do NOT need `SettingsAccessor` (that's Phase 3 APPLY-09). Parsers/matchers are pure or DB-read-only — no Settings reads. Defer SettingsAccessor to Phase 3.

### Test Strategy
- **D-30:** All Phase 2 tests in `tests/unit/` (matches Phase 1 convention).
- **D-31:** Test files mirror class structure:
  - `tests/unit/Parser/HtmInvoiceParserTest.php` (rolls up QA-01 sub-tests as multiple `it()` blocks: HandlesUnquotedAttributesTest, StripsBomBeforeParseTest, HandlesBothR20AndR21RowsTest, HandlesCRLFLineEndingsTest, RejectsMalformedHtmTest)
  - `tests/unit/Parser/InvoiceNumberResolverTest.php`
  - `tests/unit/Parser/QuantityNormalizerTest.php` (rolls up QA-02: RejectsDecimalQuantityTest)
  - `tests/unit/Parser/PriceNormalizerTest.php`
  - `tests/unit/Match/EanMatcherServiceTest.php` (rolls up QA-02: PreservesLeadingZeroEanTest)
  - `tests/unit/Dto/ParsedInvoiceTest.php` (immutability assertion + readonly enforcement)
- **D-32:** Hermetic fixtures: tests read EXCLUSIVELY from `tests/fixtures/invoices/` (already populated in Phase 1 plan 01-04). Never `<project_root>/storage/app/uploads/`.
- **D-33:** EanMatcher test uses real DB (SQLite in-memory bootstrapped by `GoodsReceivedTestCase`). Seeds 2-3 Offer rows + 2-3 Product rows, then asserts query count via `DB::enableQueryLog()` matches exactly 2.
- **D-34:** Singleton flush hook: `EanMatcherService` is service-scope (not singleton). No new singletons in Phase 2 — `flushPluginSingletons()` body in TestCase remains empty.

### Tiger-Style + Hungarian Conventions (carry from Phase 1)
- **D-35:** All new files start with `declare(strict_types=1);`.
- **D-36:** Hungarian notation in all variables (`$obParser`, `$arLines`, `$iCount`, `$sEan`, `$bIsValid`, `$fPrice`).
- **D-37:** Functions <70 lines. Max nesting 1 level (early returns + guard clauses, no `else` after early return).
- **D-38:** Explicit return types `: void`, `: array`, `: ?float`, `: ParsedInvoice`. No `mixed`.
- **D-39:** PHPStan level 10 — full `@property`/`@return`/`@param` docblocks. No errors added to baseline.

### Claude's Discretion
- Exact regex pattern for invoice number body resolution (D-18) — implementer reads fixtures + tests against all 3 to find the pattern that works for all
- DOMDocument vs SimpleHTMLDom vs symfony/dom-crawler — D-11 says DOMDocument; defer to planner if ecosystem norms disagree
- Whether `HtmInvoiceParser::parse()` accepts string content vs filepath (recommendation: filepath for resolver hook into filename; alternative: separate `parseFile`/`parseString` methods)
- File-level docblock verbosity (PHPStan accepts both)

</decisions>

<canonical_refs>
## Canonical References

### Locked Specs
- `.planning/PROJECT.md` — Architecture preview (Parse → Match → Apply table); D1-D15
- `.planning/REQUIREMENTS.md` — PARSE-01..07, MATCH-01..02, QA-01, QA-02 + traceability
- `.planning/ROADMAP.md` — Phase 2 success criteria (6 items, lines ~58-65)
- `.planning/phases/01-schema-scaffold-settings-permissions/01-CONTEXT.md` — Phase 1 decisions (D-01..D-22) — Settings, models, schema types referenced by Phase 2

### Project-Level Conventions
- `<project_root>/CLAUDE.md` — Hungarian notation, Tiger-Style, namespace
- `plugins/logingrupa/goodsreceivedshopaholic/CLAUDE.md` — Plugin-local: Tests Pest 4, hermetic fixtures, base case

### Reference Code (Lovata + Project)
- `plugins/lovata/shopaholic/models/Offer.php` — `code` column shape; `quantity` accessor (PARSE-05 must guard against silent int-clamp)
- `plugins/lovata/shopaholic/models/Product.php` — `code` column; `offer()` relation (MATCH-01 single-offer guard)
- `plugins/logingrupa/extendshopaholic/classes/import/` — Existing 1C XML import patterns (DIFFERENT bounded context per D14, but useful for parser shape inspiration)
- `plugins/logingrupa/postnordshippingshopaholic/` — QA-bar reference; mirror test structure

### Phase 1 Outputs (DEPENDS ON)
- `plugins/logingrupa/goodsreceivedshopaholic/models/Invoice.php` — Phase 3 will persist; Phase 2 doesn't touch
- `plugins/logingrupa/goodsreceivedshopaholic/tests/GoodsReceivedTestCase.php` — base case with `flushPluginSingletons()` hook
- `plugins/logingrupa/goodsreceivedshopaholic/tests/fixtures/invoices/Nr_PRO*.HTM` — 3 fixtures already copied

### HTM Format Reference
- Real fixture: `tests/fixtures/invoices/Nr_PRO033328_no_13042026.HTM` — confirmed `<TR CLASS=R20>` unquoted, UTF-8 BOM, comma-decimal, `<TD CLASS="R20C{0..9}">` columns

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `tests/GoodsReceivedTestCase.php` — base case ready
- `tests/fixtures/invoices/` — 3 hermetic fixtures
- Phase 1 models — Phase 2 doesn't touch them; Phase 3 does

### Established Patterns
- `classes/<role>/` directory layout (Lovata convention) — `classes/dto/`, `classes/exception/`, `classes/parser/`, `classes/match/`
- Pest 4 `it()` syntax for unit tests
- `class_uses_recursive` for trait assertions
- `DOMDocument::loadHTML()` accepts uppercase/unquoted attributes natively (verified by W3C HTML4 doctype in fixtures)

### Integration Points
- `EanMatcherService` reads from Phase 1 schema (lovata_shopaholic_offers + products) — verify columns exist via `Schema::hasColumn` in test setUp
- DTOs feed Phase 3 `ParseAndPersistOrchestrator::run(ParsedInvoice $obInvoice)` — locked contract here
- `MatchedLine[]` produced by EanMatcherService → consumed by Phase 3 StockApplyService

</code_context>

<specifics>
## Specific Ideas

- **Real-fixture-pinned tests** — every parser test reads ONE specific fixture and asserts EXACT line count, EXACT first EAN, EXACT first qty. Brittle by design — format drift = test failure = visibility.
- **Two-query proof test (MATCH-01):** `DB::enableQueryLog()`; call `EanMatcherService::matchBatch(['ean1', 'ean2', 'ean3', 'ean4', 'ean5'])`; assert `count(DB::getQueryLog()) === 2`. Asserts the implementation can't accidentally drift to per-line lookup.
- **Decimal-comma rejection (QA-02):** Test `QuantityNormalizer::parseQuantity('5,12')` → expects `InvalidQuantityException` thrown. Test `parseQuantity('5')` → returns int 5. This guards against silent corruption when distributor mistypes a qty.
- **Leading-zero EAN (QA-02):** Test EAN `'0000000012345'` (13 chars, leading zeros) — assert `EanMatcherService::matchBatch(['0000000012345'])` returns it as STRING in result map (not int 12345). DB column is varchar(13), but if implementation casts at any layer, this test catches it.

</specifics>

<deferred>
## Deferred Ideas

- **`SettingsAccessor`** — Phase 3 (APPLY-09)
- **`ParseAndPersistOrchestrator`** — Phase 3 (APPLY-06) wraps Phase 2 parsers in DB::transaction
- **`StockApplyService`, `ActiveFlagService`, `InitialResetService`** — Phase 3 (APPLY-01..05)
- **Backend controller** — Phase 4
- **README documenting parser format expectations** — Phase 5 (OPS-01)

</deferred>

---

*Phase: 02-pure-parsers-dtos-exceptions-ean-matcher*
*Context gathered: 2026-04-29 (autonomous mode)*
*Smart-discuss `--auto`: 39 decisions captured across 8 areas; 4 items at Claude's discretion*
