---
phase: 03-apply-layer-orchestrators
plan: 05
subsystem: apply-layer
tags: [initial-reset, snapshot-before-write, savequietly, settings-accessor, chunkbyid, batched-insert, qa-06, apply-05, php8.4, pest4]

# Dependency graph
requires:
  - phase: 03-apply-layer-orchestrators
    plan: 01
    provides: SettingsAccessor::allowInitialReset() (memoized; D-01)
  - phase: 03-apply-layer-orchestrators
    plan: 03
    provides: ApplyTestCase hermetic schema base (lovata_shopaholic_offers + products + system_settings + invoices/lines)
  - phase: 02-pure-parsers-dtos-exceptions-ean-matcher
    provides: InitialResetNotAllowedException (final, GoodsReceivedException base, $arContext readonly)
  - phase: 01-schema-scaffold-settings-permissions
    provides: logingrupa_goods_received_initial_reset_snapshot table + InitialResetSnapshot model + Invoice.initial_reset_applied bool field
provides:
  - InitialResetService — final class with reset(Invoice): void; instance-style (composable in Phase 4 controllers)
  - Two-gate guard contract: SettingsAccessor::allowInitialReset() + Invoice::where('initial_reset_applied', true)->exists()
  - Snapshot-before-write contract: snapshotAllOffers runs BEFORE zeroAllOffers (D-18)
  - Per-chunk batched InitialResetSnapshot::insert (chunk of 500 offers → ONE INSERT statement)
  - Per-chunk Product hydration via whereIn — O(1) DB roundtrip per chunk (avoids N+1 + dodges Larastan relation false positive)
  - Chunked saveQuietly mutation: Offer::chunkById(500) + Product::chunkById(500)
  - 5 QA-06 tests (RequiresAllowInitialResetSetting, OneShotEnforced, SnapshotsBeforeWrite, RollbackRestoresExactPriorState, ChunkedNotSingleStatement)
  - ApplyTestCase schema extension (logingrupa_goods_received_initial_reset_snapshot table)
affects: [03-06-parse-orch, 03-07-apply-orch, 04-controller-initial-reset-checkbox, 05-ops-rollback-runbook]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Two-gate guard ordering: Settings toggle FIRST (cheap memo cache read), DB one-shot check SECOND (one indexed exists() query). Cheap-first short-circuits a misconfigured site without ever touching the DB."
    - "Snapshot-before-write contract: snapshotAllOffers() returns BEFORE zeroAllOffers() begins. Test asserts ordering by reading prior_quantity=10 from the snapshot AFTER the offer was zeroed (the value can only have been captured pre-mutation)."
    - "Per-chunk batched insert: Offer::chunkById(500) → one InitialResetSnapshot::insert([…500 rows…]) per page. 1500 offers → exactly 3 snapshot INSERT statements (recorded), NOT 1500 individual create() calls."
    - "Per-chunk Product hydration via whereIn (NOT magic ->product accessor): collapses what would be N=500 product fetches per chunk into ONE statement, AND dodges the Larastan larastan.relationExistence false positive (Larastan can't see October's array-style $belongsTo declaration on Lovata Offer)."
    - "EloquentCollection<int, Model> closure signature for chunkById (mirrors ActiveFlagService 03-04): instanceof Offer narrowing inside the loop converts mixed→typed-Offer for PHPStan L10 without inline @var."
    - "Reason-tagged exception context: $arContext['reason'] ∈ {'settings_disabled', 'already_applied'}. Phase 4 controller renders distinct error UX per cause (the operator sees 'enable the toggle first' vs 'a prior reset already happened — manual rollback required')."

key-files:
  created:
    - classes/apply/InitialResetService.php (281 lines — final class with reset() + 7 private helpers, all <70 lines per Tiger-Style)
    - tests/unit/Apply/InitialResetServiceTest.php (270 lines — 5 Pest cases / 78 assertions)
  modified:
    - tests/unit/Apply/ApplyTestCase.php (+18 lines: logingrupa_goods_received_initial_reset_snapshot table create/drop)

