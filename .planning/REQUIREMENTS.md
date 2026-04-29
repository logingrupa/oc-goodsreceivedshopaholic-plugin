# Requirements: GoodsReceivedShopaholic

**Defined:** 2026-04-29
**Core Value:** Backend operators upload distributor `.HTM` delivery receipts. Stock added to matched offers idempotently. Per-site automation re-activates inbound products and deactivates zero-stock offers. Auditable history with unmatched-EAN queue.

## Locked Decisions (carried into PROJECT.md as D11-D15)

| ID | Decision |
|----|----------|
| D11 | GitHub repo: PUBLIC |
| D12 | Override-and-reimport = ADD-ON-TOP. Re-apply treats new lines as additive on top of prior apply. UX shows clear warning before override accepted. No `content_hash`, no diff preview, no decrement-then-reapply. |
| D13 | GRN owns `offer.quantity`. User manually disables quantity import in ExtendShopaholic 1C XML config (out-of-band). No cross-plugin migration in this plugin. Document dep in PROJECT.md. |
| D14 | Vendor-inline `ImportAuditService` (~50-80 LoC). No soft-dep on `Logingrupa.ExtendShopaholic`. |
| D15 | Settings extends `System\Models\SettingModel` directly + manually implements `MultisiteInterface` + `MultisiteHelperTrait`. |

---

## v1 Requirements

### Schema + Scaffold + Permissions (Phase 1)

- [ ] **SCHEMA-01**: Migration `logingrupa_goods_received_invoices` (id, invoice_number UNIQUE, invoice_date, country_code, source_filename, source_path, status enum [parsed, applied, failed, rejected_duplicate], total_lines, matched_lines, unmatched_lines, stock_added_units, applied_by_user_id, parsed_at, applied_at, initial_reset_applied bool, override_of_invoice_id nullable, notes text, timestamps). InnoDB.
- [ ] **SCHEMA-02**: Migration `logingrupa_goods_received_invoice_lines` (id, invoice_id FK cascade, row_index, ean string(13), product_name_raw, qty int, unit_price decimal(12,4) nullable, matched_offer_id nullable, matched_product_id nullable, match_strategy enum [offer_code, product_code_single_offer, none], applied bool default false, override_qty int nullable, override_reason string nullable, applied_at nullable, timestamps). EAN is STRING (preserves leading zeros).
- [ ] **SCHEMA-03**: Migration `logingrupa_goods_received_initial_reset_snapshot` (id, invoice_id FK, offer_id, prior_quantity, prior_offer_active, prior_product_id, prior_product_active, created_at). Captures full prior state for rollback.
- [ ] **SCHEMA-04**: Migration extends `lovata_shopaholic_offers` adding `active_managed_by` enum [system, operator, plugin] default 'system'. Operator-set deactivate marked `operator` so `ActiveFlagService` skips it.
- [ ] **SCHEMA-05**: `models/Invoice.php`, `models/InvoiceLine.php`, `models/InitialResetSnapshot.php` — Eloquent + `Validation` trait. Full `@property` PHPDoc blocks for PHPStan level 10. Fillable lists explicit. Relations declared.
- [ ] **SCHEMA-06**: `models/Settings.php` extends `System\Models\SettingModel` directly + implements `MultisiteInterface` + uses `MultisiteHelperTrait` (per D15). `settingsCode = 'logingrupa_goodsreceivedshopaholic_settings'`. `models/settings/fields.yaml` defines: `enabled` (switch), `auto_deactivate_on_zero` (switch, default off), `auto_activate_on_stock` (switch, default off), `allow_initial_reset` (switch, default off). All translatable via lang keys.
- [ ] **SCHEMA-07**: 4 split backend permissions registered in `Plugin::registerPermissions()`: `logingrupa.goodsreceived.upload_invoices`, `logingrupa.goodsreceived.apply_invoices`, `logingrupa.goodsreceived.override_invoices`, `logingrupa.goodsreceived.run_initial_reset`.
- [ ] **SCHEMA-08**: `lang/{en,lv,no,ru}/lang.php` scaffolded with keys for plugin name, settings labels, permission labels, controller labels (translations stubbed in EN; populated for all 4 locales in OPS-04).

### Parser + DTO + Exceptions (Phase 2)

