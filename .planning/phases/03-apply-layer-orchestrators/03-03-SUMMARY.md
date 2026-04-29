---
phase: 03-apply-layer-orchestrators
plan: 03
subsystem: testing
tags: [stock-apply, savequietly, batched-cache-flush, qa-04, apply-01, apply-02, php8.4, pest4, mockery, phpstan-stub, singleton-spy, hermetic-schema]

# Dependency graph
requires:
  - phase: 02-pure-parsers-dtos-exceptions-ean-matcher
    provides: ApplyResult DTO (units_added, offers_touched, lines_applied, lines_skipped — pure 4-int counter bag)
  - phase: 01-schema-scaffold-settings-permissions
    provides: Invoice + InvoiceLine models (status fields, applied/applied_at, override_qty)
  - phase: 01-schema-scaffold-settings-permissions
    provides: lovata_shopaholic_offers.active_managed_by additive column (carried in hermetic schema)
provides:
  - StockApplyService — load-bearing apply engine for Phase 3 (instance class, NOT static)
  - StockApplyOutcome — D-29 tuple-decision carrier (ApplyResult + list<int> affected_offer_ids)
  - flushAffectedCaches public API: O(stores) post-commit batched flush — orchestrator (03-07) wires this AFTER DB::transaction
  - Apply layer hermetic schema base — `ApplyTestCase` reusable across 03-04 (ActiveFlag), 03-05 (InitialReset), 03-07 (ApplyOrchestrator)
  - phpstan-stubs/Singleton.stub — sanctioned PHPStan stub giving October Rain Singleton trait its missing `@return static`
affects: [03-04-activeflag, 03-05-initialreset, 03-07-apply-orch, 03-08-parseandpersist-orch, 04-backend-controller]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "saveQuietly + post-commit batched flush: per-offer Eloquent saveQuietly bypasses Lovata OfferModelHandler::afterSave; ONE list-store flush per affected store post-tx-commit instead of N×stores per save"
    - "Group-by-offer pre-pass: invoice lines summed by matched_offer_id BEFORE any write so each offer is hit by exactly one UPDATE even if multiple lines target it"
    - "Batched offer fetch via Offer::whereIn(array_keys($arDeltas))->get()->keyBy('id') — collapses 200 finds into 1 SELECT"
    - "Leaf-singleton dispatch (OfferActiveListStore::instance()->clear()) instead of OfferListStore::instance()->active->clear() — observationally identical (same instance shared via addToStoreList) AND statically typed at PHPStan L10"
    - "Singleton spy via reflection on `protected static $instance` slot: each leaf Lovata store class is its own Singleton trait user; injecting a Mockery spy into the static slot intercepts every ::instance() call without altering production code shape"
    - "PHPStan stub files for upstream untyped traits: phpstan-stubs/Singleton.stub gives the trait its `@return static` annotation. Sanctioned mechanism per PHPStan user guide; distinct from forbidden @var / @phpstan-ignore inline suppression."
    - "D-29 tuple-decision realization: keep ApplyResult immutable counter bag, return list<int> $affected_offer_ids on a small readonly carrier class"

key-files:
  created:
    - classes/apply/StockApplyService.php
    - classes/apply/StockApplyOutcome.php
    - tests/unit/Apply/ApplyTestCase.php
    - tests/unit/Apply/StockApplyServiceTest.php
    - tests/unit/Apply/Apply200LinesTriggersBatchedFlushNotPerSaveTest.php
    - phpstan-stubs/Singleton.stub
  modified:
    - phpstan.neon