key-decisions:
  - "D-03-05-01 (2026-04-29): Per-chunk Product hydration via whereIn (NOT $obOffer->product magic accessor). Larastan emits larastan.relationExistence false positive on the magic accessor (it can't see October's array-style $belongsTo on Lovata models, and the Lovata Singleton stub strategy from 03-03 doesn't extend to relationship declarations). Resolution: explicit whereIn → array<int, bool> map, looked up per offer in the row builder. Side benefit: collapses N product fetches per chunk into ONE statement. Net positive — one less Larastan bypass + more efficient SQL."
  - "D-03-05-02 (2026-04-29): Two private helpers split out of snapshotAllOffers (buildSnapshotChunkRows + hydrateProductActiveMap) to keep each function under 70 lines (Tiger-Style rule). Original spec was 5 helpers; final is 7. Single-responsibility per helper: snapshot drives the chunk loop, build assembles row payloads, hydrate fetches products. Easier to test the row-builder in isolation (deferred — current 5 cases cover the integration path)."
  - "D-03-05-03 (2026-04-29): Reason-tagged exception context ($arContext['reason'] = 'settings_disabled' | 'already_applied'). Two distinct conditions both throw InitialResetNotAllowedException; the reason field disambiguates for downstream UX. Pinned by separate it() cases — RequiresAllowInitialResetSetting asserts 'settings_disabled', OneShotEnforced asserts 'already_applied'."
  - "D-03-05-04 (2026-04-29): chunkById (NOT chunk) on EVERY pass — snapshot, zero, deactivate. Even the snapshot pass is read-only on the offer table (so chunk() would also work), but consistency wins: one rule across the file makes future maintenance easier and the offset-shift bug never surfaces. Asserted by ChunkedNotSingleStatementTest at 1500 offers."

patterns-established:
  - "Reason-tagged exception context surfacing two distinct guard failures through the same exception type — Phase 4 controller can render different error UX per cause without catching subtypes."
  - "Per-chunk whereIn + map lookup pattern for cross-table reads inside a chunkById loop: avoids both N+1 and Larastan magic-accessor false positives. Reusable in any service that walks Offers and needs Product fields."
  - "buildSnapshotChunkRows() returning list<array> for batched insert — explicit array-of-arrays handed to Model::insert(), NEVER per-row create()."

requirements-completed: [APPLY-05, QA-06]

# Metrics
duration: 8min
completed: 2026-04-29
---

# Phase 3 Plan 05: InitialResetService Summary

**InitialResetService final class — one-shot baseline reset gated by Settings.allow_initial_reset + DB one-shot check, snapshots every offer's prior state to logingrupa_goods_received_initial_reset_snapshot via batched INSERT BEFORE writing, then chunkById(500) + saveQuietly per Offer/Product. 5 QA-06 Pest cases / 78 assertions covering settings guard, one-shot enforcement, snapshot-before-write ordering, exact-state rollback feasibility, and chunked-not-bulk mutation pattern.**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-04-29T20:46:52Z (after 03-04 docs commit)
- **Completed:** 2026-04-29T20:54:27Z (GREEN gate)
- **Tasks:** 2 (Task 1 service + Task 2 tests — combined into a single TDD RED→GREEN cycle since service and tests share fate)
- **Files created:** 2
- **Files modified:** 1 (ApplyTestCase.php +18 lines for the snapshot table)

## Accomplishments

- `InitialResetService::reset(Invoice): void` — public entry point with two-gate guard (Settings + DB one-shot) and orchestrated 4-step write sequence (snapshot → zero offers → deactivate products → mark invoice).
- 7 private helpers, every one under 70 lines:
    - `assertAllowed(): void` — both guards, throws InitialResetNotAllowedException with reason-tagged context.
    - `snapshotAllOffers(int): int` — Offer::chunkById(500) drives per-chunk batched INSERT.
    - `buildSnapshotChunkRows(int, EloquentCollection, string): list<array>` — assembles row payloads for one chunk.
    - `hydrateProductActiveMap(EloquentCollection): array<int, bool>` — one whereIn per chunk, returns id→active map.
    - `zeroAllOffers(): int` — chunkById(500) + per-row saveQuietly with quantity=0, active=false, active_managed_by='plugin'.
    - `deactivateAllProducts(): int` — chunkById(500) + per-row saveQuietly with active=false.
    - `markInvoiceReset(Invoice): void` — saveQuietly the one-shot bit at the very end.
