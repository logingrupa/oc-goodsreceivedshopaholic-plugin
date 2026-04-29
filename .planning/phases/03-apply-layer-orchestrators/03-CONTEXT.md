# Phase 3: Apply Layer + Orchestrators - Context

**Gathered:** 2026-04-29
**Status:** Ready for planning
**Mode:** Auto (smart-discuss `--auto`)

<domain>
## Phase Boundary

Apply parsed invoices to live stock idempotently inside a single DB transaction with provenance-aware active-flag reconcile, batched cache flush, and one-shot baseline reset; ALL Settings reads go through `SettingsAccessor`.

**In scope:**
- `classes/support/SettingsAccessor` (single accessor; DRY-grep gate enforced)
- `classes/support/ImportAuditService` (vendor-inline ~50-80 LoC; logs to `Log::*` with structured context)
- `classes/apply/StockApplyService` (Eloquent `saveQuietly` per offer; batched post-commit cache flush via `OfferListStore::instance()->clearCache()` once per affected offer batch — NOT once per line)
- `classes/apply/ActiveFlagService` (provenance-aware: skips offers with `active_managed_by='operator'`; reconciles only affected offers, NOT full table; sets `active_managed_by='plugin'` when toggling)
- `classes/apply/InitialResetService` (one-shot guard; snapshots all offers + products BEFORE writing; chunked `Offer::chunk(500)` + `Product::chunk(500)` with `saveQuietly`)
- `classes/orchestrator/ParseAndPersistOrchestrator` (parse → resolve → duplicate-check → persist Invoice@status=parsed → batch-match → write line `matched_offer_id`/`match_strategy`; `DB::transaction` boundary)
- `classes/orchestrator/ApplyOrchestrator` (`Invoice::lockForUpdate()` + `ApplyAlreadyDoneException` on duplicate apply; runs `StockApplyService` → `ActiveFlagService::reconcile($arAffected)` → flips `Invoice.status='applied'` → records audit ALL inside ONE `DB::transaction`; `flushAffectedCaches` AFTER commit)
- Override-reimport flow (D12): new `Invoice` row with `override_of_invoice_id` pointer; ADD-ON-TOP semantics
- CI grep gate (`make lint:settings-accessor`)

**Out of scope:**
- Backend controller (Phase 4 UI-01..10)
- Console command (Phase 4 UI-11)
- Backend AJAX (Phase 4 UI-04)
- Plugin boot self-check (Phase 4 UI-12)
- README + runbook (Phase 5)

</domain>

<spec_lock>
## Locked Requirements (REQUIREMENTS.md)

Phase 3 reqs LOCKED:
- **APPLY-01..10** — Stock apply, batched cache flush, ActiveFlag, InitialReset, ParseAndPersistOrchestrator, ApplyOrchestrator, override-reimport, SettingsAccessor, ImportAuditService (lines 48-57)
- **QA-03** — Idempotency tests: DuplicateInvoiceRejectedTest, OverrideReimportAddsOnTopTest, ApplyAlreadyDoneThrowsTest, LockForUpdateSerializesConcurrentApplyTest
- **QA-04** — Cache-cascade smoke: Apply200LinesTriggersBatchedFlushNotPerSaveTest
- **QA-05** — ActiveFlag matrix 4-cell + SkipsManuallyDeactivatedOfferTest
- **QA-06** — InitialReset tests (RequiresAllowInitialResetSetting, OneShotEnforced, SnapshotsBeforeWrite, RollbackRestoresExactPriorState, ChunkedNotSingleStatement)
- **QA-08** — Transaction safety: PartialFailureRollsBackEverythingTest, ActiveFlagInsideSameTransactionAsStockApplyTest
- **QA-09** — Settings DRY gate: SettingsAccessorIsSoleConsumerOfSettingsGetTest (CI grep)

</spec_lock>

<decisions>
## Implementation Decisions