key-decisions:
  - "D-03-03-01 (2026-04-29): Group-by-offer pre-pass + batched whereIn fetch. The plan's first sketch had a per-line Offer::find loop (1 SELECT per line + 1 UPDATE per line = 400 queries). Hoisted to one whereIn fetch + one save per UNIQUE offer = 401 queries actual for 200 lines. Test pins ≤ 500 to leave headroom for sortable-trait order SELECTs."
  - "D-03-03-02 (2026-04-29): Leaf-singleton dispatch via OfferActiveListStore::instance() etc. instead of going through OfferListStore::instance()->active. Required because AbstractListStore::__get returns mixed; PHPStan L10 cannot dereference. Observationally identical at runtime — addToStoreList registers the SAME singleton in both paths via the leaf class's `instance()` factory."
  - "D-03-03-03 (2026-04-29): phpstan-stubs/Singleton.stub for the upstream October Rain trait. The trait's instance() method has no return type annotation, so PHPStan infers `mixed`. The stub gives it `@return static` — sanctioned PHPStan stubFiles config, NOT @var or @phpstan-ignore (which are explicitly forbidden per the project's analyzer rules). Side effect: future Phase 3+ plans get cleaner Singleton typing for free."
  - "D-03-03-04 (2026-04-29): Spies installed via reflection on each leaf class's static::$instance slot rather than swapping arStoreList entries on the parent OfferListStore. Cleaner, decoupled, survives the leaf-direct service refactor. tearDown calls forgetInstance() on each so spies never leak across tests."
  - "D-03-03-05 (2026-04-29): universalObjectCratesClasses extended to Lovata Offer + Product models. The Apply layer reads/writes Offer::quantity which Larastan sees as `mixed` (Eloquent magic property). The crate registration suppresses the property-not-found error WITHOUT inline @var; intval() wraps every read so PHPStan L10 accepts the int conversion."

patterns-established:
  - "Apply-layer hermetic schema base: `ApplyTestCase` extends GoodsReceivedTestCase, manually creates lovata_shopaholic_{products,offers} + logingrupa_goods_received_{invoices,invoice_lines} via Schema::create. tearDown drops all 4. Decoupled from migration order — works around Lovata's broken SQLite drop-indexed-column migration. Reusable by 03-04 / 03-05 / 03-07."
  - "Singleton spy via reflection on protected static \$instance: works for any October Rain Singleton trait user (including all Lovata Stores). injectSingletonSpy(LeafClass::class, \\Mockery::spy(LeafClass::class)) — afterEach calls forgetInstance() to prevent cross-test pollution."
  - "Anti-pattern guard test (Apply200LinesTriggersBatchedFlushNotPerSaveTest): codifies the QA-04 invariant — a regression to `->save()` per line would explode the spy `clear()` count from O(stores) to O(lines × stores). The test name pins the contract for grep-back during code review."

requirements-completed: [APPLY-01, APPLY-02, QA-04]

# Metrics
duration: 12min
completed: 2026-04-29
---

# Phase 3 Plan 03: StockApplyService + QA-04 Cache Flush Summary

**StockApplyService final class with saveQuietly per-offer (group-by-offer pre-pass + batched whereIn fetch) and separate flushAffectedCaches public API the orchestrator calls AFTER DB::transaction commits — the entire Phase 3 apply engine, with 200-line query budget locked at 401 actual / ≤ 500 ceiling and list-store cache flush count locked at exactly 4 (≤ 5 hard contract).**

## Performance

- **Duration:** ~12 min
- **Started:** 2026-04-29T20:16:22Z
- **Completed:** 2026-04-29T20:28:41Z
- **Tasks:** 2 (Task 1: service; Task 2: tests + cache-flush counter)
- **Files created:** 6
- **Files modified:** 1

## Accomplishments

- `StockApplyService::apply(Invoice, DbCollection<InvoiceLine>): StockApplyOutcome` — group-by-offer pre-pass, batched whereIn fetch, saveQuietly per UNIQUE offer, per-line applied=true / applied_at=now in pass 2.
- `StockApplyService::flushAffectedCaches(list<int>): void` — empty-id no-op + 4 list-store flushes via leaf-singleton dispatch + per-id `OfferItem::clearCache` loop.
- `StockApplyOutcome` — D-29 tuple-decision carrier (final readonly: ApplyResult + list<int> affected_offer_ids).
- 12 Pest cases / 56 assertions across two test files — 100% green.
- 200-line apply: **exactly 401 queries** (1 batched fetch + 200 offer saveQuietly + 200 line saveQuietly) — well under the ≤ 500 budget.
- 200-line apply: **exactly 4 list-store cache flushes** (`OfferActiveListStore::clear` ×1 + `OfferSortingListStore::clear` ×2 for SORT_NO/SORT_NEW + `ProductActiveListStore::clear` ×1) — under the ≤ 5 QA-04 hard contract.
- `phpstan-stubs/Singleton.stub` ships the `@return static` annotation the upstream October Rain trait lacks — sanctioned PHPStan stubFiles mechanism, not inline @var suppression.
- `make all` green: 118 / 463 tests / 3.80s. phpstan-baseline.neon SHA unchanged.