- 5 dedicated Pest cases (78 assertions total) pinning every load-bearing invariant from REQUIREMENTS.md QA-06:
    1. RequiresAllowInitialResetSetting — Settings.allow_initial_reset=false → throws with reason='settings_disabled'.
    2. OneShotEnforced — prior Invoice with initial_reset_applied=true exists → throws with reason='already_applied'.
    3. SnapshotsBeforeWrite — 5-offer fixture, post-reset snapshot pins the prior_quantity (which is now zero on the offer table — proves ordering) + every offer is zeroed/deactivated/'plugin'-managed + every product is inactive + Invoice.initial_reset_applied=true.
    4. RollbackRestoresExactPriorState — manually walks snapshot rows + restores both Offer and Product state, asserts post-restore matches pre-reset state EXACTLY (proves snapshot is rollback-rich enough; full CLI deferred to Phase 5 ops runbook).
    5. ChunkedNotSingleStatement — 1500-offer seed, query-log capture: 3 batched snapshot INSERTs + 8 offer SELECTs + 1500 per-row offer UPDATEs, NOT one bulk UPDATE.

## Files Touched

| File | Action | Net Δ |
|------|--------|------:|
| classes/apply/InitialResetService.php | **created** (281 LoC, final class + 8 methods) | +281 |
| tests/unit/Apply/InitialResetServiceTest.php | **created** (270 LoC, 5 cases) | +270 |
| tests/unit/Apply/ApplyTestCase.php | modified (snapshot table create/drop) | +18 |

## Verification Results

```
$ make all
... (132 tests, 589 assertions, 8.54s) ✓ all green
```