### SettingsAccessor (APPLY-09 + QA-09)
- **D-01:** `classes/support/SettingsAccessor.php` — singleton-style static service. Memoized per-request (in-memory cache; cleared in `flushPluginSingletons()`).
  ```php
  final class SettingsAccessor {
      private static ?array $arCache = null;
      
      public static function isEnabled(): bool { return self::get('enabled'); }
      public static function autoDeactivateOnZero(): bool { return self::get('auto_deactivate_on_zero'); }
      public static function autoActivateOnStock(): bool { return self::get('auto_activate_on_stock'); }
      public static function allowInitialReset(): bool { return self::get('allow_initial_reset'); }
      
      public static function flush(): void { self::$arCache = null; }
      
      private static function get(string $sKey): bool {
          self::$arCache ??= [
              'enabled' => (bool) Settings::get('enabled'),
              'auto_deactivate_on_zero' => (bool) Settings::get('auto_deactivate_on_zero'),
              'auto_activate_on_stock' => (bool) Settings::get('auto_activate_on_stock'),
              'allow_initial_reset' => (bool) Settings::get('allow_initial_reset'),
          ];
          return self::$arCache[$sKey];
      }
  }
  ```
- **D-02:** `Settings::get(` literal STRING appears ONLY in `SettingsAccessor.php`. Enforced by:
  - Makefile target `lint:settings-accessor` — `grep -rn "Settings::get(" classes/ components/ models/ controllers/ Plugin.php | grep -v "classes/support/SettingsAccessor.php" | (! read)`
  - Pest test `SettingsAccessorIsSoleConsumerOfSettingsGetTest` runs the same grep at test time
