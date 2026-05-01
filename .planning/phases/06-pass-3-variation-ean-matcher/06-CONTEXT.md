# Phase 6: Pass 3 Variation EAN Matcher — Context

**Gathered:** 2026-05-01
**Status:** Ready for planning
**Source:** User-supplied spec from /gsd-plan-phase --auto invocation (treated as PRD-style locked decisions)

<domain>
## Phase Boundary

Add a deterministic third matching pass to the EAN match chain so invoice lines whose EAN is missing from `lovata_shopaholic_offers.code` (Pass 1) and from `lovata_shopaholic_products.code` single-offer (Pass 2) can still be recovered by matching on the offer-name `variation` token. Refactor the monolithic `EanMatcherService::matchBatch` into a chain runner backed by a `MatchStrategy` interface; lift D-25 query budget 2 → 3; widen `MatchedLine.strategy` union with `'variation'`; surface the new strategy in the backend lines list with an orange + asterisk render branch.

**In scope:**
- 5 new files: `MatchStrategy` interface, `OfferCodeMatcher`, `ProductCodeSingleOfferMatcher`, `VariationMatcher`, `VariationExtractor`.
- 3 file updates: `EanMatcherService.php` (refactor to chain runner), `MatchedLine.php` (strategy union + 'variation'), `ParseAndPersistOrchestrator.php:319` (caller signature fix), `models/invoiceline/_column_product_name.htm` (new variation render branch — DRY-replace inline regex with `VariationExtractor::extract`).
- 4 test files: `VariationMatcherTest`, `VariationExtractorTest`, `EanMatcherServiceTest` (chain integration), `_column_product_name.htm` snapshot/render test.
- D-25 lock annotation flipped 2 → 3 queries in source-of-truth (PROJECT.md or wherever D-25 lives in code constant).

**Out of scope:**
- DB schema changes (`match_strategy varchar(32)` already accommodates the new literal).
- Product-name lookup or fuzzy matching beyond the comma-split last-segment.
- Touching unrelated columns: `active_managed_by` (active-flag automation, separate concern explicitly called out).
- Pass 4 / future strategies — Pass 3 is the only addition.

</domain>

<decisions>
## Implementation Decisions

### Match Chain (locked)

- **D-25 (updated):** Total chain query budget = 3 (was 2). Pass 1 = 1 query, Pass 2 = 1 query, Pass 3 = 1 query. Counter-pinned via Pest DB query count assertion in `EanMatcherServiceTest`.
- **Pass 3 trigger:** runs ONLY for lines unmatched by Pass 1 AND Pass 2 (cascade — each stage receives only the residue from prior stages).
- **Pass 3 query:** exactly one — `Offer::whereIn('variation', $arUnique)->select('id','variation','product_id')->get()`. No JOIN, no product-name lookup, no LIKE.
- **Single-offer guard (Tiger-Style determinism):** group result rows by variation; variation hits exactly 1 offer → match (`match_strategy='variation'`); variation hits ≥2 offers → leave as `'none'` (ambiguous, never silently best-guess); variation empty / no comma → `'none'`.
- **Strategy union:** `MatchedLine.strategy` widened to `'offer_code' | 'product_code_single_offer' | 'variation' | 'none'`. DB column `match_strategy varchar(32)` already fits — no migration.

### VariationExtractor (locked)

- **Regex:** `/^(.+),\s+([^,]+)$/u` — last-comma greedy; multi-comma input (`"A, B, C"`) splits at LAST comma so `("A, B", "C")`.
- **Whitespace:** trim both groups; reject empty trailing segment.
- **No-comma input:** return `null`.
- **Empty input:** return `null`.
- **Sole regex source:** both `VariationMatcher` and `_column_product_name.htm` consume `VariationExtractor::extract($sName)` — DRY-enforced by grep in QA-09 sibling gate (one regex literal occurrence in `classes/`, zero re-implementations in views).

### Chain Runner (locked)

- **Signature change:** `EanMatcherService::matchBatch(array $arEans)` → `EanMatcherService::matchLines(list<ParsedLine> $arLines): list<MatchedLine>`. No back-compat shim — Phase 6 is post-v1 internal refactor; only one caller site (`ParseAndPersistOrchestrator.php:319`).
- **Why pass `ParsedLine`, not just EAN:** Pass 3 needs the offer-name string to extract variation. EAN alone is insufficient.
- **Chain order:** OfferCodeMatcher → ProductCodeSingleOfferMatcher → VariationMatcher. Order is deterministic (stage-by-stage filter on residue).
- **Interface (locked shape):** `MatchStrategy::match(list<ParsedLine> $arUnmatched): list<MatchedLine>` — each stage receives unmatched residue, returns its matches; runner concatenates and tracks remaining unmatched for next stage.

### UI Render Branch (locked)

- **File:** `models/invoiceline/_column_product_name.htm`.
- **NEW branch — `match_strategy='variation'`:** orange + asterisk + resolved product name. Both HTM-raw product name AND resolved product name visible (per discuss summary).
- **PRESERVED branches:**
  - `'offer_code'` matched → black/normal product link (current behavior, byte-for-byte).
  - `'product_code_single_offer'` matched → black/normal product link (current behavior, byte-for-byte).
  - `'none'` unmatched → plain text + asterisk (current behavior, byte-for-byte).
- **DRY:** the partial calls `VariationExtractor::extract($sName)` rather than re-implementing the regex inline.