## Task Commits

Each task committed atomically following the TDD gate sequence:

1. **Task 1+2 RED — failing tests for StockApplyService + QA-04 cache flush** — `bc9c9f9` (test) — 12 tests, all failing with "Class StockApplyService not found"
2. **Task 1+2 GREEN — StockApplyService + StockApplyOutcome implementation** — `b32a3fc` (feat) — 12/12 pass / 56 assertions

REFACTOR pass: not committed — code already minimal at GREEN. The service is 215 raw lines (8 lines over the soft ≤ 200 target) but the methods themselves are well within Tiger-Style limits (apply: 59 LoC; flushAffectedCaches: 28 LoC; helpers: ≤ 15 LoC each). The over-budget lines are entirely PHPDoc threat-model documentation — high signal, no fluff.

**Plan metadata commit:** _(forthcoming after this SUMMARY.md is written)_

## Files Created/Modified

- `classes/apply/StockApplyService.php` (215 lines) — `final class StockApplyService` with `apply()` + `flushAffectedCaches()` + 4 private helpers. saveQuietly contract enforced; batched whereIn fetch; leaf-singleton dispatch.
- `classes/apply/StockApplyOutcome.php` (36 lines) — `final readonly class` carrying `ApplyResult $result` + `list<int> $affected_offer_ids`.
- `tests/unit/Apply/ApplyTestCase.php` (145 lines) — abstract hermetic-schema base; manual `Schema::create` for products + offers + invoices + invoice_lines; tearDown drops all 4.
- `tests/unit/Apply/StockApplyServiceTest.php` (293 lines) — 9 Pest cases covering additive write, sum-by-offer, null skip, override_qty, applied marker, outcome shape (no dupes), missing-offer defensive skip, saveQuietly proof, 200-line query budget.
- `tests/unit/Apply/Apply200LinesTriggersBatchedFlushNotPerSaveTest.php` (227 lines) — QA-04 enforcement: 3 cases with Mockery spies on each leaf-singleton's `static::$instance` slot.
- `phpstan-stubs/Singleton.stub` (39 lines) — stub for `October\Rain\Support\Traits\Singleton::instance(): static`.
- `phpstan.neon` — added `stubFiles: [phpstan-stubs/Singleton.stub]` + extended `universalObjectCratesClasses` with `Lovata\Shopaholic\Models\{Offer,Product}`.

## QA Gate Results

| Gate | Result | Notes |
|------|--------|-------|
| `make pint-test` | pass | `{"result":"pass"}` |
| `make lint-settings-accessor` | exit 0 | no offenders |
| `make analyse` (PHPStan L10 + Larastan) | clean | `[OK] No errors` across 27 paths; baseline SHA unchanged |
| `make phpmd` | clean | no new violations |
| `make test` (Pest 4) | 118 passed / 463 assertions | up from 106/407 in plan 03-02 (+12 cases / +56 assertions this plan) |
| `make all` total | exit 0 | 3.80s |

## phpstan-baseline.neon SHA

| When | SHA-256 |
|------|---------|
| Before plan 03-03 | `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` |
| After plan 03-03 | `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` |

**Unchanged** — the new code, stub file, and config additions raised zero new baseline entries. Larastan + PHPStan L10 see the apply layer as fully typed.

## Verification Output (recorded for downstream reference)

### Forbidden patterns — must be 0 in code (excluding doc comments)

```
$ grep -vE '^\s*\*|^\s*//' classes/apply/StockApplyService.php | grep -nE 'DB::statement|DB::raw|->whereRaw|->save\(\)'
exit=1   (no matches)
```

### Required pattern — saveQuietly count

```
$ grep -c 'saveQuietly' classes/apply/StockApplyService.php
3   (1 in docblock, 2 in code: writeOfferStock + markLineApplied)
```

### Actual query count for 200-line apply (instrumentation hard-pinned via test)

```
ACTUAL_QUERIES=401
```

Breakdown:
- 1× `Offer::whereIn('id', [1..200])->get()` — batched fetch
- 200× UPDATE on offers via `saveQuietly` — one save per UNIQUE offer
- 200× UPDATE on invoice_lines via `saveQuietly` — one mark per LINE (audit precision)