- **D-03:** Hook `SettingsAccessor::flush()` into `GoodsReceivedTestCase::flushPluginSingletons()` (Phase 1's empty hook gets first body here).

### ImportAuditService (APPLY-10)
- **D-04:** `classes/support/ImportAuditService.php` ~50-80 LoC. Vendor-inlined per D-14.
  ```php
  final class ImportAuditService {
      public function logApply(int $iInvoiceId, ApplyResult $obResult, int $iAppliedByUserId): void { ... }
      public function logParse(int $iInvoiceId, ParsedInvoice $obParsed): void { ... }
      public function logReject(string $sReason, array $arContext = []): void { ... }
      public function logInitialReset(int $iInvoiceId, int $iOffersZeroed, int $iProductsDeactivated): void { ... }
  }
  ```
- **D-05:** All log entries via `Log::info()` / `Log::warning()` / `Log::error()` with structured context array (json-safe per Phase 2 GoodsReceivedException pattern). Keys: `event` (e.g., 'apply', 'parse', 'reject'), `invoice_id`, `units_added`, `offers_touched`, `applied_by`, `correlation_id` (uuid v7).

### StockApplyService (APPLY-01, APPLY-02, QA-04)
- **D-06:** `classes/apply/StockApplyService.php`. Public method:
  ```php
  public function apply(Invoice $obInvoice, array $arMatchedLines): ApplyResult
  ```
- **D-07:** For each matched line, increment `offer.quantity` via Eloquent `$obOffer->quantity += $iQty; $obOffer->saveQuietly()`. NEVER `DB::statement` (skips cache invalidation hooks). NEVER `->save()` per-line (fires 8-12 cache flushes per offer × N lines = 1200+ flushes for 200-line invoice — that's the QA-04 anti-pattern).
- **D-08:** After ALL line writes commit, fire ONE batched cache flush per `OfferListStore`-style cache. Implementation:
  ```php
  // Collect affected offer IDs during apply
  $arAffectedOfferIds = [];
  foreach ($arMatchedLines as $obLine) { ... $arAffectedOfferIds[] = $obLine->matched_offer_id; ... }
  // After tx commit, call:
  $this->flushAffectedCaches($arAffectedOfferIds);
  ```
  ```php
  public function flushAffectedCaches(array $arOfferIds): void {
      \Lovata\Shopaholic\Classes\Store\OfferListStore::instance()->clearCache();
      // ... potentially other stores: ProductListStore, etc. — investigate Lovata Shopaholic for canonical batched cache flush patterns
  }
  ```
- **D-09:** Test (QA-04, Apply200LinesTriggersBatchedFlushNotPerSaveTest): seed 200 unique offers, parse a 200-line invoice (use synthetic test fixture), apply, count cache-flush invocations. Assert ≤ 5 (or ≤ N where N is number of distinct stores flushed batched, NOT 1200+).
- **D-10:** Cache flush MUST occur AFTER `DB::transaction` commits. Inside transaction = wasted (cache repopulates from in-flight stale data). Use `DB::transaction(function() { ... return $arAffectedIds; }); $this->flushAffectedCaches($arAffected);` pattern.

### ActiveFlagService (APPLY-03, APPLY-04, QA-05)
- **D-11:** `classes/apply/ActiveFlagService.php`. Public method:
  ```php
  public function reconcile(array $arAffectedOfferIds): void
  ```
- **D-12:** For each offer in `$arAffectedOfferIds`:
  - Skip if `offer.active_managed_by === 'operator'` (operator-set deactivate) — NEVER touch
  - Read settings via `SettingsAccessor::autoDeactivateOnZero()` and `SettingsAccessor::autoActivateOnStock()`
  - Compute target state:
    - If `qty <= 0` AND `auto_deactivate_on_zero`: target `active=false, active_managed_by='plugin'`
    - If `qty > 0` AND `auto_activate_on_stock` AND `active=false`: target `active=true, active_managed_by='plugin'`
    - Else: no-op (idempotent)
  - Write via `saveQuietly` (no event spam)
- **D-13:** 4-cell matrix test (QA-05):
  - (deactivate=on, activate=on) — both transitions occur
  - (deactivate=on, activate=off) — only deactivate-on-zero fires
  - (deactivate=off, activate=on) — only activate-on-stock fires
  - (deactivate=off, activate=off) — no transitions
- **D-14:** SkipsManuallyDeactivatedOfferTest: seed offer with `active=false`, `active_managed_by='operator'`, qty=10. With `auto_activate_on_stock=true`, call reconcile. Assert offer remains `active=false` (provenance respected).
- **D-15:** ActiveFlag reconcile MUST live INSIDE same DB::transaction as StockApplyService.apply (QA-08 ActiveFlagInsideSameTransactionAsStockApplyTest). Wrap both in ApplyOrchestrator.

### InitialResetService (APPLY-05, QA-06)
- **D-16:** `classes/apply/InitialResetService.php`. Public method:
  ```php
  public function reset(Invoice $obInvoice): void
  ```
- **D-17:** Guards (RequiresAllowInitialResetSetting, OneShotEnforced):
  - `SettingsAccessor::allowInitialReset()` MUST be true; else throws `InitialResetNotAllowedException`
  - No prior `Invoice` with `initial_reset_applied=true` may exist; else throws
- **D-18:** Snapshot BEFORE write (SnapshotsBeforeWriteTest):
  - For every Offer: insert row in `logingrupa_goods_received_initial_reset_snapshot` with `prior_quantity`, `prior_offer_active`, `prior_product_id`, `prior_product_active`
  - Use `Offer::chunk(500)` + `InitialResetSnapshot::insert(['offer_id' => ..., ...])` batch insert (NOT `->create()` per-row)
- **D-19:** After snapshot, mutation (ChunkedNotSingleStatementTest):
  - `Offer::chunk(500, fn($obChunk) => $obChunk->each(fn($obOffer) => { $obOffer->quantity = 0; $obOffer->active = false; $obOffer->active_managed_by = 'plugin'; $obOffer->saveQuietly(); }))`
  - `Product::chunk(500, fn($obChunk) => $obChunk->each(fn($obProduct) => { $obProduct->active = false; $obProduct->saveQuietly(); }))`
  - Mark `Invoice.initial_reset_applied = true; $obInvoice->saveQuietly()`
- **D-20:** Test RollbackRestoresExactPriorState: snapshot covers full state; rollback path (manual ops, not part of reset()) reads snapshot + restores. Phase 3 ships the snapshot; rollback CLI is Phase 4 UI-11 territory or Phase 5 ops runbook. Phase 3 only writes the snapshot.

### ParseAndPersistOrchestrator (APPLY-06)
- **D-21:** `classes/orchestrator/ParseAndPersistOrchestrator.php`. Public method:
  ```php
  public function run(string $sHtmlContent, string $sSourceFilename, int $iAppliedByUserId): Invoice
  ```
- **D-22:** Sequence inside `DB::transaction(function() {...})`:
  1. Parse: `$obParsed = HtmInvoiceParser::parse($sHtmlContent, $sSourceFilename)`
  2. Resolve invoice number (already done by parser via InvoiceNumberResolver — read `$obParsed->invoice_number`)
  3. Duplicate check: `Invoice::where('invoice_number', $obParsed->invoice_number)->lockForUpdate()->first()` — if exists AND status='applied' → throw `DuplicateInvoiceException` (with prior-apply context for UX). UNLESS override flag passed (D12 override-reimport — separate method `runOverride()`)
  4. Persist `Invoice` with `status='parsed'`, source_filename, parsed_at=now
  5. EanMatcher batch-match: `$arMatched = EanMatcherService::matchBatch(array_column($obParsed->lines, 'ean'))`
  6. Persist `InvoiceLine` rows with `matched_offer_id`, `match_strategy` per match result
  7. Audit: `ImportAuditService::logParse(...)`
  8. Return `$obInvoice`

### ApplyOrchestrator (APPLY-07, APPLY-08, QA-03, QA-08)
- **D-23:** `classes/orchestrator/ApplyOrchestrator.php`. Public method:
  ```php
  public function apply(int $iInvoiceId, int $iAppliedByUserId): ApplyResult
  ```
- **D-24:** Sequence:
  1. `$obInvoice = Invoice::lockForUpdate()->findOrFail($iInvoiceId)` (LockForUpdateSerializesConcurrentApplyTest)
  2. If `$obInvoice->status === 'applied'` → throw `ApplyAlreadyDoneException` with prior result context (ApplyAlreadyDoneThrowsTest)
  3. Inside `DB::transaction(function() use ($obInvoice) { ... })`:
     - Read `$arMatchedLines = $obInvoice->lines()->whereNotNull('matched_offer_id')->get()`
     - `$obResult = StockApplyService::apply($obInvoice, $arMatchedLines)` — returns ApplyResult, also updates each line's `applied=true, applied_at=now`
     - `$arAffectedOfferIds = $obResult->getAffectedOfferIds()` (added to ApplyResult — extends Phase 2 DTO contract via subclass `ExtendedApplyResult` OR added field — recommend adding optional `affected_offer_ids` to ApplyResult ahead of time; or pass via separate channel)
     - `ActiveFlagService::reconcile($arAffectedOfferIds)` — same transaction (QA-08 ActiveFlagInsideSameTransactionAsStockApplyTest)
     - `$obInvoice->status = 'applied'; $obInvoice->applied_at = now(); $obInvoice->applied_by_user_id = $iAppliedByUserId; $obInvoice->saveQuietly()`
     - `ImportAuditService::logApply(...)`
     - Return `[$obResult, $arAffectedOfferIds]`
  4. AFTER commit: `StockApplyService::flushAffectedCaches($arAffectedOfferIds)` — outside transaction (D-10)
- **D-25:** Partial failure inside transaction → rollback EVERYTHING (PartialFailureRollsBackEverythingTest). Tests use a controlled exception inside one of the line writes; assert `Offer.quantity` rolled back, `InvoiceLine.applied` reverted, `Invoice.status` not changed.

### Override-Reimport Flow (APPLY-08 / D12)
- **D-26:** `ParseAndPersistOrchestrator::runOverride(string $sHtmlContent, string $sSourceFilename, int $iPriorInvoiceId, int $iAppliedByUserId): Invoice` — explicit method, not a flag. Caller (Phase 4 controller) explicitly opts in.
- **D-27:** Behavior:
  - Re-parse HTM (same content; no duplicate-check this time)
  - Persist NEW `Invoice` row with `override_of_invoice_id = $iPriorInvoiceId`, status='parsed'
  - EanMatcher matches new lines (independent of prior)
  - Persist `InvoiceLine` rows
- **D-28:** When operator clicks Apply on the override invoice, `ApplyOrchestrator::apply(...)` runs as normal. Stock writes are ADDITIVE — no decrement of prior apply (per D12 add-on-top semantics). Test OverrideReimportAddsOnTopTest: seed Offer.quantity=10, apply prior invoice (qty=5 in line) → Offer.quantity=15, override-reimport same invoice (qty=5) → Offer.quantity=20 (NOT reset to 15 + 5 = 20 by decrement-then-reapply, just additive: 15 + 5 = 20).

### Settings + ApplyResult DTO Extension
- **D-29:** Phase 2 `ApplyResult` DTO is locked. To support `$arAffectedOfferIds` in Phase 3 path, EITHER:
  - Add optional field to `ApplyResult` (revise Phase 2 DTO — minor breaking change but tests in 02-01 protect signature)
  - OR: ApplyOrchestrator returns a tuple `[ApplyResult, list<int> $arAffectedOfferIds]` (cleaner; ApplyResult stays pure summary)
  - **Decision:** tuple. ApplyResult stays as Phase 2 defined.

### Tiger-Style + Conventions (carry from Phase 1+2)
- **D-30:** All new files `declare(strict_types=1);`. Hungarian. Functions <70 lines. Max 1 nesting level. Guard clauses + early returns.
- **D-31:** `final class` everywhere except where extension is required (none in Phase 3).
- **D-32:** `#[\Override]` only when actually overriding parent (don't reflexively add).
- **D-33:** PHPStan level 10 — full docblocks. No mixed. Explicit return types.
- **D-34:** Tests in `tests/unit/<Subdir>/` lowercase paths. Real DB (SQLite in-memory via `GoodsReceivedTestCase::$autoMigrate=true`). Real models. No mocking business logic (per project CLAUDE.md Tiger-Style rule).

### Test Strategy
- **D-35:** `tests/unit/Apply/StockApplyServiceTest.php`
- **D-36:** `tests/unit/Apply/ActiveFlagServiceTest.php` (4-cell matrix)
- **D-37:** `tests/unit/Apply/InitialResetServiceTest.php`
- **D-38:** `tests/unit/Orchestrator/ParseAndPersistOrchestratorTest.php`
- **D-39:** `tests/unit/Orchestrator/ApplyOrchestratorTest.php` (idempotency + transaction safety + lockForUpdate)
- **D-40:** `tests/unit/Support/SettingsAccessorTest.php` + grep gate test
- **D-41:** `tests/unit/Support/ImportAuditServiceTest.php`
- **D-42:** SQLite migration order: tests rely on Phase 1 schema (logingrupa_goods_received_*). The `TestCase::setUp` calls `migrateModules()` + `migrateCurrentPlugin()`. Verify Phase 1 migrations + the offers extension (active_managed_by) apply cleanly in test mode.

### Claude's Discretion
- Exact `OfferListStore::instance()->clearCache()` call signature — verify in Lovata Shopaholic source
- Whether ApplyResult tuple return adds confusion vs adding field — implementer's call
- Test fixture for 200-line invoice (D-09) — synthetic HTML built in test setUp via array_map + sprintf, not a checked-in fixture (avoids fixture bloat)

</decisions>

<canonical_refs>
## Canonical References

### Locked Specs
- `.planning/PROJECT.md` — Architecture preview (Apply layer table); D1-D15
- `.planning/REQUIREMENTS.md` — APPLY-01..10, QA-03/04/05/06/08/09 specs
- `.planning/ROADMAP.md` — Phase 3 success criteria (7 items)
- `.planning/phases/01-schema-scaffold-settings-permissions/01-CONTEXT.md` — Phase 1 schema (active_managed_by column, Invoice/InvoiceLine/InitialResetSnapshot models, Settings)
- `.planning/phases/02-pure-parsers-dtos-exceptions-ean-matcher/02-CONTEXT.md` — Phase 2 DTOs, exceptions, parser, matcher

### Lovata References
- `plugins/lovata/shopaholic/classes/store/OfferListStore.php` — cache invalidation pattern; `instance()` singleton + `clearCache()`
- `plugins/lovata/shopaholic/classes/store/ProductListStore.php` — same pattern
- `plugins/lovata/shopaholic/models/Offer.php` — `quantity` field; `setQuantityAttribute` (silent int-clamp warning)
- `plugins/lovata/shopaholic/classes/event/ProductModelHandler.php` — Lovata cache flush idiom
- `plugins/lovata/toolbox/classes/event/ModelHandler.php` — base ModelHandler

### Phase 1+2 Outputs (DEPENDS ON)
- `models/{Invoice,InvoiceLine,InitialResetSnapshot,Settings}.php`
- `classes/dto/{ParsedInvoice,ParsedLine,MatchedLine,ApplyResult}.php`
- `classes/exception/{8 typed}.php`
- `classes/parser/{HtmInvoiceParser,InvoiceNumberResolver,QuantityNormalizer,PriceNormalizer}.php`
- `classes/match/EanMatcherService.php`

### Phase 3 New Files
- `classes/support/{SettingsAccessor,ImportAuditService}.php`
- `classes/apply/{StockApplyService,ActiveFlagService,InitialResetService}.php`
- `classes/orchestrator/{ParseAndPersistOrchestrator,ApplyOrchestrator}.php`
- Makefile target: `lint:settings-accessor`

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `EanMatcherService::matchBatch` — used by ParseAndPersistOrchestrator
- `HtmInvoiceParser::parse` — used by ParseAndPersistOrchestrator
- `InvoiceNumberResolver` (already invoked inside parser) — duplicate detection at orchestrator level
- 8 typed exceptions — orchestrator catches and re-throws or audits
- Phase 1 models — Invoice/InvoiceLine/InitialResetSnapshot scaffolded; orchestrator persists

### Established Patterns
- `final class` + `declare(strict_types=1)` everywhere
- Pure static services (Normalizers) vs instance services (HtmInvoiceParser, EanMatcherService) — Apply services are INSTANCE (carry state, configurable, mockable in tests)
- Pest 4 `it()` syntax + real DB tests
- `class_uses_recursive` for trait checks
- `DB::enableQueryLog()` for query-budget assertions

### Integration Points
- Phase 4 controller will call `ParseAndPersistOrchestrator::run()` for upload → preview screen, then `ApplyOrchestrator::apply()` on Apply button
- Phase 4 console command `goodsreceived:recompute_active_from_stock` calls `ActiveFlagService::reconcileAll()` — Phase 3 ships `reconcile($arOfferIds)`; `reconcileAll()` is a thin wrapper iterating chunked offers (Phase 3 ships it OR Phase 4 — recommend Phase 3 ships it for completeness)
- Phase 4 UI-08 InitialReset checkbox calls `InitialResetService::reset()`
- Phase 4 UI-09/10 Override-and-reimport calls `ParseAndPersistOrchestrator::runOverride()`

</code_context>

<specifics>
## Specifics

- **Cache flush proof test (QA-04):** Mock or count `OfferListStore::clearCache()` invocations. Pest's `Mockery` or class spying via static counter. The test asserts ≤ N flushes (specific value: ≤ 5, conservative). The "1200+" anti-pattern is `(8 flushes per offer × N lines)` if implementation uses `->save()` per line.
- **Lock-for-update test (QA-03 LockForUpdateSerializesConcurrentApplyTest):** Spawn two threads (or simulate sequential via shared lock). First call `apply()` → starts transaction → holds row lock → second call blocks on `lockForUpdate` → first commits → second sees `status='applied'` → throws `ApplyAlreadyDoneException`. SQLite has limited concurrency; may need to use Postgres or rely on `SHARED LOCK` semantics. Alternatively, structural test: mock the locking, assert lockForUpdate was called BEFORE status check.
- **Override-reimport additive test (QA-03 OverrideReimportAddsOnTopTest):** Create Offer.quantity=10 → apply original invoice (5 units) → Offer.quantity=15 → call `runOverride(...)` → apply new invoice → Offer.quantity=20 (15 + 5). Verify `override_of_invoice_id` chain set correctly.
- **Settings DRY grep gate (APPLY-09 + QA-09):** Add `Makefile` target:
  ```makefile
  lint-settings-accessor:
  	@./scripts/lint-settings-accessor.sh
  ```
  Or inline:
  ```makefile
  lint-settings-accessor:
  	@! grep -rn 'Settings::get(' classes/ components/ models/ controllers/ Plugin.php 2>/dev/null | grep -v 'classes/support/SettingsAccessor.php'
  ```
  Pest test mirrors the same grep.

</specifics>

<deferred>
## Deferred Ideas

- **Backend controller** — Phase 4 (UI-01..10)
- **Console command `goodsreceived:recompute_active_from_stock`** — Phase 4 (UI-11) — wraps `ActiveFlagService::reconcileAll()` (Phase 3 ships `reconcileAll`)
- **Plugin boot self-check** — Phase 4 (UI-12)
- **Initial reset rollback CLI** — Phase 4 or Phase 5 ops runbook
- **README documenting override semantics + InitialReset runbook** — Phase 5 (OPS-01)

</deferred>

---

*Phase: 03-apply-layer-orchestrators*
*Context gathered: 2026-04-29 (autonomous mode)*
*Smart-discuss `--auto`: 42 decisions captured across 9 areas; 3 items at Claude's discretion*
