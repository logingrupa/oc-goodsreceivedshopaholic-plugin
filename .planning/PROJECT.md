# GoodsReceivedShopaholic Plugin

## What This Is

A Lovata Shopaholic ecosystem plugin (`Logingrupa\GoodsReceivedShopaholic`) that imports distributor goods-received notes (GRN) — uploaded as `.HTM` delivery receipts in the backend — and applies them as **incremental stock additions** to matched offers. Per-site settings drive active-flag automation and a one-shot baseline-reset feature for first-time imports.

## Core Value

Backend operators upload distributor delivery receipts. Stock is added to matched offers idempotently. Per-site automation re-activates inbound products and deactivates zero-stock offers. Auditable history with unmatched-EAN queue for manual resolution.

## Current Milestone: v1.1 Pass 3 Variation EAN Matcher

**Goal:** Recover invoice lines whose EAN matches neither `offer.code` (Pass 1) nor `product.code` single-offer (Pass 2) by adding a deterministic third pass that matches on the offer-name `variation` token (last comma-segment of "Product, Variation"), with a single-offer guard for ambiguity safety.

**Target features:**
- `MatchStrategy` interface + 3 chain-stage matchers (`OfferCodeMatcher`, `ProductCodeSingleOfferMatcher`, `VariationMatcher`) extracted from monolithic `EanMatcherService`.
- `VariationExtractor` shared regex helper (`/^(.+),\s+([^,]+)$/u` — last-comma greedy, multi-comma safe, whitespace-trimmed, no-comma → null).
- D-25 query budget lifted 2 → 3 (one extra `Offer::whereIn('variation', …)` SELECT, no product-name lookup).
- `MatchedLine.strategy` union extended with `'variation'`; `EanMatcherService::matchBatch` → `matchLines(list<ParsedLine>)`.
- Backend lines list `_column_product_name.htm`: new orange + asterisk render branch for `'variation'` matches; existing `'offer_code'` / `'product_code_single_offer'` (black + product link) and `'none'` (plain + asterisk) branches preserved verbatim.
- DB column `match_strategy varchar(32)` already fits — **no migration**.

**Key context:**
- All 5 v1 phases sealed 2026-04-30 (`MILESTONE-V1.0-CLOSURE.md`); phpstan-baseline.neon SHA `4b3227fa…91530a` must remain unchanged.
- Single phase (Phase 6) — scope is matcher contract + 5 new files + 3 file updates + 4 test files.
- Tiger-style determinism: ambiguous variation (≥2 offers) → `'none'`, never silent best-guess.

## Requirements

### Validated

50 of 56 v1 requirements closed across Phases 1-4 + Phase 5 plans 05-01 / 05-02 (2026-04-29 → 2026-04-30). Full traceability lives in `.planning/REQUIREMENTS.md ## Traceability`; this section is the navigable rollup. The 6 still-Pending v1 reqs (QA-07, QA-11, OPS-03, OPS-05, OPS-06 + OPS-02 itself which closes via this very plan) remain in **Active** below per the no-silent-flip rule (T-05-03-01 mitigation).

**Schema, Scaffold, Settings, Permissions (Phase 1, closed 2026-04-29):**
- [x] SCHEMA-01 — Migration `logingrupa_goods_received_invoices`
- [x] SCHEMA-02 — Migration `logingrupa_goods_received_invoice_lines`
- [x] SCHEMA-03 — Migration `logingrupa_goods_received_initial_reset_snapshot`
- [x] SCHEMA-04 — Additive column on `lovata_shopaholic_offers.active_managed_by`
- [x] SCHEMA-05 — Eloquent models with PHPStan-L10 @property blocks
- [x] SCHEMA-06 — Settings model with Multisite (D15)
- [x] SCHEMA-07 — 4 split backend permissions
- [x] SCHEMA-08 — Lang scaffold (en/lv/no/ru); fully populated in OPS-04