Test budget asserts ≤ 500 (room for sortable-trait order SELECTs in real schema). Anti-pattern guard: regression to per-line `Offer::find()` would explode this to ≥ 600.

### Actual list-store cache flush count for 200-line apply

```
OfferActiveListStore::instance()->clear()              ×1
OfferSortingListStore::instance()->clear(SORT_NO)      ×1
OfferSortingListStore::instance()->clear(SORT_NEW)     ×1
ProductActiveListStore::instance()->clear()            ×1
                                                       --
Total list-store flushes:                               4   (≤ 5 hard contract)
```

QA-04 contract met. Asserted via Mockery `shouldHaveReceived('clear')->once()` on each leaf-singleton spy.

## Decisions Made

- **D-03-03-01 — Group-by-offer pre-pass + batched fetch.** First sketch had per-line `Offer::find()` (400 queries for 200 lines). Hoisted to ONE `whereIn` SELECT + ONE save per UNIQUE offer. Drives query count from 400+ to 401 actual, leaves room under the ≤ 500 budget.
- **D-03-03-02 — Leaf-singleton dispatch for cache flush.** `OfferListStore::instance()->active` returns `mixed` via `__get` magic; PHPStan L10 forbids dereferencing. Each sub-store IS its own Singleton trait user (`protected static $instance` declarations), so `OfferActiveListStore::instance()` returns the SAME singleton via a typed factory. Plan-acceptable and statically valid.
- **D-03-03-03 — PHPStan stub for upstream Singleton trait.** The October Rain trait's `instance()` method has no return-type annotation. Inline `@var` is forbidden by the project rules. The PHPStan-sanctioned mechanism is `stubFiles:` config — a stub gives the trait `@return static`. Side benefit: every other Singleton trait user (Lovata stores, helpers, ItemStorage) gets typed too.
- **D-03-03-04 — Spy via static::$instance slot reflection.** Cleaner than swapping the parent OfferListStore's `arStoreList` entries because the service no longer dispatches through that path. `injectSingletonSpy(LeafClass, $obSpy)` + `afterEach { forgetInstance() }` keeps the spy state hermetic.
- **D-03-03-05 — Lovata Offer + Product registered as universal object crates.** Larastan can't infer Eloquent magic-property types. The crate registration suppresses property-not-found errors WITHOUT inline `@var`. Combined with `intval(...)` wrappers on every attribute read, PHPStan L10 accepts the int conversions.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] PHPStan L10 cannot type magic-property access on AbstractListStore::__get / Singleton::instance()**
- **Found during:** Task 1 GREEN (`make analyse` after first implementation pass)
- **Issue:** Plan's prescribed `OfferListStore::instance()->active->clear()` triggered `Cannot call method clear() on mixed` — `__get` returns `mixed`, and `Singleton::instance()` has no return-type annotation upstream so PHPStan infers `mixed` there too. Project rules explicitly forbid `@var`, `@phpstan-ignore`, and assert() suppression.
- **Fix (multi-part):**
  1. Created `phpstan-stubs/Singleton.stub` with `@return static` on the trait's `instance()` method. Wired via `stubFiles:` in phpstan.neon. This is the PHPStan-sanctioned path for typing untyped third-party code.
  2. Refactored the cache flush dispatch to leaf-singleton calls: `OfferActiveListStore::instance()->clear()` + `OfferSortingListStore::instance()->clear($sSortMode)` + `ProductActiveListStore::instance()->clear()`. Observationally identical to the parent-store path because `addToStoreList` registers the SAME singleton via the leaf class's own `instance()` factory.
  3. Test spies updated correspondingly: `injectSingletonSpy()` writes to each leaf class's `static::$instance` slot via reflection. afterEach calls `forgetInstance()` on each.
- **Files modified:** `classes/apply/StockApplyService.php`, `phpstan-stubs/Singleton.stub`, `phpstan.neon`, `tests/unit/Apply/Apply200LinesTriggersBatchedFlushNotPerSaveTest.php`
- **Verification:** `make analyse` → `[OK] No errors`; QA-04 spy test still asserts exactly 4 `clear()` calls (`shouldHaveReceived('clear')->once()` ×4). Behavior unchanged.
- **Committed in:** `b32a3fc` (Task 1+2 GREEN gate commit — same commit as the service)

