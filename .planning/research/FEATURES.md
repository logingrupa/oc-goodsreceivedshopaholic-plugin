# Feature Research

**Domain:** Backend GRN (Goods Received Note) import for e-commerce inventory (Lovata Shopaholic / OctoberCMS)
**Researched:** 2026-04-29
**Confidence:** HIGH (table stakes & anti-features verified against multiple sources); MEDIUM (differentiators — informed by warehouse/3PL practice, applied to small-operator context)

---

## Executive Recommendation (1-paragraph)

PROJECT.md's Active list already covers the **complete table-stakes set** for a small-operator GRN flow plus two genuine differentiators (per-site automation toggles, console reconciliation command). The biggest decision-points worth confirming in v1 are: (a) **invoice idempotency UX** — "override and re-import" is intentionally non-standard and must be guarded with a friction step; (b) **one-shot baseline reset** — destructive, irreversible, must be locked behind a per-site setting AND a typed confirmation; (c) **unmatched-EAN handling** — keep as a passive queue (already planned) and explicitly do NOT build a SKU-mapping UI in v1. The four legitimate v1 differentiators worth adding are (1) preview-line-level qty override, (2) duplicate detection on upload (before parse), (3) per-invoice CSV/HTM artifact archive for audit, and (4) "dry-run preview without persistence" toggle. Everything else (supplier defaults, photo capture, cost averaging, three-way matching, ASN, mobile UX) is **explicit defer** for this domain.

---

## Feature Landscape

### Table Stakes (Operators Expect These — Already in PROJECT.md unless noted)

Features without which the product feels broken or untrustworthy.

