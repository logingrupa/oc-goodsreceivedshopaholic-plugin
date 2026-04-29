# Pitfalls Research — GoodsReceivedShopaholic

**Domain:** Distributor GRN import for Lovata Shopaholic on multi-site OctoberCMS v4
**Researched:** 2026-04-29
**Confidence:** HIGH on parser/cache/Offer-quantity findings (verified against fixtures + Lovata source); MEDIUM on race-condition recovery patterns (no production telemetry); LOW on operator-misclick UX (no UAT data yet).

Scope: pitfalls specific to ADDING this plugin to *this* codebase (multi-site .no/.lv/.lt with separate DBs, ExtendShopaholic 1C XML import already writing Offer fields, CampaignpricingShopaholic Phase 3 still parked, Lovata cache cascade on every Offer save). Generic Laravel/PHP pitfalls excluded.

Verified evidence sources used throughout:
- `plugins/lovata/shopaholic/models/Offer.php` lines 540–550 — `setQuantityAttribute` int-cast + clamp-to-zero behavior
- `plugins/lovata/shopaholic/classes/event/offer/OfferModelHandler.php` — cache cascade fired on every save
- `storage/app/uploads/invoices/Nr_PRO033328_no_13042026.HTM` — UTF-8 BOM, CRLF, **unquoted** `CLASS=R20` attributes, decimal comma
- `storage/app/uploads/invoices/Nr_PRO026712_no_28112024.HTM` — confirms R21 alternating row class exists in real data
- `plugins/logingrupa/extendshopaholic/classes/import/ImportOfferModelFromXML.php` — uses `LoginGrupa\` (old casing) and **replaces** `quantity`, not increments
- `plugins/logingrupa/extendshopaholic/Plugin.php` namespace `LoginGrupa\` vs `Logingrupa\` (this plugin) — case mismatch on Linux PSR-4
- `modules/system/models/SettingModel.php:217-235` — settings cache key includes site context only when `MultisiteInterface` implemented

---

## Critical Pitfalls

### Pitfall 1: `setQuantityAttribute` silently clamps the additive write to zero

**What goes wrong:**
`StockApplyService` reads current `offer.quantity = 0`, parses HTM line qty `5`, writes `$obOffer->quantity = 0 + 5 = 5`. So far so good. But on a *negative* read-back (e.g. an admin set `-3` manually with `allow_negative_offer_quantity` on, then it's switched off mid-import) the additive write becomes `-3 + 5 = 2` — but `setQuantityAttribute` clamps any non-positive *result* of the float→int chain to 0 silently when the global setting `allow_negative_offer_quantity` is false. Worse: assigning `$obOffer->quantity = $iCurrent + $iLineQty` where the right-hand side is float (because `$iLineQty` was parsed from `"5,12"` and the comma normalizer is missing) is cast to `(int)5` losing 0.12 of stock per line.

**Why it happens:**
Three layers conspire — (1) HTM decimal comma parsing in PHP `(float)"5,12"` silently returns `5.0`; (2) `Offer::setQuantityAttribute` int-casts on assign; (3) the silent clamp at line 545 has no exception, just a value mutation. No log, no trace. Operator sees "Apply succeeded, 100 lines applied" with quietly truncated stock.

**How to avoid:**
- `StockApplyService::apply()` MUST treat `quantity` as integer end-to-end. Reject any HTM line where the qty cell does not match `^\d+$` after stripping whitespace. If a future supplier ships decimal qty, that's a feature decision, not a parser quietly truncating.
- Decimal-comma normalizer (`HtmInvoiceParser::normalizeNumeric()`) is for *price* columns only; qty goes through a separate `parseQuantity(string): int` that throws on non-integer input.
- Test name (mandatory, must exist before merge): `tests/unit/HtmInvoiceParser/RejectsDecimalQuantityTest.php` — feeds a synthetic row with `<TD>5,12</TD>` in the qty column and asserts the parser raises `InvalidQuantityException`, NOT silently truncates.
- Test name: `tests/unit/StockApplyService/PreservesNegativeStockWhenSettingAllowsTest.php` — primes an Offer at `-3`, applies +5, asserts final quantity is `2`, NOT `5` (i.e. proves we read-modify-write, not blind-assign).
- Guard rail in `StockApplyService::apply()`: `assert($iLineQty > 0, 'qty must be positive integer')` per Tiger-Style positive-space invariant from PROJECT.md line 78.

**Warning signs:**
Audit log shows `units_added` ≠ Σ(line.qty) for any invoice. Operator complaint that "stock looks lower than the delivery note." A `Log::warning` from `setQuantityAttribute` would help — currently there is none, so add one in our service before calling save.

**Phase to address:** Parser phase (must reject decimal qty); StockApply phase (must use read-modify-write with explicit cast at boundary, not at Eloquent setter).

**Severity:** HIGH — silent stock loss compounds invisibly across imports.

---

### Pitfall 2: Per-save cache cascade turns one apply into N×6 cache clears

**What goes wrong:**
Every `$obOffer->save()` triggers `OfferModelHandler::afterSave()` → `checkActiveField()` → `clearProductActiveList()` + `OfferListStore::active->clear()` + `clearProductSortingByPrice()` + `clearProductItemCache()` + `clearCachedListBySite()` per enabled site (3 in our case). A 200-line invoice = ~3,600 cache-key invalidations during apply. During that window, storefront product-list requests miss cache, hit DB, slow page paint to 2-5s. On nailscosmetics.no during business hours this is visible.

**Why it happens:**
Lovata's cache invalidation is correct *per save event* but assumes saves are sparse (admin editing one offer). Bulk import patterns are not in scope for the upstream design. Naive `foreach ($arLines as $obLine) { $obOffer->save(); }` triggers the full cascade per offer.

**How to avoid:**
- Wrap apply in `DB::transaction()` AND use `$obOffer->saveQuietly()` for the quantity update so model events do not fire mid-batch. Then, after commit, fire ONE batch cache flush via direct calls to `OfferListStore::instance()->active->clear()` + `ProductListStore::instance()->active->clear()` — surgical, not per-row.
- Active-flag flip happens AFTER the quiet quantity save, in `ActiveFlagService::reconcile($arOfferIdList)`, also using `saveQuietly()` then a single batched cache-flush.
- Test name: `tests/integration/StockApplyService/EmitsBatchedCacheFlushOnceTest.php` — spy `OfferListStore::instance()->active` clear count, apply 50-line invoice, assert clear() was called ≤ 2 times not 50.
- Test name: `tests/integration/StockApplyService/DoesNotFireOfferModelHandlerPerLineTest.php` — register a counting listener on `eloquent.saved: Offer`, apply 20 lines, assert listener call count ≤ 1 (or 0 if `saveQuietly` is used).
- Document the contract in StockApplyService docblock: "Caller's responsibility — after `apply()` returns, the cascade is replaced by `flushBatch()`. If you bypass this and use raw `Offer::save()` instead, expect storefront latency."

**Warning signs:**
APM (if any) shows storefront p95 latency spike during apply windows. Cache MISS rate on `OfferList`/`ProductList` keys correlates with apply timestamps in the audit log.

**Phase to address:** StockApply phase. ActiveFlagService phase must follow the same contract.

**Severity:** HIGH — directly hurts customer-facing latency on the primary site (.no) during business-hour imports.

---

### Pitfall 3: Namespace casing collision between `LoginGrupa\` (legacy) and `Logingrupa\` (new)

**What goes wrong:**
ExtendShopaholic ships `LoginGrupa\ExtendShopaholic\Traits\ImportLoggingTrait` (capital G). New plugin namespace is `Logingrupa\GoodsReceivedShopaholic` (lowercase g). On Linux (case-sensitive PSR-4), `use LoginGrupa\ExtendShopaholic\Traits\ImportLoggingTrait;` works, `use Logingrupa\ExtendShopaholic\Traits\ImportLoggingTrait;` does not. PROJECT.md soft-dependency option "reuse `ImportLoggerService` + `ImportLoggingTrait`" hits this immediately. Local dev on macOS (case-insensitive HFS+/APFS by default) won't reproduce — bug only appears on Forge production.

**Why it happens:**
Two-decade-old Lovata-style PHP plugins predate PSR-4 strict casing enforcement. ExtendShopaholic was authored under the wrong casing and never migrated. New plugin correctly uses `Logingrupa\`. Composer autoloader treats them as different namespaces.

**How to avoid:**
- **Vendor-inline decision (D-recommended):** copy `ImportLoggingTrait` and `ImportLoggerService` into `Logingrupa\GoodsReceivedShopaholic\Traits\` rather than declaring a soft dependency. ~150 LoC duplication, but no casing trap, no cross-plugin coupling, and SRP (we own our import logging).
- If soft-dependency MUST be used: declare `"logingrupa/extendshopaholic-plugin": "*"` in composer.json + import as `LoginGrupa\` (yes, with the wrong casing) + add a phpstan suppression with comment explaining the legacy mismatch + a CI check that fails if the import is ever auto-corrected.
- Test name: `tests/unit/ImportLoggingTraitResolutionTest.php` — uses `class_exists(\Logingrupa\GoodsReceivedShopaholic\Traits\ImportLoggingTrait::class, true)` to assert the vendored class is autoloadable from THIS plugin's namespace, NOT from `LoginGrupa`.
- CI gate: `make analyse` on Linux runner catches this; do NOT trust local macOS runs.

**Warning signs:**
Class-not-found in production but not local dev. PHPStan green locally, red on CI. Composer dump-autoload regenerates classmap and breaks production but not staging if staging runs OPcache differently.

**Phase to address:** Scaffold phase / composer config phase — decide vendor-inline vs soft-dep as part of plugin bootstrap, not later.

**Severity:** HIGH — production-only bug class. Slipping past local QA.

---

### Pitfall 4: Idempotency-by-invoice-number alone misses "same number, different content"

**What goes wrong:**
PROJECT.md D8 contract: unique constraint on `invoice_number` rejects duplicates. But operator workflow: "I edited the HTM in Excel, fixed a row, re-uploaded with same invoice number." Plugin rejects as duplicate, says "applied at $timestamp by $user, total units $N" — but the rejected file had a corrected qty for line 7. Operator now thinks the corrected stock IS in the system, but actually the original wrong qty is in the system. Worse: if we open an "override and re-import" path (per PROJECT.md line 22 active requirement), the apply runs *additively* on top of the original apply — double-counting all unchanged lines, not just correcting line 7.

**Why it happens:**
Idempotency is a function of (invoice_number, content_hash), not invoice_number alone. The "additive" semantic of the import (stock += line.qty) makes the override path dangerous: re-applying without first reversing the prior apply double-writes everything.

**How to avoid:**
- Schema: add `content_hash` column (sha256 of normalized parsed lines, sorted by row#). On re-upload, compare both:
  - Same number + same hash → "already applied" reject (current behavior, correct).
  - Same number + different hash → "content drift detected" — show diff in preview (added/removed/changed lines) and require explicit operator confirmation per CHANGED line. Override clones a NEW invoice row with `supersedes_invoice_id` FK and applies ONLY the delta (line.qty_new - line.qty_old) per offer.
- Test name: `tests/integration/StockApplyService/ReUploadSameNumberDifferentContentDetectsDriftTest.php` — apply invoice A (line 7 qty=5), upload A' (line 7 qty=7), assert plugin shows diff and does NOT silently accept either path.
- Test name: `tests/integration/StockApplyService/OverrideAppliesDeltaNotSumTest.php` — after override, assert offer.quantity reflects 7 net, not 5+7=12.
- Audit row for override path MUST include link to superseded invoice + per-line delta.

**Warning signs:**
Operator reports "I corrected the file but stock didn't change" or "I re-uploaded and stock doubled." Audit log shows two invoice rows with same `invoice_number` but different content hashes.

**Phase to address:** Schema/migration phase (add content_hash column from day 1) + StockApply phase (delta-application logic) + UI phase (diff preview).

**Severity:** HIGH — two distinct silent-corruption modes (under-count if reject-only, double-count if naive override).

---

### Pitfall 5: Apply transaction crashes mid-flight leaving `invoice.status='applied'` with partial line writes

**What goes wrong:**
```php
DB::transaction(function() {
    $obInvoice->status = 'applied'; $obInvoice->save();   // line 1 of txn
    foreach ($arLines as $obLine) {
        $obOffer->quantity += $obLine->qty;
        $obOffer->save();
        $obLine->applied = true; $obLine->save();
    }
});
```
If line 17/100 throws (offer deleted concurrently, deadlock, OOM), the transaction rolls back ALL writes including `invoice.status`. So far OK. But if developer (forgivably) splits the writes to avoid Pitfall 2's cache cascade, putting `$obInvoice->status='applied'` OUTSIDE the loop transaction OR in a separate transaction OR forgetting to wrap at all, partial state lands in DB. On re-upload, idempotency check sees `status='applied'` and refuses. Operator stuck — invoice marked done, only 16/100 stock added.

**Why it happens:**
The "split for cache reasons" refactor and the "all-or-nothing" requirement are in tension. Easy to get wrong if not enforced by code structure.

**How to avoid:**
- `StockApplyService::apply()` MUST be a single `DB::transaction()` from start to finish. Within it: `saveQuietly()` for all Offer writes (per Pitfall 2), set `invoice.status='applied'` LAST, commit. Cache flush happens AFTER commit, OUTSIDE the transaction (cache writes are not transactional anyway).
- If the line count exceeds a threshold (configurable, default 500), chunk into sub-transactions and use a `invoice.status='applying'` interim state. Each chunk advances `invoice.lines_applied_count`. On crash mid-chunk, recovery command picks up where left off.
- Test name: `tests/integration/StockApplyService/PartialFailureRollsBackEverythingTest.php` — inject a failure at line 17/100 (e.g. delete an offer mid-loop via DB facade), assert NO offer.quantity changed, invoice.status reverts to 'parsed', no InvoiceLine.applied=true persists.
- Test name: `tests/integration/StockApplyService/AppliedStatusSetLastInTransactionTest.php` — read SQL log, assert UPDATE on `invoices.status='applied'` is the final write before COMMIT.
- Add `goodsreceived:recover_partial_apply` console command for the chunked-apply path.

**Warning signs:**
Audit row with `status='applied'`, `lines_applied_count < lines_total_count`. Storefront stock < expected total. Re-upload rejected with "already applied" but operator insists it never finished.

**Phase to address:** StockApply phase. Recovery command in a follow-on phase if chunking is needed.

**Severity:** HIGH — corrupts the idempotency contract (Pitfall 4 layered on top makes it worse).

---

### Pitfall 6: HTM parser using `DOMDocument::loadXML()` instead of `loadHTML()` chokes on real fixtures

**What goes wrong:**
Sample HTMs use `<TR CLASS=R20>` (UNQUOTED attribute value — verified in `Nr_PRO033328_no_13042026.HTM` line 341). `DOMDocument::loadXML()` rejects this as malformed. `simplexml_load_string()` rejects it. Only `DOMDocument::loadHTML()` (HTML5-tolerant parser) accepts it — and that emits warnings to stderr / log unless wrapped in `libxml_use_internal_errors(true)` + `libxml_clear_errors()`. UTF-8 BOM (verified via `file` command on the same fixture) confuses `loadHTML` into encoding the BOM as `Â?` characters in product names unless explicitly stripped before parse.

**Why it happens:**
Lovata's existing XML import (`AbstractImportModelFromXML`) uses `simplexml`, which would not work here. Developer instinct says "HTM is HTML, use DOMDocument" but forgets the BOM strip and warning-suppression idiom required for tag-soup HTM.

**How to avoid:**
- `HtmInvoiceParser::parse(string $sHtm)` MUST: (1) strip UTF-8 BOM with `ltrim($sHtm, "\xEF\xBB\xBF")`; (2) `libxml_use_internal_errors(true)` before load; (3) use `DOMDocument::loadHTML($sHtm, LIBXML_NOERROR | LIBXML_NOWARNING)`; (4) collect any libxml errors of severity ≥ `LIBXML_ERR_ERROR` and reject parse with line numbers; (5) `libxml_clear_errors()` in `finally`.
- DO NOT use `mb_convert_encoding($sHtm, 'HTML-ENTITIES', 'UTF-8')` (deprecated in PHP 8.2, removed-behavior pending). Use `DOMDocument::loadHTML($sHtm, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)` and pass UTF-8 string after BOM strip.
- XPath selector for data rows: `//tr[@class='R20' or @class='R21']` (verified both classes exist in real fixtures: R21 in `Nr_PRO026712_no_28112024.HTM`).
- DEFENSIVE: filter out any TR whose ancestor includes a nested `<TABLE>` (sub-table edge case in PROJECT.md question). XPath: `//tr[(@class='R20' or @class='R21') and not(ancestor::table[position()>1])]`.
- Test name: `tests/unit/HtmInvoiceParser/HandlesUnquotedAttributesTest.php` — pin against `tests/fixtures/invoices/Nr_PRO033328_no_13042026.HTM`, assert 100% of expected 25 R20 rows extracted.
- Test name: `tests/unit/HtmInvoiceParser/StripsBomBeforeParseTest.php` — feed string `"\xEF\xBB\xBF<html>...<tr class='R20'><td>...Lakier żelowy</td>"`, assert product name comes back as `Lakier żelowy` not `?Lakier żelowy` or mojibake.
- Test name: `tests/unit/HtmInvoiceParser/HandlesBothR20AndR21RowsTest.php` — pin against `Nr_PRO026712_no_28112024.HTM`, assert R21 rows are NOT skipped.

