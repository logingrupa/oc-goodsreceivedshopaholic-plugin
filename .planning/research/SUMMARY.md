# Project Research Summary

**Project:** GoodsReceivedShopaholic v1.0 — distributor GRN (Goods Received Note) import plugin
**Domain:** OctoberCMS v4 / Lovata Shopaholic backend plugin — multi-file `.HTM` upload → parse → preview → apply pipeline with audit, deployed multi-site (.no/.lv/.lt) on per-site DBs
**Researched:** 2026-04-29
**Confidence:** HIGH (every recommendation grounded in installed `vendor/` source, real fixtures in `storage/app/uploads/invoices/`, existing reference plugins, and PROJECT.md locked decisions D1–D10)

## Executive Summary

Four researchers (stack, features, architecture, pitfalls) converged independently on the same shape: this plugin is a **classic ETL/audit pipeline** (parse → match → apply) bolted onto an existing Shopaholic monorepo, with the unusual twist of **incremental stock writes plus per-site automation toggles**. PROJECT.md's locked decisions D1–D10 are correct and uncontested — the research adds **zero new composer dependencies**, validates the planned three-layer architecture, and confirms the "Out of Scope" defers are right for this bounded context.

The single biggest finding is that **PROJECT.md under-specifies the architecture by exactly one layer**. To preserve the LOCKED engineering quality bar, the controller must NOT call `StockApplyService` and `ActiveFlagService` directly — that leaks transaction boundaries into HTTP code and makes idempotency racy. Research recommends inserting a thin **Orchestrator layer** (`ParseAndPersistOrchestrator`, `ApplyOrchestrator`) that owns `DB::transaction(...)` and composes the pure services. This is the only architectural addition; everything else mirrors PROJECT.md.

The dominant risk class is **silent stock corruption**, with three independent attack vectors: (1) Lovata's `Offer::setQuantityAttribute` int-clamps any non-positive value to zero with no log; (2) `ExtendShopaholic`'s 1C XML import currently **replaces** `offer.quantity` while we increment — same column, two writers, no coordination; (3) override-and-reimport semantics are unspecified and the wrong choice doubles stock on every override. The secondary risk is the **cache cascade** — Lovata's `OfferModelHandler::afterSave` fires 8–12 cache `forget()` calls per offer; a 200-line invoice without `saveQuietly()` + batched flush would emit ~3,600 cache invalidations and visibly degrade .no storefront latency.

## Open Questions Blocking Plan-Phase (MUST resolve before requirements lock)