| Feature | Why Expected | Complexity | PROJECT.md? | Notes |
|---------|--------------|------------|-------------|-------|
| Multi-file upload in one submission | Operators receive 3-10 invoices/day; one-by-one is friction | LOW | YES | Already planned. Use OctoberCMS `fileupload` widget. |
| Two-step Parse → Preview → Apply | Industry standard ([Hopstack](https://www.hopstack.io/blog/improve-the-warehouse-receiving-process)); single-click "import" feels reckless on stock writes | MEDIUM | YES | Already planned. Larajax. |
| Per-line preview (matched/unmatched/qty/EAN) | Operator must see what will happen BEFORE writes | LOW | YES | Already planned. Rendered from `InvoiceLine` rows after parse. |
| Partial apply (matched lines apply, unmatched persist for resolution) | Refusing to apply because of 1 unmatched line blocks all stock | LOW | YES | Already planned. Critical: `WHERE matched_offer_id IS NULL` is the unmatched queue. |
| Idempotency lock (re-upload of same invoice rejected) | Without this, double-click on Apply doubles stock — silent corruption | MEDIUM | YES | Already planned via unique `invoice_number`. **HIGH severity** if missed. |
| Audit trail (who applied what, when, units per offer) | Stock discrepancies require provenance for investigation | MEDIUM | YES | Already planned (Audit history page). |
| Unmatched-EAN queue (persistent, not lost) | Operators resolve over days/weeks; queue must survive sessions | LOW | YES | Already planned (query lines `WHERE matched_offer_id IS NULL`). |
| Operator confirmation before destructive Apply | Stock writes are non-trivially reversible | LOW | YES (implied by two-step UI) | OctoberCMS confirmation modal on Apply button. Should display total units and offer count being mutated. |
| Reject upload on missing/invalid invoice number | Without invoice_number, idempotency contract is void → silent duplicate corruption | LOW | YES | Body-first → filename fallback → reject. |
| EAN matching with documented strategy (offers.code first, products.code single-offer fallback) | Ambiguity in matching = silent stock-on-wrong-product errors | MEDIUM | YES | Already planned. Strategy stored on `InvoiceLine.match_strategy` for audit. |
| Per-line match strategy visible in preview | Operator must see "matched on product (single offer)" vs "matched on offer" to verify | LOW | YES (implied) | Display `match_strategy` field in preview table column. |
| Backend access control (operator role / Buddies plugin) | Stock writes are sensitive; not every backend user should apply | LOW | NOT EXPLICIT | **Add to v1 plan**: October backend permission `logingrupa.goodsreceived.apply_invoices` checked on Apply handler. Buddies-compatible. |
| Preserve original HTM file (download from audit page) | Disputes with distributor require original document | LOW | NOT EXPLICIT | **Add to v1 plan**: store uploaded HTM in `storage/app/uploads/invoices/applied/<invoice_number>.htm`. Cheap, huge audit value. |

#### Are the PROJECT.md "Out of Scope" defers reasonable?

| Deferred feature | Risk if deferred | Verdict |
|-----------------|------------------|---------|
| Pricing import | None — this is a **stock** import, prices live in 1C XML pipeline (different bounded context). HTM prices are parsed for audit; ignoring writes is correct. | **CORRECT defer** |
| Outbound stock writes (sales decrement) | None — Shopaholic OrderProcessor already owns this. Duplication would corrupt. | **CORRECT defer** |
| Supplier 1C XML import | None — separate plugin, separate concern. SRP boundary clean. | **CORRECT defer** |
| Stocktake / cycle count | LOW — no current operator demand; would require physical-count UI + variance reporting. Different mental model (replace, not increment). | **CORRECT defer.** Note: the *initial-reset* feature is conceptually adjacent to stocktake — call out the distinction in operator docs. |
| Multi-currency conversion | None — qty writes are unit-count integers. Price columns are display/audit only. | **CORRECT defer** |
| Email notifications on import results | LOW — operator stays in backend, sees results immediately. **Mild risk** if multi-operator and one applies async without others knowing. Mitigation: keep audit page sortable by recent. | **CORRECT defer** for v1; revisit at UAT. |

---

### Differentiators (Set Good GRN Systems Apart)

Features that elevate a GRN tool from "functional" to "operator-loved."

| Feature | Value Proposition | Complexity | Recommended for v1? |
|---------|-------------------|------------|---------------------|
| **Line-level qty override at preview** | Distributor delivers 10 but ships 9 (damaged-in-transit); operator must adjust before apply without rejecting whole invoice | MEDIUM | **YES — v1.** Cheap addition: editable qty input per line in preview table; persist override + reason on `InvoiceLine.override_qty`, `override_reason`. Audit trail records both original and applied qty. |
| **Pre-parse duplicate detection on upload** | Reject duplicate at upload (filename or invoice_number guess from filename) before consuming parse cycles + DB rows | LOW | **YES — v1.** Already implied by idempotency, but expose as upfront UX: filename-pattern check during multi-file upload, warn before submitting. |
| **Original HTM file archive (download from audit)** | Disputes / audits / re-parse forensics | LOW | **YES — v1.** Listed as table-stakes addition above. |
| **Dry-run preview without persistence** | Operator can test on a sample invoice without committing parse rows to DB (useful when validating new distributor format) | MEDIUM | **YES — v1.** Add `?preview_only=1` flag — parses to in-memory DTO, renders preview, never writes Invoice/InvoiceLine rows. Operator clicks "Save & Apply" or "Discard". |
| **Console command: recompute active flags from current stock** | Operations: "we changed the auto-deactivate setting, reconcile existing offers" | LOW | **YES — v1.** Already in PROJECT.md (`goodsreceived:recompute_active_from_stock`). |
| **Per-site automation toggles (auto-deactivate / auto-activate / allow-reset)** | Each market has different policies (.no may want auto-deactivate; .lv may not) | MEDIUM | **YES — v1.** Already in PROJECT.md. Genuine differentiator. |
| **EAN match strategy column in preview + audit** | Operator can spot "matched on product (fallback)" lines and validate manually if uncertain | LOW | **YES — v1.** Already implied; surface explicitly in UI. |
| **Per-import summary metric (units added, offers touched, value implied)** | Operator gets one-glance confirmation; disputes catch "I added 5 but invoice was 50" mistakes | LOW | **YES — v1.** Cheap: aggregate query on `InvoiceLine` after apply, display on audit page row. |
| Supplier-keyed defaults (default match strategy, default unit conversion) | Multi-supplier operations | HIGH | **NO — defer to v2.** Currently single distributor (`_no_`); adding supplier model is premature abstraction. Re-evaluate if .lv or .lt onboards a different distributor. |
| Photo attachment of paper receipt | Reconciliation against physical paper | LOW | **NO — defer.** Distributor already provides HTM digitally; paper receipt redundant. Adds File model + storage cost. |
| Auto-detect duplicate by content hash (not just invoice_number) | Catches re-uploads with edited invoice_number | LOW | **NO — defer.** Current operators are trusted; invoice_number from HTM body is reliable. Add only if abuse observed. |
| Retroactive cost averaging (FIFO/LIFO/weighted) | Accounting integration | HIGH | **NO — defer.** Requires Cost model on Offer, valuation policy, period-locked accounting. Out of bounded context. |
| Three-way matching (PO / GRN / invoice) | Procurement compliance | HIGH | **NO — defer.** No PO model exists in Shopaholic. Distributor invoices arrive without PO ref. Massive scope explosion. |
| Batch bulk-edit of unmatched lines (assign offer_id to N lines at once) | Speed up resolving 50+ unmatched at once | MEDIUM | **NO — defer to v1.x** if unmatched volume is high in UAT. Single-line edit covers v1. |
| ASN (Advanced Shipping Notice) integration | EDI compliance with large suppliers | HIGH | **NO — defer.** Distributor uses HTM, not EDI. |
| Mobile / handheld scanner UI | Warehouse-floor receiving | HIGH | **NO — defer.** Operators do this at desk; HTM is already digital. Scanner workflow is for physical-count, not GRN. |
| AI-based duplicate invoice detection ([Bluebash](https://www.bluebash.co/services/artificial-intelligence/ai-agents/duplicate-invoice-detection)) | Catches near-duplicates with fuzzed numbers | HIGH | **NO — defer.** Overkill for trusted single distributor. Invoice_number uniqueness covers 99% of cases. |

---

### Anti-Features (Avoid These — They Look Good, Cause Pain)

Features that seem helpful but add complexity without proportional value in this context.

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| **"Reset all stock to zero" button (always available, not one-shot)** | Easy correction tool | Irreversible per [Cin7 stocktake guidance](https://help.core.cin7.com/hc/en-us/articles/9034616083599-Stocktake): items not counted go to zero. Permanent destruction risk if mis-clicked. | **One-shot only**, gated by `allow_initial_reset` setting + typed confirmation ("type RESET to confirm"). Auto-disable setting after first apply. PROJECT.md is correct here — reinforce in UI. |
| **Auto-apply on upload (skip preview)** | "It's faster, my operators trust it" | One bad parse silently corrupts stock for hundreds of offers. No human safety net. Industry standard ([WSI](https://www.wsinc.com/blog/improve-warehouse-receiving-process/)) is to auto-hold inventory until QC clears. | **Always require Apply step**, even for trusted distributors. The 5 seconds saved per invoice doesn't beat the recovery cost of one bad apply. |
| **In-system SKU/EAN mapping UI ("map this EAN to this offer permanently")** | Resolves recurring unmatched lines | Creates a second source of truth alongside `offers.code`. Operators forget which mapping is canonical. Drift accumulates. ([WMS guidance](https://racklify.com/encyclopedia/asn-best-practices-and-common-mistakes-to-avoid/): sync the item master, don't aliasing on top.) | **Edit `offers.code` directly** in Shopaholic backend. Unmatched queue is the prompt to do so. One source of truth. |
| **Real-time webhook to distributor on apply** | "Notify supplier we received their delivery" | Couples internal stock writes to external network. Failures cascade. No business benefit (distributor knows they shipped). | Don't build. If ever needed, build async outbound queue separately. |
| **Stock-level forecasting / reorder suggestions** | "While we're touching stock, why not optimize?" | Different bounded context (demand prediction, not receipt). Mixing concerns. Requires sales velocity model. | Build as separate plugin if business needs it. |
| **Inventory location / bin assignment** | Multi-warehouse tracking | Shopaholic offers don't model location. Adding one ripples to cart, order, shipping. | Out of scope. Single virtual stock pool per site. |
| **Configurable parser (admin uploads parser rules)** | "What if distributor changes HTM format?" | Configurable parsers become unreadable. Format change is a code change with tests; takes 1 hour to ship. | **Hardcoded parser**, version-pinned to current HTM format. Update with migration when format changes. PROJECT.md's `HtmInvoiceParser` is correct. |
| **GraphQL/REST API for external systems to push GRN** | "What if our 3PL wants to integrate?" | YAGNI. No 3PL exists. API surface = test surface = breakage surface. | Backend-only upload UI. Re-evaluate only when concrete external integration request lands. |
| **Per-line approval workflow (multiple approvers)** | "What if we need 4-eyes on stock writes?" | Single trusted operator currently. Adds workflow state machine, notifications, queue. | Single-operator Apply with audit trail. Add 4-eyes only if compliance forces it. |
| **Real-time stock dashboard "live tile"** | Visual feedback | Implies WebSocket / polling infra. Audit page covers post-apply visibility. | Audit history page, sorted by `applied_at DESC`. Refresh button is fine. |

---

## Feature Dependencies

```
[Multi-file upload]
    └──requires──> [Per-file invoice number resolution]
                       └──requires──> [HtmInvoiceParser + InvoiceNumberResolver]

[Apply with idempotency]
    └──requires──> [Invoice header persisted with status]
                       └──requires──> [logingrupa_goods_received_invoices migration]
    └──requires──> [unique constraint on invoice_number]

[Override and re-import]
    └──requires──> [Apply with idempotency]
    └──requires──> [Reversal/void of prior apply OR recorded-in-audit replacement]
        └──MUST DECIDE──> [does override DECREMENT prior added stock first, or APPLY ADDITIONALLY?]

[Initial baseline reset]
    └──requires──> [Settings.allow_initial_reset = true]
    └──requires──> [audit row written before any mutation]
    └──conflicts──> [normal Apply on same invoice run]
        (reset must complete & audit BEFORE first apply, atomic)

[Auto-deactivate on zero]
    └──requires──> [Settings.auto_deactivate_on_zero = true]
    └──requires──> [ActiveFlagService.reconcile() called after every StockApplyService.apply()]

[Auto-activate on stock]
    └──requires──> [Settings.auto_activate_on_stock = true]
    └──requires──> [reconcile detects offer.quantity > 0 AND product.active=false → activate both]

[Console: recompute_active_from_stock]
    └──requires──> [ActiveFlagService is callable outside HTTP context]
    └──enhances──> [Auto-deactivate / auto-activate] (manual reconciliation when settings change)

[Audit history page]
    └──requires──> [Invoice + InvoiceLine models + StockApplyService writing audit fields]
    └──enhances──> [Override and re-import] (operator sees prior apply context)

[Original HTM archive (recommended addition)]
    └──requires──> [storage/app/uploads/invoices/applied/ writable]
    └──enhances──> [Audit history] (download original from audit detail)

[Backend permission for Apply (recommended addition)]
    └──requires──> [Permission registered in Plugin::registerPermissions()]
    └──enhances──> [All Apply flows] (multi-operator safety)

[Line-level qty override at preview (recommended differentiator)]
    └──requires──> [InvoiceLine.override_qty + override_reason columns]
    └──enhances──> [Preview UI] (handle damaged/short delivery)

[Dry-run preview (recommended differentiator)]
    └──requires──> [HtmInvoiceParser is pure (already planned per PROJECT.md)]
    └──enhances──> [Multi-file upload] (validate new distributor format safely)
```

### Dependency Notes

- **Override-and-reimport semantics MUST be decided in plan phase.** PROJECT.md req #4 says "rejected with prior-apply summary AND allows override and re-import" — but doesn't specify whether override **replaces** prior apply (decrement old qty + apply new qty) or **adds on top** (apply new qty additionally, prior qty stays). These produce different stock totals. Recommendation: **override = decrement-then-reapply**, recorded as a new audit row referencing the void. This is the only consistent semantics with "increment, never replace" being the *normal* path.
- **Initial reset must run inside one DB transaction with the first invoice apply OR be its own audited transaction with explicit "RESET" log entry.** PROJECT.md says "logged in audit row" — clarify: is the reset its own Invoice row with `status=reset`, or a flag on the first applied invoice? Recommendation: separate `logingrupa_goods_received_resets` audit table, immutable.
- **Auto-deactivate + auto-activate must be in same `ActiveFlagService` call** to avoid race where reconcile sees offer.quantity=0 mid-apply (before line increments). The service must be called AFTER all `offer.quantity` writes complete in the transaction.
- **Per-site `enabled` setting** controls whether the Settings menu entry shows — recommendation in operator UX section below.

---

## MVP Definition

### Launch With (v1) — All PROJECT.md Active items + 4 additions

#### Already in PROJECT.md Active list (P1 — must have)

- [x] Backend upload page, multi-file `.HTM`
- [x] HtmInvoiceParser extracting all 9 columns
- [x] InvoiceNumberResolver (body → filename → reject)
- [x] Re-upload rejection with prior-apply summary, override-and-reimport flow
- [x] EAN matching: offers.code first, products.code single-offer fallback
- [x] Two-step Parse → Preview → Apply (Larajax)
- [x] Additive stock writes (never replace)
- [x] Unmatched-EAN persistent queue (no separate table — query)
- [x] Per-site Settings: `enabled`, `auto_deactivate_on_zero`, `auto_activate_on_stock`, `allow_initial_reset`
- [x] One-shot initial-reset checkbox + audit log
- [x] Console `goodsreceived:recompute_active_from_stock`
- [x] Settings menu entry only — no top-nav clutter
- [x] Audit history page + per-import detail
- [x] Multi-site (.no/.lv/.lt) per-DB
- [x] Composer-installable from public GitHub repo

#### Recommended additions to v1 (P1 — table stakes or cheap differentiators)

- [ ] **Backend permission `logingrupa.goodsreceived.apply_invoices`** — Buddies-compatible role gating on Apply handler. **Why:** stock writes need access control; ungated = any backend user mutates stock. Cost: ~30 lines in Plugin.php + permission check in controller.
- [ ] **Original HTM file archive** — store uploaded HTM in `storage/app/uploads/invoices/applied/<invoice_number>.htm`; expose download link in audit detail. **Why:** disputes with distributor, parse forensics. Cost: 1 file write + 1 download route.
- [ ] **Line-level qty override at preview** with reason field — editable qty input per line, persisted on `InvoiceLine.override_qty`/`override_reason`. **Why:** real-world delivery short/damage handling without rejecting whole invoice. Cost: 2 columns + preview form input + audit display.
- [ ] **Pre-parse duplicate detection on upload** — filename-pattern check warns operator before submitting if `Nr_PRO<num>` matches an already-applied invoice. **Why:** saves operator time, prevents wasteful parse-then-reject cycle. Cost: ~20 lines on upload handler.
- [ ] **Per-import summary metric on audit row** — total units added, offers touched, units-per-offer breakdown. **Why:** one-glance verification, spot mistakes. Cost: aggregate query on `InvoiceLine` after apply, denormalize on Invoice header.
- [ ] **Match strategy column in preview + audit** — surface "matched offer" vs "matched product (single offer fallback)" so operator can verify. **Why:** trust through transparency. Cost: rendering only (data already on `InvoiceLine.match_strategy`).
- [ ] **Apply confirmation modal** showing total units + offer count to be mutated. **Why:** last safety net before destructive write. Cost: October backend modal partial.
- [ ] **Initial-reset confirmation: typed "RESET" string** before button enables. **Why:** stock-zeroing is irreversible per [Cin7 stocktake guidance](https://help.core.cin7.com/hc/en-us/articles/9034616083599-Stocktake). Cost: small Larajax handler.

### Add After Validation (v1.x — triggered by UAT signal)

- [ ] **Email notification on apply success/failure** — trigger: multi-operator UAT reveals coordination issues
- [ ] **Bulk-edit unmatched lines (assign offer_id to N at once)** — trigger: unmatched volume > 20 lines/import on average
- [ ] **CSV export of audit history** — trigger: accountant requests reporting feed
- [ ] **Filter audit history by date range / operator / status** — trigger: history exceeds 100 rows and Ctrl-F becomes painful
- [ ] **Auto-archive old applied HTM files (compress / move to cold storage)** — trigger: storage volume becomes operational concern

### Future Consideration (v2+)

- [ ] **Supplier model + per-supplier defaults** — defer until second distributor onboards
- [ ] **Multi-warehouse / location tracking** — defer until business adds physical locations to stock model (currently single virtual pool)
- [ ] **Stocktake / cycle count UI** — separate plugin, separate mental model (replace not increment)
- [ ] **Three-way matching (PO/GRN/invoice)** — defer until purchase order workflow exists in Shopaholic
- [ ] **Mobile / scanner UI** — defer until physical-floor receiving workflow exists (currently desk-only)
- [ ] **API for external system push** — defer until concrete integration request

---

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| Multi-file upload | HIGH | LOW | **P1** |
| HTM parser | HIGH | MEDIUM | **P1** |
| Two-step Parse → Preview → Apply | HIGH | MEDIUM | **P1** |
| Idempotency lock | HIGH | MEDIUM | **P1** |
| Unmatched persistent queue | HIGH | LOW | **P1** |
| EAN match strategy (offer → product fallback) | HIGH | MEDIUM | **P1** |
| Per-site automation settings | HIGH | MEDIUM | **P1** |
| Audit history + per-import detail | HIGH | MEDIUM | **P1** |
| One-shot initial reset (gated, audited) | MEDIUM | MEDIUM | **P1** (per-site demand) |
| Console reconciliation command | MEDIUM | LOW | **P1** |
| Override-and-reimport flow | MEDIUM | MEDIUM | **P1** (PROJECT.md req) |
| Backend permission for Apply | HIGH | LOW | **P1 (add)** |
| Original HTM archive | MEDIUM | LOW | **P1 (add)** |
| Line-level qty override | HIGH | MEDIUM | **P1 (add)** |
| Pre-parse duplicate detection | MEDIUM | LOW | **P1 (add)** |
| Per-import summary metric | MEDIUM | LOW | **P1 (add)** |
| Match strategy column visible | MEDIUM | LOW | **P1 (add)** |
| Apply confirmation modal | HIGH | LOW | **P1 (add)** |
| Typed RESET confirmation | HIGH | LOW | **P1 (add)** |
| Dry-run preview without persistence | MEDIUM | MEDIUM | **P2** |
| Email notifications | LOW (currently) | MEDIUM | **P2** |
| Bulk-edit unmatched | MEDIUM (volume-dependent) | MEDIUM | **P2** |
| CSV export of audit | LOW | LOW | **P2** |
| Supplier model + defaults | LOW (single distributor) | HIGH | **P3** |
| Photo attachment | LOW | LOW | **P3** |
| Multi-warehouse | LOW | HIGH | **P3** |
| Three-way matching | LOW | HIGH | **P3** |
| Mobile/scanner UI | LOW | HIGH | **P3** |
| ASN/EDI integration | LOW | HIGH | **P3** |
| Cost averaging | LOW | HIGH | **P3** |

---

## Operator UX — Domain-Specific Recommendations

### Per-site `enabled` toggle interaction

**Question:** When `Settings.enabled=false`, what does the operator see?

**Recommendation:** **Hide the Settings sub-menu entry entirely** when disabled, EXCEPT for the Settings page itself (so operators can re-enable). Showing greyed-out "feature off" entries clutters the menu and confuses operators across sites who don't need GRN at all (e.g., if .lt operates differently).

**Rationale:** The plugin is deployed identically to all three sites; per-site enable lets each market opt in/out without code branching. The Settings model's October Backend menu entry should be conditional in `registerNavigation()` based on the cached `Settings::get('enabled')`. This is a 5-line check and matches how `octobercms` toggles non-essential plugin nav.

**Edge case:** If a backend user has `BACKEND_URI/settings/logingrupa/goodsreceivedshopaholic/settings` bookmarked, they can still reach Settings page when disabled. This is correct — they need to enable it.

### One-shot baseline reset (zero-all + deactivate-all)

**Standard practice?** **Unusual but legitimate** for this specific use case. [Standard stocktake](https://help.core.cin7.com/hc/en-us/articles/9034616083599-Stocktake) expects physical count entry per item; resetting-to-zero-then-applying-invoice is a specialized "I'm starting over" workflow that maps to first-time GRN deployment in a store with unreliable existing stock data.

**Risks (HIGH severity):**
1. **Irreversibility**: per [Cin7 docs](https://help.core.cin7.com/hc/en-us/articles/9034616083599-Stocktake), Zero All is irreversible. Operator who clicks by mistake destroys the entire stock baseline.
2. **Auto-deactivation cascade**: combined with `auto_deactivate_on_zero`, the reset will `product.active=false` for the entire catalog — products vanish from storefront immediately. This is correct intent but catastrophic if accidental.
3. **Multi-operator confusion**: operator A enables `allow_initial_reset` for legitimate use; operator B uploads a routine invoice and accidentally checks the reset box. Stock destroyed.

**Mitigations (recommended for v1):**
1. **Typed confirmation**: button enables only after operator types `RESET` in a confirmation field (not just a checkbox).
2. **Auto-disable setting after use**: `Settings.allow_initial_reset = true` flips back to `false` automatically after the first reset is applied. Re-enabling requires explicit Settings save (audit-logged).
3. **Audit row before mutation**: write audit row with `before_state` (offer count, total units, active count) BEFORE the destructive operation. If it crashes mid-way, audit shows what was lost.
4. **Idempotency**: reset itself should be idempotent — running twice produces same result. Combined with `allow_initial_reset` auto-disable, this means operator can't accidentally double-reset.
5. **Display warning banner** on Apply screen when initial-reset is checked, summarizing exactly what will be zeroed and how many products will deactivate.

### Override-and-re-import flow (already-applied invoice)

**Common pattern?** **Uncommon in WMS/3PL** — most systems treat applied invoices as immutable and require an *adjustment* document instead. However, in a small-operator context with a trusted single distributor and no external accounting integration, override is pragmatic.

**UX recommendations:**
1. **First page on re-upload of applied invoice**: read-only summary of prior apply (timestamp, applying user, units added per offer, link to download original HTM). NO action button visible yet.
2. **Explicit "Override and re-import" button** below the summary, not inline. Requires permission `logingrupa.goodsreceived.override_invoices` (separate from regular apply permission).
3. **Confirmation modal** with typed "OVERRIDE" string, similar to RESET confirmation.
4. **Override semantics decision (CRITICAL — must be in plan phase):**
   - **Option A (recommended): Decrement-then-reapply.** Decrement prior `applied_qty` from each offer, then apply new qty. Net result: stock = pre-original + new_qty. Audit trail records two events: void + new apply. Only consistent with "increment never replace" being the *normal* path.
   - **Option B: Add-on-top.** Skip decrement, apply new qty additively. Result: stock = pre-original + original_qty + new_qty. Means re-uploading same invoice doubles stock. **DO NOT** use this — it's the bug idempotency was supposed to prevent.
   - **Option C: Hard replace.** Set offer.quantity to a target value. Requires "what is the target" UX. **DO NOT** use this — violates "increment, never replace" invariant.
5. **Audit trail must show both prior and new apply**, with override reason captured (free-text field, required).

### Single vs multi-operator concurrency

**Current reality:** likely 1-2 operators per site, low concurrency. Per [pessimistic vs optimistic locking](https://medium.com/@abhirup.acharya009/managing-concurrent-access-optimistic-locking-vs-pessimistic-locking-0f6a64294db7), low-contention workloads favor optimistic (no explicit lock).

**Recommendations:**
1. **No invoice lock during preview.** If two operators preview the same invoice (rare), the first to click Apply wins (idempotency rejects the second). This is acceptable.
2. **Use DB transaction on Apply** to serialize stock writes (`StockApplyService::apply` wraps in `DB::transaction`). Existing PROJECT.md plan already has this.
3. **Optimistic check at apply time**: before persisting `invoice.status = 'applied'`, re-check `WHERE invoice.status = 'parsed'` in the UPDATE. If 0 rows updated, another operator already applied — show error.
4. **Audit row on `applied_by_user_id`** prevents confusion about which operator did what.
5. **DO NOT build pessimistic invoice lock UI** ("Operator X is editing this invoice, wait"). Adds complexity, locks orphan if operator closes tab. YAGNI for this scale.

### Notification preferences (deferred)

**Is deferring email OK?** **YES for v1.** Reasoning:
- Operators stay in backend during the import workflow. Apply result is shown immediately on screen.
- Audit history page provides post-hoc visibility.
- Distributor delivery is not time-critical to notify (it's already arrived).
- Email infrastructure exists in October but adds testing surface.

**When to revisit:** UAT signals (a) operator misses async apply by another operator, (b) management wants daily summary digest, (c) compliance requires email-of-record for audit.

### Mobile workflow needs

**Operators usually on desktop?** **YES.** This is a backend-admin desk task, not warehouse-floor. The HTM file arrives by email/portal, operator downloads to laptop, uploads via backend. No scanner workflow.

**Recommendation:** **No mobile UX investment in v1.** OctoberCMS backend is responsive enough for emergency tablet access. If field operators ever need a scanner-driven inbound workflow, that's a *physical* receiving feature (different mental model — line-by-line scan validation, not file upload) — separate plugin.

---

## Competitor / Reference Feature Analysis

| Feature | Shopify Stocky | Cin7 / DEAR | NetSuite | Our Approach |
|---------|----------------|-------------|----------|--------------|
| File-upload GRN | Yes (CSV) | Yes (CSV/XML) | Yes (CSV/XML) | HTM-specific (distributor format), Multi-file |
| Per-line preview | Yes | Yes | Yes | Yes (preview-then-apply, Larajax) |
| Idempotency by document number | Implicit | Yes | Yes | Yes (unique invoice_number constraint) |
| Unmatched SKU queue | Yes | Yes | Yes | Yes (passive — `WHERE matched_offer_id IS NULL`) |
| In-system SKU mapping | Yes (alias table) | Yes | Yes | **NO** — edit `offers.code` directly (single source of truth) |
| Stocktake / zero-all | Yes (irreversible warning) | Yes (irreversible warning) | Yes | Yes — one-shot only, gated, typed confirmation |
| Three-way matching (PO/GRN/invoice) | No | Yes | Yes | **NO** — out of scope (no PO model) |
| Cost averaging | No | Yes | Yes | **NO** — out of scope |
| Multi-location | Yes | Yes | Yes | **NO** — single virtual pool per site |
| Mobile/scanner | Yes | Yes | Yes | **NO** — desk-only |
| Audit history | Yes | Yes | Yes | Yes (per-import detail page) |
| Per-line qty override | Yes | Yes | Yes | **YES — recommended v1 add** |
| Per-site automation toggles | N/A (single site) | N/A | N/A | **YES — our differentiator** (multi-site .no/.lv/.lt) |

**Our differentiation strategy:** Don't compete with Shopify/Cin7/NetSuite on feature breadth. Compete on **fit to bounded context** — small-operator multi-site with single distributor, integrated into existing OctoberCMS/Shopaholic ecosystem, no separate WMS license, no separate ledger system. The per-site automation toggles + the bounded-scope refusal to build cost averaging / three-way matching / multi-location ARE the value proposition.

---

## Open Questions for Plan Phase (must resolve before coding)

1. **Override-and-reimport semantics:** decrement-then-reapply vs add-on-top vs hard-replace. **Recommendation: decrement-then-reapply.**
2. **Initial reset audit storage:** dedicated `logingrupa_goods_received_resets` table vs flag on first applied invoice. **Recommendation: dedicated table.**
3. **`Logingrupa.ExtendShopaholic` reuse for `ImportLoggerService`:** soft dependency vs vendor-inline. **Lean: vendor-inline (~50 LOC) to avoid coupling new plugin to legacy plugin's release cadence.**
4. **Backend permission granularity:** single `apply_invoices` permission vs split (`upload`, `apply`, `override`, `reset`). **Recommendation: split into 4 permissions** — defaults grouped under one role to keep operator UX simple, but separable for compliance edge cases.
5. **HTM file archive retention:** keep forever vs auto-archive after N months. **Recommendation: keep forever in v1 (~1KB per file × hundreds of invoices = trivial).** Revisit at v1.x if storage matters.
6. **Settings caching:** October Settings model is cached per-process — confirm cache invalidation on Settings save. **Action: write integration test for settings change → ActiveFlagService picks up new value within same request cycle.**
7. **Currency context:** PROJECT.md says "qty-only writes; price columns parsed for audit." Confirm price columns persist on `InvoiceLine` (not silently dropped) so audit can verify against future disputes. **Recommendation: persist `unit_price`, `discount`, `line_price`, `total` columns on `InvoiceLine`; never read in business logic.**

---

## Sources

- [Zycus — Goods Received Note (GRN) guide](https://www.zycus.com/blog/source-to-pay/goods-received-note-procurement) — GRN purpose, three-way matching, automation
- [HighRadius — GRN importance & best practices](https://www.highradius.com/resources/Blog/goods-received-note/) — operator workflow, system integration
- [Spendflo — What is a GRN](https://www.spendflo.com/blog/what-is-a-goods-received-note) — fields, format
- [Manifestly — GRN accuracy checklist](https://www.manifest.ly/blog/how-to-create-an-accurate-goods-received-note-grn/)
- [WareIQ — GRN explained](https://wareiq.com/resources/blogs/goods-received-note-grn/) — process flow
- [Hopstack — Warehouse receiving best practices](https://www.hopstack.io/blog/improve-the-warehouse-receiving-process) — exception workflow, auto-hold
- [Racklify — ASN best practices](https://racklify.com/encyclopedia/asn-best-practices-and-common-mistakes-to-avoid/) — SKU mismatches, item master sync
- [WSI — Warehouse receiving for fulfillment](https://www.wsinc.com/blog/improve-warehouse-receiving-process/) — count errors, QC hold
- [PackageX — 10 inbound receiving mistakes](https://packagex.io/blog/inbound-receiving-process)
- [Folio3 — Ecommerce inventory mistakes](https://ecommerce.folio3.com/blog/ecommerce-inventory-management-common-mistakes-and-fixes/)
- [Mercury — 8 most common inventory mistakes](https://mercury.com/blog/common-inventory-mistakes-ecommerce)
- [Unleashed — 8 ecommerce inventory mistakes](https://www.unleashedsoftware.com/blog/3-common-problems-ecommerce-inventory-management/) — over-customization warning
- [Cin7 Core — Stocktake guidance](https://help.core.cin7.com/hc/en-us/articles/9034616083599-Stocktake) — irreversibility of zero-all, baseline reset risk
- [Shopify — Stocktakes (Stocky)](https://help.shopify.com/en/manual/sell-in-person/shopify-pos/inventory-management/stocky/inventory-management/stocktakes)
- [Sumtracker — Stocktake step-by-step](https://www.sumtracker.com/blog/how-to-do-a-stocktake-the-complete-step-by-step-guide)
- [Clear.tech — Duplicate invoices: causes and prevention](https://www.clear.tech/blog/duplicate-invoices-why-it-happens-and-how-to-prevent-it)
- [Precoro — Duplicate invoices: detection & prevention](https://precoro.com/blog/what-are-duplicate-invoices/)
- [Stripe — Idempotency for preventing duplicate payments](https://stripe.com/resources/more/how-to-prevent-duplicate-payments) — idempotency key pattern, applicable to invoice_number
- [Simplico — Idempotency in payment APIs](https://simplico.net/2026/04/04/idempotency-in-payment-apis-prevent-double-charges-with-stripe-omise-and-2c2p/) — invoice_number-based idempotency precedent
- [Bluebash — AI duplicate invoice detection](https://www.bluebash.co/services/artificial-intelligence/ai-agents/duplicate-invoice-detection) — over-engineering reference (avoided)
- [Medium / Acharya — Optimistic vs pessimistic locking](https://medium.com/@abhirup.acharya009/managing-concurrent-access-optimistic-locking-vs-pessimistic-locking-0f6a64294db7) — concurrency choice
- PROJECT.md — `/home/forge/nailscosmetics.lv/plugins/logingrupa/goodsreceivedshopaholic/PROJECT.md`
- CLAUDE.md (plugin) — `/home/forge/nailscosmetics.lv/plugins/logingrupa/goodsreceivedshopaholic/CLAUDE.md`
- CLAUDE.md (project) — `/home/forge/nailscosmetics.lv/CLAUDE.md`

---
*Feature research for: GRN/inbound stock import in Lovata Shopaholic e-commerce admin*
*Researched: 2026-04-29*