**Pure Parsers, DTOs, Exceptions, EAN Matcher (Phase 2, closed 2026-04-29):**
- [x] PARSE-01 — Readonly DTOs (ParsedInvoice, ParsedLine, MatchedLine, ApplyResult)
- [x] PARSE-02 — Typed exception hierarchy (8 subclasses + 1 abstract base)
- [x] PARSE-03 — HtmInvoiceParser (DOMDocument + XPath + LIBXML_NONET)
- [x] PARSE-04 — InvoiceNumberResolver (body → filename fallback → throw)
- [x] PARSE-05 — QuantityNormalizer (rejects decimal-comma BEFORE Eloquent int-clamp)
- [x] PARSE-06 — PriceNormalizer (audit-only)
- [x] PARSE-07 — Hermetic test fixtures (3 real `.HTM` files in `tests/fixtures/invoices/`)
- [x] MATCH-01 — EanMatcherService (two-query batch lookup)
- [x] MATCH-02 — Unmatched lines persisted with `matched_offer_id=NULL`
- [x] QA-01 — HTM parser real-fixture pin tests (BOM/CRLF/unquoted-attr/malformed)
- [x] QA-02 — Stock-write guard tests (decimal-qty rejection + leading-zero EAN preservation)

**Apply Layer + Orchestrators (Phase 3, closed 2026-04-29):**
- [x] APPLY-01 — StockApplyService (saveQuietly per unique offer)
- [x] APPLY-02 — Batched cache flush (≤ 5 leaf-singleton flushes for any apply size)
- [x] APPLY-03 — ActiveFlagService provenance gate (skip `active_managed_by='operator'`)
- [x] APPLY-04 — ActiveFlagService 4-cell matrix
- [x] APPLY-05 — InitialResetService (one-shot + snapshot-before-write + chunked)
- [x] APPLY-06 — ParseAndPersistOrchestrator
- [x] APPLY-07 — ApplyOrchestrator (lockForUpdate + 1 transaction + post-commit flush)
- [x] APPLY-08 — Override-reimport (D12) parse-side + apply-side
- [x] APPLY-09 — SettingsAccessor (DRY accessor + grep gate)
- [x] APPLY-10 — ImportAuditService (vendor-inlined, D14)
- [x] QA-03 — Idempotency tests (4 dedicated)
- [x] QA-04 — Cache-cascade smoke test (200-line batched-flush counter)
- [x] QA-05 — ActiveFlag matrix + provenance skip
- [x] QA-06 — InitialReset 5 dedicated tests
- [x] QA-08 — Transaction safety (partial-failure rollback + ActiveFlag-inside-tx)
- [x] QA-09 — Settings DRY grep gate (Makefile + Pest mirror)

**Backend Controller, Upload/Preview/Apply UI, Console (Phase 4, closed 2026-04-30):**
- [x] UI-01 — Backend controller `Invoices` (Settings menu, not main nav, per D6)
- [x] UI-02 — Multi-file upload with per-file boundary catch
- [x] UI-03 — Preview screen + per-line override_qty / override_reason editing
- [x] UI-04 — Apply confirmation modal + Cache::lock debounce
- [x] UI-05 — Audit history list (sorted by applied_at DESC)
- [x] UI-06 — Original `.HTM` archived via attachOne + downloadable
- [x] UI-07 — Per-import metric panel
- [x] UI-08 — Initial-reset typed-RESET gate + pre-mutation snapshot count modal
- [x] UI-09 — Pre-parse duplicate detection (filename regex short-circuit)
- [x] UI-10 — Override-reimport typed-OVERRIDE gate + warning copy verbatim
- [x] UI-11 — Console command `goodsreceived:recompute_active_from_stock`
- [x] UI-12 — Plugin boot self-check (max_file_uploads / upload_max_filesize)
- [x] QA-10 — 4 permission gate tests + ControllerTestCase base

**Operations + QA + Polish (Phase 5, partial closure 2026-04-30):**
- [x] OPS-01 — README operator runbook (11 sections per D-01) — closed plan 05-02
- [x] OPS-04 — lang/{en,lv,no,ru}/lang.php fully populated; key parity test (629 assertions) — closed plan 05-01