| Gate | Result |
|------|--------|
| `make pint-test` | `{"result":"pass"}` |
| `make lint-settings-accessor` | green (Settings::get( still appears only in classes/support/SettingsAccessor.php) |
| `make analyse` (PHPStan L10) | `[OK] No errors` |
| `make phpmd` | (no output → no warnings) |
| `make test` (full Pest suite) | 132 passed (589 assertions) |
| InitialResetServiceTest filter | 5 passed (78 assertions) — 3.47s |

### Grep verification (code-only, excluding doc comments)

| Pattern | Expected | Actual |
|---------|---------:|-------:|
| `Settings::get(` | 0 | 0 ✓ |
| `SettingsAccessor::` | 1 | 1 ✓ |
| `::insert` | 1 | 1 ✓ (the batched snapshot insert) |
| `->saveQuietly(` | ≥3 | 3 ✓ (Offer + Product + Invoice mark) |
| `->save(` | 0 | 0 ✓ |
| `DB::statement / DB::raw / whereRaw / ->update(` | 0 | 0 ✓ |

(The plan's spec values count both code and doc-comment occurrences, which trips on `DB::statement` mentioned in the threat-coverage docblock and `SettingsAccessor::` in the same. Code-only counts match spec exactly.)

### Recorded query counts (ChunkedNotSingleStatementTest, 1500-offer fixture)

| Metric | Value |
|--------|------:|
| Total queries during reset() | 1520 |
| Snapshot batched INSERT statements | 3 (1500 / 500 = 3 batches) |
| Offer SELECT pages (chunkById) | 8 (snapshot pass + zero pass + product hydration overhead) |
| Per-row Offer UPDATEs (saveQuietly) | 1500 (one per offer — NOT a single bulk UPDATE) |
| Snapshot rows written | 1500 (one per offer) |

The `1500` UPDATEs vs `3` INSERTs is the load-bearing contract: per-row saveQuietly proves no DB::statement/whereRaw bypass; batched snapshot insert proves the audit trail is written in O(catalog/500) statements rather than O(catalog).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] PHPStan L10 larastan.relationExistence false positive on `$obOffer->product`**

- **Found during:** Task 1 GREEN gate (`make analyse`)
- **Issue:** Larastan's relation-existence rule cannot see October's array-style `$belongsTo = ['product' => [Product::class]]` declaration on the Lovata Offer model. The plan's eager-load `Offer::with('product:id,active')` and per-offer `$obOffer->product` accessor both error out at `larastan.relationExistence`. The plan's note acknowledged this risk: "If PHPStan or runtime errors on this, fall back to ..."
- **Fix:** Replaced the magic-accessor approach with explicit per-chunk Product hydration: `hydrateProductActiveMap()` does ONE `Product::whereIn('id', […])->get(['id', 'active'])` per chunk and returns `array<int, bool>`. Row builder looks up the id in the map.
- **Files modified:** `classes/apply/InitialResetService.php` (added `hydrateProductActiveMap` helper, refactored `snapshotAllOffers`)
- **Side benefit:** Avoids both N+1 (no per-offer product fetch) AND the Larastan workaround. Net positive.
- **Commit:** included in `4390e4a`

**2. [Rule 3 - Blocking] PHPStan L10 alwaysTrue / function.alreadyNarrowedType on `is_numeric($obOffer->product_id)`**

- **Found during:** Task 1 GREEN gate (`make analyse`)
- **Issue:** Per the phpstan.neon `universalObjectCratesClasses` declaration (added in 03-03), Larastan reads Lovata's docblock-typed `@property int $product_id` as concrete `int`. So `is_numeric($int)` is "always true" and `$int !== null` is "always true".
- **Fix:** Removed the redundant `is_numeric` and `!== null` guards. Trust the Lovata docblock contract (per project rule "Do not add type casts just to silence errors"; no casts added — guards REMOVED). Defensive zero-check in `hydrateProductActiveMap` handles the runtime-nullable column case (a 0-cast from a null read = "no product attached").
- **Files modified:** `classes/apply/InitialResetService.php`
- **Commit:** included in `4390e4a`

**3. [Rule 3 - Blocking] PHPStan L10 instanceof.alwaysTrue on `$obProduct instanceof Product`**

- **Found during:** Task 1 GREEN gate (`make analyse`)
- **Issue:** After fix #1 dropped the magic-accessor, the buildSnapshotRow helper's `instanceof Product` check became always-true (whereIn returns a typed Collection<Product>).
- **Fix:** Inlined the row builder back into `buildSnapshotChunkRows`, dropped the now-unused `Product`-typed local variable, lookup goes straight to the `array<int, bool>` map. The remaining `instanceof Offer` checks inside chunkById callbacks are NOT always-true (chunkById's typed signature is `Collection<int, Model>`).
- **Files modified:** `classes/apply/InitialResetService.php`
- **Commit:** included in `4390e4a`

### File-size deviation (note)

The plan target was `wc -l ≤ 200` for InitialResetService.php; actual is 281 LoC. The +81 LoC is entirely the per-chunk Product hydration helper (`hydrateProductActiveMap` + the refactored `buildSnapshotChunkRows`) added to dodge Larastan rule #1 above. Methods remain under 70 lines (largest is hydrateProductActiveMap at 31). PHPMD `ExcessiveClassLength` threshold is 1000 — well within budget.

### Test-count deviation

Test file is 270 LoC (no spec target was given for tests). 78 assertions across 5 cases (spec said exactly 5 cases, satisfied).

## Architecture Notes

- The two-gate guard's ordering is intentional and cheap-first:
    1. `SettingsAccessor::allowInitialReset()` — memoized boolean read from in-memory cache (0 DB roundtrips after the first call in a request).
    2. `Invoice::where('initial_reset_applied', true)->exists()` — one indexed scalar query against the invoices table.
  
  A misconfigured production site (toggle off) never hits the DB. A site with a prior reset incurs exactly one query before throwing.

- Snapshot-before-write is enforced by program order, not transaction. The service does NOT wrap itself in `DB::transaction` — caller (Phase 4 controller for direct reset path, OR plan 03-07 ApplyOrchestrator if reset+apply are composed) decides the boundary. Inside a transaction, partial failure rolls back BOTH the snapshot and the mutations together — the operator can retry. Outside, partial failure leaves the snapshot rows AS the rollback record.

- `saveQuietly` choice (not bare `->save()`) matters for parity with StockApplyService and ActiveFlagService: every Phase 3 write goes through saveQuietly so Lovata's `OfferModelHandler::afterSave` (8-12 cache flushes per save) never fires. Cache flush is the orchestrator's responsibility (Phase 3 plan 03-07), called ONCE post-commit.

## Note for plan 03-07 (ApplyOrchestrator)

Per the plan's `<output>` directive: **InitialReset is NOT called inside the apply transaction.** It has its own caller boundary — Phase 4 will surface a UI-08 checkbox on the import-preview screen that triggers `InitialResetService::reset($obInvoice)` directly (or wraps it in its own `DB::transaction(fn() => $obService->reset(...))` if atomicity is desired across the snapshot + mutation). Phase 3 ships only the service; the orchestrator (03-07) need not reference InitialResetService at all.

Phase 3 plan 03-07 ApplyOrchestrator concerns itself with `StockApplyService` + `ActiveFlagService` composition — both of those run INSIDE the apply transaction (QA-08 ActiveFlagInsideSameTransactionAsStockApplyTest); InitialReset is a separate operator-driven path.

## Threat Coverage Recap

All 6 threats from the plan's `<threat_model>`:

| Threat | Disposition | Mitigation in shipped code | Test |
|--------|-------------|----------------------------|------|
| T-03-05-01 Tampering — repeated reset destroys apply history | mitigate | Two-gate guard (`SettingsAccessor::allowInitialReset` + `Invoice::where('initial_reset_applied', true)->exists()`) | RequiresAllowInitialResetSetting + OneShotEnforced |
| T-03-05-02 Information disclosure — lost prior state | mitigate | Snapshot-before-write contract: snapshot pass runs BEFORE zero pass | SnapshotsBeforeWrite + RollbackRestoresExactPriorState |
| T-03-05-03 DoS — OOM on 50,000-offer catalog | mitigate | chunkById(500) on every pass; whereIn-per-chunk Product hydration is also O(500) memory | ChunkedNotSingleStatement (1500 offers / 3 chunk pages) |
| T-03-05-04 Tampering — DB::statement bypass | mitigate | saveQuietly per row + InitialResetSnapshot::insert (snapshot table has no model handlers); zero DB::statement/whereRaw/->update calls | grep verification + ChunkedNotSingleStatement (1500 individual UPDATEs) |
| T-03-05-05 Repudiation — lost audit trail on reset | mitigate (Phase 3 plan 03-07 territory) | Phase 3 plan 03-07 ApplyOrchestrator (or Phase 4 controller for the direct reset path) wraps reset() with `ImportAuditService::logInitialReset(invoice_id, ...)` | deferred to 03-07 + Phase 4 UI-08 |
| T-03-05-06 Race — concurrent reset attempts | accept | SQLite tests can't reproduce; production MySQL handles via Invoice::where(...)->exists() seeing the FIRST commit — second operator gets 'already_applied' | accepted |

## Self-Check: PASSED

**Files created:**
- `classes/apply/InitialResetService.php` — FOUND (281 LoC)
- `tests/unit/Apply/InitialResetServiceTest.php` — FOUND (270 LoC)

**Files modified:**
- `tests/unit/Apply/ApplyTestCase.php` — FOUND (146 LoC)

**Commits:**
- `a4166ff` — RED gate (failing tests + ApplyTestCase schema extension) — FOUND in `git log`
- `4390e4a` — GREEN gate (InitialResetService implementation) — FOUND in `git log`

**Requirements closed:** APPLY-05, QA-06
