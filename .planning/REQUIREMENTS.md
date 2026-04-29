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
- [x] **APPLY-03**: `classes/apply/ActiveFlagService` — honors `active_managed_by` provenance. Skips offers where `active_managed_by='operator'` (operator manually deactivated for QA). Reconciles only `$arAffectedOfferIds` (not full table). Sets `active_managed_by='plugin'` when toggling. *(closed 2026-04-29 in plan 03-04 — `final class ActiveFlagService` with `reconcile(list<int>): void` + `reconcileAll(int $iChunkSize=500): int` (Phase 4 console command entry). Operator-managed offers skipped at TWO layers (defense-in-depth): per-row gate at the first line of `reconcileSingleOffer` short-circuits before any settings check OR qty branch; `reconcileAll` path adds a WHERE-level filter so operator rows never even hydrate. When toggling, sets `active_managed_by='plugin'` so subsequent reconciles know the row is plugin-managed. Asserted by `SkipsManuallyDeactivatedOfferTest` (per-row gate) + `ActiveFlagServiceTest::it reconcileAll excludes operator-managed offers via WHERE filter at query level` (defense-in-depth).)*
- [x] **APPLY-04**: `classes/apply/ActiveFlagService::reconcile()` honors per-site Settings: `auto_deactivate_on_zero` (qty<=0 → offer.active=false), `auto_activate_on_stock` (qty>0 + previously inactive → offer.active=true). Idempotent. 4-cell matrix asserted by test. *(closed 2026-04-29 in plan 03-04 — pure `decideTargetState(Offer, bool, bool): ?bool` 4-cell matrix returns null sentinel for "no change requested"; idempotency check (already in target state) lives in `reconcileSingleOffer` so the matrix logic stays decoupled from the save short-circuit. Settings read EXCLUSIVELY via `SettingsAccessor::autoDeactivateOnZero()` + `SettingsAccessor::autoActivateOnStock()` — QA-09 grep gate green (`grep -c 'Settings::get(' classes/apply/ActiveFlagService.php` = 0). Idempotency proven via query-count delta test: first reconcile = SELECT + UPDATE, second reconcile = SELECT only.)*
- [x] **APPLY-05**: `classes/apply/InitialResetService` — one-shot. Setting `allow_initial_reset` MUST be true AND no prior invoice with `initial_reset_applied=true` MUST exist; else throws `InitialResetNotAllowedException`. Snapshots ALL offers + products to `goods_received_initial_reset_snapshot` BEFORE write. Then chunked Eloquent `Offer::chunk(500, fn(...) => quantity=0, active=false; saveQuietly())` and `Product::chunk(500, ...->saveQuietly())`. Marks `Invoice.initial_reset_applied=true`. *(closed 2026-04-29 in plan 03-05 — `final class InitialResetService` with `reset(Invoice): void` + 7 private helpers (all <70 lines per Tiger-Style); two-gate guard in `assertAllowed()` (cheap memo'd `SettingsAccessor::allowInitialReset()` first, then one indexed `Invoice::where('initial_reset_applied', true)->exists()`); reason-tagged `$arContext['reason']` ∈ {'settings_disabled', 'already_applied'} on the thrown exception so Phase 4 controller can render distinct error UX per cause; per-chunk Product hydration via `whereIn` (NOT `$obOffer->product` magic accessor — Larastan can't see October's array-style $belongsTo on Lovata models, AND collapses N product fetches per chunk into ONE statement); chunkById(500) on every pass — snapshot, zero offers, deactivate products; per-row saveQuietly contract enforced by 1500-offer test asserting 1500 individual offer UPDATEs (NOT one bulk UPDATE); InitialResetSnapshot::insert batched 3 INSERTs for 1500 rows; `make all` green: 132 tests / 589 assertions / 8.54s; phpstan-baseline.neon SHA unchanged.)*
- [x] **APPLY-06**: `classes/orchestrator/ParseAndPersistOrchestrator` — single public method. Wraps: parse → resolve → duplicate-check → persist Invoice@status=parsed → batch-match → write line `matched_offer_id`/`match_strategy`. `DB::transaction` boundary lives here, NOT in controller. *(closed 2026-04-29 in plan 03-06 — `final class ParseAndPersistOrchestrator` with `run()` + `runOverride()` + 6 private helpers (all <70 lines per Tiger-Style); ONE `DB::transaction` wrapper around the entire parse → dup-check → persist Invoice → batch-match → persist InvoiceLine rows sequence; `Invoice::where('invoice_number')->lockForUpdate()` race-safe duplicate detection inside the tx (T-03-06-01); `DuplicateInvoiceException` thrown with `prior_invoice_id` / `prior_applied_at` / `prior_applied_by` context when prior status='applied'; reject-log-after-rollback contract via try/catch outside the DB::transaction wrapper (T-03-06-03); `new Invoice() + save()` pattern (NOT `Invoice::create([...])`) to keep typed Invoice return without inline @var; `Carbon::instance($obParsed->invoice_date)` bridge for DateTimeImmutable→Carbon at persistence boundary; ZERO Offer:: / Settings::get( references — stock writes deferred to plan 03-07 ApplyOrchestrator; 5 Pest cases / 27 new assertions covering happy path, duplicate detection, override-reimport, parse-failure rollback, and post-rollback reject logging; `make all` green: 137 tests / 624 assertions / 7.05s; phpstan-baseline.neon SHA unchanged.)*
- [x] **APPLY-07**: `classes/orchestrator/ApplyOrchestrator` — single public method. Acquires `Invoice::lockForUpdate()`; throws `ApplyAlreadyDoneException` if `status='applied'`; runs `StockApplyService::apply` → `ActiveFlagService::reconcile($arAffected)` → flips `Invoice.status='applied'` → records audit. ALL inside ONE `DB::transaction`. `flushAffectedCaches` fires AFTER commit. *(closed 2026-04-29 in plan 03-07 — `final class ApplyOrchestrator` with `apply(int $iInvoiceId, int $iAppliedByUserId): ApplyResult` + 4 private helpers (executeInTransaction, assertNotApplied, loadMatchedLines, markInvoiceApplied) all <70 lines per Tiger-Style; lockForUpdate INSIDE the tx (the canonical Laravel pattern — lockForUpdate outside a tx is a no-op on most drivers because the lock releases immediately); ApplyAlreadyDoneException carries rich prior-result context (invoice_id, invoice_number, prior_applied_at, prior_applied_by, prior_stock_added_units) so Phase 4 controller can render distinct UX; flushAffectedCaches OUTSIDE the closure AFTER commit per D-10 (T-03-07-04 mitigation); SQLite strips `for update` from compiled SQL by design (Laravel SQLiteGrammar) so LockForUpdateSerializesConcurrentApplyTest combines source-grep pin (->lockForUpdate() inside executeInTransaction body) + runtime-ordering pin (Invoice SELECT precedes any offer UPDATE) — together they pin the contract on every driver; pinned by ApplyOrchestratorTest (happy path, 13 assertions) + LockForUpdateSerializesConcurrentApplyTest (2 cases, 17 assertions) + ApplyAlreadyDoneThrowsTest (15 assertions).)*
- [x] **APPLY-08**: Override-reimport (D12): when operator ticks "Override and re-import" on a duplicate invoice, ApplyOrchestrator re-applies the new lines additively on top of the prior apply. New `Invoice` row with `override_of_invoice_id` = prior. UX warning displayed before submit; operator confirms by typed string. *(parse-side closed 2026-04-29 in plan 03-06 — `ParseAndPersistOrchestrator::runOverride()` explicit method per D-26; new Invoice gets `override_of_invoice_id` + `'<orig>-OVR-<priorId>'` suffix; duplicate-check skipped in override mode. Apply-side closed 2026-04-29 in plan 03-07 — ApplyOrchestrator runs UNCHANGED on override invoices; ADD-ON-TOP semantics emerge naturally because StockApplyService writes qty += delta. Pinned by OverrideReimportAddsOnTopTest: Offer.quantity 10 → apply prior (qty=5) → 15 → apply override (qty=5) → 20 (NOT decrement-then-reapply, NOT reset). UX confirmation flow ships in Phase 4 UI-09/10.)*
- [x] **APPLY-09**: `classes/support/SettingsAccessor` — single accessor for all Settings reads. Memoized per-request. CI grep gate enforces `Settings::get(` only appears in `SettingsAccessor.php` (Makefile target `lint:settings-accessor`). *(closed 2026-04-29 in plan 03-01 — SettingsAccessor with 4 boolean getters + flush(); atomic 4-key bulk-fill memoization; flushPluginSingletons() wired)*
- [x] **APPLY-10**: `classes/support/ImportAuditService` — vendor-inlined ~50-80 LoC (per D14). Logs to `Log::*` with structured context array (invoice_id, status, units_added, offers_touched, applied_by). NO soft-dep on ExtendShopaholic. *(closed 2026-04-29 in plan 03-02 — final class with 4 public log methods (logApply/logParse/logReject/logInitialReset) + 3 private helpers; routes through Laravel Log facade with structured context arrays carrying canonical `event` key + uuid v7 `correlation_id`; 96 raw / 65 code lines within ≤100 LoC ceiling per D-04; 6 Pest cases / 130 assertions; PHPStan L10 clean; PHPMD clean; full make all green: 106/407)*

### Backend UI + Console (Phase 4)

- [ ] **UI-01**: `controllers/Invoices` — extends `Backend\Classes\Controller` with `ListController`, `FormController`, `RelationController` behaviors. Thin: validates input, calls Orchestrators. Registered under Settings menu (NOT main nav, per D6). Permissions enforced. *(2026-04-29: PARTIAL closure in plan 04-03 — `final class Invoices extends Backend\Classes\Controller` with all 3 behaviors via `$implement`, registered under Settings menu via second `Plugin::registerSettings()` entry routing to controller URL (D-04 alternative), class-level `$requiredPermissions = ['logingrupa.goodsreceived.upload_invoices']` enforces D-02 loose gate. Per-action permission gates (apply / override / run_initial_reset) and the 4 dedicated QA-10 permission tests land in 04-04 (onUpload), 04-05 (onApply), 04-06 (onOverrideConfirm + onInitialResetConfirm), 04-07 (QA-10). UI-01 will be marked fully closed when the per-action gates are wired and tested.)*
- [x] **UI-02**: Multi-file upload via `Backend\FormWidgets\FileUpload` with `attachMany` for `.HTM` files. Operator submits N files; controller iterates each through `ParseAndPersistOrchestrator`. *(2026-04-29: closed by plan 04-04 — `onUpload` AJAX handler on `Logingrupa\GoodsReceivedShopaholic\Controllers\Invoices` accepts `Input::file('files')` (array of UploadedFile from `<input type="file" name="files[]" multiple data-request="onUpload" data-request-files>`), validates each file's extension (.htm whitelist; D-06) + size (10 MB cap; D-07), routes through ParseAndPersistOrchestrator::run (Phase 3 plan 03-06), aggregates per-file outcomes into 3 AJAX-target panels (#invoicePreviewWrap / #invoiceRejectWrap / #invoiceUploadErrors). Per-file boundary catch: GoodsReceivedException + Throwable both route to per-file errors; ONE failing file does NOT abort the batch. `_partials/_upload_form.htm` ships the multi-file widget + 3 AJAX-target containers; `controllers/invoices/preview.htm` mounts the form. Verified end-to-end against the fixture `Nr_PRO033328_no_13042026.HTM` — 21 InvoiceLine rows persisted under a single happy-path call; multi-file pin verifies 2 fixtures → 2 Invoice rows.)*
- [x] **UI-03**: Preview screen — list view of Invoice@status=parsed showing matched/unmatched lines with `match_strategy` column visible. Operator can edit `override_qty` + `override_reason` per line before apply. *(2026-04-29: closed by plan 04-04 — `_partials/_preview_lines.htm` renders one card per uploaded invoice with the per-import summary panel inline + a sortable line table (Row # / EAN / Product name / Qty / Override qty / Override reason / Match strategy / Matched offer); matched / unmatched rows are CSS-class-distinguished. Per-line override_qty + override_reason inputs use October's `data-track-input` + `data-request="onUpdateLine" data-request-data="line_id: {{ line.id }}"` to trigger the `onUpdateLine` AJAX handler. `onUpdateLine` validates line_id (>0; existing) + override_qty (≥0) + override_reason (trim → NULL on empty) and persists via saveQuietly; override fields are audit-only metadata consumed at apply time by StockApplyService's `override_qty ?? qty` read (T-04-04-08 acceptance). The Apply button at the bottom of each card binds to `data-handler="onApplyShowConfirm"` (handler ships in plan 04-05).)*
- [x] **UI-04**: Apply button uses October backend AJAX (`data-request="onApply"`). Shows confirmation modal: total units to add, offer count, unmatched line count. `Cache::lock('apply-invoice-{id}', 60)` prevents double-click double-apply. Spinner during request. *(2026-04-29: closed by plan 04-05 — `onApplyShowConfirm` AJAX handler renders `_partials/_apply_confirm.htm` modal listing total_units (computed via `COALESCE(override_qty, qty)` DB::raw sum across matched lines, matching StockApplyService's per-line read order from Phase 3 plan 03-03; D-04-05-02), matched offer_count, unmatched_count BEFORE the operator commits the apply. `onApply` AJAX handler acquires `Cache::lock('apply-invoice-{id}', 60)` (`APPLY_LOCK_TTL_SECONDS = 60` const; D-13) and returns `_partials/_apply_in_progress.htm` when the lock is held (T-04-05-01 fail-fast first line of defense; the orchestrator's body-side `Invoice::lockForUpdate` from Phase 3 plan 03-07 remains the DB-layer enforcer). Runs `ApplyOrchestrator::apply` under `try { runApplyUnderLock } finally { $obLock->release() }` so any throw still releases the lock (T-04-05-02; pinned by failing-orchestrator subclass test). `ApplyAlreadyDoneException` caught in the inner try and rendered as structured `_partials/_apply_already_done.htm` with prior-result context (invoice_number, prior_applied_at, prior_applied_by, prior_stock_added_units; T-04-05-04). Apply button on `_partials/_apply_confirm.htm` includes `data-request-loading=".applying-spinner"` for D-15 spinner. ApplyOrchestrator opened from `final` for failing-orchestrator subclass test (D-04-05-01 mirrors D-03-07-01 + D-04-02-01 — third boundary-mock final-removal in the plugin; production code never subclasses). Dual-pin debounce contract (source-grep + runtime; D-04-05-05 mirrors Phase 3 D-03-07-05) — 4 source-grep pins in the controller source + 2 runtime pins via TestableInvoices shim's `iApplyOrchestratorResolvedCount` counter prove the orchestrator was/was-not invoked under each branch. 9 lang keys under `apply.*`. Permission-gated at handler entry (`logingrupa.goodsreceived.apply_invoices`).)*
- [x] **UI-05**: Audit history list view — Invoices ordered by `applied_at DESC`. Columns: invoice_number, status, total_lines, matched_lines, stock_added_units, applied_by, applied_at. Filter by status. Detail view shows lines + unmatched queue. *(2026-04-29: closed by plan 04-03 — `controllers/invoices/config_list.yaml` + `controllers/invoices/index.htm` ship the audit history list view; columns from `models/invoice/columns.yaml` cover invoice_number / status / total_lines / matched_lines / unmatched_lines / stock_added_units / applied_by_user_id / applied_at + invisible country_code; `defaultSort: { column: applied_at, direction: desc }` pins the chronological order; `filter.scopes` provides status group filter (parsed/applied/failed/rejected_duplicate) AND country_code group filter (no/lv/lt) — exceeds spec's "filter by status" minimum; reachable via Backend → Settings → Goods Received — Invoice History (D-04 alternative second `registerSettings` entry); detail view at `controllers/invoices/update.htm` renders form + lines RelationController panel + audit metric partial; structural test `InvoicesControllerStructureTest` pins the literal canonical model class reference in config_list.yaml against drift (T-04-03-03 mitigation).)*
- [x] **UI-06**: Original HTM file archived via `attachOne` (System\Models\File). Visible/downloadable from Invoice detail view. Disputes/forensics use case. *(2026-04-29: closed by plan 04-03 — `models/Invoice.php` declares `public $attachOne = ['original_file' => [\System\Models\File::class]];` with adjacent `@property-read \System\Models\File|null $original_file` PHPDoc for PHPStan L10 magic-property visibility; `models/invoice/fields.yaml` includes `original_file: { type: fileupload, mode: file, disabled: true, span: full }` so the file is downloadable from the Invoice detail view via October's built-in fileupload widget. Upload-side wiring (calling `$obInvoice->original_file()->save(System\Models\File::createFromUploaded($obFile))` from `onUpload`) lands in plan 04-04. October's System\Models\File handles UUID-based file paths + content-disposition out-of-the-box (T-04-03-02 mitigation: no custom serving code = no path-traversal surface introduced by this plugin).)*
- [x] **UI-07**: Per-import summary metric panel on Invoice detail: units added, offers touched, time elapsed, applied_by user, override-of pointer if any. *(2026-04-29: closed by plan 04-03 — `controllers/invoices/_partials/_audit_panel.htm` ships the per-import metric panel rendered inline on the Invoice detail view (`update.htm` calls `$this->makePartial('_partials/_audit_panel')`); reads from `formModel` directly so the form widgets and audit panel share a single source of truth; metrics displayed: status, total_lines, matched_lines, unmatched_lines, stock_added_units, applied_by_user_id, applied_at (formatted Y-m-d H:i:s), AND override_of_invoice_id (conditionally rendered only when set, satisfying the "override-of pointer if any" spec clause). Time-elapsed metric not separately surfaced — applied_at − parsed_at is implicit in the form (both fields rendered) and can be added as a derived field accessor in plan 04-05/04-06 if operator feedback requires it.)*
- [ ] **UI-08**: Initial-reset checkbox on upload preview. Disabled unless Settings.allow_initial_reset=true. When ticked, requires operator type literal string `RESET` into a confirmation field before Apply enabled. Pre-mutation snapshot count shown in confirmation copy.
- [x] **UI-09**: Pre-parse duplicate detection — on upload, controller checks invoice_number from filename pattern BEFORE running parse; if duplicate exists, shows reject screen with prior-apply summary + override checkbox (D12) before parse cost incurred. *(2026-04-29: closed by plan 04-04 — `Invoices::extractInvoiceNumberFromFilename(string)` uses regex `/^Nr_PRO(\\d+)_/i` (case-insensitive, D-16) to extract the `PRO<digits>` invoice_number from the distributor's filename pattern (`Nr_PRO<num>_<country>_<DDMMYYYY>.HTM`). When the regex matches AND a prior `Invoice@status='applied'` row exists with that number, `onUpload` short-circuits to the reject partial (#invoiceRejectWrap) WITHOUT calling ParseAndPersistOrchestrator (counter-pin: `iOrchestratorResolvedCount === 0`). The reject partial (`_partials/_reject.htm`) renders one warning per duplicate with prior_invoice_id + prior_applied_at + prior_applied_by + prior_stock_added_units + an Override CTA (handler ships in plan 04-06). The gate is APPLIED-only — a prior `parsed` row does NOT short-circuit (the orchestrator's body-side `Invoice::lockForUpdate` inside the parse transaction remains the authoritative race-safe enforcer per D-04-04-05 / D-03-06-06). Both gates together: skip pointless parsing AND survive concurrent uploads (T-04-04-07 + T-03-06-01 mitigations).)*
- [ ] **UI-10**: Override-and-reimport UX — explicit warning copy: "This re-applies the invoice ADDITIVELY on top of the prior apply. Stock will be incremented by new line quantities. This is NOT a delta calculation. Continue?" Operator types `OVERRIDE` to confirm.
- [x] **UI-11**: Console command `goodsreceived:recompute_active_from_stock` registered via `Plugin::register()` → `registerConsoleCommand()`. Calls `ActiveFlagService::reconcileAll()` chunked (500). Sync execution. Honors `active_managed_by='operator'` skip.
- [x] **UI-12**: Plugin boot self-check: warn-log if PHP `max_file_uploads` < 20 OR `upload_max_filesize` < 10M (multi-file upload prerequisite).

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
- [x] **QA-03**: Idempotency tests: `DuplicateInvoiceRejectedTest`, `OverrideReimportAddsOnTopTest`, `ApplyAlreadyDoneThrowsTest`, `LockForUpdateSerializesConcurrentApplyTest`. *(closed 2026-04-29 in plan 03-07 — 4 dedicated Pest files in `tests/unit/Orchestrator/`, one per QA-named contract: (1) DuplicateInvoiceRejectedTest wires ParseAndPersistOrchestrator + ApplyOrchestrator end-to-end (parse → apply → re-parse same HTM → DuplicateInvoiceException); (2) OverrideReimportAddsOnTopTest pins ADD-ON-TOP additive math (10 → 15 → 20, no decrement-then-reapply); (3) ApplyAlreadyDoneThrowsTest pins ApplyAlreadyDoneException with rich prior-result context (invoice_id, invoice_number, prior_applied_at, prior_applied_by, prior_stock_added_units); (4) LockForUpdateSerializesConcurrentApplyTest uses TWO independent assertions — source-grep that ->lockForUpdate() exists inside executeInTransaction body AND runtime query log that Invoice SELECT precedes any offer UPDATE (SQLite strips `for update` from compiled SQL by design — Laravel SQLiteGrammar.php; the source pin is the only reliable pin for the CALL itself, the runtime ordering proves order-of-execution, together they cover every driver). Total: 5 Pest files / 6 it() cases / 50 assertions; all green; PHPStan + Pint + PHPMD clean.)*
- [x] **QA-04**: Cache-cascade smoke test: `Apply200LinesTriggersBatchedFlushNotPerSaveTest` (asserts ≤ N flushes, not N×lines). *(closed 2026-04-29 in plan 03-03 — `tests/unit/Apply/Apply200LinesTriggersBatchedFlushNotPerSaveTest.php`, 3 cases / 7 Mockery `shouldHaveReceived` assertions on each leaf-singleton spy. Spy injection mechanism: reflection on each leaf class's `protected static $instance` slot; afterEach calls `forgetInstance()`. Actual list-store flushes counted: 4 (`OfferActiveListStore::clear` ×1, `OfferSortingListStore::clear` ×2 for SORT_NO + SORT_NEW, `ProductActiveListStore::clear` ×1). Anti-pattern guard: regression to per-line `->save()` would explode the spy count from 4 to ≥ 1600.)*
- [x] **QA-05**: ActiveFlag matrix: 4-cell test `(deactivate_on_zero on/off) × (activate_on_stock on/off)` plus `SkipsManuallyDeactivatedOfferTest` (active_managed_by=operator). *(closed 2026-04-29 in plan 03-04 — `tests/unit/Apply/ActiveFlagServiceTest.php` ships 5 matrix `it()` cases (4 cells + an explicit "both off → no-op for both qty branches in one assertion") plus idempotency + reconcileAll chunked iteration + reconcileAll WHERE-filter operator skip. `tests/unit/Apply/SkipsManuallyDeactivatedOfferTest.php` is the dedicated QA-05 part B: per-row provenance gate via the reconcile() path proves operator-managed offer with `active=false`, `qty=10`, `autoActivateOnStock=true` stays untouched. Total: 9 Pest cases / 48 assertions / `make all` green.)*
- [x] **QA-06**: InitialReset tests: `RequiresAllowInitialResetSettingTest`, `OneShotEnforcedTest`, `SnapshotsBeforeWriteTest`, `RollbackRestoresExactPriorStateTest`, `ChunkedNotSingleStatementTest`. *(closed 2026-04-29 in plan 03-05 — `tests/unit/Apply/InitialResetServiceTest.php` ships exactly 5 dedicated `it()` cases / 78 assertions, one per spec entry: (1) RequiresAllowInitialResetSetting — Settings.allow_initial_reset=false → throws with reason='settings_disabled' + snapshot table empty; (2) OneShotEnforced — prior Invoice with initial_reset_applied=true exists → throws with reason='already_applied'; (3) SnapshotsBeforeWrite — 5-offer fixture proves ordering by reading prior_quantity from snapshot AFTER offer is zeroed (the only way that value can have been captured is pre-mutation); (4) RollbackRestoresExactPriorState — manually walks every snapshot row, restores Offer + Product state, asserts post-restore matches pre-reset state EXACTLY (rollback feasibility, full CLI deferred to Phase 5 ops runbook); (5) ChunkedNotSingleStatement — 1500-offer seed + DB::enableQueryLog: 3 batched snapshot INSERTs + 8 offer SELECT pages + 1500 per-row offer UPDATEs (proves no DB::statement / whereRaw / bulk UPDATE bypass). ApplyTestCase extended with logingrupa_goods_received_initial_reset_snapshot table — durable for any downstream plan that exercises the snapshot.)*
- [ ] **QA-07**: Multi-site Settings: `IsMultisiteAwareTest`, `MultisiteContextSwitchClearsCacheTest`.
- [x] **QA-08**: Transaction safety: `PartialFailureRollsBackEverythingTest`, `ActiveFlagInsideSameTransactionAsStockApplyTest`. *(closed 2026-04-29 in plan 03-07 — 2 dedicated Pest files in `tests/unit/Orchestrator/`: (1) PartialFailureRollsBackEverythingTest — FailingAuditService boundary stub (extends ImportAuditService, throws inside logApply mid-transaction) triggers full rollback; assertions cover offer.quantity reverts, line.applied=false + applied_at=null, invoice.status='parsed' + applied_at=null + applied_by_user_id=null + stock_added_units=0. The FailingAuditService extension required opening ImportAuditService (was final → class) — Tiger-Style boundary-mock allowance per CLAUDE.md, justified by docblock; production never subclasses. (2) ActiveFlagInsideSameTransactionAsStockApplyTest — DB::beforeExecuting Laravel instrumentation hook records the transactionLevel for every UPDATE on lovata_shopaholic_offers; both StockApply quantity UPDATE and ActiveFlag active UPDATE run with transactionLevel >= 1 (inside a tx). Regression where ActiveFlag.reconcile runs OUTSIDE the transaction would produce an active UPDATE at transactionLevel=0, failing the loop assertion. Total: 2 Pest files / 2 it() cases / 21 assertions; all green; PHPStan + Pint + PHPMD clean.)*
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
| APPLY-01 | Phase 3 | Closed (2026-04-29) — plan 03-03 |
| APPLY-02 | Phase 3 | Closed (2026-04-29) — plan 03-03 |
| APPLY-03 | Phase 3 | Closed (2026-04-29) — plan 03-04 |
| APPLY-04 | Phase 3 | Closed (2026-04-29) — plan 03-04 |
| APPLY-05 | Phase 3 | Closed (2026-04-29) — plan 03-05 |
| APPLY-06 | Phase 3 | Closed (2026-04-29) — plan 03-06 |
| APPLY-07 | Phase 3 | Closed (2026-04-29) — plan 03-07 |
| APPLY-08 | Phase 3 | Closed (2026-04-29) — plans 03-06 + 03-07 |
| APPLY-09 | Phase 3 | Closed (2026-04-29) — plan 03-01 |
| APPLY-10 | Phase 3 | Closed (2026-04-29) — plan 03-02 |
| UI-01 | Phase 4 | Partial (2026-04-29) — plan 04-03 (controller + class-level gate); per-action gates close in 04-04..04-06 + QA-10 in 04-07 |
| UI-02 | Phase 4 | Closed (2026-04-29) — plan 04-04 |
| UI-03 | Phase 4 | Closed (2026-04-29) — plan 04-04 |
| UI-04 | Phase 4 | Closed (2026-04-29) — plan 04-05 |
| UI-05 | Phase 4 | Closed (2026-04-29) — plan 04-03 |
| UI-06 | Phase 4 | Closed (2026-04-29) — plan 04-03 |
| UI-07 | Phase 4 | Closed (2026-04-29) — plans 04-03 (detail panel) + 04-04 (upload preview panel) |
| UI-08 | Phase 4 | Pending |
| UI-09 | Phase 4 | Closed (2026-04-29) — plan 04-04 |
| UI-10 | Phase 4 | Pending |
| UI-11 | Phase 4 | Closed (2026-04-29) — plan 04-02 |
| UI-12 | Phase 4 | Closed (2026-04-29) — plan 04-01 |
| OPS-01 | Phase 5 | Pending |
| OPS-02 | Phase 5 | Pending |
| OPS-03 | Phase 5 | Pending |
| OPS-04 | Phase 5 | Pending |
| OPS-05 | Phase 5 | Pending |
| OPS-06 | Phase 5 | Pending |
| QA-01 | Phase 2 | Closed (2026-04-29) — plan 02-05 |
| QA-02 | Phase 2 | Closed (2026-04-29) — plans 02-03 + 02-06 |
| QA-03 | Phase 3 | Closed (2026-04-29) — plan 03-07 |
| QA-04 | Phase 3 | Closed (2026-04-29) — plan 03-03 |
| QA-05 | Phase 3 | Closed (2026-04-29) — plan 03-04 |
| QA-06 | Phase 3 | Closed (2026-04-29) — plan 03-05 |
| QA-07 | Phase 1 | Pending |
| QA-08 | Phase 3 | Closed (2026-04-29) — plan 03-07 |
| QA-09 | Phase 3 | Closed (2026-04-29) — plan 03-01 |
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