**Warning signs:**
Parser returns line count lower than visible rows in the HTM (R21 rows silently dropped). Product names start with `?` or contain `Â` glyphs (BOM mishandled). Stderr noise during console-command runs.

**Phase to address:** Parser phase. Fixtures must be copied to `tests/fixtures/invoices/` per CLAUDE.md guidance — do NOT read from `storage/app/uploads/invoices/` in tests (hermeticity).

**Severity:** HIGH — without it, parser fails on day 1 with real data.

---

### Pitfall 7: 13-digit EAN lost when accidentally cast to int (PHP_INT_MAX edge case)

**What goes wrong:**
PHP int is 64-bit on Forge production (PHP 8.4 on Linux x86_64), so `(int)"4752307000097"` is fine. BUT: in tests, fixture authors who type EAN as `4752307000097` (numeric literal in PHP) get an int that survives roundtrip. If an EAN starting with `0` (e.g. `0123456789012`) appears, `(int)"0123456789012"` returns `123456789012` — leading zero dropped, EAN no longer 13 digits, no match found in `offers.code`. PROJECT.md asserts EAN is 13 digits; sample fixtures only have `4752*` prefixes, but the codebase WILL eventually receive EANs with leading zeros (US/UK UPC-A padded to EAN-13 always starts with 0). Match silently fails, line goes to unmatched queue, operator does not investigate the EAN-format problem because "the unmatched queue is normal."