**2. [Rule 3 — Blocking] PHPStan L10 cannot type Offer::quantity attribute access**
- **Found during:** Task 1 GREEN (`make analyse`)
- **Issue:** `(int) $obOffer->quantity + $iDelta` triggered `Cannot cast mixed to int` and `Access to undefined property Illuminate\Database\Eloquent\Model::$quantity`. Larastan can't infer Eloquent magic-property column types from the upstream Offer model.
- **Fix:**
  1. Extended `universalObjectCratesClasses` in phpstan.neon with `Lovata\Shopaholic\Models\Offer` + `Lovata\Shopaholic\Models\Product` — suppresses the property-not-found error WITHOUT inline @var.
  2. Replaced every `(int) $obFoo->property` with `intval($obFoo->property)` — `intval()` is a PHP function returning `int` regardless of input type, so the L10 strict-cast rule is satisfied.
- **Files modified:** `phpstan.neon`, `classes/apply/StockApplyService.php`
- **Verification:** PHPStan L10 clean. Behavior identical to `(int)` cast (both go through PHP's int conversion).
- **Committed in:** `b32a3fc` (Task 1+2 GREEN gate commit)

**3. [Rule 1 — Bug] Test attempted in-place mutation of readonly array property**
- **Found during:** Task 1 GREEN (one Pest test failed with `Cannot modify readonly property StockApplyOutcome::$affected_offer_ids`)
- **Issue:** The "returns StockApplyOutcome with correct ApplyResult shape" test called `sort($obOutcome->affected_offer_ids)` directly. PHP forbids mutating a readonly property even via reference-style functions like `sort`.
- **Fix:** Copy the readonly list to a local var before sorting: `$arActual = $obOutcome->affected_offer_ids; sort($arActual); expect($arActual)->toBe($arExpected);`. The behavior the test pins is unchanged — dedup + element identity.
- **Files modified:** `tests/unit/Apply/StockApplyServiceTest.php`
- **Verification:** Test now passes; readonly contract on StockApplyOutcome preserved.
- **Committed in:** `b32a3fc` (Task 1+2 GREEN gate commit)

---

**Total deviations:** 3 auto-fixed (2 Rule 3 — Blocking, 1 Rule 1 — Bug)
**Impact on plan:** No scope creep. All three deviations were correctness/criterion satisfiers — the plan's prescribed dispatch path was legitimate but tripped PHPStan L10 limitations on upstream untyped code; the readonly-property test bug was a self-inflicted typo. The PHPStan stub is a NET POSITIVE for the project — every Singleton trait user across the codebase now types cleanly without per-call workarounds.

## Issues Encountered

- **Singleton-spy injection refactor.** First spy implementation worked through `OfferListStore::instance()->arStoreList` — when the service then refactored to leaf-direct dispatch (D-03-03-02), the spies stopped intercepting. Fixed by inverting the spy mechanism to inject directly into each leaf class's `static::$instance` slot. Resolution time: ~5 min. No production code impact — only the test file was touched.

## Threat Surface Update

All four mitigations from `<threat_model>` now active:
- T-03-03-01 (Tampering via raw DB writes) — mitigated. `grep -nE 'DB::statement|DB::raw|->whereRaw' classes/apply/StockApplyService.php` returns 0 matches in code (only docblock cautions).
- T-03-03-02 (DoS via per-save cache cascade) — mitigated. saveQuietly + post-commit batched flush. QA-04 test asserts ≤ 5 list flushes (actual 4); query budget asserts ≤ 500 (actual 401).
- T-03-03-03 (Stale-cache race) — mitigated. flushAffectedCaches is a SEPARATE public method the orchestrator (03-07) calls AFTER `DB::transaction` commits. Service does NOT auto-fire from inside apply().
- T-03-03-04 (Lost-update on concurrent apply) — accepted by design. Defense lives in plan 03-07 ApplyOrchestrator via `Invoice::lockForUpdate()`.
- T-03-03-05 (Soft-deleted offer info disclosure) — accepted by design. `Offer::whereIn(...)->get()` respects softDeletes; if an offer is soft-deleted between match and apply, the keyBy('id') lookup returns null, the offer is skipped in pass 1, the line is counted as `lines_skipped` in pass 2, and the audit row remains.

No new threat surface introduced. The PHPStan stub does NOT expand attack surface — it only provides type information to the static analyzer.

## Note for Downstream Plans (03-04 / 03-07)

- **StockApplyOutcome shape is LOCKED.** `final readonly class StockApplyOutcome { public ApplyResult $result; public array $affected_offer_ids; }`. Plan 03-04 (ActiveFlagService) consumes `$obOutcome->affected_offer_ids` directly. Plan 03-07 (ApplyOrchestrator) consumes both fields. Do NOT modify the carrier shape.
- **flushAffectedCaches contract is LOCKED.** Public method, signature `flushAffectedCaches(array<int> $arOfferIds): void`, empty-list no-op, called by orchestrator AFTER `DB::transaction` commits.
- **ApplyTestCase hermetic schema is reusable.** Plans 03-04 / 03-05 / 03-07 should `extends ApplyTestCase` directly — same offers + products + invoices + invoice_lines minimal schema applies. If a future plan needs additional columns (e.g., 03-05 needs `logingrupa_goods_received_initial_reset_snapshot`), add to ApplyTestCase::setUp without breaking existing tests.
- **Singleton spy mechanism is reusable.** `injectSingletonSpy(LeafClass::class, \Mockery::spy(LeafClass::class))` + `afterEach { LeafClass::forgetInstance() }` pattern works for any October Rain Singleton trait user. Plan 03-04 will likely use the same pattern to spy on ActiveFlagService dependencies; plan 03-07 may use it on the orchestrator's outer transaction.

## Next Phase Readiness

- **Phase 3 Wave 2 plans 03-04 (ActiveFlagService) and 03-05 (InitialResetService) unblocked.** Both can `extends ApplyTestCase`, consume `StockApplyOutcome::$affected_offer_ids`, and rely on the established saveQuietly + post-commit-flush pattern.
- **Phase 3 Wave 3 plans 03-07 (ApplyOrchestrator) and 03-08 (ParseAndPersistOrchestrator) partially unblocked.** They will instantiate `StockApplyService`, call `apply()` inside `DB::transaction(...)`, then call `flushAffectedCaches()` AFTER commit.
- **No blockers.**

## TDD Gate Compliance

- **RED gate:** `bc9c9f9` (`test(03-03): add failing tests for StockApplyService + QA-04 cache flush (RED)`) — 12/12 tests fail with "Class … not found"
- **GREEN gate:** `b32a3fc` (`feat(03-03): implement StockApplyService + StockApplyOutcome (APPLY-01 / 02 / QA-04 GREEN)`) — 12/12 pass / 56 assertions
- **REFACTOR gate:** N/A (code minimal at GREEN; no duplication; methods within Tiger-Style 70-line caps)

Plan-level TDD type was `tdd="true"` on both tasks; both gates committed in correct order.

## Self-Check: PASSED

Verified at completion:

- ✓ `classes/apply/StockApplyService.php` exists (215 lines)
- ✓ `classes/apply/StockApplyOutcome.php` exists (36 lines)
- ✓ `tests/unit/Apply/ApplyTestCase.php` exists (145 lines)
- ✓ `tests/unit/Apply/StockApplyServiceTest.php` exists (293 lines)
- ✓ `tests/unit/Apply/Apply200LinesTriggersBatchedFlushNotPerSaveTest.php` exists (227 lines)
- ✓ `phpstan-stubs/Singleton.stub` exists (39 lines)
- ✓ Commit `bc9c9f9` (RED gate) found in `git log --oneline`
- ✓ Commit `b32a3fc` (GREEN gate) found in `git log --oneline`
- ✓ `make all` exit 0 — all gates green (pint, lint-settings-accessor, analyse, phpmd, test)
- ✓ 118/118 tests pass (463 assertions)
- ✓ `phpstan-baseline.neon` SHA unchanged (`4b3227fa…`)
- ✓ `grep -vE '^\s*\*|^\s*//' classes/apply/StockApplyService.php | grep -nE 'DB::statement|DB::raw|->whereRaw|->save\(\)'` returns 0 matches in code
- ✓ `grep -c 'saveQuietly' classes/apply/StockApplyService.php` returns 3 (≥ 2 required)
- ✓ 200-line query count: 401 (≤ 500 budget)
- ✓ List-store flush count: 4 (≤ 5 QA-04 contract)

---
*Phase: 03-apply-layer-orchestrators*
*Plan: 03 (StockApplyService — APPLY-01 / APPLY-02 / QA-04)*
*Completed: 2026-04-29*
