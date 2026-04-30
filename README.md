# Goods Received Shopaholic

October CMS plugin for the Lovata Shopaholic ecosystem that imports distributor goods-received notes (`.HTM` delivery receipts) and applies them as incremental stock additions to matched offers.

[![Composer](https://img.shields.io/badge/composer-logingrupa%2Foc--goodsreceived--plugin-blue.svg)](https://packagist.org/packages/logingrupa/oc-goodsreceived-plugin)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](https://opensource.org/licenses/MIT)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4.svg)](https://www.php.net/)
[![October CMS](https://img.shields.io/badge/october--cms-v4-orange.svg)](https://octobercms.com/)

The plugin is operator-facing: a warehouse clerk uploads the distributor's `.HTM` delivery receipt in the backend; the plugin parses it, matches each line by EAN, previews matched / unmatched lines, and on confirmation increments `offer.quantity` additively. Per-site Settings drive the active-flag automation (deactivate at zero, reactivate on inbound) and a one-shot baseline-reset for the first import.

---

## Table of Contents

1. [Installation](#installation)
2. [Configuration — Settings](#configuration--settings)
3. [Permissions](#permissions)
4. [Operator workflow (Upload → Preview → Apply)](#operator-workflow-upload--preview--apply)
5. [Override and re-import (D12 — add-on-top semantics)](#override-and-re-import-d12--add-on-top-semantics)
6. [Initial-reset runbook (one-shot baseline)](#initial-reset-runbook-one-shot-baseline)
7. [GRN-canonical stock writer — disabling 1C XML quantity import (D13)](#grn-canonical-stock-writer--disabling-1c-xml-quantity-import-d13)
8. [Console command — recompute active flags from stock](#console-command--recompute-active-flags-from-stock)
9. [Troubleshooting — `Log::*` event key map](#troubleshooting--log-event-key-map)
10. [Multi-site notes](#multi-site-notes)

---

## Installation

The plugin installs as a standard October CMS plugin via Composer. It is targeted at PHP `^8.3` (production runs 8.4) and depends on Lovata Toolbox + Lovata Shopaholic.

```bash
# 1. Pull the plugin into the project's composer.json
composer require logingrupa/oc-goodsreceived-plugin

# 2. Run the plugin migrations (creates 3 tables + extends offers + extends products)
php artisan october:up
```

After migrations succeed, sign into the backend and turn the plugin on:

> **Backend → Settings → Goods Received → toggle `Enable goods-received import` to ON.**

The plugin ships disabled by default. Until `enabled=true` is set on the site, no upload UI is reachable and no automation fires.

**Required sister plugins** (already declared in `composer.json` `require` — Composer pulls them automatically):

| Plugin                       | Constraint | Role                                 |
|------------------------------|------------|--------------------------------------|
| `lovata/toolbox-plugin`      | `^2.2`     | Hungarian-notation backbone, Item/Collection/Store cache scaffold |
| `lovata/shopaholic-plugin`   | `^1.32`    | Core e-commerce — provides `Offer`, `Product`, `Category`, `Brand` |

**Migrations created on `october:up`:**

| Table                                                             | Role                                                          |
|-------------------------------------------------------------------|---------------------------------------------------------------|
| `logingrupa_goods_received_invoices`                              | Invoice header — one row per uploaded `.HTM` file             |
| `logingrupa_goods_received_invoice_lines`                         | One row per `.HTM` data row (EAN, qty, override_qty, match)   |
| `logingrupa_goods_received_initial_reset_snapshot`                | Pre-mutation snapshot for one-shot baseline reset (rollback)  |
| `lovata_shopaholic_offers.active_managed_by` (column added)       | Provenance flag — `'system'` (default) or `'operator'`        |

---

## Configuration — Settings

All four toggles live at **Backend → Settings → Goods Received**. Each is a per-site switch — see [Multi-site notes](#multi-site-notes) for what "per-site" means.

| Setting key                  | Type   | Default | Purpose                                                                                                  | When to enable                                                                          |
|------------------------------|--------|---------|----------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------|
| `enabled`                    | switch | OFF     | Master toggle. While OFF, the upload UI is hidden and no automation fires.                               | Always — turning this on is the first action after install.                             |
| `auto_deactivate_on_zero`    | switch | OFF     | When an offer's `quantity` reaches `0`, set `offer.active=false`.                                         | Turn ON if the storefront should hide out-of-stock offers automatically.                |
| `auto_activate_on_stock`     | switch | OFF     | When inbound stock arrives for an inactive offer, set `offer.active=true`.                                | Turn ON if previously-deactivated offers should re-appear once stock returns.           |
| `allow_initial_reset`        | switch | OFF     | Show the one-shot baseline-reset checkbox on the upload preview screen.                                   | Turn ON only on day-zero, before the first ever GRN apply. See the runbook below.       |

Both auto-flag toggles honor `offer.active_managed_by='operator'` — offers that an operator manually deactivated stay deactivated regardless of stock movement (the operator wins).

---

## Permissions

The plugin registers four split permissions under **Backend → Settings → Administrators → Roles → tab `Goods Received`**. Each backend AJAX handler checks exactly one of them. Split-permission design enables least-privilege role assignment.

| Permission                                       | Required for                              | Typical role     | Why split                                                                                                  |
|--------------------------------------------------|-------------------------------------------|------------------|------------------------------------------------------------------------------------------------------------|
| `logingrupa.goodsreceived.upload_invoices`       | Upload `.HTM` files (parse + persist)     | Warehouse clerk  | Reading distributor invoices is low-trust; clerks shouldn't be able to commit stock.                       |
| `logingrupa.goodsreceived.apply_invoices`        | Click Apply (commit stock writes)         | Warehouse manager| Apply changes the storefront — limit to senior operators who reconcile vs. the physical receipt.           |
| `logingrupa.goodsreceived.override_invoices`     | Re-apply a duplicate invoice ADDITIVELY   | Senior operator  | Override-and-reimport is a destructive correction path (D12 add-on-top); only senior operators trigger it. |
| `logingrupa.goodsreceived.run_initial_reset`     | Trigger the one-shot baseline reset       | Admin / owner    | Reset zeroes every offer + deactivates every product on this site — admin / launch-day only.               |

A typical day-1 role layout:

- **Warehouse clerk role** — `upload_invoices` only (drops files into the queue; senior operator clicks Apply).
- **Warehouse manager role** — `upload_invoices` + `apply_invoices`.
- **Senior operator role** — `upload_invoices` + `apply_invoices` + `override_invoices`.
- **Admin role** — all four (only the admin should hold `run_initial_reset` after launch day).

Each permission is enforced server-side at the AJAX handler — checking the role checkbox is the contract; the backend is the enforcer.

---

## Operator workflow (Upload → Preview → Apply)

The everyday path. All steps happen inside the October CMS backend.

1. Sign in to the backend.
2. Navigate **Settings → Goods Received → Invoice History**. The list view shows all prior invoices (status / country code / applied_at, sorted newest first).
3. Click **Upload .HTM Invoices** (or use the toolbar button on an empty list).
4. Select one or many `.HTM` files (per-file size limit: 10 MB; total: ≤ 20 files). The browser's file-picker accepts multi-select.
5. Click **Upload**. The plugin parses each file in turn:
   - Each invoice's number is read from the body (or filename `Nr_PRO<num>_<country>_<DDMMYYYY>.HTM` as a fallback).
   - If an invoice with that number is already `applied` on this site, the upload is rejected with a "duplicate invoice" notice that links to the prior apply (timestamp + applying user + units added). See [Override and re-import](#override-and-re-import-d12--add-on-top-semantics).
   - Otherwise, all data rows are extracted, each EAN is matched (offer.code → product.code fallback for single-offer products).
6. The **Preview** screen shows one card per uploaded invoice with a per-line table:

   | Column            | Meaning                                                                                                  |
   |-------------------|----------------------------------------------------------------------------------------------------------|
   | Row #             | The line's row number from the `.HTM` table.                                                              |
   | EAN               | 13-digit EAN from the invoice line.                                                                       |
   | Product name      | Distributor's product name (from the `.HTM`).                                                             |
   | Qty               | Quantity from the `.HTM`. **This is what gets applied unless override_qty is set.**                       |
   | Override qty      | Optional operator override (audit-only metadata; consumed at apply time as `COALESCE(override_qty, qty)`).|
   | Override reason   | Free-text justification when override_qty is non-null.                                                    |
   | Match strategy    | `offer_code`, `product_code_single_offer`, or `unmatched`.                                                |
   | Matched offer     | Short link to the matched offer page (when found).                                                        |

   Operators can edit `override_qty` + `override_reason` inline per row — these save via AJAX and persist on the InvoiceLine row.

7. Click **Apply** on the invoice card. A confirmation modal appears:

   > **About to apply invoice `Nr_PROxxxxx`:**
   > - Total units to add: **N**
   > - Matched offers: **M**
   > - Unmatched lines: **K**

8. Click **Confirm** in the modal. The plugin runs the apply under a server-side `Cache::lock('apply-invoice-{id}', 60)` — clicking Apply twice in rapid succession does **not** double-apply. The first click acquires the lock and writes; the second click sees the lock and renders the "apply in progress" partial.
9. On success, the Invoice card flips to `applied` status. Stock is now incremented on every matched offer; auto-activate fires for offers that were inactive (when `auto_activate_on_stock=true`).

If the apply fails partway (DB error, model handler exception), the entire transaction rolls back — no partial state is committed. Re-upload is rejected as a duplicate; use override-and-reimport to retry deliberately.

---

## Override and re-import (D12 — add-on-top semantics)

> **Warning copy (verbatim, shown in the override modal):**
> *"This re-applies the invoice ADDITIVELY on top of the prior apply. Stock will be incremented by new line quantities. This is NOT a delta calculation."*

**When to use this flow:** the distributor re-sends an invoice with corrected quantities AFTER the original was already applied, and the corrected quantities should stack on top of the original receipt (not replace it, not be a diff). The most common real-world cause is a missed pallet that ships separately under the same invoice number — the second receipt is genuinely additional stock.

**What happens technically:**

1. Operator uploads the `.HTM` whose `invoice_number` already exists with `status='applied'` on this site.
2. The system rejects the duplicate AND surfaces a reject screen with the prior-apply summary:
   - Prior `applied_at` timestamp
   - Applying user
   - Units added per offer
   - "Override and re-import" button (visible only when the operator holds `override_invoices`).
3. The operator clicks **Override and re-import**.
4. A typed-confirmation modal asks the operator to type the literal `OVERRIDE` (uppercase). The server-side check is strict equality (`===`) — no case-insensitive match, no whitespace trimming. Mistyped input is rejected.
5. On confirm, the plugin creates a NEW Invoice row:
   - `override_of_invoice_id` → points at the prior invoice.
   - `invoice_number` → suffixed `-OVR-<priorId>` (the UNIQUE index on `invoice_number` would otherwise reject the new row; the suffix is a derived label that satisfies the index while preserving the canonical reference).
6. The new invoice runs the standard parse → preview → apply flow. When the operator clicks Apply, stock is **incremented again** — `offer.quantity` grows by the new line quantities.

**What this flow does NOT do:**

- It does NOT decrement-then-reapply (no rollback of the prior apply).
- It does NOT calculate a delta between the prior apply and the new invoice.
- It does NOT mutate the prior Invoice row (audit trail preserved verbatim).

If the distributor sent a corrected invoice meant to **replace** the prior receipt (rare), do NOT use this flow — adjust stock manually via the CMS Offer editor, then disable auto-deactivate-on-zero before the next apply if needed.

---

## Initial-reset runbook (one-shot baseline)

The one-shot baseline reset is the day-zero mechanism that hands `offer.quantity` ownership from the legacy import path (or manual DB state) to this plugin. It zeroes every offer's quantity, deactivates every offer + product, snapshots prior values for rollback, and then immediately applies the first GRN invoice on top of the cleared state.

> **Reset is one-shot — once `Invoice.initial_reset_applied=true` exists on this site, the gate cannot fire again. There is no automated rollback CLI in v1.**

### Pre-flight checklist

Before starting, confirm every item below:

1. **`Settings.allow_initial_reset` is ON for this site.** Toggle at Backend → Settings → Goods Received.
2. **No prior reset has been recorded.** Check the Invoice History list — no row has `initial_reset_applied=true`. (The pre-mutation modal will refuse if a prior reset exists; this is the second of two guards — defense in depth.)
3. **You hold `logingrupa.goodsreceived.run_initial_reset`** — verify under Settings → Administrators → Edit role.
4. **A DB backup is taken.** This plugin's `logingrupa_goods_received_initial_reset_snapshot` table captures per-row prior values (`prior_quantity`, `prior_offer_active`, `prior_product_active`, `prior_product_id`) — but a full DB backup is the operator's safety net for anything outside the snapshot scope. Do NOT skip this step.
5. **Estimate snapshot count.** The pre-mutation modal will show actual counts before commit (`This will zero out N offers and deactivate M products`); you should already have a rough idea of N + M from the catalog.
6. **No other backend session is uploading or applying.** Reset takes a write-heavy lock on `lovata_shopaholic_offers` + `lovata_shopaholic_products`; concurrent activity makes diagnostics painful if anything fails.

### Procedure

1. Upload the first `.HTM` invoice via the standard upload flow.
2. On the preview screen, tick the **One-shot baseline reset** checkbox (visible only when `allow_initial_reset=true` AND no prior reset exists).
3. Click Apply. The pre-mutation modal opens with the actual snapshot count:
   > *"This will zero out N offers and deactivate M products before applying invoice `Nr_PROxxxxx`."*
4. Type the literal `RESET` (uppercase) in the confirmation field. The server-side check is strict equality (`===`) — mistyped input is rejected.
5. Click **Run reset + apply**.
6. The plugin runs in **strict order** (locked per D-24):
   1. **PARSE** — extract data rows from the `.HTM`.
   2. **RESET** — snapshot every offer + product into `logingrupa_goods_received_initial_reset_snapshot` (chunked-of-500 batched INSERT), zero `offer.quantity`, set `offer.active=false`, set `product.active=false`, mark `Invoice.initial_reset_applied=true`.
   3. **APPLY** — increment `offer.quantity` by the new invoice's line quantities; auto-activate fires per the Settings.

### What gets zeroed / deactivated

After step 6.2 (RESET) and before step 6.3 (APPLY):

| Field                                        | New value           |
|----------------------------------------------|---------------------|
| `lovata_shopaholic_offers.quantity`          | `0` (every row)     |
| `lovata_shopaholic_offers.active`            | `false` (every row) |
| `lovata_shopaholic_offers.active_managed_by` | `'plugin'`          |
| `lovata_shopaholic_products.active`          | `false` (every row) |

The Invoice row gets `initial_reset_applied=true` so the gate cannot fire again on this site.

### Reading the snapshot table for rollback

If reset was triggered in error, the snapshot lets the operator reconstruct prior state. **No automated rollback CLI ships in v1** — the procedure below is manual operator action against the DB.

```sql
-- Inspect the snapshot rows for the reset invoice
SELECT *
FROM logingrupa_goods_received_initial_reset_snapshot
WHERE invoice_id = <reset_invoice_id>;
```

Each snapshot row carries:

| Column                 | Pre-reset value source                       |
|------------------------|----------------------------------------------|
| `offer_id`             | the offer's id                               |
| `prior_quantity`       | `lovata_shopaholic_offers.quantity` before   |
| `prior_offer_active`   | `lovata_shopaholic_offers.active` before     |
| `prior_product_id`     | `lovata_shopaholic_offers.product_id` before |
| `prior_product_active` | `lovata_shopaholic_products.active` before   |

To roll back manually: walk the snapshot, restore each field on `lovata_shopaholic_offers` + `lovata_shopaholic_products`, then `DELETE` the reset Invoice row + its `initial_reset_applied=true` flag. Take a DB backup BEFORE rolling back — this is a destructive secondary operation.

A rollback CLI (`php artisan goodsreceived:rollback_initial_reset --invoice=<id>`) is on the v2 backlog.

---

## GRN-canonical stock writer — disabling 1C XML quantity import (D13)

> **This step is required before turning the plugin on.**

Two import paths could write to `lovata_shopaholic_offers.quantity` on a fully-equipped Shopaholic store:

1. **This plugin (the new GRN canonical writer).** Owns `quantity` and `active` (when the auto-flag toggles are ON).
2. **`Logingrupa.ExtendShopaholic` 1C XML import.** The legacy stock-sync path used before this plugin shipped.

Per locked decision **D-13**, this plugin **owns** the `quantity` column. Operators MUST manually disable quantity-write in `Logingrupa.ExtendShopaholic`'s 1C XML config so the two writers don't fight (one apply increments stock; the next 1C XML run could blow it back to zero — exactly the silent stock-drift bug D-13 prevents).

This plugin does **not** auto-disable that setting — D-13 was deliberately scoped as an explicit out-of-band step (no cross-plugin migration, no surprise behaviour in someone else's plugin).

### Steps

1. **Backend → Settings → ExtendShopaholic → 1C XML Import** (the exact menu label may differ slightly between releases of `Logingrupa.ExtendShopaholic`; verify in your install).
2. Locate the toggle that controls **import quantity from 1C XML**. In recent releases it is labelled `Import offer quantity` or `Sync stock from 1C` and lives in the ExtendShopaholic Settings model. The settings file is at `plugins/logingrupa/extendshopaholic/models/settings/fields.yaml`; search for fields whose name contains `quantity` or `stock`.
3. Set it to **OFF** on every site running this plugin (.no, .lv, .lt — see [Multi-site notes](#multi-site-notes); each site has its own Settings store).
4. Verify by triggering the next 1C XML import (CLI: `php artisan shopaholic:import_from_xml` or the ExtendShopaholic-specific variant) and confirming `offer.quantity` does **not** change for offers that the GRN apply has already written.

If you cannot find the toggle, ask the ExtendShopaholic maintainer or grep the plugin source for the import path that writes `offer.quantity`:

```bash
grep -RnE "quantity\s*=" plugins/logingrupa/extendshopaholic/classes/import/
```

The location to patch is wherever an import step assigns `$obOffer->quantity = ...` from 1C XML data.

---

## Console command — recompute active flags from stock

```bash
php artisan goodsreceived:recompute_active_from_stock {--chunk=500}
```

Reconciles every offer's `active` flag from its current `quantity` and the live Settings, in chunks. Honors `active_managed_by='operator'` — operator-set deactivations are **sticky** and never get reverted by the reconcile pass.

### When to run

- **After flipping `auto_deactivate_on_zero` or `auto_activate_on_stock` ON for the first time.** The toggles only fire on subsequent applies; the existing catalog needs one pass to converge.
- **After bulk-importing stock outside the plugin** (manual SQL `UPDATE`, restored from backup, etc.).
- **After a disaster-recovery restore.** Run this as a dry-run to confirm the active flags match the restored quantities.
- **As a periodic reconcile cron.** Recommended cadence: weekly, low-traffic window (e.g. Sunday 04:00). Drift between `quantity` and `active` is rare in normal operation but a reconcile cron is cheap insurance.

### What it does

For every offer that is NOT operator-managed:

| `quantity > 0` | `auto_activate_on_stock` | Setting | Action                                            |
|----------------|--------------------------|---------|---------------------------------------------------|
| `true`         | `true`                   |         | `offer.active = true`                             |
| `false`        | `auto_deactivate_on_zero` = `true` |   | `offer.active = false`                            |
| any            | both auto-flag toggles OFF        |   | nothing — the command short-circuits to exit 0    |

The reconcile is idempotent — running it twice in a row writes nothing on the second run (each row's target state is a pure function of its current `quantity` + the Settings). Verify cheaply via `php artisan goodsreceived:recompute_active_from_stock` followed by a query log: the first run runs `SELECT + UPDATE` for changed rows; the second runs `SELECT` only.

### Options

- `--chunk=N` — chunk size for the offer iteration. Default: `500`. Non-positive values silently coerce to `500` (T-04-02-01 — operator typos must never DoS via infinite-loop or zero-chunk).

### Exit codes

- `0` — success. Final line printed: `Reconciled N offers (chunk=K).`
- `1` — uncaught exception. Final line printed: `Recompute failed: <message>`. Inspect `storage/logs/laravel-*.log` for the stack trace.

---

## Troubleshooting — `Log::*` event key map

Every plugin operation emits a structured log entry through Laravel's `Log` facade. The string in the message is a stable event key — grep for it.

| Event key                    | Level    | When fired                                             | Context fields                                                                                                                       | grep recipe                                                                       |
|------------------------------|----------|--------------------------------------------------------|--------------------------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------|
| `goodsreceived.parse`        | info     | `HtmInvoiceParser` produces a `ParsedInvoice`          | `invoice_id`, `invoice_number`, `source_filename`, `total_lines`, `skipped_count`, `correlation_id`                                  | `grep -F 'goodsreceived.parse' storage/logs/laravel-*.log`                        |
| `goodsreceived.apply`        | info     | `StockApplyService` writes complete                    | `invoice_id`, `units_added`, `offers_touched`, `lines_applied`, `lines_skipped`, `applied_by`, `correlation_id`                      | `grep -F 'goodsreceived.apply' storage/logs/laravel-*.log`                        |
| `goodsreceived.reject`       | warning  | Duplicate / malformed / invalid HTM rejected           | `event=reject`, `reason`, `invoice_number_attempted`, `prior_invoice_id` (when duplicate), `prior_applied_at`, `correlation_id`      | `grep -F 'goodsreceived.reject' storage/logs/laravel-*.log`                       |
| `goodsreceived.initial_reset`| info     | `InitialResetService` completes                        | `invoice_id`, `offers_zeroed`, `products_deactivated`, `correlation_id`                                                              | `grep -F 'goodsreceived.initial_reset' storage/logs/laravel-*.log`                |
| `plugin.boot.config_warning` | warning  | Plugin boot detects PHP `max_file_uploads<20` or `upload_max_filesize<10M` | `current`, `recommended`                                                                                                              | `grep -F 'plugin.boot.config_warning' storage/logs/laravel-*.log`                 |

### Tracing one upload end-to-end via `correlation_id`

Every `goodsreceived.*` log entry carries a `correlation_id` — a uuid-v7 string (time-ordered) generated per `ImportAuditService` call. To follow one upload from parse through apply (or reject), find any entry for the upload, copy the `correlation_id`, and grep:

```bash
tail -f storage/logs/laravel-*.log | grep "goodsreceived\."
# pick out the correlation_id from a goodsreceived.parse line, then:
grep -F '0190a4e3-7b4e-7000-8e95-1f4b7e2f9c01' storage/logs/laravel-*.log
```

That single grep surfaces every parse / apply / reject event sharing the same upload's audit chain.

### Common error scenarios — first-line debug

| Symptom                                                       | First check                                                                                                                                                                  |
|---------------------------------------------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Apply silently does nothing                                    | Check `storage/logs/laravel-*.log` for `goodsreceived.apply`. If absent, check `goodsreceived.reject` — the upload may have been rejected before parse.                       |
| "Apply already done" rendered in the UI                        | Inspect `Invoice.status` for the invoice. If `'applied'`, the dedicated handler intentionally rejects (idempotency). Use the override-and-reimport flow if reapply was meant. |
| "Initial reset not allowed" rendered in the UI                 | The two-gate guard fired. Check the log for `goodsreceived.reject` with `reason=settings_disabled` or `reason=already_applied`.                                              |
| "Forbidden" on upload / apply / override / reset               | Backend user lacks the corresponding permission. Verify role assignments under Settings → Administrators → Roles → tab `Goods Received`.                                     |
| Upload fails with no log entry                                 | Browser-side or PHP-ini reject before the controller runs. Check `plugin.boot.config_warning` lines for `max_file_uploads` or `upload_max_filesize` warnings.                |
| Offer stock didn't increment after apply                        | Check `goodsreceived.apply` `units_added` + `offers_touched`. If `offers_touched=0`, every line was unmatched — look at the InvoiceLine rows for `match_strategy='unmatched'`. |
| 1C XML import keeps overwriting plugin-applied stock            | Re-read [GRN-canonical stock writer](#grn-canonical-stock-writer--disabling-1c-xml-quantity-import-d13). The 1C XML quantity-write toggle is still ON in ExtendShopaholic.    |

---

## Multi-site notes

The same plugin code runs on three deployments under one Git repo:

| Site                 | Country | Currency | Distributor language tag |
|----------------------|---------|----------|--------------------------|
| nailscosmetics.no    | Norway  | NOK      | `_no_`                   |
| nailscosmetics.lv    | Latvia  | EUR      | `_lv_`                   |
| nailscosmetics.lt    | Lithuania | EUR    | `_lt_`                   |

Each site has its **own database** — there is no shared schema. Consequently:

- **Settings are per-site.** The October Settings model writes into the site's own DB; toggling `enabled` on `.no` does NOT change `enabled` on `.lv` or `.lt`. Each site is configured independently.
- **Permissions are per-site.** Each site has its own backend users + roles table. A clerk on `.no` does NOT automatically have access to `.lv` — they need a separate role assignment on each site they operate.
- **Invoice history is per-site.** The `logingrupa_goods_received_invoices` table is per-DB; `.no` and `.lv` invoice numbers can collide (they're scoped to their respective sites' UNIQUE index).
- **Initial reset is per-site one-shot.** Doing the reset on `.no` does NOT reset `.lv` or `.lt`. Each site has its own day-zero.
- **The 1C XML disable step (D13) must be applied separately on each site.** ExtendShopaholic's settings live in each site's DB; turning off the quantity import on `.no` leaves `.lv` and `.lt` untouched.

Multi-site isolation is verified manually per OPS-06 (UAT checklist documented separately under plan 05-05). The expected verification: change a Setting on `.no`, confirm `.lv` and `.lt` do not flip; upload one `.HTM` to `.no`, confirm the same EAN's stock on `.lv` is unchanged.

---

## License

[MIT License](LICENSE) © Logingrupa.

## Reference

- Plugin source: `plugins/logingrupa/goodsreceivedshopaholic/` (this directory).
- Composer package: `logingrupa/oc-goodsreceived-plugin`.
- October plugin code: `Logingrupa.GoodsReceivedShopaholic`.
- QA pipeline: `composer qa` (runs `pint-test` + `analyse` + `phpmd` + `test`).
- Issue tracker: see the GitHub repo `logingrupa/oc-goodsreceived-plugin`.