### Active

The 6 v1 requirements still open as of plan 05-03 write-time. Each carries a precise reason it is NOT yet validated (no silent flips — T-05-03-01 mitigation). Cross-reference: `.planning/REQUIREMENTS.md ## Traceability`.

- [ ] OPS-02 — PROJECT.md update with D11-D15 *(closing via THIS plan; flips to Validated upon plan 05-03 commit + verifier sign-off)*
- [ ] OPS-03 — Composer publish to PUBLIC GitHub repo + `composer require` verified on clean install *(scheduled Phase 5 plan 05-05)*
- [ ] OPS-05 — `make all` green: pint-test + phpstan L10 + phpmd + pest --coverage --min=N *(scheduled Phase 5 plan 05-04)*
- [ ] OPS-06 — Multi-site UAT executed on .no / .lv / .lt staging *(scheduled Phase 5 plan 05-06)*
- [ ] QA-07 — Multi-site Settings tests (`IsMultisiteAwareTest`, `MultisiteContextSwitchClearsCacheTest`) *(REQUIREMENTS.md traceability still shows Pending; needs Phase 1 plan 01-07 follow-up — out-of-scope for milestone v1.0 close, candidate for v1.0.1 patch)*
- [ ] QA-11 — `GoodsReceivedTestCase::tearDown()` calls `flushModelEventListeners()` AND each plugin singleton's `flush()` *(REQUIREMENTS.md traceability still shows Pending; needs Phase 1 plan 01-07 follow-up — same v1.0.1 patch candidate)*

Post-v1 work tracks under `.planning/REQUIREMENTS.md ## v2 Requirements (deferred)`.

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
| D11 | GitHub repo: PUBLIC | OPS-03 — Composer require works against clean OctoberCMS 4 + Lovata Shopaholic install | Locked, validated 2026-04-29 |
| D12 | Override-and-reimport = ADD-ON-TOP. Re-apply treats new lines as additive on top of prior apply. UX shows clear warning before override accepted. No `content_hash`, no diff preview, no decrement-then-reapply. | Operator-friendly semantics; simpler than delta calculation; UX warning + typed `OVERRIDE` confirmation prevents accidents | Locked, validated 2026-04-29; shipped Phase 3 plans 03-06 + 03-07 + Phase 4 plan 04-06 |
| D13 | GRN owns `offer.quantity`. User manually disables quantity import in ExtendShopaholic 1C XML config (out-of-band). No cross-plugin migration in this plugin. Document dep in PROJECT.md. | Single canonical writer for stock; explicit out-of-band step keeps plugins decoupled; documented in README section 8 (D-01) | Locked, validated 2026-04-29; documented in README via plan 05-02 |
| D14 | Vendor-inline `ImportAuditService` (~50-80 LoC). No soft-dep on `Logingrupa.ExtendShopaholic`. | Plugin should be installable on a fresh Shopaholic install without ExtendShopaholic; 50-80 LoC of audit logging is cheaper than a soft dependency contract | Locked, validated 2026-04-29; shipped Phase 3 plan 03-02 (96 raw / 65 code lines within ≤100 LoC ceiling) |
| D15 | Settings extends `System\Models\SettingModel` directly + manually implements `MultisiteInterface` + `MultisiteHelperTrait`. | Avoids the `Lovata\Toolbox\Models\CommonSettings` base class which makes Multisite awkward; direct extension keeps the Settings model under our control | Locked, validated 2026-04-29; shipped Phase 1 plan 01-05 |

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
*Last updated: 2026-04-30 — milestone v1.0 close pass: D11-D15 locked + 50 v1 reqs moved Active → Validated (plan 05-03 / OPS-02). 6 reqs still Active pending plans 05-03..05-06 + Phase 1 plan 01-07 follow-up.*

*Previously updated: 2026-04-29 — scaffold + project doc seeded from `/gsd-quick --full --research --discuss` capture (`<project_root>/.planning/captures/20260429-stockinvoiceimport-discuss.md`)*
