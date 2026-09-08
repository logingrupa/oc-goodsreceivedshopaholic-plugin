---
quick_id: 260908-lql
status: complete
subsystem: matcher, apply, backend-ui
tags: [duplicate-codes, ambiguous, offer_code_active, offer_code_operator, apply-modal]

key-files:
  created:
    - classes/match/DuplicateCodeResolver.php
    - classes/apply/LineResolutionService.php
    - controllers/invoices/_partials/_offer_choice.htm
    - controllers/invoices/_partials/_offer_choice_guard.htm
    - tests/unit/Apply/LineResolutionServiceTest.php
  modified:
    - models/InvoiceLine.php
    - classes/dto/MatchedLine.php
    - classes/match/OfferCodeMatcher.php
    - classes/match/ProductCodeSingleOfferMatcher.php
    - controllers/Invoices.php
    - controllers/invoices/_partials/_apply_modal.htm
    - controllers/invoices/_partials/_apply_confirm.htm
    - controllers/invoices/_partials/_unmatched_lines.htm
    - models/invoice/_summary_block.htm
    - models/invoiceline/_column_product_name.htm
    - lang/en/lang.php
    - lang/lv/lang.php
    - lang/no/lang.php
    - lang/ru/lang.php
    - updates/version.yaml

key-decisions:
  - "The active-rule pick is offer_code_active for BOTH matchers. The brief defines only three new strategies; the name records that the active rule decided, whichever code column matched."
  - "A candidate is a live offer whose own code OR whose product's code equals the line EAN, so lines made ambiguous by the product-code matcher get their candidates too. This is a superset of each matcher's group, loaded with one joined SELECT."
  - "The select partial is also rendered in the confirm modal reached from the invoice page. Without it an invoice persisted with ambiguous lines could never be applied from the backend, and onApply is one of the two callers the brief names."
  - "LineResolutionService throws AjaxException directly. Its only callers are the controller AJAX handlers and the controller may not gain a method to translate a typed exception."
  - "resolve() recounts matched_lines / unmatched_lines after a pick with the same rule ParseAndPersistOrchestrator uses, so the modal and the summary block keep quoting the right numbers."

completed: 2026-09-08
---

# Quick Task 260908-lql: Duplicate offer codes resolved by active rule or operator choice

**562 of the 566 duplicate-code groups with an active offer on .no now resolve by rule; the rest, and every group without an active offer, stop apply until the operator picks.**

## Accomplishments

- `DuplicateCodeResolver::resolveByCode()` groups the rows both code matchers already fetch (now with `active`) and decides per code: one candidate keeps the stage strategy, one active among duplicates is `offer_code_active`, anything else is `ambiguous` with no offer id. Query budget unchanged, the 3-query chain pin is green.
- `InvoiceLine` gains the three strategy constants and `ambiguousFor()`, sharing one private query helper with `unmatchedFor()`.
- `LineResolutionService`: `candidatesFor()` recomputes candidates from the EAN with one joined SELECT per invoice (active first); `resolve()` validates picks against that set, stores `matched_offer_id` + `offer_code_operator`, recounts the invoice header, and throws `AjaxException` listing open rows while any line is still ambiguous.
- Controller: `onApply` and `onApplyBulk` call `resolve()` before any lock or apply (bulk runs a pre-pass over the whole selection so nothing is half applied); `onApplyShowConfirm` and `buildPreviewPayload` pass ambiguous lines and candidates to the modals. No new method on `Invoices.php`.
- UI: `_offer_choice` is the one renderer for a candidate list (offer id, offer name, product name, active state, current stock); `_offer_choice_guard` disables the apply button until every select in a checked section has a value. Bulk modal posts `invoice[<id>][offer_choice][<line_id>]`, confirm modal posts `offer_choice[<line_id>]`. `_unmatched_lines` takes a heading key; the invoice summary lists ambiguous lines under `apply.ambiguous_heading`.
- `_column_product_name.htm` dispatches through a renderer map; `ambiguous` shows a yellow label with lang text, `offer_code_active` and `offer_code_operator` render byte-equal to `offer_code`.
- Seven `apply.*` keys and one `column.*` key in en, lv, no, ru.

## Task Commits

1. **Tasks 1 to 4: resolver, service, controller call sites, partials, lang, tests, version bump** - `81d8d3b` (feat)

## Verification

Same five Makefile targets run by hand with PHP 8.4.15 (`make` is not on this host's PATH).

| Gate | Result |
|---|---|
| pint-test | fails on every CRLF file with `line_ending` + `method_argument_space`, untouched ones included; LF copies of all 16 changed PHP files pass clean |
| lint-settings-accessor | rc=0 |
| phpstan level 10 | `[OK] No errors` |
| phpmd | rc=0 (vendor deprecation notices only) |
| pest | 307 passed, 2257 assertions, 27.94s (14 new cases) |

## Deviations from Plan

- The select partial is rendered in `_apply_confirm.htm` as well as `_apply_modal.htm` (see key decisions). The invoice page itself stays read-only through `_unmatched_lines`, as the brief asked.
- Two PHPStan rounds on `LineResolutionService::loadCandidateRows`: joined columns come back `mixed`, fixed with an `instanceof Offer` narrow and `is_scalar` guards instead of casts.

## Issues Encountered

None.

## Not verified here

No browser screenshot of the modals: the backend needs an invoice whose EAN hits duplicate live offers in the local `nc` database, and no such fixture exists locally. The select markup and the gate script are covered by the partial and controller tests only.
