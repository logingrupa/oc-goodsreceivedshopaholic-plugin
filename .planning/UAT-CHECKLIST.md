# GoodsReceivedShopaholic v1.0 — UAT Checklist

**Plugin:** `logingrupa/oc-goodsreceived-plugin` (Lovata Shopaholic ecosystem)
**Milestone:** v1.0
**Purpose:** Manual user-acceptance verification of multi-site Settings isolation (OPS-06) + composer-require + plugin install on a clean OctoberCMS 4 + Lovata Shopaholic install (OPS-03).

Print this page. Tick each box as you go. Sign + date at the bottom when complete.

Cross-reference: `README.md` ## Verification section provides the install command + expected outcome; this checklist is the printable runbook for the actual UAT session.

---

## A. Single-site smoke (run on .no first)

### A1. Plugin install via Composer

- [ ] On a clean OctoberCMS 4 install with Lovata Shopaholic + Toolbox already present, run:
  ```bash
  composer require logingrupa/oc-goodsreceived-plugin:^1.0
  php artisan october:up
  ```
- [ ] `october:up` reports the 3 plugin tables created: `logingrupa_goods_received_invoices`, `logingrupa_goods_received_invoice_lines`, `logingrupa_goods_received_initial_reset_snapshot`
- [ ] `october:up` reports the column added: `lovata_shopaholic_offers.active_managed_by` (default 'system')
- [ ] Backend → Settings → Goods Received page renders without errors
- [ ] All 4 toggles visible: Enable, Auto-deactivate on zero, Auto-activate on stock, Allow initial reset

### A2. Permissions

- [ ] Backend → Settings → Administrators → Roles → tab "Goods Received" shows 4 split permissions:
  - [ ] `logingrupa.goodsreceived.upload_invoices`
  - [ ] `logingrupa.goodsreceived.apply_invoices`
  - [ ] `logingrupa.goodsreceived.override_invoices`
  - [ ] `logingrupa.goodsreceived.run_initial_reset`
- [ ] A test backend user with ONLY `upload_invoices` permission is denied when clicking Apply (expected: AjaxException with "Forbidden" message)

### A3. Upload + Apply happy path

- [ ] Toggle `enabled=true` on .no
- [ ] Upload one .HTM file from the `_no_` distributor (e.g., `Nr_PRO033328_no_13042026.HTM`)
- [ ] Preview screen renders with line table; matched/unmatched counts visible
- [ ] Click Apply → confirmation modal shows total units / matched offer count / unmatched count
- [ ] Confirm → Invoice.status flips to 'applied'
- [ ] Spot-check: pick 1 matched line; query `SELECT quantity FROM lovata_shopaholic_offers WHERE id = <matched_offer_id>` BEFORE and AFTER apply — quantity incremented by line qty

### A4. Override-and-reimport

- [ ] Re-upload the SAME .HTM file
- [ ] Reject screen appears with prior-apply summary (timestamp, applying user, units added)
- [ ] Click "Override and re-import"
- [ ] Modal shows the verbatim warning copy: "This re-applies the invoice ADDITIVELY on top of the prior apply..."
- [ ] Type literal `OVERRIDE` (uppercase) → confirm
- [ ] New Invoice row created with `override_of_invoice_id` pointing at prior + invoice_number suffix `-OVR-<priorId>`
- [ ] Apply the override invoice → offer.quantity is INCREMENTED AGAIN (additive, NOT replaced)

### A5. Initial-reset (DESTRUCTIVE — run only on a sandbox / dev DB)

- [ ] Toggle `allow_initial_reset=true` on .no
- [ ] Upload a fresh .HTM file
- [ ] Initial-reset checkbox visible on preview
- [ ] Tick the checkbox; modal shows pre-mutation snapshot count
- [ ] Type literal `RESET` (uppercase) → confirm
- [ ] All offer.quantity → 0; all offer.active → false; all product.active → false (verify with SELECT COUNT)
- [ ] Snapshot table populated: `SELECT COUNT(*) FROM logingrupa_goods_received_initial_reset_snapshot WHERE invoice_id = <id>` returns the offer count
- [ ] After reset, the just-applied invoice's stock writes are visible — confirms PARSE → RESET → APPLY order (D-24)
- [ ] Re-upload another invoice — initial-reset checkbox is now HIDDEN (one-shot enforced)

---

## B. Multi-site verification (D-14 — the canonical OPS-06 contract)

Run sequentially on .no → .lv → .lt staging environments. The point is to prove Settings + stock writes are PER-SERVER, not shared.

### B1. Deploy + migrate on each site

- [ ] Deploy plugin to .no staging; run `php artisan october:up`
- [ ] Repeat for .lv staging
- [ ] Repeat for .lt staging

### B2. Settings isolation

- [ ] On .no: Backend → Settings → Goods Received → toggle `enabled=true`
- [ ] On .lv: refresh the same page — `enabled` MUST still be FALSE (default). Confirms per-server DB isolation.
- [ ] On .lt: same — `enabled` MUST still be FALSE.
- [ ] On .lv: toggle `enabled=true`; on .no: refresh and confirm `enabled` is STILL true (didn't get reset by .lv's change). Confirms one-way write isolation.

### B3. Stock-write isolation

- [ ] Pick a product whose EAN is on BOTH .no's catalog AND .lv's catalog (a SKU that exists on both stores)
- [ ] On .no: upload an .HTM containing that EAN with qty=10; apply
- [ ] On .no: confirm offer.quantity for that EAN incremented by 10
- [ ] On .lv: query that EAN's offer.quantity — MUST BE UNCHANGED. Confirms cross-site stock isolation.

### B4. Permission isolation

- [ ] On .no: grant `apply_invoices` to user X
- [ ] On .lv: confirm user X (if they exist on .lv) does NOT have `apply_invoices` (per-server DB → per-server permissions)

---

## C. Sign-off

| Field | Value |
|-------|-------|
| UAT executed by | _____________________ |
| Date | _____________________ |
| .no install verified | [ ] Pass [ ] Fail |
| .lv install verified | [ ] Pass [ ] Fail |
| .lt install verified | [ ] Pass [ ] Fail |
| Multi-site isolation verified | [ ] Pass [ ] Fail |
| Composer require verified | [ ] Pass [ ] Fail |
| Notes (any failures, deviations, follow-ups): |  |

---

*Per D-15: This UAT is the deliverable for OPS-06; actual execution is operator action AFTER plan 05-05 closes. Once UAT signs off, OPS-06 flips to validated in PROJECT.md.*