### Tiger-Style Discipline

- All new files start with `declare(strict_types=1);`.
- All public methods on new classes get explicit return types.
- `final readonly class` for `VariationExtractor` (stateless helper).
- `final` on matcher classes; `MatchStrategy` interface uses `#[\Override]` enforcement at implementer level.
- Hungarian notation throughout (`$arOffers`, `$obMatched`, `$sName`, `$arUnique`).
- Bounded loops; no unbounded growth — `whereIn` covers all unmatched at once, single SELECT.
- `make all` exit 0; phpstan-baseline.neon SHA UNCHANGED at `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a`.

### Claude's Discretion

- Plan splits across multiple PLAN.md files: choose granularity that keeps each PLAN ≤ ~6 tasks (suggested split: tests first plan, extraction-refactor plan, new VariationMatcher + Extractor plan, UI render branch plan, final QA gate plan — but planner may choose tighter or looser groupings).
- Wave assignment / dependency edges: planner derives from file-level data flow.
- Exact PHPStan @property / @phpstan-* docblock shape on new classes — match existing plugin conventions (see `classes/match/EanMatcherService.php` and `classes/dto/MatchedLine.php`).
- Test file paths under `tests/unit/` vs `tests/feature/` — match existing convention (`VariationExtractorTest` is pure-unit; `VariationMatcherTest` and `EanMatcherServiceTest` need DB fixtures = feature).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Existing match infrastructure
- `plugins/logingrupa/goodsreceivedshopaholic/classes/match/EanMatcherService.php` — current 2-pass implementation; refactor target.
- `plugins/logingrupa/goodsreceivedshopaholic/classes/dto/MatchedLine.php` — strategy union expansion target.
- `plugins/logingrupa/goodsreceivedshopaholic/classes/dto/ParsedLine.php` — DTO consumed by chain stages (need to confirm offer-name field name on this DTO).
- `plugins/logingrupa/goodsreceivedshopaholic/classes/orchestrator/ParseAndPersistOrchestrator.php` (line ~319) — sole call site to update.

### UI render branch
- `plugins/logingrupa/goodsreceivedshopaholic/models/invoiceline/_column_product_name.htm` — the partial gaining the new branch (current commit `9bc11f8` already extracts variation regex inline; this phase replaces that inline regex with `VariationExtractor::extract`).

### Shopaholic schema (source of truth)
- `plugins/lovata/shopaholic/models/Offer.php` — has `variation` column on offers (`lovata_shopaholic_offers.variation` per Shopaholic core schema).

### Tiger-Style guardrails
- `plugins/logingrupa/goodsreceivedshopaholic/CLAUDE.md` — plugin-local conventions (Hungarian, strict_types, `#[\Override]`, PHPStan L10).
- `plugins/logingrupa/goodsreceivedshopaholic/Makefile` — `make all` gate; QA-09 grep gate; phpstan-baseline.neon SHA pin recipe.
- `plugins/logingrupa/goodsreceivedshopaholic/phpstan-baseline.neon` — must remain unchanged (SHA `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a`).

### Fixture / test conventions
- `plugins/logingrupa/goodsreceivedshopaholic/tests/Concerns/GoodsReceivedTestCase.php` (or equivalent base) — `flushModelEventListeners()` + singleton flush in `tearDown()`.
- Existing matcher tests for shape reference: any `EanMatcherTest*` files under `tests/`.

</canonical_refs>

<specifics>
## Specific Ideas

### Final architecture (after Phase 6)

```
classes/
  match/
    MatchStrategy.php                    [interface]
    OfferCodeMatcher.php                 [Pass 1, 1 query]
    ProductCodeSingleOfferMatcher.php    [Pass 2, 1 query]
    VariationMatcher.php                 [Pass 3, 1 query — variation only, single-offer guard]
    EanMatcherService.php                [chain runner — orchestrates 3 stages]
  support/
    VariationExtractor.php               [shared regex /^(.+),\s+([^,]+)$/u]
```

### Test files (suggested layout)

```
tests/unit/
  VariationExtractorTest.php             [pure regex behaviour — last-comma greedy / no-comma null / multi-comma / whitespace]
  ColumnProductNameVariationBranchTest.php [snapshot/render test for the partial — orange + asterisk + product name]
tests/feature/
  VariationMatcherTest.php               [single hit / ambiguous skip / empty skip / leading-zero EAN passthrough / query count = 1]
  EanMatcherServiceTest.php              [chain integration / ≤3 queries / strategy literals / matchLines signature — extends or replaces any existing EanMatcherTest]
```

### Existing UI extraction reference

Commit `9bc11f8` already added inline regex variation extraction in `_column_product_name.htm`. Phase 6 replaces that inline regex with `VariationExtractor::extract($sName)` so the partial and the matcher share one regex source.

</specifics>

<deferred>
## Deferred Ideas

- `active_managed_by` column changes — explicitly called out by the user as unrelated (active-flag automation work, not match-strategy work). Stays out of Phase 6.
- Pass 4+ strategies (fuzzy match, distributor SKU map, etc.) — out of scope; Pass 3 only.
- Product-name lookup for variation extraction — explicitly avoided per "no product-name lookup" in user spec.
- Decrement-then-reapply variation correction flows — not part of matcher contract.

</deferred>

---

*Phase: 06-pass-3-variation-ean-matcher*
*Context gathered: 2026-05-01 via /gsd-plan-phase --auto user-supplied spec*