| # | Question | Research Recommendation | Source |
|---|----------|-------------------------|--------|
| **OQ1** | Public vs private GitHub repo (PROJECT.md self-contradicts: line 33 "public", line 61 "private") | **PRIVATE** — parity with Vipps/StoreExtender | PITFALLS #16 |
| **OQ2** | Override-and-reimport semantics — decrement-then-reapply / add-on-top / hard-replace | **Decrement-then-reapply.** Same-number-different-content path requires `content_hash` column + diff preview + delta apply | FEATURES Open Q1, PITFALLS #4 |
| **OQ3** | Canonical writer for `offer.quantity` — GRN vs ExtendShopaholic 1C XML (currently both write, XML *replaces*) | **GRN canonical.** Add `xml_import_skip_quantity_field=true` setting on ExtendShopaholic side (migration in THIS plugin); add cron `flock` | PITFALLS #10 |
| **OQ4** | `ImportLoggerService` reuse — soft-dep vs vendor-inline | **Vendor-inline ~50–80 LoC `ImportAuditService`.** Soft-dep hits PSR-4 namespace casing trap (`LoginGrupa\` vs `Logingrupa\`) on Linux production, drags 1C-XML-shaped API, violates SRP/D1 | STACK, PITFALLS #3 |
| **OQ5** | Settings model base class — `SettingModel` direct (ARCHITECTURE) vs `CommonSettings` (STACK) — research files conflict | **Synthesizer recommendation:** extend `SettingModel` directly + manually implement `MultisiteInterface` + `MultisiteHelperTrait`. Keeps QA-reference parity with PostNord, avoids dead-weight Translate behavior, makes Multisite explicit not invisibly inherited | ARCHITECTURE vs STACK |

## Key Findings

### Recommended Stack

**Net new Composer dependencies: ZERO.** Every requirement is in `vendor/` today.

**Core technologies (all built-in):**
- `Backend\FormWidgets\FileUpload` (October 4.2.13) — multi-file upload, deferred binding. NOT `ImportExportController` (CSV-column-mapping wrong tool).
- `ext-dom` (`DOMDocument` + `DOMXPath`) — primary HTM parser. `Masterminds\HTML5` 2.10.0 fallback already in vendor (transitive via `symfony/html-sanitizer`).
- October backend AJAX framework (`data-request="onHandlerName"`) — NOT Larajax (Larajax is frontend-only).
- `System\Models\SettingModel` extension; `registerSettings()` with `category: lovata.shopaholic::lang.tab.settings` (D6 satisfied).
- `registerConsoleCommand()` in `Plugin::register()` (NOT `boot()`).
- Pest 4 / PHPUnit 12 + SQLite in-memory + `GoodsReceivedTestCase` — already in `vendor/bin/`.

Full detail: `.planning/research/STACK.md`

### Expected Features

PROJECT.md's Active list covers complete table-stakes + 2 differentiators. Research recommends **8 cheap wins to add to v1**.

**Add to v1 (NOT in PROJECT.md, all P1):**
1. Backend permission `logingrupa.goodsreceived.apply_invoices` — Buddies-compatible (split into 4: upload/apply/override/reset)
2. Original HTM file archive (attachOne via `System\Models\File`) — disputes/forensics
3. Line-level qty override at preview (`override_qty` + `override_reason` columns)
4. Pre-parse duplicate detection (filename pattern check before parse)
5. Per-import summary metric on audit row (units added, offers touched)
6. Match-strategy column visibility in preview + audit
7. Apply confirmation modal showing total units + offer count
8. Typed RESET confirmation for InitialReset (not just checkbox)

**All 6 PROJECT.md Out-of-Scope defers validated as correct.** Anti-features explicitly identified: mobile/scanner UX, in-system SKU mapping table, three-way matching (PO/GRN/invoice), cost averaging FIFO/LIFO, REST/GraphQL API, real-time stock dashboard, multi-warehouse, configurable parser.

Full detail: `.planning/research/FEATURES.md`

### Architecture Approach

Three-layer Parse → Match → Apply (per PROJECT.md) **plus an Orchestrator layer** (research addition, load-bearing for transaction boundary correctness).

**Major components (`classes/{orchestrator,parser,match,apply,support,dto,exception}/`):**
1. `ParseAndPersistOrchestrator` — parse → resolve → persist `Invoice@status='parsed'` → batch-match → write `matched_offer_id`/`match_strategy`
2. `ApplyOrchestrator` — `Invoice::lockForUpdate()` → guard → StockApply → ActiveFlag → status flip, ALL in one transaction
3. `HtmInvoiceParser` (pure) — BOM-strip + `libxml_use_internal_errors` + XPath
4. `InvoiceNumberResolver` (pure) — body → filename → throw
5. `EanMatcherService` (DB-read) — two queries, no join (offer.code primary, product.code single-offer fallback)
6. `StockApplyService` (DB-write) — `saveQuietly()` + batched cache flush after commit
7. `ActiveFlagService` (DB-write) — honors `active_managed_by` provenance, only touches `$arAffectedOfferIds`
8. `InitialResetService` (DB-write, one-shot) — snapshot BEFORE write into `goods_received_initial_reset_snapshot`; chunked Eloquent (NOT `DB::statement` — would skip cache invalidation)
9. `SettingsAccessor` — single accessor, memoized, **CI grep gate enforces `Settings::get(` only in this file**
10. PHP 8.4 readonly DTOs cross every layer
11. Typed exceptions: `InvoiceNumberMissing`, `Duplicate`, `InvalidEan`, `InvalidQuantity`, `ApplyAlreadyDone`, `InitialResetNotAllowed`

**Service injection:** static `::instance()` singletons (NOT `App::singleton` — appears only ONCE in entire vendor tree). Every singleton exposes `flush()` called from `GoodsReceivedTestCase::tearDown()`.

**Build order:** Settings → Migrations → DTOs → Exceptions → SettingsAccessor → Pure parsers → EanMatcher → Models → StockApply → ActiveFlag → InitialReset → Orchestrators → Console → Controller → Plugin wiring.

Full detail: `.planning/research/ARCHITECTURE.md`

### Critical Pitfalls

PITFALLS.md identifies 18. Top 5 must become test invariants in REQUIREMENTS:

1. **`setQuantityAttribute` silent clamp + decimal-qty truncation (HIGH)** — qty is integer end-to-end; `parseQuantity(string): int` throws on non-integer; decimal-comma normalizer for *price only*. Tests: `RejectsDecimalQuantityTest`, `PreservesNegativeStockWhenSettingAllowsTest`.
2. **Cache cascade per-save (HIGH)** — `saveQuietly()` + batched flush after commit. Tests: `EmitsBatchedCacheFlushOnceTest`, `DoesNotFireOfferModelHandlerPerLineTest`.
3. **Idempotency-by-number-only misses content drift (HIGH)** — add `content_hash` (sha256 of normalized lines); same number + different hash → diff preview + delta apply. Tests: `ReUploadSameNumberDifferentContentDetectsDriftTest`, `OverrideAppliesDeltaNotSumTest`.
4. **HTM parser real-fixture quirks (HIGH)** — UTF-8 BOM, CRLF, unquoted `CLASS=R20` in real fixtures. Strip BOM + `libxml_use_internal_errors` + XPath with subtable guard. Tests pinned to fixtures copied to `tests/fixtures/invoices/`: `HandlesUnquotedAttributesTest`, `StripsBomBeforeParseTest`, `HandlesBothR20AndR21RowsTest`.
5. **ExtendShopaholic XML overwrites our increments (HIGH)** — OQ3 resolution + cron `flock`. Tests: `XmlImportRespectsQuantitySkipFlagTest`, `IncrementSurvivesSimulatedXmlImportTest`.

**Additional must-have test invariants:**
- `PartialFailureRollsBackEverythingTest` (Pitfall #5)
- `PreservesLeadingZeroEanTest` (Pitfall #7 — EAN is STRING throughout)
- `SkipsManuallyDeactivatedOfferTest` (Pitfall #8 — `active_managed_by` provenance)
- `SnapshotsBeforeWriteTest` + `RollbackRestoresExactPriorStateTest` (Pitfall #9 — audit ≠ recovery)
- `IsMultisiteAwareTest` + `MultisiteContextSwitchClearsCacheTest` (Pitfall #12)
- `ForeignKeysEnforcedInTestEnvTest` (Pitfall #15 — SQLite vs MySQL)

Full detail: `.planning/research/PITFALLS.md`

## Implications for Roadmap

Research recommends **5 phases** with Schema/Settings as Phase 1 (lock contract first).

### Phase 1: Schema + Scaffold + Settings + Permissions
**Rationale:** 9 pitfalls (#3, #4, #8, #9, #10, #11, #12, #14, #16) need schema decisions baked in.
**Delivers:** Reconciled composer.json (OQ1) + Plugin.php; migrations for invoices, invoice_lines, initial_reset_snapshot, ExtendShopaholic-side `xml_import_skip_quantity_field` setting, Offer extension adding `active_managed_by` real column; Settings model with `MultisiteInterface`; `SettingsAccessor` + CI grep gate; 4 split permissions; full `@property` docblocks; `tests/fixtures/invoices/` with 3 real `.HTM` files copied.
**Avoids:** Pitfalls #3, #4, #8, #9, #10, #11, #12, #14, #16.

### Phase 2: Pure Parsers + DTOs + Exceptions + EanMatcher
**Rationale:** Zero-dependency, parallelizable with Phase 1.
**Delivers:** PHP 8.4 readonly DTOs; typed exceptions; `HtmInvoiceParser` (BOM strip, libxml suppression, integer-only qty); `InvoiceNumberResolver`; `EanMatcherService` (two-query batch, EAN-as-string).
**Avoids:** Pitfalls #1, #6, #7, #17.

### Phase 3: StockApply + ActiveFlag + InitialReset + Orchestrators
**Rationale:** Highest pitfall density — all stock-corruption vectors.
**Delivers:** `StockApplyService` (saveQuietly + batched flush + single-transaction); `ActiveFlagService` (provenance-honoring); `InitialResetService` (snapshot-before-write + one-shot enforcement + chunked Eloquent); both Orchestrators with `lockForUpdate()` + ApplyAlreadyDoneException guard.
**Avoids:** Pitfalls #1, #2, #4, #5, #8, #9, #10, #11.

### Phase 4: Console + Backend Controller + UI Partials
**Rationale:** UI layer last — depends on all services. Operator-facing UX pitfalls live here.
**Delivers:** `RecomputeActiveFromStock` console (synchronous chunked); `Controllers\Invoices` thin (ListController + FormController + RelationController behaviors); upload/preview/_apply_summary partials; spinner + `Cache::lock()` + idempotency-as-success messaging; original HTM archive; line-level qty override; per-import summary metric; match-strategy column; typed RESET confirmation; plugin boot self-check for `max_file_uploads`.
**Avoids:** Pitfalls #13, #17, #18, security mistakes.

### Phase 5: Operations + Lang + Polish
**Rationale:** Cross-cutting concerns.
**Delivers:** `lang/{en,lv,ru,no}/lang.php`; ops runbook (php.ini, cron flock, MySQL release-gate); PROJECT.md Decisions log update with OQ1–OQ5 resolutions (D11–D15); README; final `make all` green.
**Avoids:** Pitfalls #15, language-hardcoding regressions.

### Research Flags

**Needs research-phase during planning:**
- **Phase 1 (Schema):** OQ5 reconciliation (SettingModel vs CommonSettings); cross-plugin `xml_import_skip_quantity_field` migration shape; ExtendShopaholic Offer-extension `active_managed_by` registration coordination.
- **Phase 3 (Orchestrator):** `Cache::lock()` integration with DB transaction (lock outside or inside? lock-timeout-during-long-apply edge cases); exact `OfferModelHandler` cache-flush method signatures for post-commit batched flush.

**Standard patterns (skip research):**
- **Phase 2 (Pure Parsers):** DOMDocument + DOMXPath well-trodden; real fixtures in hand.
- **Phase 4 (Backend UI):** mirrors `plugins/logingrupa/storeextender/controllers/groups/` exactly.
- **Phase 5 (Ops):** standard October post-deploy + Forge runbook.

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | Verified against installed `vendor/` + 3 reference plugins; zero external WebSearch needed |
| Features | HIGH on table-stakes/anti-features; MEDIUM on differentiators (no UAT data yet) |
| Architecture | HIGH | Every pattern traced to concrete file paths in `plugins/lovata/` + `plugins/logingrupa/` |
| Pitfalls | HIGH on parser/cache/Offer-quantity (verified against fixtures + Lovata source); MEDIUM on race-condition recovery; LOW on operator-misclick UX (#9, #18 — no UAT data) |

**Overall confidence:** HIGH for proceeding to requirements. OQ1–OQ5 are clearly scoped — resolution is a discussion, not more research.

### Gaps to Address

- **Operator UAT data** (Pitfalls #9, #18): ship recommended mitigations (typed RESET, Cache::lock, spinner); revisit at v1.x.
- **CampaignpricingShopaholic Phase 3 collision** (Pitfall #11): use real DB columns (not dynamic props); coordinate if/when unparked; integration test conditional.
- **Override-and-reimport UX** (OQ2): unusual pattern; needs UX walkthrough on diff-preview screen before Phase 4.
- **MySQL release gate** (Pitfall #15): manual MySQL smoke test in plugin CLAUDE.md (Phase 5); future v1.x consider CI MySQL service.
- **InitialReset file-cache scale**: ~100s for 10k offers is estimate, not measured; verify on staging before first production reset.

## Sources

### Primary (HIGH confidence — direct file inspection)
- `plugins/lovata/shopaholic/{Plugin.php, models/Offer.php, models/Product.php, classes/event/offer/OfferModelHandler.php, classes/event/product/ProductModelHandler.php, updates/create_table_offers.php, updates/create_table_products.php, classes/store/brand/ListByCategoryStore.php}`
- `plugins/lovata/toolbox/{models/CommonSettings.php, classes/helper/PriceHelper.php, classes/event/ModelHandler.php}`
- `plugins/lovata/labelsshopaholic/models/label/fields.yaml`
- `modules/system/models/SettingModel.php`
- `modules/backend/formwidgets/FileUpload.php`
- `plugins/logingrupa/postnordshippingshopaholic/{Plugin.php, models/Settings.php, tests/PostNordTestCase.php}` (QA reference)
- `plugins/logingrupa/storeextender/{controllers/Groups.php, controllers/groups/config_form.yaml}`
- `plugins/logingrupa/extendshopaholic/{classes/services/ImportLoggerService.php, traits/ImportLoggingTrait.php, classes/import/ImportOfferModelFromXML.php, Plugin.php}`
- `plugins/logingrupa/goodsreceivedshopaholic/{PROJECT.md, CLAUDE.md, composer.json}`
- Real fixtures: `storage/app/uploads/invoices/Nr_PRO*.HTM`
- `composer.lock`; `vendor/masterminds/html5/`; `php -m` (PHP 8.4.18 NTS)
- Root `CLAUDE.md`; `.planning/ROADMAP.md`; `.planning/STATE.md`

### Secondary (MEDIUM)
Hopstack, Cin7 Core stocktake, Stripe idempotency, Racklify ASN, optimistic-vs-pessimistic locking; Zycus, Manifestly, WareIQ for GRN domain.

### Tertiary (LOW)
Exact preview-degradation thresholds; operator UX assumptions for Pitfalls #9/#18; PHP `(int)"5,12"` truncation behavior.

---

*Synthesis complete. Orchestrator should resolve OQ1–OQ5 inline, then proceed to REQUIREMENTS.md.*