**Why it happens:**
Hungarian notation `$iEan` invites int casting. Strict type checks (`int $iEan`) propagate. Anyone writing `Offer::where('code', (int) $sEan)` strips zeros. PHPStan level 10 with int param types would NOT catch this — it'd encourage it.

**How to avoid:**
- EAN is a STRING throughout the codebase. `$sEan13`, never `$iEan`. Schema: `invoice_lines.ean VARCHAR(13)`. Match query: `Offer::whereIn('code', $arSEans)->get()` — never cast.
- Validation: `assert(preg_match('/^\d{13}$/', $sEan13) === 1, "EAN must be 13 digits, got: ".var_export($sEan13, true))` — positive-space invariant per Tiger-Style.
- Reject invoice line with `ean_format_invalid` status if regex fails. DO NOT silently send to unmatched queue (different failure mode, different operator action).
- Test name: `tests/unit/HtmInvoiceParser/PreservesLeadingZeroEanTest.php` — synthetic row with `<TD CLASS="R20C2">0123456789012</TD>`, assert parsed line `ean === "0123456789012"` (string, length 13).
- Test name: `tests/unit/EanMatcherService/MatchesLeadingZeroEanInOfferCodeTest.php` — seed `Offer{code: '0123456789012'}`, assert match found (i.e. the matcher does not type-juggle).
- PHPStan: declare `@phpstan-type Ean = string` and use it in service signatures.

**Warning signs:**
Unmatched queue contains EANs that look "wrong length" (12 digits) — a 12-digit EAN is the leading-zero-stripped form of a 13-digit one. Audit any invoice where the parser line count differs from `count(unique EANs in HTM)`.

**Phase to address:** Parser phase + Matcher phase. Encode in shared DTO (`ParsedLine::$sEan`).

**Severity:** MEDIUM — current `_no_` distributor uses `4752*` prefix, but as soon as any EU/US supplier is added, this fails.

---

### Pitfall 8: `auto_activate_on_stock` overrides operator's manual deactivation

**What goes wrong:**
Operator manually deactivates Offer X via Backend (e.g. for QA: product recalled, do not sell). Stock = 0. Distributor delivery arrives next morning containing X with qty=10. Apply runs, `ActiveFlagService` sees qty went 0→10 with `auto_activate_on_stock=true`, sets `offer.active=true`. Recalled product is back on the storefront. Compliance/safety incident.

**Why it happens:**
The flag is binary — it cannot distinguish "deactivated because zero stock" from "deactivated because operator decided so." Without operator-intent provenance, automation overrides intent.

**How to avoid:**
- Add column `offer_active_managed_by ENUM('manual', 'auto_stock', null)` on offers (via plugin migration extending Offer). Set to `'manual'` when operator changes active in Backend (model handler trap). Set to `'auto_stock'` when our service flips it. ActiveFlagService refuses to flip an offer whose `active_managed_by='manual'` — logs `Log::info('skipped auto-activate, manually managed', ['offer_id' => ...])`.
- Backend: show the provenance in the Offer edit form ("Last set: manually by jane@example.com on 2026-04-15" vs "Last set: auto from invoice PRO033328 on 2026-04-29").
- Console command `goodsreceived:reset_active_provenance` to clear provenance for selected offers (operator escape hatch).
- Test name: `tests/integration/ActiveFlagService/SkipsManuallyDeactivatedOfferTest.php` — create offer, deactivate "manually" (set `active_managed_by='manual'`), apply invoice with stock for that offer, assert `active=false` after apply.
- Test name: `tests/integration/ActiveFlagService/ActivatesAutoManagedOfferOnInboundTest.php` — create offer with `active_managed_by='auto_stock'`, qty=0, active=false, apply +5, assert `active=true`.
- Test name: `tests/integration/ActiveFlagService/SetsManagedByAutoOnFirstAutoFlipTest.php` — create offer with `active_managed_by=null`, apply +5 with auto-activate on, assert `active_managed_by='auto_stock'`.

**Warning signs:**
Operator complaint "we deactivated X for QA, why is it live again?" — usually surfaces only after a customer order. Audit log should make this trivially queryable.

**Phase to address:** ActiveFlag phase + schema migration phase (add provenance column).

**Severity:** HIGH (compliance risk) but rare event-rate.

---

### Pitfall 9: Initial-reset misclick is unrecoverable

**What goes wrong:**
PROJECT.md D5 + line 28: one-shot baseline-reset checkbox zeros ALL `offer.quantity`, sets all `product.active=false` and `offer.active=false`. PROJECT.md says "logged in audit row." Audit log is observation, not undo. If operator on .no clicks this in production by accident, entire site goes dark. No "undo" because we have no snapshot of pre-reset state.

**Why it happens:**
Destructive one-shot operations need a confirmation gate AND a rollback plan, not just a log entry. Audit ≠ recovery.

**How to avoid:**
- BEFORE reset writes any zero/false, snapshot affected rows into `goods_received_initial_reset_snapshot` table: `(offer_id, original_quantity, original_offer_active, product_id, original_product_active, snapshot_at)`. Reset itself is a single transaction that writes the snapshot AND the zeros — same txn, atomic.
- Console command `goodsreceived:rollback_initial_reset {snapshot_id}` reads snapshot, restores values. Available for 30 days then snapshot purgeable.
- UI: two-step confirm gate. Type the country code (`no`/`lv`/`lt`) into a confirmation box matching the active site's code. Backend setting `allow_initial_reset` must be ENABLED for the country and DISABLED again automatically after one apply (PROJECT.md says "one-shot" — enforce in code, do not rely on operator discipline).
- Audit row: include count of offers zeroed, count of offers/products deactivated, snapshot row id, operator id, IP.
- Test name: `tests/integration/InitialResetService/SnapshotsBeforeWriteTest.php` — assert snapshot rows persist before zeros are written, verifiable by mid-transaction inspection.
- Test name: `tests/integration/InitialResetService/RollbackRestoresExactPriorStateTest.php` — seed 50 offers with mixed quantities/active flags, run reset, run rollback, assert all 50 offers byte-equal to pre-reset.
- Test name: `tests/integration/InitialResetService/AllowResetSettingDisabledAfterOneUseTest.php` — assert `allow_initial_reset=false` after one apply, second click raises permission error.

