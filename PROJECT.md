# GoodsReceivedShopaholic Plugin

## What This Is

A Lovata Shopaholic ecosystem plugin (`Logingrupa\GoodsReceivedShopaholic`) that imports distributor goods-received notes (GRN) — uploaded as `.HTM` delivery receipts in the backend — and applies them as **incremental stock additions** to matched offers. Per-site settings drive active-flag automation and a one-shot baseline-reset feature for first-time imports.

## Core Value

Backend operators upload distributor delivery receipts. Stock is added to matched offers idempotently. Per-site automation re-activates inbound products and deactivates zero-stock offers. Auditable history with unmatched-EAN queue for manual resolution.

## Requirements

### Validated

(None yet — ship to validate)

### Active

- [ ] Backend upload page accepts one or many `.HTM` files in a single submission
- [ ] HTM parser extracts `[row#, EAN, name, unit, qty, unit_price, discount, line_price, total]` from data rows (`<TR class="R20|R21">`) ignoring header/footer rows
- [ ] Invoice number resolved from HTM body, fallback to filename pattern `Nr_PRO<num>_<country>_<DDMMYYYY>.HTM`; reject upload if neither yields a number
- [ ] Re-upload of an already-applied invoice is rejected with prior-apply timestamp, applying user, and units added per offer, and allows to select overide and re import. 
- [ ] EAN matching: `offers.code` first; fallback `products.code` only when product has a single offer
- [ ] Two-step UI: parse → preview matched/unmatched lines → operator clicks Apply - we use OctoberCMS UI components and if needed custom, always use Larajax!
- [ ] Apply increments `offer.quantity` by line qty (additive, never replace)
- [ ] Unmatched lines persist in DB for manual resolution; never block partial apply
- [ ] Per-site settings: `enabled`, `auto_deactivate_on_zero`, `auto_activate_on_stock`, `allow_initial_reset`
- [ ] One-shot initial-reset checkbox on import preview: zero all `offer.quantity`, set all `product.active=false` and `offer.active=false` before applying first invoice; logged in audit row
- [ ] Console command `goodsreceived:recompute_active_from_stock` reconciles active flags from current stock without import
- [ ] Settings menu entry only — no top-nav clutter
- [ ] Audit history page lists imports with status/lines/units/applied_by/timestamps; per-import detail view exposes lines + unmatched queue
- [ ] Multi-site: works on .no/.lv/.lt with per-DB settings; no shared state assumed
- [ ] Plugin installable via Composer from public GitHub repo

### Out of Scope

- Pricing import (HTM contains prices; we ignore — pricing managed elsewhere)
- Outbound stock writes (sales decrement) — handled by Shopaholic order processors
- Supplier 1C XML import (different bounded context — `Logingrupa.ExtendShopaholic` owns it)
- Stocktake/cycle-count flows (separate concern; may justify own plugin later)
- Multi-currency conversion (qty-only writes; price columns parsed for audit, never written)
- Email notifications on import results (deferred until UAT signals demand)

## Context

- **Sample data:** `<project_root>/storage/app/uploads/invoices/Nr_PRO*.HTM` (15 files at scaffold time, all `_no_` distributor)
- **Filename pattern:** `Nr_PRO<INV_NUM>_<COUNTRY>_<DDMMYYYY>.HTM`
- **HTM structure:** data rows alternate `<TR class="R20|R21">` after header `<TR class="R19">`. Columns 0-indexed after empty first cell: `[row_idx, EAN(13), name, unit(PCE), qty, unit_price, discount, line_price, total]`. Decimal separator: comma. Charset: UTF-8 declared in `<META>`.
- **Match target:** `lovata_shopaholic_offers.code` (primary), `lovata_shopaholic_products.code` (single-offer fallback). Both fields confirmed via `plugins/lovata/shopaholic/models/{Offer,Product}.php`.
- **Stock field:** `lovata_shopaholic_offers.quantity` — integer, additive.
- **Audit reuse:** `Logingrupa.ExtendShopaholic` exposes `ImportLoggerService` + `ImportLoggingTrait` — soft-dependency vs vendor-inline decision deferred to plan phase.
- **Multi-site:** same repo deployed to nailscosmetics.no/.lv/.lt with separate DBs; settings naturally per-server via October Settings model.

## Constraints

