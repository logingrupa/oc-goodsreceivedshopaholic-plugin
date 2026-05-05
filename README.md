# Goods Received Shopaholic

October CMS plugin for Lovata Shopaholic. Imports distributor `.HTM` delivery receipts. Applies as incremental stock additions to matched offers.

[![Composer](https://img.shields.io/badge/composer-logingrupa%2Foc--goodsreceivedshopaholic--plugin-blue.svg)](https://packagist.org/packages/logingrupa/oc-goodsreceivedshopaholic-plugin)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4.svg)](https://www.php.net/)
[![October CMS](https://img.shields.io/badge/october--cms-v4-orange.svg)](https://octobercms.com/)

**Flow:** clerk uploads `.HTM` → plugin parses + matches by EAN → preview shows matched/unmatched → on Apply, `offer.quantity` increments additively. Per-site Settings drive active-flag automation + one-shot baseline reset.

---

## TOC

1. [Install](#install)
2. [Settings](#settings)
3. [Permissions](#permissions)
4. [Operator workflow](#operator-workflow)
5. [Override + re-import (D12)](#override--re-import-d12)
6. [Initial reset (one-shot)](#initial-reset-one-shot)
7. [Disable 1C XML quantity import (D13)](#disable-1c-xml-quantity-import-d13)
8. [Console command](#console-command)
9. [Logs + troubleshooting](#logs--troubleshooting)
10. [Multi-site](#multi-site)
11. [Publish + verify](#publish--verify)

---

## Install

PHP `^8.3` (prod 8.4). Requires Lovata Toolbox + Shopaholic.

```bash
php artisan plugin:install Logingrupa.GoodsReceivedShopaholic \
    --from=https://github.com/logingrupa/oc-goodsreceivedshopaholic-plugin.git \
    --want=dev-master --oc

php artisan october:migrate
```

October auto-registers the GitHub VCS repo + runs `composer require`. After v1.x tag swap `--want=dev-master` → `--want=^1.1`.

Manual alternative (if VCS repo already registered):

```bash
composer require logingrupa/oc-goodsreceivedshopaholic-plugin
php artisan october:migrate
```

After migrate: **Backend → Settings → Goods Received → toggle `Enable goods-received import` ON.** Plugin ships disabled.

**Sister plugins** (auto-pulled):

| Plugin | Constraint | Role |
|---|---|---|
| `lovata/toolbox-plugin` | `^2.2` | Hungarian backbone, Item/Collection/Store cache |
| `lovata/shopaholic-plugin` | `^1.32` | Core e-commerce — `Offer`, `Product`, `Category`, `Brand` |

**Migrations:**

| Table | Role |
|---|---|
| `logingrupa_goods_received_invoices` | Invoice header — one row per `.HTM` |
| `logingrupa_goods_received_invoice_lines` | One row per data row (EAN, qty, override, match) |
| `lovata_shopaholic_offers.active_managed_by` (col) | Provenance — `'system'` (default) or `'operator'` |

---

## Settings

**Backend → Settings → Goods Received.** Per-site (see [Multi-site](#multi-site)).

| Key | Type | Default | Purpose | Enable when |
|---|---|---|---|---|
| `enabled` | switch | OFF | Master toggle. OFF = upload UI hidden, no automation. | Always — first action after install. |
| `auto_deactivate_on_zero` | switch | OFF | Offer qty hits `0` → `offer.active=false`. | Storefront should hide OOS offers. |
| `auto_activate_on_stock` | switch | OFF | Inbound stock for inactive offer → `offer.active=true`. | Deactivated offers should re-appear on stock return. |
| `allow_initial_reset` | switch | OFF | Show one-shot baseline-reset checkbox on upload preview. | Day-zero only, before first GRN apply. See runbook. |

Auto-flags honor `active_managed_by='operator'` — operator-set deactivations are sticky.

---

## Permissions

**Backend → Settings → Administrators → Roles → tab `Goods Received`.** Each AJAX handler checks one. Server-side enforced.

| Permission | Required for | Typical role | Why split |
|---|---|---|---|
| `upload_invoices` | Upload `.HTM` (parse + persist) | Warehouse clerk | Reading distributor invoices is low-trust. |
| `apply_invoices` | Click Apply (commit stock writes) | Warehouse manager | Apply changes storefront — limit to seniors. |
| `override_invoices` | Re-apply duplicate ADDITIVELY | Senior operator | Destructive correction (D12 add-on-top). |
| `run_initial_reset` | Trigger one-shot baseline reset | Admin / owner | Zeroes every offer + deactivates every product on this site. |

All keys prefix `logingrupa.goodsreceived.`. Example day-1 layout:

- **Clerk** — `upload_invoices`
- **Manager** — `upload_invoices` + `apply_invoices`
- **Senior** — adds `override_invoices`
- **Admin** — all four (`run_initial_reset` admin-only after launch)

---

## Operator workflow

1. Sign in. Navigate **Settings → Goods Received → Invoice History**.
2. Click **Upload .HTM Invoices** (multi-select OK; per-file 10 MB, ≤20 files).
3. Plugin parses each:
   - Invoice number from body (or filename `Nr_PRO<num>_<country>_<DDMMYYYY>.HTM` fallback).
   - Prior `applied` row → reject with override link.
   - Prior `parsed` row (operator closed modal earlier) → silently replaced by fresh parse.
   - Each EAN matched: `offer.code` → `product.code` (single-offer fallback) → `offer.variation` (last comma segment).
4. **Preview modal** — single form, N invoice sections, per-row checkbox (default checked):

   | Column | Meaning |
   |---|---|
   | Row # | Line number from `.HTM` |
   | EAN | 13-digit |
   | Product | Distributor name + `(N pcs)` current stock |
   | Parsed qty | From `.HTM`. Applied unless override set. |
   | Override qty | Operator override (audit + apply path). |
   | After import | LIVE preview — current stock + override (or parsed) qty. |
   | Matched | Link to product/offer page; orange* = variation match. |

5. Uncheck rows to skip. Edit `override_qty` inline (table updates). Notes textarea per invoice.
6. Click **Apply selected**. Modal disables button + spins. Server runs each checked invoice under `Cache::lock('apply-invoice-{id}', 60)` — double-click safe.
7. On success modal closes + page reloads. Status `parsed` → `applied`. Stock incremented; auto-activate fires per Settings.

Apply failure → full transaction rollback, no partial state.

---

## Override + re-import (D12)

> *"Re-applies invoice ADDITIVELY on top of prior apply. Stock incremented by new line quantities. NOT a delta calculation."*

**When:** distributor re-sends invoice with corrected/additional quantities AFTER original was applied. Most common: missed pallet ships separately under same invoice number = genuinely additional stock.

**Flow:**

1. Upload `.HTM` whose invoice_number is already `applied`.
2. Reject screen surfaces prior-apply summary (timestamp, user, units added) + **Override and re-import** button (gated by `override_invoices` perm).
3. Type literal `OVERRIDE` (uppercase, strict `===`). Mistype → reject.
4. New Invoice row created:
   - `override_of_invoice_id` → prior id
   - `invoice_number` → `<orig>-OVR-<priorId>` (UNIQUE index satisfied)
5. Standard parse → preview → apply. Stock incremented AGAIN.

**Does NOT:**
- Decrement-then-reapply (no rollback of prior).
- Calculate delta.
- Mutate prior Invoice row (audit preserved).

For "replace" intent (rare) → adjust stock manually in Offer editor. Not this flow.

---

## Initial reset (one-shot)

Day-zero handover from legacy `quantity` ownership to plugin. Zeroes every offer.quantity, deactivates every offer + product, optionally applies first GRN on top.

> **One-shot. `Invoice.initial_reset_applied=true` exists → gate cannot fire again on this site. NO automated rollback. Take DB backup first.**

### Pre-flight

1. `Settings.allow_initial_reset` ON for this site.
2. No prior reset (no `initial_reset_applied=true` row in Invoice History).
3. Operator holds `run_initial_reset`.
4. DB backup taken + verified (`/home/forge/backup_db.sh` or equivalent).
5. No concurrent backend uploads/applies (write-heavy lock on offers + products).

### Procedure

1. Invoices list → **Initial Reset** in toolbar.
2. Pre-mutation modal shows actual counts (`zero N offers, deactivate M products`).
3. Type `RESET` (uppercase, strict `===`).
4. Optional: select first `.HTM` to apply atop cleared state. Blank = reset-only.
5. Click **Run reset + apply**. Plugin runs:
   1. **(if file)** PARSE `.HTM`
   2. RESET — zero `offer.quantity`, `offer.active=false`, `product.active=false`, mark `Invoice.initial_reset_applied=true`
   3. **(if file)** APPLY — increment per parsed lines; auto-activate fires per Settings.

### Post-reset state

| Field | Value |
|---|---|
| `lovata_shopaholic_offers.quantity` | `0` (every row) |
| `lovata_shopaholic_offers.active` | `false` (every row) |
| `lovata_shopaholic_offers.active_managed_by` | `'plugin'` |
| `lovata_shopaholic_products.active` | `false` (every row) |

### Rollback

None automated. Recovery = restore pre-reset DB backup. Snapshot scaffolding removed 2026-05-05 (forensic-only, no programmatic restore shipped).

---

## Disable 1C XML quantity import (D13)

> **Required before turning plugin on.**

Two paths could write `lovata_shopaholic_offers.quantity`:
1. **This plugin** — owns `quantity` + `active` per D-13.
2. **`Logingrupa.ExtendShopaholic`** 1C XML import — legacy stock-sync.

Two writers fight: apply increments → next 1C XML run blows it back to zero (silent stock-drift bug). Plugin does NOT auto-disable D-13 — explicit out-of-band step.

### Steps

1. **Backend → Settings → ExtendShopaholic → 1C XML Import** (label may vary).
2. Find toggle `Import offer quantity` / `Sync stock from 1C` (in `plugins/logingrupa/extendshopaholic/models/settings/fields.yaml` — grep for `quantity`/`stock`).
3. Set **OFF** on every site running this plugin (.no, .lv, .lt — each site has own Settings store).
4. Verify: trigger next 1C XML import (`php artisan shopaholic:import_from_xml`); confirm `offer.quantity` does NOT change for offers GRN already wrote.

Can't find toggle → grep:

```bash
grep -RnE "quantity\s*=" plugins/logingrupa/extendshopaholic/classes/import/
```

Patch wherever import assigns `$obOffer->quantity = ...` from 1C XML.

---

## Console command

```bash
php artisan goodsreceived:recompute_active_from_stock {--chunk=500}
```

Reconciles every offer's `active` from current `quantity` + Settings, in chunks. Honors `active_managed_by='operator'` — operator deactivations sticky.

### Run when

- After flipping `auto_deactivate_on_zero` / `auto_activate_on_stock` ON first time (existing catalog needs one pass to converge).
- After bulk stock import outside plugin (manual SQL, backup restore).
- Disaster-recovery sanity check.
- Periodic cron — weekly low-traffic window (Sun 04:00). Cheap insurance.

### Logic per non-operator offer

| `qty > 0` | Settings | Action |
|---|---|---|
| true | `auto_activate_on_stock=true` | `offer.active = true` |
| false | `auto_deactivate_on_zero=true` | `offer.active = false` |
| any | both auto-flag toggles OFF | exit 0 (no-op) |

Idempotent. Re-run = SELECT-only on second pass.

### Options + exit

- `--chunk=N` (default 500). Non-positive coerces to 500 (T-04-02-01 — typo can't DoS).
- Exit 0 success: `Reconciled N offers (chunk=K).`
- Exit 1 throw: `Recompute failed: <msg>` — see `storage/logs/laravel-*.log`.

---

## Logs + troubleshooting

Every operation emits structured `Log::*` entry. Stable event keys — grep them.

| Event key | Level | When | Context fields |
|---|---|---|---|
| `goodsreceived.parse` | info | `HtmInvoiceParser` produces `ParsedInvoice` | `invoice_id`, `invoice_number`, `source_filename`, `total_lines`, `skipped_count`, `correlation_id` |
| `goodsreceived.apply` | info | `StockApplyService` writes complete | `invoice_id`, `units_added`, `offers_touched`, `lines_applied`, `lines_skipped`, `applied_by`, `correlation_id` |
| `goodsreceived.reject` | warning | Duplicate / malformed / invalid HTM rejected | `event=reject`, `reason`, `invoice_number_attempted`, `prior_invoice_id`, `prior_applied_at`, `correlation_id` |
| `goodsreceived.initial_reset` | info | `InitialResetService` completes | `invoice_id`, `offers_zeroed`, `products_deactivated`, `correlation_id` |
| `plugin.boot.config_warning` | warning | PHP `max_file_uploads<20` or `upload_max_filesize<10M` | `current`, `recommended` |

### Trace one upload via correlation_id

Every entry carries a uuid-v7 `correlation_id` (time-ordered, per `ImportAuditService` call). Grep:

```bash
tail -f storage/logs/laravel-*.log | grep "goodsreceived\."
# pick correlation_id from goodsreceived.parse, then:
grep -F '0190a4e3-7b4e-7000-8e95-1f4b7e2f9c01' storage/logs/laravel-*.log
```

One grep surfaces every parse / apply / reject sharing that audit chain.

### First-line debug

| Symptom | First check |
|---|---|
| Apply silently does nothing | `goodsreceived.apply` absent → check `goodsreceived.reject` (rejected pre-parse). |
| "Apply already done" UI | `Invoice.status='applied'` — handler rejects per idempotency. Use override flow. |
| "Initial reset not allowed" UI | Two-gate guard fired. Log: `reason=settings_disabled` or `reason=already_applied`. |
| "Forbidden" on action | User lacks permission. Verify role under Settings → Administrators. |
| Upload fails, no log | Pre-controller reject (browser/PHP-ini). Check `plugin.boot.config_warning`. |
| Stock didn't increment | `goodsreceived.apply` `units_added`/`offers_touched`. If `0` → all unmatched. Check InvoiceLine `match_strategy='unmatched'`. |
| 1C XML overwrites plugin stock | Re-do [D13](#disable-1c-xml-quantity-import-d13). 1C XML quantity-write still ON. |
| `/invoices/update/<id>` "Form behavior not initialized" | Invoice deleted; controller redirects to list with flash (since 2026-05-05). Old behavior shouldn't recur — file a bug if seen. |

---

## Multi-site

Three deployments, one Git repo:

| Site | Country | Currency | Distributor lang tag |
|---|---|---|---|
| nailscosmetics.no | Norway | NOK | `_no_` |
| nailscosmetics.lv | Latvia | EUR | `_lv_` |
| nailscosmetics.lt | Lithuania | EUR | `_lt_` |

Each site has **own DB**. No shared schema:

- **Settings per-site.** `enabled` ON on `.no` ≠ ON on `.lv`/`.lt`.
- **Permissions per-site.** Each site has own users + roles. Clerk on `.no` needs separate role on `.lv`.
- **Invoice history per-site.** UNIQUE on `invoice_number` scoped to site DB; `.no` and `.lv` numbers can collide harmlessly.
- **Initial reset per-site one-shot.** `.no` reset doesn't affect `.lv`/`.lt`.
- **D13 (1C XML disable) per-site.** Separate toggle on each site.

Multi-site UAT per OPS-06: change Setting on `.no`, confirm `.lv`/`.lt` don't flip; upload `.HTM` to `.no`, confirm same EAN's stock on `.lv` unchanged.

---

## Publish + verify

`composer.json` publish-ready (verified at v1.0):

| Field | Value |
|---|---|
| `name` | `logingrupa/oc-goodsreceivedshopaholic-plugin` |
| `type` | `october-plugin` |
| `license` | `MIT` |
| `require` | `lovata/toolbox-plugin ^2.2`, `lovata/shopaholic-plugin ^1.32`, `php ^8.3`, `october/system ^4.0`, `october/rain ^4.0` |
| `autoload` PSR-4 | `Logingrupa\GoodsReceivedShopaholic\` |
| `extra.october.plugin` | `Logingrupa.GoodsReceivedShopaholic` |
| `extra.october.installer-name` | `goodsreceivedshopaholic` |

### Pre-publish secret-leak guard

Public repos retain every commit forever. Audit before going public:

```bash
git ls-files | xargs grep -l -iE '(password|secret|api_key|aws_|stripe_|sendgrid_|_token)' || echo CLEAN
```

CLEAN = safe. Any output = audit before push.

### Make public + tag v1.0.0

```bash
gh repo create logingrupa/oc-goodsreceivedshopaholic-plugin --public --source=. --remote=origin --push
git tag v1.0.0
git push --tags
```

### Optional Packagist

1. https://packagist.org → Submit
2. Paste GitHub URL → Check → Submit

Without Packagist, consumers add VCS block:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/logingrupa/oc-goodsreceivedshopaholic-plugin" }
]
```

### Pre-publish dry-run (clean OctoberCMS 4 sandbox)

```bash
composer require logingrupa/oc-goodsreceivedshopaholic-plugin:dev-master
php artisan october:migrate
```

Expected:
- Plugin in `plugins/logingrupa/goodsreceivedshopaholic/`
- 3 plugin tables created + `lovata_shopaholic_offers.active_managed_by` column added
- Backend → Settings → Goods Received page renders 4 toggles

Multi-site UAT checklist: `.planning/UAT-CHECKLIST.md`.

After UAT + v1.0.0 tag:

```bash
composer require logingrupa/oc-goodsreceivedshopaholic-plugin:^1.0
```

---

## Reference

- Source: `plugins/logingrupa/goodsreceivedshopaholic/`
- Composer: `logingrupa/oc-goodsreceivedshopaholic-plugin`
- October code: `Logingrupa.GoodsReceivedShopaholic`
- QA: `composer qa` (pint-test + analyse + phpmd + test)
- Issues: GitHub `logingrupa/oc-goodsreceivedshopaholic-plugin`

## License

[MIT](LICENSE) © Logingrupa.
