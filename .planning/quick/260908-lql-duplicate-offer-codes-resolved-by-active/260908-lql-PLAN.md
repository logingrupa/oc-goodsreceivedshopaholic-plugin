---
quick_id: 260908-lql
type: quick
mode: quick
files_modified:
  - models/InvoiceLine.php
  - classes/dto/MatchedLine.php
  - classes/match/DuplicateCodeResolver.php
  - classes/match/OfferCodeMatcher.php
  - classes/match/ProductCodeSingleOfferMatcher.php
  - classes/apply/LineResolutionService.php
  - controllers/Invoices.php
  - controllers/invoices/_partials/_apply_modal.htm
  - controllers/invoices/_partials/_apply_confirm.htm
  - controllers/invoices/_partials/_unmatched_lines.htm
  - controllers/invoices/_partials/_offer_choice.htm
  - controllers/invoices/_partials/_offer_choice_guard.htm
  - models/invoice/_summary_block.htm
  - models/invoiceline/_column_product_name.htm
  - lang/en/lang.php
  - lang/lv/lang.php
  - lang/no/lang.php
  - lang/ru/lang.php
  - updates/version.yaml
autonomous: true
must_haves:
  truths:
    - "A code carried by 2+ live offers is never picked by DB row order. Exactly one active offer in the group is picked with strategy offer_code_active; 0 or 2+ active offers yield strategy ambiguous with matched_offer_id null."
    - "Detection costs zero extra queries: the same whereIn('code') SELECT reads active, grouping happens in PHP. EanMatcherServiceTest still pins 3 queries per chain."
    - "One grouping helper (DuplicateCodeResolver) serves both OfferCodeMatcher and ProductCodeSingleOfferMatcher."
    - "Candidates are not stored. LineResolutionService recomputes them from the EAN with one whereIn for all ambiguous lines of an invoice."
    - "Only the operator choice is persisted: matched_offer_id plus strategy offer_code_operator. A chosen offer outside the candidate set throws AjaxException."
    - "Apply (onApply and onApplyBulk) throws AjaxException listing the rows while any ambiguous line of the invoice is unresolved; Offer.quantity stays unchanged."
    - "Invoices.php gains no method. Candidate loading and choice validation live in LineResolutionService."
    - "_column_product_name.htm dispatches by a handler map: ambiguous renders a label-warning with lang text, offer_code_active and offer_code_operator render exactly like offer_code."
    - "All new lang keys exist in en, lv, no, ru; LangCompletenessTest passes."
  artifacts:
    - path: "classes/match/DuplicateCodeResolver.php"
      provides: "resolveByCode(candidates, uniqueStrategy): per-code decision {offer_id, strategy}"
    - path: "classes/apply/LineResolutionService.php"
      provides: "candidatesFor(lines), resolve(invoiceId, choices)"
    - path: "controllers/invoices/_partials/_offer_choice.htm"
      provides: "the one <select> renderer for a candidate list"
---

# Quick Task 260908-lql: Duplicate offer codes resolved by active rule or operator choice

## Why

Prod `.no`, 2026-09-08: 1832 codes belong to 2+ offers (3968 rows). 562 of those groups have exactly one active offer, 4 have 2+ active, 1266 have none. `OfferCodeMatcher::lookupOffersByCode` and `ProductCodeSingleOfferMatcher::lookupProductsWithSingleOffer` both end with `$arMap[$sCode] = $iOfferId`, so the last DB row wins silently. `VariationMatcher::match` already groups and skips groups with 2+ hits; that is the model.

## Locked decisions (from the brief, not reopened)

1. `ambiguous` is a strategy of its own, `matched_offer_id` null. No migration (`match_strategy` is `string(32)`).
2. Exactly one active offer in the group is auto-picked as `offer_code_active`. Applies to both matchers; the strategy name records that the active rule decided, whichever code column matched.
3. Zero extra queries. The existing `whereIn('code')` also selects `active`; grouping is one helper used by both matchers.
4. Candidates are recomputed at render time from the EAN, one `whereIn` per invoice. A candidate is a live offer whose own code or whose product's code equals the line EAN.
5. UI: `<select name="invoice[<id>][offer_choice][<line_id>]">` in the bulk apply modal; the same select partial in the confirm modal reached from the invoice page, posting `offer_choice[<line_id>]` to `onApply`. The invoice page summary lists ambiguous lines through `_unmatched_lines.htm` under a separate heading.
6. Apply is blocked while an ambiguous line is unresolved: server throws `AjaxException` with the row list, the button is disabled until every select has a value. `none` lines stay skipped and counted.
7. Server validates the choice against the candidate set.
8. No new controller method. `LineResolutionService` does the work; `onApply`, `onApplyBulk`, `onApplyShowConfirm` and `buildPreviewPayload` call it.
9. `_column_product_name.htm` uses a handler map.
10. Lang keys in all four locales.

## Tasks

### Task 1: Model constants, resolver, matchers, DTO union
- `InvoiceLine`: `MATCH_STRATEGY_AMBIGUOUS`, `MATCH_STRATEGY_OFFER_CODE_ACTIVE`, `MATCH_STRATEGY_OFFER_CODE_OPERATOR`; `ambiguousFor()` sharing one private query helper with `unmatchedFor()`.
- `DuplicateCodeResolver::resolveByCode()`.
- Both matchers select `active`, feed the resolver, emit `ambiguous` / `offer_code_active` / their unique strategy.
- `MatchedLine` docblock union widened.

### Task 2: LineResolutionService and controller call sites
- `candidatesFor(list<InvoiceLine>)`, `resolve(int, mixed)` (persist choices, recount invoice counters, throw on unresolved).
- `onApply`, `onApplyBulk` (pre-pass over parsed ids before any apply), `onApplyShowConfirm`, `buildPreviewPayload`.

### Task 3: Partials, lang, column dispatch
- `_offer_choice.htm`, `_offer_choice_guard.htm`, `_apply_modal.htm`, `_apply_confirm.htm`, `_unmatched_lines.htm` heading parameter, `_summary_block.htm`, `_column_product_name.htm`.
- Lang keys en/lv/no/ru.

### Task 4: Tests and version bump
- Matcher cases (3 each), `LineResolutionServiceTest`, controller cases in `ApplyBulkHandlerTest`, column partial cases, `make all` equivalent, `version.yaml` 1.0.8.

## Out of scope
- Catalog cleanup of the 1829 legacy duplicate groups, 1C XML feed, missing barcodes, backfill of already applied invoices.