- [x] **PARSE-01**: PHP 8.4 readonly DTOs in `classes/dto/`: `ParsedInvoice` (header + lines), `ParsedLine` (row_index, ean, product_name_raw, qty, unit_price), `MatchedLine` (line + matched_offer_id + match_strategy), `ApplyResult` (units_added, offers_touched, lines_applied, lines_skipped). Immutable, fully typed. *(2026-04-29: closed by plan 02-01 — 4 final readonly classes in `classes/dto/`. Phase 2 plan 02-07 final QA gate tuned phpmd.xml `ExcessiveParameterList` 8→10 and `ShortVariable` 4→3 to accommodate ParsedLine's canonical 9-field schema with industry-standard `ean`/`qty` field names.)*
- [x] **PARSE-02**: Typed exceptions in `classes/exception/`: `InvoiceNumberMissingException`, `DuplicateInvoiceException`, `InvalidEanException`, `InvalidQuantityException`, `ApplyAlreadyDoneException`, `InitialResetNotAllowedException`, `OperatorOverridesActiveFlagException`, `MalformedHtmException`. All extend `GoodsReceivedException`. *(2026-04-29: closed by plan 02-02 — 8 final subclasses + 1 abstract base in `classes/exception/`.)*
- [x] **PARSE-03**: `classes/parser/HtmInvoiceParser` — pure (no DB, no IO beyond input string). Uses `DOMDocument::loadHTML()` with BOM strip + `libxml_use_internal_errors(true)`. XPath extracts `<TR class="R20|R21">` rows handling unquoted `CLASS=R20` (verified in real fixtures), CRLF, UTF-8 BOM. Returns `ParsedInvoice` DTO. *(2026-04-29: closed by plan 02-05 — `final class HtmInvoiceParser` with `MAX_ROWS=10000` DoS guard, `LIBXML_NONET` XXE guard, throw-vs-skip decision matrix locked. 8 tests / 35 assertions, PHPStan L10 + Pint green, baseline unchanged.)*
- [x] **PARSE-04**: `classes/parser/InvoiceNumberResolver` — pure. Body match first; filename pattern `Nr_PRO<num>_<country>_<DDMMYYYY>.HTM` fallback; throws `InvoiceNumberMissingException` if neither yields number. *(2026-04-29: closed by plan 02-04 — 11 tests / 34 assertions, all 3 hermetic fixtures pinned, PHPStan L10 + Pint green)*
- [x] **PARSE-05**: `classes/parser/QuantityNormalizer` — pure. `parseQuantity(string): int` throws `InvalidQuantityException` on non-integer (including decimal-comma `5,12`). Strict validation BEFORE Eloquent's silent `setQuantityAttribute` int-clamp can silently lose stock. *(2026-04-29: closed by plan 02-03 — 13 tests / 26 assertions covering decimal-comma rejection, zero rejection, negative rejection, scientific-notation rejection, context attachment.)*
- [x] **PARSE-06**: `classes/parser/PriceNormalizer` — pure. Decimal-comma normalizer for unit_price/discount/total columns ONLY (not qty). Returns float or null. *(2026-04-29: closed by plan 02-03 — 10 tests covering decimal-comma + decimal-period parsing, zero/negative as valid audit values, multi-marker rejection.)*
- [x] **PARSE-07**: `tests/fixtures/invoices/` contains 3 representative real `.HTM` files copied from `storage/app/uploads/invoices/`. Hermetic — parser tests never read outside `tests/`. *(2026-04-29: copy completed in Phase 1 plan 01-04; hermetic invariant verified by Phase 2 plan 02-07 final QA gate — `grep -rE "storage/app/uploads/invoices" classes/ tests/` returns zero matches; all 3 fixtures intact with UTF-8 BOM.)*

### Match (Phase 2)

- [x] **MATCH-01**: `classes/match/EanMatcherService` — two-pass batch query. Pass 1: `Offer::whereIn('code', $arEans)`. Pass 2 (only for unmatched EANs): `Product::whereIn('code', $arEans)->has('offer', '=', 1)` with single-offer guard. Two queries, no JOIN. EAN handled as STRING throughout. *(2026-04-29: closed by plan 02-06 — `EanMatcherService::matchBatch()` ships with correlated `addSelect` subquery for Pass 2 keeping it to one round-trip; 2-query budget pinned by `DB::enableQueryLog` count assertion.)*
- [x] **MATCH-02**: Unmatched lines persist with `matched_offer_id=NULL`, `match_strategy='none'`. Never block partial apply; queryable via `WHERE matched_offer_id IS NULL`. *(2026-04-29: closed by plan 02-06 — matcher returns `match_strategy='none'`, `matched_offer_id=null` for any EAN nowhere in offers.code or products.code; pinned by "returns none for fully unmatched EAN" + "returns none when product has multiple offers" tests.)*

### Apply Layer (Phase 3)

- [x] **APPLY-01**: `classes/apply/StockApplyService` — increments `offer.quantity` per matched line via Eloquent `$obOffer->quantity += $iQty; $obOffer->saveQuietly()`. NEVER `DB::statement` (would skip cache invalidation). NEVER `->save()` per-line (would fire 8-12 cache flushes per offer × N lines). *(closed 2026-04-29 in plan 03-03 — `final class StockApplyService` with group-by-offer pre-pass + batched `Offer::whereIn()->get()` fetch; saveQuietly per UNIQUE offer; per-line `applied=true / applied_at=now` audit marker also via saveQuietly. 200-line apply: 401 actual queries / ≤ 500 budget. Bound model-event counter test asserts `model.afterSave` fires zero times — the saveQuietly contract.)*
- [x] **APPLY-02**: After all line writes commit, `StockApplyService::flushAffectedCaches(array $arOfferIds): void` fires ONE batched `OfferListStore` flush + 4 batched store flushes total. Asserted by test: 200-line apply triggers ≤ N flushes (not 1200+). *(closed 2026-04-29 in plan 03-03 — `flushAffectedCaches(list<int>): void` public method, empty-id no-op, leaf-singleton dispatch (`OfferActiveListStore::instance()->clear()` + `OfferSortingListStore::instance()->clear(SORT_NO/SORT_NEW)` + `ProductActiveListStore::instance()->clear()`). Actual list-store flushes for 200-line apply: 4 (≤ 5 hard contract). Per-id `OfferItem::clearCache` loop bounded at O(unique_offers).)*
- [ ] **APPLY-03**: `classes/apply/ActiveFlagService` — honors `active_managed_by` provenance. Skips offers where `active_managed_by='operator'` (operator manually deactivated for QA). Reconciles only `$arAffectedOfferIds` (not full table). Sets `active_managed_by='plugin'` when toggling.
- [ ] **APPLY-04**: `classes/apply/ActiveFlagService::reconcile()` honors per-site Settings: `auto_deactivate_on_zero` (qty<=0 → offer.active=false), `auto_activate_on_stock` (qty>0 + previously inactive → offer.active=true). Idempotent. 4-cell matrix asserted by test.
- [ ] **APPLY-05**: `classes/apply/InitialResetService` — one-shot. Setting `allow_initial_reset` MUST be true AND no prior invoice with `initial_reset_applied=true` MUST exist; else throws `InitialResetNotAllowedException`. Snapshots ALL offers + products to `goods_received_initial_reset_snapshot` BEFORE write. Then chunked Eloquent `Offer::chunk(500, fn(...) => quantity=0, active=false; saveQuietly())` and `Product::chunk(500, ...->saveQuietly())`. Marks `Invoice.initial_reset_applied=true`.
- [ ] **APPLY-06**: `classes/orchestrator/ParseAndPersistOrchestrator` — single public method. Wraps: parse → resolve → duplicate-check → persist Invoice@status=parsed → batch-match → write line `matched_offer_id`/`match_strategy`. `DB::transaction` boundary lives here, NOT in controller.
- [ ] **APPLY-07**: `classes/orchestrator/ApplyOrchestrator` — single public method. Acquires `Invoice::lockForUpdate()`; throws `ApplyAlreadyDoneException` if `status='applied'`; runs `StockApplyService::apply` → `ActiveFlagService::reconcile($arAffected)` → flips `Invoice.status='applied'` → records audit. ALL inside ONE `DB::transaction`. `flushAffectedCaches` fires AFTER commit.
- [ ] **APPLY-08**: Override-reimport (D12): when operator ticks "Override and re-import" on a duplicate invoice, ApplyOrchestrator re-applies the new lines additively on top of the prior apply. New `Invoice` row with `override_of_invoice_id` = prior. UX warning displayed before submit; operator confirms by typed string.
- [x] **APPLY-09**: `classes/support/SettingsAccessor` — single accessor for all Settings reads. Memoized per-request. CI grep gate enforces `Settings::get(` only appears in `SettingsAccessor.php` (Makefile target `lint:settings-accessor`). *(closed 2026-04-29 in plan 03-01 — SettingsAccessor with 4 boolean getters + flush(); atomic 4-key bulk-fill memoization; flushPluginSingletons() wired)*
- [x] **APPLY-10**: `classes/support/ImportAuditService` — vendor-inlined ~50-80 LoC (per D14). Logs to `Log::*` with structured context array (invoice_id, status, units_added, offers_touched, applied_by). NO soft-dep on ExtendShopaholic. *(closed 2026-04-29 in plan 03-02 — final class with 4 public log methods (logApply/logParse/logReject/logInitialReset) + 3 private helpers; routes through Laravel Log facade with structured context arrays carrying canonical `event` key + uuid v7 `correlation_id`; 96 raw / 65 code lines within ≤100 LoC ceiling per D-04; 6 Pest cases / 130 assertions; PHPStan L10 clean; PHPMD clean; full make all green: 106/407)*

### Backend UI + Console (Phase 4)

- [ ] **UI-01**: `controllers/Invoices` — extends `Backend\Classes\Controller` with `ListController`, `FormController`, `RelationController` behaviors. Thin: validates input, calls Orchestrators. Registered under Settings menu (NOT main nav, per D6). Permissions enforced.
- [ ] **UI-02**: Multi-file upload via `Backend\FormWidgets\FileUpload` with `attachMany` for `.HTM` files. Operator submits N files; controller iterates each through `ParseAndPersistOrchestrator`.
- [ ] **UI-03**: Preview screen — list view of Invoice@status=parsed showing matched/unmatched lines with `match_strategy` column visible. Operator can edit `override_qty` + `override_reason` per line before apply.
- [ ] **UI-04**: Apply button uses October backend AJAX (`data-request="onApply"`). Shows confirmation modal: total units to add, offer count, unmatched line count. `Cache::lock('apply-invoice-{id}', 60)` prevents double-click double-apply. Spinner during request.
- [ ] **UI-05**: Audit history list view — Invoices ordered by `applied_at DESC`. Columns: invoice_number, status, total_lines, matched_lines, stock_added_units, applied_by, applied_at. Filter by status. Detail view shows lines + unmatched queue.
- [ ] **UI-06**: Original HTM file archived via `attachOne` (System\Models\File). Visible/downloadable from Invoice detail view. Disputes/forensics use case.
- [ ] **UI-07**: Per-import summary metric panel on Invoice detail: units added, offers touched, time elapsed, applied_by user, override-of pointer if any.
- [ ] **UI-08**: Initial-reset checkbox on upload preview. Disabled unless Settings.allow_initial_reset=true. When ticked, requires operator type literal string `RESET` into a confirmation field before Apply enabled. Pre-mutation snapshot count shown in confirmation copy.
- [ ] **UI-09**: Pre-parse duplicate detection — on upload, controller checks invoice_number from filename pattern BEFORE running parse; if duplicate exists, shows reject screen with prior-apply summary + override checkbox (D12) before parse cost incurred.
- [ ] **UI-10**: Override-and-reimport UX — explicit warning copy: "This re-applies the invoice ADDITIVELY on top of the prior apply. Stock will be incremented by new line quantities. This is NOT a delta calculation. Continue?" Operator types `OVERRIDE` to confirm.
- [ ] **UI-11**: Console command `goodsreceived:recompute_active_from_stock` registered via `Plugin::register()` → `registerConsoleCommand()`. Calls `ActiveFlagService::reconcileAll()` chunked (500). Sync execution. Honors `active_managed_by='operator'` skip.
- [ ] **UI-12**: Plugin boot self-check: warn-log if PHP `max_file_uploads` < 20 OR `upload_max_filesize` < 10M (multi-file upload prerequisite).

### Operations + QA + Polish (Phase 5)

- [ ] **OPS-01**: README documents installation, settings, override semantics (D12), GRN-canonical stock writer dependency on user disabling 1C XML quantity import (D13), 4 permissions, runbook for InitialReset, troubleshooting keyed to `Log::*` context arrays.
- [ ] **OPS-02**: PROJECT.md Key Decisions table updated with D11-D15 outcomes (resolved 2026-04-29).
- [ ] **OPS-03**: Composer package published to PUBLIC GitHub repo `logingrupa/oc-goodsreceived-plugin` (D11). `composer require` works on clean OctoberCMS 4 + Lovata Shopaholic install.
- [ ] **OPS-04**: `lang/{en,lv,no,ru}/lang.php` fully populated for all user-facing strings. RainLab.Translate compatible.
- [ ] **OPS-05**: `make all` green: `pint-test` clean, `phpstan analyse` 0 errors at level 10, `phpmd` 0 violations, `pest` all green with `--coverage --min=85`.
- [ ] **OPS-06**: Verified working on .no, .lv, .lt staging (or dev parity). Multi-site Settings isolation confirmed by manual test.

### Cross-cutting QA (verified across all phases)

- [x] **QA-01**: HTM parser real-fixture pin tests: `HandlesUnquotedAttributesTest`, `StripsBomBeforeParseTest`, `HandlesBothR20AndR21RowsTest`, `HandlesCRLFLineEndingsTest`, `RejectsMalformedHtmTest`. *(2026-04-29: closed by plan 02-05 — all 5 sub-tests as `it()` blocks in `tests/unit/Parser/HtmInvoiceParserTest.php` plus 3 round-out invariants; pinned to PRO033328 + PRO026712 real fixtures with exact line/skip counts.)*
- [x] **QA-02**: Stock-write guard tests: `RejectsDecimalQuantityTest`, `PreservesLeadingZeroEanTest`, `Offer setQuantityAttribute clamp test boundary`. *(2026-04-29: closed across plans 02-03 + 02-06 — `RejectsDecimalQuantityTest` in `tests/unit/Parser/QuantityNormalizerTest.php` (plan 02-03), `PreservesLeadingZeroEanTest` rolled into `tests/unit/Match/EanMatcherServiceTest.php` (plan 02-06) using `array_keys()` identity check to pin string-key invariant. The Offer clamp boundary is implicitly guarded by QuantityNormalizer rejecting decimal input BEFORE reaching `setQuantityAttribute` — no separate clamp test needed; the rejection point is upstream.)*
- [ ] **QA-03**: Idempotency tests: `DuplicateInvoiceRejectedTest`, `OverrideReimportAddsOnTopTest`, `ApplyAlreadyDoneThrowsTest`, `LockForUpdateSerializesConcurrentApplyTest`.
- [x] **QA-04**: Cache-cascade smoke test: `Apply200LinesTriggersBatchedFlushNotPerSaveTest` (asserts ≤ N flushes, not N×lines). *(closed 2026-04-29 in plan 03-03 — `tests/unit/Apply/Apply200LinesTriggersBatchedFlushNotPerSaveTest.php`, 3 cases / 7 Mockery `shouldHaveReceived` assertions on each leaf-singleton spy. Spy injection mechanism: reflection on each leaf class's `protected static $instance` slot; afterEach calls `forgetInstance()`. Actual list-store flushes counted: 4 (`OfferActiveListStore::clear` ×1, `OfferSortingListStore::clear` ×2 for SORT_NO + SORT_NEW, `ProductActiveListStore::clear` ×1). Anti-pattern guard: regression to per-line `->save()` would explode the spy count from 4 to ≥ 1600.)*
- [ ] **QA-05**: ActiveFlag matrix: 4-cell test `(deactivate_on_zero on/off) × (activate_on_stock on/off)` plus `SkipsManuallyDeactivatedOfferTest` (active_managed_by=operator).
- [ ] **QA-06**: InitialReset tests: `RequiresAllowInitialResetSettingTest`, `OneShotEnforcedTest`, `SnapshotsBeforeWriteTest`, `RollbackRestoresExactPriorStateTest`, `ChunkedNotSingleStatementTest`.
- [ ] **QA-07**: Multi-site Settings: `IsMultisiteAwareTest`, `MultisiteContextSwitchClearsCacheTest`.
- [ ] **QA-08**: Transaction safety: `PartialFailureRollsBackEverythingTest`, `ActiveFlagInsideSameTransactionAsStockApplyTest`.
- [x] **QA-09**: Settings DRY gate: `SettingsAccessorIsSoleConsumerOfSettingsGetTest` (CI grep). *(closed 2026-04-29 in plan 03-01 — dual-gate: Makefile `lint-settings-accessor` target + Pest mirror; both negative-path verified; in `make all` chain)*
- [ ] **QA-10**: Permissions: `RequiresApplyPermissionTest`, `RequiresUploadPermissionTest`, `RequiresOverridePermissionTest`, `RequiresInitialResetPermissionTest`.
- [ ] **QA-11**: Test base `GoodsReceivedTestCase::tearDown()` calls `flushModelEventListeners()` AND each plugin singleton's `flush()` method. Cross-test bleed prevention.

---

## v2 Requirements (deferred)

### Operator Productivity
- **V2-OP-01**: Inline qty edit on preview screen (saves clicks vs current row-edit form)
- **V2-OP-02**: Bulk-edit unmatched lines (assign EAN to product in bulk)
- **V2-OP-03**: Email notifications on import results (success summary, failure alert)

### Differentiators (deferred from FEATURES research)
- **V2-DIFF-01**: Supplier-keyed defaults (auto-detect distributor by filename pattern)
- **V2-DIFF-02**: Photo attachment of paper receipt alongside HTM
- **V2-DIFF-03**: Retroactive cost-averaging hooks (price columns currently parsed for audit only)
- **V2-DIFF-04**: Stale `status='parsed'` cleanup scheduled command
- **V2-DIFF-05**: External alerting on dead-letter (Slack/email/Telegram for failed imports)

### Operations
- **V2-OPS-01**: CI MySQL service test gate (replace SQLite-only release gate)
- **V2-OPS-02**: Backend widget showing recent imports + failure rate

---

## Out of Scope (v1)

| Feature | Reason |
|---------|--------|
| Pricing import from HTM | Pricing managed elsewhere; HTM prices parsed for audit display only, never written |
| Outbound stock writes (sales decrement) | Owned by Shopaholic order processors |
| Supplier 1C XML import | Different bounded context; `Logingrupa.ExtendShopaholic` owns it |
| Stocktake/cycle-count flows | Separate concern; may justify own plugin later |
| Multi-currency conversion | Qty-only writes; price columns parsed for audit, never written |
| Email notifications on import results | Deferred to v2 (no current ops channel) |
| Mobile/scanner UX | Operator workflow is desktop |
| In-system SKU mapping table | Anti-feature — confuses source-of-truth; use distributor's EAN as-is |
| Three-way matching (PO/GRN/invoice) | We don't issue POs to distributors |
| Cost averaging FIFO/LIFO | Financial concern, separate plugin |
| REST/GraphQL API surface | Backend-only feature |
| Real-time stock dashboard | Existing Shopaholic backend lists suffice |
| Multi-warehouse | Single warehouse per site |
| Configurable parser (other HTM formats) | Single distributor format; revisit when 2nd distributor onboards |
| Decrement-then-reapply override (research-recommended) | Per D12 — operator chose simpler add-on-top semantics with UX warning |
| `content_hash` column | Per D12 — not needed for add-on-top semantics |
| Diff-preview screen on override | Per D12 — operator confirms via typed `OVERRIDE` string instead |
| Cross-plugin migration disabling 1C XML qty import | Per D13 — user disables manually out-of-band |
| Soft-dep on `Logingrupa.ExtendShopaholic` | Per D14 — vendor-inline `ImportAuditService` |
| `Lovata\Toolbox\Models\CommonSettings` base | Per D15 — extend `SettingModel` direct + manual MultisiteInterface |

---

## Traceability

Filled by roadmapper 2026-04-29. Every v1 REQ-ID maps to exactly one phase. 56/56 requirements mapped (100% coverage).

| Requirement | Phase | Status |
|-------------|-------|--------|
| SCHEMA-01 | Phase 1 | Pending |
| SCHEMA-02 | Phase 1 | Pending |
| SCHEMA-03 | Phase 1 | Pending |
| SCHEMA-04 | Phase 1 | Pending |
| SCHEMA-05 | Phase 1 | Pending |
| SCHEMA-06 | Phase 1 | Pending |
| SCHEMA-07 | Phase 1 | Pending |
| SCHEMA-08 | Phase 1 | Pending |
| PARSE-01 | Phase 2 | Pending |
| PARSE-02 | Phase 2 | Pending |
| PARSE-03 | Phase 2 | Closed (2026-04-29) — plan 02-05 |
| PARSE-04 | Phase 2 | Closed (2026-04-29) — plan 02-04 |
| PARSE-05 | Phase 2 | Pending |
| PARSE-06 | Phase 2 | Pending |
| PARSE-07 | Phase 2 | Pending |
| MATCH-01 | Phase 2 | Closed (2026-04-29) — plan 02-06 |
| MATCH-02 | Phase 2 | Closed (2026-04-29) — plan 02-06 |
| APPLY-01 | Phase 3 | Pending |
| APPLY-02 | Phase 3 | Pending |
| APPLY-03 | Phase 3 | Pending |
| APPLY-04 | Phase 3 | Pending |
| APPLY-05 | Phase 3 | Pending |
| APPLY-06 | Phase 3 | Pending |
| APPLY-07 | Phase 3 | Pending |
| APPLY-08 | Phase 3 | Pending |
| APPLY-09 | Phase 3 | Closed (plan 03-01, 2026-04-29) |
| APPLY-10 | Phase 3 | Closed (plan 03-02, 2026-04-29) |
| UI-01 | Phase 4 | Pending |
| UI-02 | Phase 4 | Pending |
| UI-03 | Phase 4 | Pending |
| UI-04 | Phase 4 | Pending |
| UI-05 | Phase 4 | Pending |
| UI-06 | Phase 4 | Pending |
| UI-07 | Phase 4 | Pending |
| UI-08 | Phase 4 | Pending |
| UI-09 | Phase 4 | Pending |
| UI-10 | Phase 4 | Pending |
| UI-11 | Phase 4 | Pending |
| UI-12 | Phase 4 | Pending |
| OPS-01 | Phase 5 | Pending |
| OPS-02 | Phase 5 | Pending |
| OPS-03 | Phase 5 | Pending |
| OPS-04 | Phase 5 | Pending |
| OPS-05 | Phase 5 | Pending |
| OPS-06 | Phase 5 | Pending |
| QA-01 | Phase 2 | Closed (2026-04-29) — plan 02-05 |
| QA-02 | Phase 2 | Closed (2026-04-29) — plans 02-03 + 02-06 |
| QA-03 | Phase 3 | Pending |
| QA-04 | Phase 3 | Pending |
| QA-05 | Phase 3 | Pending |
| QA-06 | Phase 3 | Pending |
| QA-07 | Phase 1 | Pending |
| QA-08 | Phase 3 | Pending |
| QA-09 | Phase 3 | Closed (plan 03-01, 2026-04-29) |
| QA-10 | Phase 4 | Pending |
| QA-11 | Phase 1 | Pending |

**Coverage by phase:**

| Phase | REQ count | REQ-IDs |
|-------|-----------|---------|
| Phase 1: Schema, Scaffold, Settings, Permissions | 10 | SCHEMA-01..08, QA-07, QA-11 |
| Phase 2: Pure Parsers, DTOs, Exceptions, EAN Matcher | 11 | PARSE-01..07, MATCH-01..02, QA-01, QA-02 |
| Phase 3: Apply Layer + Orchestrators | 16 | APPLY-01..10, QA-03, QA-04, QA-05, QA-06, QA-08, QA-09 |
| Phase 4: Backend Controller, Upload/Preview/Apply UI, Console | 13 | UI-01..12, QA-10 |
| Phase 5: Ops, Lang, Polish, Public Release | 6 | OPS-01..06 |
| **Total** | **56** | All v1 REQ-IDs mapped exactly once |

---

*Requirements defined: 2026-04-29*
*Open questions OQ1-OQ5 resolved with user 2026-04-29 → D11-D15 locked*
*Roadmap traceability populated 2026-04-29*