**Warning signs:**
Compliance/ops review post-incident. Audit row with `lines_zeroed > 100` and `recovery_command_invoked = false` after 24h.

**Phase to address:** InitialReset phase. Snapshot table in same migration as core schema.

**Severity:** HIGH (existential for affected site).

---

### Pitfall 10: ExtendShopaholic 1C XML import scheduled clash overwrites our increments

**What goes wrong:**
ExtendShopaholic's `ImportOfferModelFromXML::setQuantityField()` (verified at lines 154–167) **replaces** `quantity` with the value from XML — `(int) $iQuantity`, no addition. If both nightly imports run on the same window:
- 23:00 GRN apply: offer X qty 5→10 (+5 from delivery)
- 23:30 1C XML import: offer X qty 10→3 (because 1C feed reflects supplier's view, possibly stale, possibly authoritative — we don't know which)

Five units of stock vanish. Worse: the 1C feed reflects what supplier *thinks* we have, not what we actually have. After GRN apply, our system is correct (+5 received) but 1C still shows the pre-delivery number until supplier updates their feed.

**Why it happens:**
Two writers to the same column with different semantics (additive vs replacing) and no coordination layer.

**How to avoid:**
- **Decision required (LOG IN PROJECT.md Key Decisions):** which system is canonical for `offer.quantity`?
  - Option A (recommended): GRN canonical. Disable 1C XML qty write entirely. ExtendShopaholic config flag `xml_import_skip_quantity_field=true`. Operationally simpler, semantically correct (we know what we received).
  - Option B: 1C canonical, GRN advisory only. Then this whole plugin's "increments stock" promise is a lie — should be a stock-receipt LOG, not a stock writer.
- If A: add a check in `ImportOfferModelFromXML::setQuantityField()` (or via event listener) that early-returns when the per-site setting flag is on. NEW migration adds the flag default-on.
- If A: add cron lock `flock /var/lock/grn_xml_import.lock` so the two cannot ever interleave even if scheduling drifts.
- Test name: `tests/integration/InteropExtendShopaholic/XmlImportRespectsQuantitySkipFlagTest.php` — boot both plugins, set flag on, run XML import on a seeded offer with quantity 10, assert quantity remains 10 after XML import.
- Test name: `tests/integration/StockApplyService/IncrementSurvivesSimulatedXmlImportTest.php` — apply GRN (+5), then run XML import (with flag on), assert qty still reflects GRN add.
- Audit cross-link: GRN apply log records `xml_import_skip_quantity_active=true/false`. If false, log a WARNING.

**Warning signs:**
Stock counts oscillate day-over-day. Audit log shows GRN apply succeeded, but morning storefront shows lower stock than the GRN added. `lovata_shopaholic_offers.updated_at` newer than GRN apply timestamp on same offer with same-day import.

**Phase to address:** Schema phase (add the skip-flag setting on ExtendShopaholic side via migration in THIS plugin); StockApply phase (verify flag, log if not set); Operations runbook (cron lock).

**Severity:** HIGH — silent stock corruption with two correct-looking imports both succeeding.

---

### Pitfall 11: CampaignpricingShopaholic Phase 3 (parked) collides with our Offer extensions

**What goes wrong:**
Root `.planning/ROADMAP.md` Phase 3 is "OfferItem extension and CampaignPricing component (INTG-01, INTG-02, INTG-03)" — it WILL extend `OfferItem` with `campaign_pricing_list` accessor. Our plugin will extend `Offer` model with `active_managed_by` column AND `OfferItem` cache-property. Both plugins boot, both call `Offer::extend()` and `OfferItem::extend()`. Order of boot determines which extension fires first; if both add a dynamic property with the same name (unlikely but possible if conventions overlap), latter-loaded overrides former. Our `ActiveFlagService` reading `$obOfferItem->active_managed_by` may receive `null` if our extension isn't booted yet.

**Why it happens:**
Both plugins are independent units and the team has not coordinated extension-name namespacing. October's `Plugin::boot()` order is alphabetical-ish but not guaranteed.

**How to avoid:**
- Naming convention enforced in code review: all Logingrupa-plugin Offer dynamic properties prefixed with the plugin's slug — `gr_active_managed_by`, `gr_last_invoice_id` for this plugin; `cp_pricing_list` for CampaignpricingShopaholic. Document in plugin's CLAUDE.md.
- Alternative: extension is a column (real DB column, not dynamic), accessed as `$obOffer->active_managed_by` on the model directly. Migration adds column. No collision because schema is unified.
- Boot-order test: `tests/integration/PluginBoot/OurExtensionsApplyAfterCampaignPricingTest.php` — boot both plugins (skip if CampaignpricingShopaholic not installed), assert our `gr_active_managed_by` accessor exists on a freshly-made OfferItem.
- Defensive: ItemHandlers register via `Event::subscribe` with explicit priority where needed; if October's Event::subscribe accepts priority, set ours to a deterministic value.
- Cache: invalidating OfferItem cache (which CampaignpricingShopaholic also does in its handlers, per ROADMAP Phase 2 note) means our additive saveQuietly + batched flush (Pitfall 2) must NOT skip the OfferItem cache key. Add explicit `OfferItem::clearCache($iOfferId)` in our batched flush.