- **Tech stack:** October CMS v4 (Laravel 12), Lovata Shopaholic ecosystem, Lovata.Toolbox backbone
- **PHP:** 8.4 prod (8.3+ supported per `composer.json`)
- **Naming:** Hungarian notation (`$obItem`, `$arList`, `$iCount`, `$sSlug`, `$bIsActive`, `$fPrice`)
- **Multi-site:** deployed to .no/.lv/.lt — handle NOK/EUR currency in display only; stock writes are unit-count
- **Production safety:** must not regress existing `ExtendShopaholic` 1C XML import or Shopaholic stock semantics
- **Composer:** proper package installable from private GitHub repo
- **No jQuery:** any frontend JS uses Vanilla JS + Larajax (none planned at MVP — backend-only feature)

## Engineering Quality Bar (LOCKED)

These rules govern every plan and every commit. Plan-checker rejects any plan that violates them.

### DRY + SRP

- One class, one responsibility. No god classes.
- Parsing, matching, applying, active-flag logic, reset logic — each in its own service class.
- No duplicated parsing logic between controller and console command — both call the same service.
- Settings reads go through one accessor; no scattered `Settings::get()` calls.

### Tiger-Style (TigerBeetle fail-fast)

- **Safety > Performance > Features.**
- Assert invariants at function boundaries (positive AND negative space): EAN must be 13 digits, qty must be `> 0`, invoice_number must be non-empty.
- **Fail fast, fail hard.** Throw early. No silent catches except boundary layer (controller render → 500-safe response, parser → row-skipped audit).
- Every `catch` either logs + rethrows OR carries an explicit one-line reason comment.
- Bounded loops, bounded memory. Imports paginate/chunk if line count exceeds a configured threshold.
- **Short functions (<70 lines).** Split if bigger.
- Explicit types, explicit returns. Declare `: void`, `: array`, `: InvoiceItem`, `: bool`. No `mixed` returns.
- No dynamic dispatch where static works.

### No Spaghetti — Functional Style

- **Max nesting: 1 level deep.** No `if { if { if { … } } }`. Use guard clauses + early returns.
- **No `else` after early return.** Inverse the condition, return early.
- **No nested loops.** Extract inner loop into a named method or use array transforms (`array_map`, `array_filter`, `array_reduce`, `Collection::map/filter/reduce`).
- Branch logic by **dispatch table or polymorphism**, never `switch` ladders longer than 3 cases.
- Mutate as little as possible. Build new arrays/collections; do not push into outer-scope arrays from inside loops.
- Side effects isolated to dedicated services (`StockApplyService::apply`, `ActiveFlagService::reconcile`); pure functions everywhere else.

### Static Analysis (HARD GATE)

- **PHPStan level 10** (with Larastan) — zero new errors. Baseline file is for legacy only; new code adds nothing.
- **PHPMD** (`phpmd.xml`, Lovata thresholds) — zero violations on `classes/components/models/Plugin.php`.
- **Pint** (PSR-12 + ordered imports) — pint-test must pass before merge.
- **Rector** runs in dry-run during plan review; suggestions either applied or explicitly skipped with reason.
- `declare(strict_types=1);` at the top of every PHP file.

### Tests (NON-NEGOTIABLE)

- **Pest 4 / PHPUnit 12**, SQLite in-memory, October bootstrap.
- **Coverage policy:** every service class has a unit test. Every backend action handler has an integration test using `GoodsReceivedTestCase`.
- **HTM parser tests:** pinned against fixtures copied to `tests/fixtures/invoices/Nr_PRO*.HTM` (3 representative samples minimum). Hermetic — no reads outside `tests/`.
- **Idempotency test:** apply same invoice twice; second attempt rejected with stable error code; first apply's stock unchanged on second attempt.
- **Initial-reset test:** verify `offer.quantity = 0` and `*.active = false` for ALL records, then verify subsequent apply restores stock + active flags.
- **Active-flag matrix:** four cases — (deactivate-on-zero on/off) × (activate-on-stock on/off) — each asserted.
- **No mocking business logic.** Real DB (SQLite in-memory). HTTP (none planned) would mock at boundary only.
- **Determinism:** time + randomness injected, never called inside services.
- All tests must pass in CI before merge. `make all` is the gate.

## Architecture (planned)

Three-layer: **Parse → Match → Apply.** Each pure-ish, individually testable, no cross-knowledge.

