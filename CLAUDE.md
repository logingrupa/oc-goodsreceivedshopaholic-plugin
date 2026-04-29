# CLAUDE.md

Guidance for Claude Code when working in this repo.

## Project

**GoodsReceivedShopaholic Plugin** (`Logingrupa\GoodsReceivedShopaholic`)

A Lovata Shopaholic ecosystem plugin that imports distributor goods-received notes (GRN) — uploaded as `.HTM` delivery receipts in the backend — and applies them as **incremental stock additions** to matched offers. Per-site toggles drive active-flag automation: auto-deactivate offers at zero stock, auto-activate on inbound, and a one-shot baseline-reset checkbox for the first import.

**Target stores:** nailscosmetics.no (primary — current invoices originate from `_no_` distributor), nailscosmetics.lv, nailscosmetics.lt
**Languages:** lv, ru, en, no

## Build & QA Commands

All QA tooling lives in the parent project's `vendor/bin/`. Run from the plugin directory.

```bash
make all                # full pipeline: pint-test → analyse → phpmd → test
make test               # Pest 4 / PHPUnit 12, SQLite in-memory
make analyse            # PHPStan level 10 + Larastan
make phpmd              # Lovata ruleset
make pint               # auto-fix style
make pint-test          # check style only
make rector-dry         # preview refactors
make rector             # apply refactors
make baseline           # regenerate phpstan-baseline.neon
```

Composer aliases: `composer qa`, `composer test`, `composer analyse`, `composer pint-test`.

**Bootstrap:** `../../../modules/system/tests/bootstrap.php` (October CMS test harness).

## Architecture (planned)

Mirrors `logingrupa/postnordshippingshopaholic` and `logingrupa/campaignpricingshopaholic` (the QA reference standards).

### Domain model

| Layer | Class (planned) | Role |
|-------|-----------------|------|
| Parser | `HtmInvoiceParser` | Reads HTM, extracts table rows: row#, EAN(13), name, unit, qty, prices |
| Service | `InvoiceMatcherService` | Matches EAN → `offers.code` first, fallback `products.code` (single-offer products) |
| Service | `StockApplyService` | Increments `offer.quantity` per matched line, idempotent on `invoice_number` |
| Service | `ActiveFlagService` | Honors per-site settings: auto-deactivate-on-zero, auto-activate-on-stock |
| Service | `InitialResetService` | One-shot baseline reset: zero all offer quantities, deactivate all products/offers |
| Model | `Invoice` | `logingrupa_goods_received_invoices` row — invoice header + status |
| Model | `InvoiceLine` | `logingrupa_goods_received_invoice_lines` — per-row EAN/qty/match |
| Model | `Settings` | Plugin settings (per-site DB) |
| Controller | `Invoices` | Backend list/upload/preview/apply/history |

Settings menu entry only — no top-nav clutter.

### Idempotency

`invoice_number` is unique. Re-upload of an already-applied invoice is rejected with details: prior apply timestamp, applying user, units added per offer.

### Sample data

`storage/app/uploads/invoices/Nr_PRO*.HTM` (15 sample files at scaffold time). Filename pattern: `Nr_PRO<num>_<country>_<DDMMYYYY>.HTM`. Data rows: `<TR class="R20|R21">` after header `<TR class="R19">`. Decimal separator: comma (normalize before cast).

## Dependencies

```
Lovata.Toolbox     ^2.2
Lovata.Shopaholic  ^1.32
```

Optional reuse target: `Logingrupa.ExtendShopaholic` provides `ImportLoggerService` + `ImportLoggingTrait`. Decision deferred to plan phase: vendor inline vs add as soft dependency.

## Conventions

### PHP

- **Hungarian notation** (Lovata.Toolbox standard): `$obItem`, `$arList`, `$iCount`, `$sSlug`, `$bIsActive`, `$fPrice`
- **`declare(strict_types=1)`** at top of every file (enforced by Rector)
- **`#[\Override]`** on parent overrides
- **PSR-12** style (Pint)
- **PHPStan level 10** with Larastan
- Translatable strings via `lang.php` keys — never hardcode
- Namespace: `Logingrupa\GoodsReceivedShopaholic` (use `Logingrupa` not `LoginGrupa`)

### Plugin extension

- `Event::subscribe(HandlerClass::class)` in `Plugin::boot()` for model handlers
- `Model::extend()` / `addDynamicMethod()` for model extensions
- Never subclass upstream models

### Tests

- Base case: `GoodsReceivedTestCase` (this repo) — Pest 4 / PHPUnit 12 compatible
- `tearDown()` calls `flushModelEventListeners()` — prevents state leakage
- HTM parser tests: pin against `storage/app/uploads/invoices/Nr_PRO*.HTM` fixtures (copy to `tests/fixtures/` to keep tests hermetic)

## Reference

- **PRD/decisions:** `<project_root>/.planning/captures/20260429-stockinvoiceimport-discuss.md` (until promoted to plugin-local PROJECT.md/ROADMAP.md)
- **QA reference plugin:** `plugins/logingrupa/postnordshippingshopaholic/`
- **Existing 1C XML import (different concern):** `plugins/logingrupa/extendshopaholic/classes/import/`
- **Offer stock field:** `plugins/lovata/shopaholic/models/Offer.php` → `quantity`, `code`