**Warning signs:**
Storefront shows "campaign pricing not loading" after GRN apply (our flush wiped CampaignPricing cache without it being repopulated, and CampaignPricing's lazy-load races with our flush). Or `null` reads on dynamic properties after a deploy.

**Phase to address:** Schema phase (use real columns, not dynamic properties) + StockApply phase (explicit OfferItem cache flush).

**Severity:** MEDIUM — depends on whether CampaignpricingShopaholic Phase 3 ever ships. If parked permanently, lower. If unparked while we're live, becomes HIGH.

---

### Pitfall 12: Settings cache leak across console-command site context switches

**What goes wrong:**
Per PROJECT.md: separate DBs per server, so settings are physically isolated. BUT: `goodsreceived:recompute_active_from_stock` console command on a single server hosting multiple sites (October multisite within one DB, hypothetical future config) would read `Settings::getValue('auto_activate_on_stock')` which October caches in static `SettingModel::$instances` (verified at `modules/system/models/SettingModel.php:217-244`, `clearInternalCache()` at line 241–244). Cache key includes site context only when `MultisiteInterface` implemented — if our `Settings` model omits that interface, cache key is global, first site's value leaks into second site's reads.

**Why it happens:**
Lovata Settings model template doesn't always implement `MultisiteInterface`. PROJECT.md "settings naturally per-server via October Settings model" assumes single-site-per-server, which is true today but fragile.

**How to avoid:**
- Our `Settings` model declares `implements \October\Contracts\Database\MultisiteInterface` and uses `MultisiteHelperTrait` — even though current deployment is one-site-per-server, defensive against future. Test name: `tests/unit/Settings/IsMultisiteAwareTest.php` — assert `is_subclass_of(Settings::class, MultisiteInterface::class)`.
- Console commands MUST call `\System\Models\SettingModel::clearInternalCache()` between site context switches. Wrap in helper `withSiteContext($iSiteId, callable)`.
- Service classes MUST NOT cache settings reads in instance properties; always re-read via `Settings::getValue(...)`. Lint rule (PHPMD custom or just code review): forbid `private $arSettings` properties on services.
- Test name: `tests/integration/Settings/MultisiteContextSwitchClearsCacheTest.php` — set value V1 in site context A, switch to site B, assert reading the same key returns DEFAULT not V1 (proves cache is cleared). Skip if not in multisite mode.

**Warning signs:**
.lt site behaves like .lv (or vice versa) after a console-command apply. `auto_activate_on_stock` toggles inexplicably "stuck" between sites. Operators on different country teams report different behavior than they configured.

**Phase to address:** Settings phase. Multisite-interface declaration is one line in the model — cheap insurance.

**Severity:** MEDIUM today (one site per server); HIGH if October multisite mode ever enabled.

---

### Pitfall 13: Backend bulk upload widget hits PHP `upload_max_filesize` / `post_max_size` / `max_file_uploads`

**What goes wrong:**
Operator drags 50 HTM files (each 10–50 KB) into the upload widget. Each is small, total <2MB — under file size limits — but PHP's `max_file_uploads` defaults to 20 on many distributions. The 21st through 50th files SILENTLY drop out of the request. Operator clicks "Apply All", sees "20/50 processed", thinks the other 30 had errors (not "they never arrived"). Even if `max_file_uploads` is raised, hitting `post_max_size` (default 8M) without raising it gives a 500 with no useful error — request body is malformed before PHP sees it.

**Why it happens:**
PHP default sysctl/php.ini values are conservative. Forge typically raises `upload_max_filesize` and `post_max_size` for plugin uploads but not always `max_file_uploads`. October's media manager handles this with chunking; bulk file widget does not.

**How to avoid:**
- Document required php.ini in plugin README and run a startup self-check in `Plugin::boot()`: read `ini_get('max_file_uploads')`, `upload_max_filesize`, `post_max_size`. If `max_file_uploads < 100`, log a `Log::warning` once per request — visible in logs without crashing.
- Backend upload UI: enforce a JS-side count check (`if (files.length > maxAllowed) reject with friendly message`). Use Larajax (per CLAUDE.md No-jQuery rule).
- Server-side: count `$arUploadedFiles = Input::file('files')`; assert `count($arUploadedFiles) <= ini_get('max_file_uploads')`. Throw with operator-readable message.
- Test name: `tests/unit/Controllers/Invoices/RejectsExcessFileCountTest.php` — submit a request with 25 files when limit is 20, assert 422 + clear error message in response, assert no Invoice rows created.
- Operations runbook: php.ini settings to raise for this plugin (`max_file_uploads=200`, `post_max_size=64M`, `upload_max_filesize=2M`, `memory_limit=256M`).

**Warning signs:**
"Apply" page shows fewer invoices than operator uploaded. Web server access log shows only N files in multipart body. Audit log has no row for the missing invoices.

**Phase to address:** Backend UI phase + plugin boot phase (self-check warning).

**Severity:** MEDIUM — annoying but recoverable (re-upload), not data-corrupting.

---

### Pitfall 14: PHPStan level 10 vs Eloquent dynamic properties

**What goes wrong:**
`$obOffer->quantity = $iCurrent + $iLineQty` — PHPStan level 10 with strict-rules + Larastan flags this as "Access to undefined property `Offer::$quantity`" unless the model has correct `@property` annotations. Lovata's `Offer.php` has them (verified — line 46 `* @property integer $quantity`), so reads are fine. But our InvoiceLine and Invoice models — if we forget to add `@property` doc blocks — will fail PHPStan from day 1. Plugin docblocks are not generated by an IDE-helper (no `larastan/larastan-ide-helper` in composer.lock).

**Why it happens:**
PHPStan level 10 is unforgiving on dynamic properties. Larastan partially helps but requires correct `@property` declarations, not just `$fillable`.

**How to avoid:**
- EVERY new model has full `@property` block in the docblock. Match column types: `int`, `string`, `bool`, `\Carbon\Carbon` for timestamps. PROJECT.md schema lock at plan phase MUST include this.
- Add `larastan/larastan` (likely already via composer.lock — verify) to dev-deps. Configure `phpstan.neon` to include the model paths.
- DO NOT add ide-helper-style annotation generation as a CI step — it'll churn diffs. Hand-author once at model creation.
- Test name: `make analyse` is the gate. NO baseline entries permitted for new models — baseline is for legacy-only per PROJECT.md line 99.
- Pattern to follow: `plugins/logingrupa/postnordshippingshopaholic/models/` (QA reference per CLAUDE.md).

**Warning signs:**
`make analyse` red after adding a model. Engineer adds entries to `phpstan-baseline.neon` to make it green — that's a smell, reject in code review.

**Phase to address:** Schema/Model phase. Every model PR includes `@property` block.

**Severity:** MEDIUM — productivity drag, not a bug; but PROJECT.md's "PHPStan level 10 zero new errors" is HARD GATE so blocks merges.

---

### Pitfall 15: SQLite in-memory test DB doesn't enforce all MySQL semantics

**What goes wrong:**
Idempotency contract relies on MySQL's UNIQUE constraint behavior + transaction isolation level. SQLite differences:
- SQLite doesn't enforce FK constraints by default (PRAGMA foreign_keys=ON needed) — tests pass with orphan FKs that production rejects.
- SQLite has no `READ COMMITTED` / `REPEATABLE READ` distinction — all transactions are SERIALIZABLE. Race condition tests give false confidence.
- SQLite VARCHAR length is advisory — `VARCHAR(13)` accepts 14 chars; production MySQL truncates or rejects.
- SQLite UNIQUE on string columns is case-sensitive by default; MySQL with `utf8mb4_unicode_ci` collation is case-insensitive. Invoice numbers `pro12345` and `PRO12345` would be treated as distinct in tests but identical in MySQL.
- ENUM types: SQLite stores as TEXT, accepts any value; MySQL rejects out-of-range.

**Why it happens:**
Standard October test setup uses SQLite in-memory for speed. Most differences are silent until production.

**How to avoid:**
- Schema migrations DO NOT use raw `enum()` — use string + application-level validation (or check constraint where supported). Settings/status fields use string + validator.
- For invoice_number uniqueness: explicitly normalize to UPPERCASE (or LOWERCASE) on write, BOTH at app layer AND in migration with `Schema::table` raw SQL adding a virtual generated column for uniqueness. Test the normalizer at app layer.
- Test name: `tests/unit/Invoice/InvoiceNumberCaseInsensitiveUniqueTest.php` — create invoice `PRO12345`, attempt `pro12345`, assert rejected. SQLite mode acceptable here because the rejection happens at app layer (pre-DB).
- For race conditions: write the tests, mark them with PHPUnit `@group race` or skipping outside the SQLite testing constraint, AND also document the manual MySQL test in plugin's CLAUDE.md as a release-gate.
- Foreign key tests: `Schema::enableForeignKeyConstraints()` in test bootstrap or `GoodsReceivedTestCase::setUp()`.
- Test name: `tests/integration/Setup/ForeignKeysEnforcedInTestEnvTest.php` — attempts to insert orphan `invoice_lines.invoice_id`, asserts it fails. Catches drift if test bootstrap regresses.

**Warning signs:**
Tests green, production hits unique-violation or FK-violation surprises post-deploy. CI passes, manual smoke test on staging fails.

**Phase to address:** Test infrastructure phase (one-time setup). All schema migrations.

**Severity:** MEDIUM — false-confidence class. Surfaces only in production unless explicitly hunted.

---

### Pitfall 16: Composer name collision and PROJECT.md private/public ambiguity

**What goes wrong:**
PROJECT.md Active Requirements line 33 says "Plugin installable via Composer from **public** GitHub repo." Constraints section line 61 says "proper package installable from **private** GitHub repo." Conflict. If team picks public but the parent project (root composer.json) has Vipps and other private packages requiring `composer config http-basic`, the install instructions diverge. If team picks private, public-distribution use case (other Lovata sites adopting the plugin) blocked.

Separately: composer.json `name` is `logingrupa/oc-goodsreceived-plugin`. The existing `Logingrupa\GoodsReceivedShopaholic` namespace needs both PSR-4 namespace AND October plugin code (`Logingrupa.GoodsReceivedShopaholic`) to match. If a developer renames any of (composer name, PSR-4 namespace key, `extra.october.plugin`, plugin folder path), October auto-discovery silently fails to load the plugin — `php artisan plugin:list` omits it, no error.

**Why it happens:**
Three layered identity systems (Composer, PSR-4, October) must agree. Each lives in different files. PROJECT.md not yet reconciled on public/private.

**How to avoid:**
- Resolve PROJECT.md ambiguity before plan phase: pick one (recommend PRIVATE for parity with Vipps/StoreExtender per Constraints section) and update Active Requirements line 33 in the same commit.
- composer.json `extra.october.plugin` MUST be `Logingrupa.GoodsReceivedShopaholic` (verified in current composer.json — OK).
- composer.json PSR-4 key MUST be `"Logingrupa\\GoodsReceivedShopaholic\\": ""` (verified — OK).
- Plugin path MUST be `plugins/logingrupa/goodsreceivedshopaholic/` (lowercase per October convention — verified OK).
- For local dev with monorepo: use composer path repository in root composer.json:
  ```json
  "repositories": [{"type": "path", "url": "plugins/logingrupa/goodsreceivedshopaholic", "options": {"symlink": true}}]
  ```
  — symlinking lets the plugin live both as a directory in the monorepo and as a Composer package without duplicate code.
- Test name: `tests/integration/PluginRegistration/AppearsInPluginListTest.php` — boot fresh app, assert `\System\Classes\PluginManager::instance()->getPlugin('Logingrupa.GoodsReceivedShopaholic')` is not null.
- CI in plugin's own repo (when split out): no October install needed — run `make analyse + make pint-test + make test` only.

**Warning signs:**
`php artisan plugin:list` doesn't show plugin after `composer install`. October docs say plugin is missing but no error. Developer adds the plugin twice (once via composer require, once by copying files) and confusion ensues.

**Phase to address:** Scaffold phase / repo-setup phase. Resolve PROJECT.md ambiguity in same PR.

**Severity:** MEDIUM — plugin invisibility wastes dev time, doesn't corrupt data.

---

### Pitfall 17: 5,000-line "pathological" invoice DOSes preview UI

**What goes wrong:**
Operator uploads an unusually large invoice (year-end clearance, supplier bulk shipment). Preview controller loads ALL parsed lines into a single page. Backend list widget renders 5,000 rows of HTML. Browser hangs, operator force-quits, preview state lost. On retry, parser re-runs, persists 5,000 InvoiceLine rows again — IF idempotency is line-level (it isn't currently per PROJECT.md, only invoice-level), or worse, parses successfully and the operator's second click duplicates state.

**Why it happens:**
Backend list widget is fine for 100s of rows, not 1000s. No pagination on preview.

**How to avoid:**
- Preview controller paginates lines: 100 per page. Use October's standard `ListController` with `recordsPerPage = 100`.
- "Apply" button operates on the whole invoice, not the visible page (clarify in UI: "Apply ALL 5,234 lines, not just this page").
- Parser writes to `Invoice` (status='parsed') + `InvoiceLine` rows in chunks of 500 within a transaction. If parse exceeds 60s or 256MB, abort with explicit "too large" error rather than php-fpm timeout.
- Test name: `tests/integration/HtmInvoiceParser/HandlesLargeInvoiceWithoutMemoryExceedTest.php` — synthesize 5,000-line HTM in tempfile, parse, assert peak memory under 64MB and parse completes in <10s.
- Test name: `tests/integration/Controllers/Invoices/PreviewPaginatesAtThresholdTest.php` — seed 1000-line invoice, request preview, assert response contains only first 100 lines + pagination links.

**Warning signs:**
Browser unresponsive on preview click. PHP-FPM slow log entries during apply window. `memory_get_peak_usage` in audit log >128MB.

**Phase to address:** Backend UI phase + Parser phase (chunked persist).

**Severity:** LOW (rare event) but high impact when it hits.

---

### Pitfall 18: Apply button double-click — operator confused by error message

**What goes wrong:**
Operator clicks "Apply", page is slow (per Pitfall 2 cache cascade), no spinner shown clearly, operator clicks again. Second click hits idempotency check, returns "Invoice already applied" error. Operator interprets as "the first click failed, apply did not happen" — refreshes, tries again, sees same error, escalates to dev team. Wasted support cycle.

**Why it happens:**
Idempotency error and "duplicate submission" are technically the same condition but operator-facing they are different — one means "your work is done, success" and the other means "we caught a duplicate submission."

**How to avoid:**
- Backend Larajax: disable Apply button on click + show spinner + show "Apply in progress, this may take a minute" message. Re-enable only on response (success or error).
- Server-side: distinguish `status: applied` (success result) from `status: already_applied_by_other_request` (in-flight duplicate guard). Use `Cache::lock()` (Laravel's atomic lock) keyed on `invoice_id` for the duration of apply.
- UI: when apply succeeds, redirect to invoice detail page showing "Applied just now by you, X units to N offers." When idempotency rejects on a SECOND click of same invoice, show "Already applied (just now by you)" — same friendly framing, not an error red banner.
- Test name: `tests/integration/Controllers/Invoices/DoubleClickShowsSuccessNotErrorTest.php` — simulate two near-simultaneous Apply requests for same invoice (with `Cache::lock` mock if needed), assert second response has 200 status and friendly message, not 4xx.

**Warning signs:**
Support tickets "I clicked Apply and got an error." Audit log has rapid double-clicks within seconds.

**Phase to address:** Backend UI phase.

**Severity:** LOW — UX polish, not data integrity.

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| Use `(int) $sQty` instead of regex-validated parser | 1 line of code | Silent stock loss on decimal qty (Pitfall 1) | NEVER |
| Read directly from `storage/app/uploads/invoices/` in tests | No fixture-copy step | Tests fail when fixtures move/are cleaned (`storage/` is gitignored on some envs) | NEVER — copy to `tests/fixtures/` per CLAUDE.md |
| Skip `content_hash` column, idempotency by number alone | Smaller schema | Override path silently double-counts (Pitfall 4) | NEVER |
| Soft-dep on `LoginGrupa\ExtendShopaholic` ImportLogger trait | Avoids ~150 LoC duplication | PSR-4 casing trap on Linux production (Pitfall 3) | NEVER — vendor-inline instead |
| Use `Offer::save()` instead of `saveQuietly()` in apply loop | Standard pattern | Per-row cache cascade (Pitfall 2) | NEVER for bulk; OK for single-row admin edits |
| Rely on audit log as "rollback" for initial-reset | One log statement | Unrecoverable site outage (Pitfall 9) | NEVER |
| Skip `MultisiteInterface` on Settings model "because we're single-site" | One less interface declaration | Cache leak if multisite ever enabled (Pitfall 12) | NEVER (one-line declaration, free insurance) |
| Use SQLite for race-condition tests | Fast | False confidence — SQLite serializes (Pitfall 15) | OK if tests are marked + manual MySQL gate exists |
| Skip `@property` blocks on new models, baseline the errors | Faster initial author | PHPStan baseline grows, "level 10 zero new errors" rule violated | NEVER per PROJECT.md HARD GATE |
| Boot order assumed alphabetical for plugin loading | "It works on dev" | Random extension override on prod after a deploy reorder (Pitfall 11) | OK if real DB columns used instead of dynamic properties |

---

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| ExtendShopaholic 1C XML import | Both writers to `offer.quantity`, no coordination | Disable XML qty write via setting flag; cron lock; Pitfall 10 |
| CampaignpricingShopaholic (parked Phase 3) | Two plugins extend `OfferItem` with naming overlap | Real DB columns + plugin-slug dynamic property prefixes |
| Lovata cache cascade | Per-save events fire 6+ cache clears | `saveQuietly()` + batched single flush after commit; Pitfall 2 |
| October Settings model | Forget `MultisiteInterface`, cache leaks | Always implement + use `MultisiteHelperTrait`; Pitfall 12 |
| October Backend file widget | Assume `max_file_uploads` is sufficient | Self-check at boot, JS count check, server-side assert; Pitfall 13 |
| OctoberCMS Plugin auto-discovery | Composer name ↔ PSR-4 ↔ `extra.october.plugin` ↔ folder path mismatch | Lock all four to match in scaffold; integration test for `plugin:list`; Pitfall 16 |
| Lovata `Offer::setQuantityAttribute` setter | Assume direct assignment works | Setter int-casts and clamps to 0 silently — read-modify-write, validate at boundary; Pitfall 1 |
| RainLab.Translate `lang.php` | Mix translatable + hardcoded English | All operator-facing strings via `lang.php` per PROJECT.md HARD GATE |

---

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| Per-row cache cascade during apply | Storefront p95 latency spike during apply window | `saveQuietly()` + batched flush; Pitfall 2 | Any invoice >20 lines on a busy site (.no during business hours) |
| Whole-invoice preview rendering | Browser hang on Apply page | Paginate at 100 lines/page; Pitfall 17 | Pathological invoices (5,000+ lines, year-end) |
| N+1 EAN match queries | Apply takes 30+ seconds | Single `whereIn('code', $arEans)` batch lookup in `EanMatcherService` | Any invoice >50 lines |
| Loading full Offer model when only quantity needed | Memory bloat on large invoices | `Offer::query()->select(['id', 'code', 'quantity'])` in apply loop | Invoices > 500 lines AND `quantity` field operations |
| Synchronous file parsing in HTTP request | PHP-FPM slow log fills | If file size > threshold, queue via `php artisan queue:work` (October queues) | Files >1MB or >2,000 lines |
| Logging every line to a per-line audit row | Audit table grows unboundedly | Aggregate per-invoice stats; per-line stored only on InvoiceLine, not Audit | After ~50 invoices/month for ~12 months |

---

## Security Mistakes

| Mistake | Risk | Prevention |
|---------|------|------------|
| Trust filename for invoice number | Operator could craft `Nr_PRO[any-existing-applied]_no_x.HTM` to trigger idempotency reject and read prior-apply audit (info disclosure: who applied what, when) | Validate body's invoice number; only fall back to filename per PROJECT.md D7. Audit-rejected uploads with operator id. |
| Backend route accessible to non-finance backend users | Any backend user could apply stock | Require backend permission `goodsreceived.invoices.apply`; configure in `Plugin::registerPermissions()`. Test name: `tests/integration/Permissions/RejectsBackendUserWithoutApplyPermissionTest.php`. |
| Unsigned/unauthenticated console command | Cron with sudo could be exploited if shell access leaks | Console command requires `--site=no` arg; logs operator as `console:cron`; runs only under www-data, not root. |
| Audit log shows raw HTM filename including supplier name | Discloses supplier relationship if log file leaked | Sanitize filename before persist (strip path, validate `^Nr_PRO\d+_(no\|lv\|lt)_\d{8}\.HTM$`). Log violations separately. |
| Initial-reset checkbox visible to all backend users | Anyone with Backend access could nuke the site | Permission `goodsreceived.initial_reset.execute` separate from `apply`; only granted to admins; UI hidden if permission absent (don't rely on UI hiding alone — server-side gate too). |
| Preview shows full product names from HTM unescaped | Stored XSS if supplier injects HTML in product name | Escape via Twig `|e` filter (default in October Twig — verify on render). Test name: `tests/integration/Controllers/Invoices/PreviewEscapesProductNameTest.php` — feed line with `<script>alert(1)</script>` as name, assert response contains escaped form. |

---

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---------|-------------|-----------------|
| Slow Apply with no spinner → double-click → error message | Operator confusion + escalation; Pitfall 18 | Disable button + spinner + friendly success messaging on idempotency reject |
| Initial-reset checkbox in same form as everyday Apply | Misclick risk; Pitfall 9 | Separate page, two-step confirm, type country code, permission gate |
| Unmatched lines bucket grows unboundedly | Operator never resolves them, becomes ignored | Per-invoice unmatched count visible in list; warning threshold; older-than-30-day stale-resolution job |
| Override path same UI affordance as fresh upload | Operator doesn't realize they triggered destructive override; Pitfall 4 | Diff preview required step; visually distinct (orange banner "Content drift detected — review changes") |
| Active-flag automation runs without visibility | Operator surprised when product re-activates; Pitfall 8 | Show "auto-managed" badge on Offer edit form; show full provenance |
| Parse errors batched with apply errors in same toast | Operator can't tell what went wrong | Separate parse-result page (with row-level errors) BEFORE apply |
| "Apply" UI doesn't show whether ExtendShopaholic XML import recently overwrote data | Operator applies, sees stock reverted, can't explain; Pitfall 10 | Last-XML-import timestamp shown on apply preview; warning if within last 1h |

---

## "Looks Done But Isn't" Checklist

- [ ] **HTM Parser:** Often missing BOM strip + libxml warning suppression — verify `tests/unit/HtmInvoiceParser/StripsBomBeforeParseTest.php` passes against real `Nr_PRO033328_no_13042026.HTM` fixture.
- [ ] **HTM Parser:** Often missing R21-row support — verify `HandlesBothR20AndR21RowsTest.php` against `Nr_PRO026712_no_28112024.HTM` (the only fixture with R21 rows).
- [ ] **Idempotency:** Often missing content-hash detection — verify re-upload with one-line edit triggers diff preview, NOT silent reject.
- [ ] **Idempotency:** Often missing override-as-delta semantic — verify override of (5→7) results in qty +2, NOT +7.
- [ ] **StockApplyService:** Often missing single-transaction enforcement — verify mid-apply failure rolls back invoice.status AND all line writes (not just lines).
- [ ] **StockApplyService:** Often missing `saveQuietly()` for cache-cascade avoidance — count `OfferModelHandler::afterSave` calls during apply, must be 0 (or ≤ 1 batched).
- [ ] **InitialResetService:** Often missing snapshot-before-write — verify rollback command exists AND restores byte-equal state.
- [ ] **InitialResetService:** Often missing one-shot enforcement — verify `allow_initial_reset` flips to false after one apply.
- [ ] **ActiveFlagService:** Often missing manual-vs-auto provenance — verify operator's manual deactivation survives an auto-activate trigger.
- [ ] **ExtendShopaholic interop:** Often missing skip-quantity-flag — verify nightly XML import does NOT overwrite GRN-applied stock.
- [ ] **Settings model:** Often missing `MultisiteInterface` — verify `is_subclass_of(Settings::class, MultisiteInterface::class)` is `true`.
- [ ] **Backend UI:** Often missing JS count check on bulk upload — verify uploading 25 files when limit is 20 returns user-readable error, not silent drop.
- [ ] **Backend UI:** Often missing Apply spinner + idempotency-as-success messaging — verify double-click test passes.
- [ ] **Permissions:** Often missing `goodsreceived.initial_reset.execute` separate from `goodsreceived.invoices.apply` — verify backend user without reset permission cannot fire reset.
- [ ] **Tests:** Often missing real-fixture pinning — verify `tests/fixtures/invoices/` exists with at least 3 representative `Nr_PRO*.HTM` files, NOT symlinks to `storage/`.
- [ ] **Tests:** Often missing FK enforcement — verify `Schema::enableForeignKeyConstraints()` in `GoodsReceivedTestCase::setUp()`.
- [ ] **PHPStan:** Often missing `@property` blocks on new models — verify `make analyse` passes with NO new baseline entries.
- [ ] **Composer:** Often missing local-dev path repository — verify monorepo dev install does not duplicate plugin files.

---

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| 1 — Silent stock truncation | MEDIUM | Query `invoice_lines WHERE units_added != qty * sign(matched_offer_id)` to find affected; manually correct from raw HTM |
| 2 — Cache cascade latency spike | LOW | Schedule applies outside business hours; warn-and-go-live for tomorrow's import; refactor StockApplyService for next release |
| 3 — Namespace casing failure | LOW | Production hotfix: vendor-inline the affected trait, deploy, verify `class_exists`. Add CI gate to prevent recurrence. |
| 4 — Same-number-different-content drift | HIGH | Manually compute correct stock from latest HTM; UPDATE `offer.quantity` directly; document operator workflow change |
| 5 — Mid-flight crash leaves status='applied' partial | MEDIUM | Console command `goodsreceived:repair_partial_apply {invoice_id}` reads InvoiceLine.applied=false rows and re-applies; only safe if Pitfall 4's content-hash is in place |
| 6 — Parser crashes on malformed HTM | LOW | Per-row skip with audit; never block whole invoice |
| 7 — Leading-zero EAN lost | MEDIUM | Run reconciliation query `SELECT * FROM invoice_lines WHERE LENGTH(ean)=12 AND matched_offer_id IS NULL`; manually retry with `0||ean` |
| 8 — Auto-activate overrides QA hold | HIGH | Re-deactivate immediately, communicate to ops, postmortem; add provenance column urgently |
| 9 — Initial-reset misclick | EXISTENTIAL without snapshot, LOW with snapshot | `goodsreceived:rollback_initial_reset {snapshot_id}`; if no snapshot exists, restore from last DB backup (multi-hour outage) |
| 10 — XML import overwrites GRN | MEDIUM | Disable XML import schedule until Pitfall 10 fix shipped; manually replay GRN apply with Pitfall 4's override path |
| 11 — Plugin extension boot-order race | LOW | Add explicit Event::subscribe priority; use real columns instead of dynamic props |
| 12 — Settings cache leak | MEDIUM | `php artisan cache:clear` + `php artisan config:clear`; deploy MultisiteInterface fix |
| 13 — Backend bulk upload drops files | LOW | Operator re-uploads in smaller batches; raise php.ini permanently |
| 14 — PHPStan level 10 errors block merge | LOW | Author `@property` block; do NOT baseline |
| 15 — SQLite test passes, MySQL prod fails | MEDIUM | Add manual MySQL gate to release checklist; convert specific tests to MySQL |
| 16 — Plugin invisible after install | LOW | `composer dump-autoload`; `php artisan october:up`; verify `plugin:list` |
| 17 — 5000-line invoice DOSes preview | LOW | Pagination + chunked parse |
| 18 — Double-click error confusion | LOW | Spinner + messaging; ops training |

---

## Pitfall-to-Phase Mapping

Suggested phase ordering. Plugin scaffold and schema must come first (Pitfalls 1, 4, 8, 11, 14, 16 all need schema decisions baked in).

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| 1 — `setQuantityAttribute` clamp | Parser + StockApply | `RejectsDecimalQuantityTest`, `PreservesNegativeStockWhenSettingAllowsTest` |
| 2 — Cache cascade per-save | StockApply + ActiveFlag | `EmitsBatchedCacheFlushOnceTest`, `DoesNotFireOfferModelHandlerPerLineTest` |
| 3 — Namespace casing collision | Scaffold (composer/Plugin.php) | `ImportLoggingTraitResolutionTest` + Linux CI gate |
| 4 — Content drift idempotency | Schema + StockApply + UI | `ReUploadSameNumberDifferentContentDetectsDriftTest`, `OverrideAppliesDeltaNotSumTest` |
| 5 — Mid-flight crash partial state | StockApply | `PartialFailureRollsBackEverythingTest`, `AppliedStatusSetLastInTransactionTest` |
| 6 — DOMDocument parser pitfalls | Parser | `HandlesUnquotedAttributesTest`, `StripsBomBeforeParseTest`, `HandlesBothR20AndR21RowsTest` (real fixtures) |
| 7 — Leading-zero EAN | Parser + Matcher (DTO type) | `PreservesLeadingZeroEanTest`, `MatchesLeadingZeroEanInOfferCodeTest` |
| 8 — Auto-activate vs manual intent | Schema + ActiveFlag | `SkipsManuallyDeactivatedOfferTest`, `SetsManagedByAutoOnFirstAutoFlipTest` |
| 9 — Initial-reset unrecoverable | InitialReset + Schema (snapshot table) | `SnapshotsBeforeWriteTest`, `RollbackRestoresExactPriorStateTest`, `AllowResetSettingDisabledAfterOneUseTest` |
| 10 — ExtendShopaholic XML clash | Schema (skip-flag setting) + StockApply (audit) | `XmlImportRespectsQuantitySkipFlagTest`, `IncrementSurvivesSimulatedXmlImportTest` |
| 11 — CampaignPricing extension collision | Schema (real columns) + Item-extension naming convention | `OurExtensionsApplyAfterCampaignPricingTest` |
| 12 — Settings cache leak | Settings model | `IsMultisiteAwareTest`, `MultisiteContextSwitchClearsCacheTest` |
| 13 — Bulk upload limits | Backend UI + Plugin boot self-check | `RejectsExcessFileCountTest` |
| 14 — PHPStan @property blocks | Every Schema/Model PR | `make analyse` zero new errors |
| 15 — SQLite vs MySQL semantics | Test infrastructure | `ForeignKeysEnforcedInTestEnvTest`, `InvoiceNumberCaseInsensitiveUniqueTest`, manual MySQL release gate |
| 16 — Composer/PSR-4/October ID mismatch | Scaffold | `AppearsInPluginListTest`, PROJECT.md public/private resolution |
| 17 — Pathological invoice DOS | Parser (chunked) + Backend UI (paginate) | `HandlesLargeInvoiceWithoutMemoryExceedTest`, `PreviewPaginatesAtThresholdTest` |
| 18 — Double-click confusion | Backend UI | `DoubleClickShowsSuccessNotErrorTest` |

### Phase ordering implication

1. **Scaffold + Schema** must happen FIRST — Pitfalls 3, 4, 8, 9, 10, 11, 14, 16 all need schema/composer decisions baked in (content_hash, snapshot table, active_managed_by, skip-flag setting, real columns vs dynamic, @property blocks, composer identity).
2. **Parser** can develop in parallel with Schema — Pitfalls 1, 6, 7, 17 are parser-internal.
3. **Matcher** depends on Parser DTO + Schema (offers.code).
4. **StockApply** depends on Schema + Matcher — Pitfalls 1, 2, 4, 5, 10 cluster here.
5. **ActiveFlag** depends on StockApply — Pitfalls 2, 8.
6. **InitialReset** depends on Schema (snapshot) — Pitfall 9.
7. **Backend UI** depends on all services — Pitfalls 13, 17, 18 + permissions.
8. **Operations / Runbook** depends on everything — Pitfalls 10 (cron lock), 13 (php.ini), 15 (manual MySQL gate).

---

## Sources

Verified evidence in this codebase (HIGH confidence):
- `plugins/lovata/shopaholic/models/Offer.php` (lines 540–550 — `setQuantityAttribute` clamp; lines 30–60 — full `@property` annotation reference)
- `plugins/lovata/shopaholic/classes/event/offer/OfferModelHandler.php` (cache cascade verified across all 6 clear methods)
- `plugins/lovata/shopaholic/classes/event/product/ProductModelHandler.php` (further cascade)
- `plugins/logingrupa/extendshopaholic/classes/import/ImportOfferModelFromXML.php` (lines 154–167 — replace semantic; line 1 — `LoginGrupa` namespace casing)
- `plugins/logingrupa/extendshopaholic/Plugin.php` (namespace `LoginGrupa\` confirmed)
- `plugins/logingrupa/postnordshippingshopaholic/Plugin.php` + `tests/PostNordTestCase.php` (namespace `Logingrupa\` + flushModelEventListeners pattern)
- `plugins/logingrupa/goodsreceivedshopaholic/Plugin.php` + `composer.json` (current scaffold state)
- `modules/system/models/SettingModel.php` (lines 217–244 — multisite-aware caching)
- `storage/app/uploads/invoices/Nr_PRO033328_no_13042026.HTM` (UTF-8 BOM + CRLF + unquoted CLASS=R20 — verified via `file` command and direct inspection)
- `storage/app/uploads/invoices/Nr_PRO026712_no_28112024.HTM` (R21 rows confirmed)
- `.planning/ROADMAP.md` (CampaignpricingShopaholic Phase 3 still parked)
- `.planning/STATE.md` (multi-workspace context)
- `plugins/logingrupa/goodsreceivedshopaholic/PROJECT.md` (Active req line 33 says "public" GitHub vs Constraints line 61 says "private" — internal contradiction noted)
- `plugins/logingrupa/goodsreceivedshopaholic/CLAUDE.md` (`flushModelEventListeners` requirement noted, fixture-copy hermeticity rule)

Domain knowledge (MEDIUM confidence — reasoning + standard patterns, not project-specific verification):
- PHP `(int)"5,12"` truncation behavior (well-known PHP locale-string-int cast)
- `DOMDocument::loadHTML` vs `loadXML` HTML5 tolerance (libxml standard behavior)
- Linux PSR-4 case sensitivity vs macOS HFS+ case-insensitive default (filesystem behavior)
- SQLite vs MySQL semantic differences (FK, isolation, collation, ENUM, VARCHAR length)
- Laravel `Cache::lock()` for in-flight duplicate guards

LOW confidence (not verified):
- Exact threshold at which preview UI degrades (5,000 lines is an estimate, not measured)
- Operator behavior assumptions for Pitfalls 9, 18 (no UAT data)
- Whether October Plugin boot order is alphabetical (documented as "subject to change" in October docs — defensive coding regardless)

---
*Pitfalls research for: GoodsReceivedShopaholic plugin v1.0 — distributor GRN import on multi-site Lovata Shopaholic*
*Researched: 2026-04-29*