| Layer | Class (planned) | Responsibility | Pure? |
|-------|-----------------|----------------|-------|
| Parse | `HtmInvoiceParser` | HTM string → `ParsedInvoice` DTO (header + lines). No DB, no IO beyond input string. | Yes |
| Parse | `InvoiceNumberResolver` | Body → number; fallback filename → number; reject. | Yes |
| Match | `EanMatcherService` | DTO line → matched offer_id (or null). One DB read per batch via `whereIn('code', $arEans)`. | Side-effect-free in DB |
| Apply | `StockApplyService` | Persists `Invoice` + `InvoiceLine` rows; increments `offer.quantity` in a transaction. | Side effects isolated |
| Apply | `ActiveFlagService` | Per-site settings → toggle `offer.active` / `product.active`. Idempotent. | Side effects isolated |
| Apply | `InitialResetService` | One-shot baseline reset. Writes audit flag. | Side effects isolated |
| Storage | `Invoice`, `InvoiceLine` | Eloquent models on `logingrupa_goods_received_*` tables | Models |
| Storage | `Settings` | October Settings model (per-site DB) | Model |
| UI | `Controllers\Invoices` | Backend ListController + FormController. Thin: validates input, calls services. | Thin |
| Console | `Console\RecomputeActiveFromStock` | Calls `ActiveFlagService::reconcile()` for all offers. | Thin |

### Data flow

```
upload(.htm[]) ──► HtmInvoiceParser ──► ParsedInvoice DTO
                       │
                       ▼
        InvoiceNumberResolver (body → filename → reject)
                       │
                       ▼
              persist(Invoice@status=parsed, lines)
                       │
                       ▼
                EanMatcherService (batch lookup)
                       │
                       ▼
              update lines (matched_offer_id, strategy)
                       │
                       ▼
        preview UI ─── operator clicks Apply ───► StockApplyService
                                                      │
                                                      ▼
                                            (transaction)
                                              + offer.quantity += qty
                                              + line.applied = true
                                              + invoice.status = applied
                                                      │
                                                      ▼
                                            ActiveFlagService.reconcile(affected_offers)
```

### Idempotency contract

- Unique constraint on `logingrupa_goods_received_invoices.invoice_number`.
- `StockApplyService::apply($iInvoiceId)` is a no-op when `invoice.status === 'applied'` and returns the prior result.
- Re-upload of identical invoice number: parse short-circuits at persistence; controller renders prior-apply summary.

### DB schema (locked at plan phase, summarized here)

- `logingrupa_goods_received_invoices` — header, status, counters, audit fields
- `logingrupa_goods_received_invoice_lines` — one per HTM data row, match strategy, applied flag
- (No separate "unmatched" table — query `lines WHERE matched_offer_id IS NULL`.)

Full column list lives in plan phase migration plan.

## Dependencies

```
Lovata.Toolbox     ^2.2
Lovata.Shopaholic  ^1.32
```

Soft (deferred decision):
- `Logingrupa.ExtendShopaholic` — reuse `ImportLoggerService` / `ImportLoggingTrait`. Plan phase decides: declare dependency vs vendor-inline.

## Key Decisions

| ID | Decision | Rationale | Outcome |
|----|----------|-----------|---------|
| D1 | New plugin (not extend ExtendShopaholic) | Different bounded context (delivery receipts vs supplier feed). SRP. | Locked, scaffolded 2026-04-29 |
| D2 | Match `offers.code` → fallback `products.code` (single-offer only) | Stock lives on Offer; products with one offer are unambiguous | Locked |
| D3 | Increment, never replace; preview-then-apply UI | Distributor delivery is goods received (additive). Preview prevents accidental apply. | Locked |
| D4 | Per-site settings (October Settings model) | Each site has own DB → per-site naturally | Locked |
| D5 | Auto-deactivate on zero + auto-activate on inbound + initial-reset checkbox | All operator-controlled per site; reset is one-shot baseline | Locked |
| D6 | Backend Settings menu (not main nav) | Keep top-bar uncluttered | Locked |
| D7 | Invoice number from HTM body → filename → reject | Body more reliable; filename is operator-controllable | Locked |
| D8 | Pest 4 / PHPStan level 10 / PHPMD / Pint / Rector | Mirror `postnordshippingshopaholic` quality bar | Locked |
| D9 | Functional style, no nested if/else, max 1 level deep | Engineering quality bar | Locked |
| D10 | No mocking business logic in tests | Tiger-Style: real DB, deterministic | Locked |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition:**
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions

**After each milestone:**
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-04-29 — scaffold + project doc seeded from `/gsd-quick --full --research --discuss` capture (`<project_root>/.planning/captures/20260429-stockinvoiceimport-discuss.md`)*
