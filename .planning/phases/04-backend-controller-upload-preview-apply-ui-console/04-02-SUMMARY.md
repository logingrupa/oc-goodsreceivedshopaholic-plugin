---
phase: 04-backend-controller-upload-preview-apply-ui-console
plan: 02
subsystem: console-recovery-cli
tags: [console, artisan, recompute-active-from-stock, ui-11, plugin-register, idempotent-cli]
requires:
  - 04-01-SUMMARY.md  # Plan 04-01 closed UI-12; baseline still at 4b3227fa…91530a
  - 03-04-SUMMARY.md  # ActiveFlagService::reconcileAll contract (consumed by command)
provides:
  - artisan-command-goodsreceived-recompute-active-from-stock
  - plugin-register-hook-wiring
  - chunk-option-with-non-positive-coerce-default-500
  - throwable-caught-exit-1-failure-contract
affects:
  - .planning/REQUIREMENTS.md  # UI-11 flipped Pending → Closed
  - .planning/ROADMAP.md        # Phase 4 plan 04-02 box checked
  - .planning/STATE.md          # Phase 4 in-progress; plan counter advanced
tech-stack:
  added: []
  patterns:
    - artisan-command-as-recovery-cli (one-shot reconcile after settings drift)
    - tiger-style-guard-clause-coerce (non-positive --chunk → default 500; T-04-02-01)
    - throwable-catch-at-handle-boundary (exit 1 + $this->error; T-04-02-04 mitigation)
    - boundary-mock-via-subclass (failing fake bound into IoC for exit-1 contract test; mirror of D-03-07-01)
    - dual-gate-source-grep-plus-runtime (PluginRegistersConsoleCommand source pin + RecomputeActiveFromStock runtime pin)
key-files:
  created:
    - console/RecomputeActiveFromStock.php
    - tests/unit/Console/RecomputeActiveFromStockTest.php
    - tests/unit/Console/PluginRegistersConsoleCommandTest.php
    - .planning/phases/04-backend-controller-upload-preview-apply-ui-console/04-02-SUMMARY.md
  modified:
    - Plugin.php                           # added register() body + import
    - classes/apply/ActiveFlagService.php  # final removed (D-04-02-01 boundary-mock precedent)
    - phpstan.neon                          # paths += console
    - Makefile                              # phpmd + lint-settings-accessor scope += console
    - .planning/REQUIREMENTS.md
    - .planning/ROADMAP.md
    - .planning/STATE.md
key-decisions:
  - "D-04-02-01: ActiveFlagService final removed for boundary-mock support. Mirror of the Phase 3 D-03-07-01 precedent (ImportAuditService): the service is a service-layer boundary called via `app(ActiveFlagService::class)` IoC; production code never subclasses it (the leaf class is always resolved by the default container binding). The exit-1-on-failure contract test in RecomputeActiveFromStockTest needs an injected failing service to assert handle()'s Throwable catch, and subclass + override is the cleanest portable path (Mockery cannot mock final classes by default; reflection patching is brittle). Class docblock notes the boundary-mock rationale + production-code invariant."
  - "D-04-02-02: instanceof guard for `app(ActiveFlagService::class)` dropped from handle(). The plan listed the guard as a defensive narrowing for PHPStan L10, but Larastan's app() extension already narrows the return to the typed instance — the guard hits PHPStan L10's instanceof.alwaysTrue rule (verified empirically). The Throwable catch immediately after the app() resolve already covers BindingResolutionException (the only realistic IoC failure mode), so the guard added zero protection over the catch."
  - "D-04-02-03: Progress bar deferred. Plan listed `$this->output->progressStart/Advance/Finish` per D-31, but ActiveFlagService::reconcileAll already chunked-iterates internally and exposes only a final touched-count (no per-chunk callback hook); wrapping a Symfony progress bar around a call that returns only on completion would be UX theatre. Operator's receipt is the single `Reconciled N offers (chunk=K).` line printed on success. Adding a per-chunk callback to ActiveFlagService is a Phase 5 V2 enhancement if operator feedback warrants it."
  - "D-04-02-04: Static-analysis surface extended to console/. phpstan.neon paths now include `console`; Makefile phpmd + QA-09 grep-gate scopes also extended. Without this change PHPStan would silently skip the new file (verified: 31 → 32 files analysed after the path addition). Same defense-in-depth principle as the Phase 1+2+3 paths config: every production directory must be in the analyser path list, not relying on the default-include behaviour."
  - "D-04-02-05: Output capture in tests via `$sOutput = \\Artisan::output()` once-per-call. Multiple `\\Artisan::output()` calls in the same it() block returned empty on the second invocation (verified — caused 2/5 initial test failures). Capture once into a local string and assert against that. Mirror of the same Symfony BufferedOutput one-shot-read behaviour documented for `Console\\Tester\\CommandTester::getDisplay()`."
patterns-established:
  - "Console-command shape for future GoodsReceived CLIs: `final class extends Illuminate\\Console\\Command`, `$signature` carries the `goodsreceived:<verb_under_score>` name, handle() returns `int` (0=success, 1=Throwable caught), all options sanitised at the top of handle() via guard clauses (T-04-02-01 pattern). Reusable as plan 04-08 etc. add more recovery CLIs."
  - "Plugin::register() vs Plugin::boot() split: register() is for IoC wiring (registerConsoleCommand, container binds, route prefixes), boot() is for runtime hooks (event subscribers, ini self-checks, App::runningInBackend gates). Plan 04-01 used boot() correctly for the ini self-check; this plan uses register() correctly for the console command. October's PluginBase contract distinguishes the two lifecycle hooks — keep them separated."
  - "Dual gate (source-grep + runtime) for register-time wiring: the runtime test in RecomputeActiveFromStockTest registers the command on the IoC console kernel directly (bypassing Plugin::register so the command's contract is tested in isolation); the structural test in PluginRegistersConsoleCommandTest pins the source-level wiring so a future refactor cannot silently drop the registerConsoleCommand line. Either gate alone is sufficient for correctness; both together survive lifecycle-bootstrap drift OR test-double drift."
requirements-completed: [UI-11]
metrics:
  duration_minutes: 7
  completed: 2026-04-29
---

# Phase 4 Plan 02: Console Recompute-Active-From-Stock Summary

**`php artisan goodsreceived:recompute_active_from_stock {--chunk=500}` reconciles every non-operator offer's `active` flag from current `quantity` via `ActiveFlagService::reconcileAll`; honours the per-row `active_managed_by='operator'` provenance gate; exits 0 on success / 1 on uncaught Throwable; wired into `Plugin::register()` per October's lifecycle.**

## Performance

- **Duration:** ~7 min
- **Started:** 2026-04-29T22:14:00Z (post-04-01 close)
- **Completed:** 2026-04-29T22:21:00Z
- **Tasks:** 2 (Task 1 — Console command class, RED → GREEN; Task 2 — Plugin::register() wiring + dual-gate test, RED → GREEN)
- **Files modified:** 5 production-relevant (1 new console command + 1 modified service + 1 Plugin.php register() body + phpstan.neon + Makefile) + 2 new test files + 3 .planning/ docs

## Accomplishments

- **UI-11 closed.** Operators now have a CLI-driven recovery path when Settings toggles flip after stock data has drifted (e.g., legacy 1C XML import previously owned `quantity`; operator just disabled that and turned on `auto_deactivate_on_zero` — the table needs one reconcile pass before the next inbound apply). Same path covers post-restore dry-runs after disaster recovery.
- **Operator-skip contract end-to-end.** `active_managed_by='operator'` rows are excluded AT THE QUERY LEVEL inside `ActiveFlagService::reconcileAll` (D-03-04-03); the new command inherits that contract — verified by `it skips operator-managed offers end-to-end via the command`.
- **Sanitised `--chunk` option (T-04-02-01 mitigated).** Non-positive values (`--chunk=0`, `--chunk=-1`) coerce to default 500 in handle() before reaching the service; cannot silently no-op or loop. T-04-02-02 (over-large `--chunk` OOM) accepted because `chunkById` bounds memory by the chunk's collection size regardless.
- **Repudiation-safe failure (T-04-02-04 mitigated).** `Throwable` caught at handle()'s outer boundary; `$this->error('Recompute failed: ' . $obException->getMessage())` + exit code 1 surfaces in any sane orchestration / Forge deploy log.
- **Zero baseline drift.** `phpstan-baseline.neon` SHA `4b3227fa…91530a` UNCHANGED — the new console command + Plugin::register() body are L10-clean at source. The `final` removal on ActiveFlagService is a contract relaxation, not a type narrowing — PHPStan re-runs the file unchanged.
- **`make all` green.** pint-test + lint-settings-accessor (QA-09) + analyse + phpmd + test all clean.
- **Suite growth: 152/152 → 159/159 (+7).** 730 → 770 assertions (+40). 5 new RecomputeActiveFromStock cases + 2 new PluginRegistersConsoleCommand source-grep cases.

## Task Commits

Plan-level TDD enforced: RED → GREEN sequence committed atomically per task.

1. **Task 1 RED gate: failing tests for RecomputeActiveFromStock** — `3dccc5e` (test)
   - 5 it() cases pinning the artisan invocation contract, --chunk option propagation, operator-skip, and exit-1-on-Throwable behaviour. RED gate confirmed: `Class "Logingrupa\GoodsReceivedShopaholic\Console\RecomputeActiveFromStock" not found` × 5.
2. **Task 1 GREEN gate: implement console command + Rule 1/3 deviations** — `d25558a` (feat)
   - `console/RecomputeActiveFromStock.php` (Illuminate\Console\Command subclass, signature `goodsreceived:recompute_active_from_stock {--chunk=500}`, handle() returns int).
   - **Deviation D-04-02-01 (Rule 1 — Bug):** ActiveFlagService `final` removed. Test could not extend the final class for failure injection; subclass + override is the cleanest path per the Phase 3 D-03-07-01 precedent.
   - **Deviation D-04-02-02 (Rule 1 — Bug):** Plan-suggested `instanceof ActiveFlagService` guard dropped from handle(). PHPStan L10 raised `instanceof.alwaysTrue` because Larastan's `app()` extension already narrows the return type. Throwable catch covers the realistic IoC failure mode.
   - **Deviation D-04-02-04 (Rule 3 — Blocking):** phpstan.neon `paths` extended with `console`. Without this, PHPStan silently skipped the new directory (31 vs 32 files analysed). PHPMD scope + QA-09 grep gate also extended for parity.
   - **Deviation D-04-02-05 (Rule 1 — Bug):** Tests now capture `\Artisan::output()` once into a local variable. Multiple consecutive calls returned empty on the second invocation (Symfony BufferedOutput one-shot semantics) — caused 2/5 test failures during the initial GREEN run.
3. **Task 2 RED gate: failing tests for Plugin::register() wiring** — `b4918d7` (test)
   - 2 it() cases (PluginRegistersConsoleCommandTest) pinning the source-level registerConsoleCommand call + `#[\Override]` attribute. RED gate confirmed: Plugin.php had no `register()` method yet.
4. **Task 2 GREEN gate: Plugin::register() body + import** — `9f8727a` (feat)
   - Adds `use Logingrupa\GoodsReceivedShopaholic\Console\RecomputeActiveFromStock;` import + `#[\Override] public function register(): void` body calling `registerConsoleCommand('goodsreceived:recompute_active_from_stock', RecomputeActiveFromStock::class)`. 2/2 dual-gate cases green; full plugin suite 159/159; baseline SHA unchanged.

**Plan metadata commit (final):** appended after this SUMMARY is written (docs).

## Files Created/Modified

### Created

- **`console/RecomputeActiveFromStock.php`** (~70 LoC including class docblock).
  - `final class RecomputeActiveFromStock extends Illuminate\Console\Command`.
  - `$signature = 'goodsreceived:recompute_active_from_stock {--chunk=500}'`.
  - `$description` = succinct operator-facing one-liner.
  - `handle(): int` — coerces non-positive --chunk to 500, resolves `app(ActiveFlagService::class)`, calls `reconcileAll($iChunk)`, prints `Reconciled N offers (chunk=K).` and returns 0; catches `Throwable`, prints `Recompute failed: <message>` and returns 1.
  - Class docblock cross-references UI-11 / D-29..D-33 + the threat register entries (T-04-02-01, T-04-02-04).

- **`tests/unit/Console/RecomputeActiveFromStockTest.php`** (5 it() cases, 33 assertions).
  - `beforeEach` registers the command on the IoC console kernel directly via `$obKernel->registerCommand(new RecomputeActiveFromStock())` — bypasses the autoRegister=false ApplyTestCase contract, which keeps these cases focused on the command's contract rather than Plugin::register's lifecycle wiring (covered separately by PluginRegistersConsoleCommandTest).
  - Cases: clean no-op exit-0 (both toggles off), reconciles every non-operator offer in chunks + reports count, operator-skip end-to-end, --chunk option propagation, exit 1 + error message on Throwable.

- **`tests/unit/Console/PluginRegistersConsoleCommandTest.php`** (2 it() cases, 7 assertions).
  - Pure source-grep contract pin against Plugin.php — `public function register()` declaration, `registerConsoleCommand(` call, RecomputeActiveFromStock class reference, the artisan signature literal, and the `#[\Override]` attribute.

### Modified

- **`Plugin.php`** — added `use Logingrupa\GoodsReceivedShopaholic\Console\RecomputeActiveFromStock;` to the import block + new `#[\Override] public function register(): void` method placed between `pluginDetails()` and `boot()` (matches October's lifecycle ordering: register → boot → routes). `pluginDetails()` / `registerPermissions()` / `registerSettings()` / `boot()` / `parseIniSize()` UNCHANGED.

- **`classes/apply/ActiveFlagService.php`** — `final` keyword removed; class docblock extended with the boundary-mock rationale (D-04-02-01) and the production-code invariant (`app()` always resolves the leaf class).

- **`phpstan.neon`** — `paths` array now includes `console` alongside `classes`, `components`, `models`, `Plugin.php`. Empirically verified: file count 31 → 32 after the change.

- **`Makefile`** — `phpmd` target's path list extended with `$(PLUGIN_DIR)/console`. `lint-settings-accessor` (QA-09) target's grep + counter-grep path lists also extended for symmetry.

## Validation

- **`make all` green:** pint-test ✓, lint-settings-accessor (QA-09 grep gate) ✓, analyse (PHPStan L10) ✓, phpmd ✓, test (Pest 4) ✓.
- **Suite:** 159/159 (was 152/152, +7). 770 assertions (was 730, +40).
- **PHPStan baseline SHA UNCHANGED:** `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a`.
- **TDD gate sequence verified in git log:**
  - `3dccc5e test(04-02): add failing tests for RecomputeActiveFromStock console command` (RED)
  - `d25558a feat(04-02): implement goodsreceived:recompute_active_from_stock console command` (GREEN)
  - `b4918d7 test(04-02): add failing tests pinning Plugin::register() console-command wiring` (RED)
  - `9f8727a feat(04-02): wire RecomputeActiveFromStock via Plugin::register()` (GREEN)

## Deviations from Plan

### Auto-fixed Issues

**D-04-02-01 [Rule 1 — Bug] Removed `final` from `ActiveFlagService`**
- **Found during:** Task 1 GREEN run.
- **Issue:** Plan listed an anonymous-class subclass + override as the IoC failure-injection mechanism for the exit-1 contract test (`new class extends ActiveFlagService { ... reconcileAll() throws ... }`). PHP rejected this with `Class ActiveFlagService@anonymous cannot extend final class ActiveFlagService` × 5 test cases.
- **Fix:** Removed `final` from the class declaration; extended the class docblock with the boundary-mock rationale + production-code invariant. Same precedent as Phase 3 D-03-07-01 (ImportAuditService), which was opened for the same boundary-mock reason.
- **Files modified:** `classes/apply/ActiveFlagService.php`.
- **Commit:** `d25558a`.

**D-04-02-02 [Rule 1 — Bug] Dropped `instanceof ActiveFlagService` guard**
- **Found during:** Task 1 GREEN run, after PHPStan L10 ran on the new file.
- **Issue:** Plan listed an `instanceof` guard in handle() to satisfy PHPStan L10's narrowing requirement for `app(ClassString)`. Larastan's app() extension already narrows the return to the typed instance, and PHPStan L10 raised `instanceof.alwaysTrue` (with the `Because the type is coming from a PHPDoc, you can turn off this check by setting treatPhpDocTypesAsCertain: false` hint).
- **Fix:** Dropped the guard; replaced it with a comment explaining why (and that the Throwable catch already covers BindingResolutionException).
- **Files modified:** `console/RecomputeActiveFromStock.php`.
- **Commit:** `d25558a`.

**D-04-02-04 [Rule 3 — Blocking] Extended phpstan.neon paths to include `console`**
- **Found during:** Task 1 GREEN verification.
- **Issue:** PHPStan's `paths` config had `classes`, `components`, `models`, `Plugin.php` — but not `console`. The new file at `console/RecomputeActiveFromStock.php` would have been silently skipped by `make analyse` and `make all`, defeating the L10 contract for the new code.
- **Fix:** Added `console` to phpstan.neon paths. Also extended the Makefile's `phpmd` target + `lint-settings-accessor` (QA-09) grep gate path lists for symmetry. Empirically verified: phpstan now analyses 32 files instead of 31.
- **Files modified:** `phpstan.neon`, `Makefile`.
- **Commit:** `d25558a`.

**D-04-02-05 [Rule 1 — Bug] Tests capture `Artisan::output()` once per call**
- **Found during:** Task 1 GREEN verification (2/5 tests failed initially with empty output assertions).
- **Issue:** Tests 4 and 5 made multiple `expect(\Artisan::output())->toContain(...)` calls in the same it() block. The second call always returned an empty string. Symfony's BufferedOutput (which Artisan uses internally during testing) has one-shot read semantics — after the buffer is drained, subsequent reads return empty.
- **Fix:** Capture `$sOutput = \Artisan::output()` once into a local variable, then assert against the variable. Same Symfony pattern as `Console\Tester\CommandTester::getDisplay()`.
- **Files modified:** `tests/unit/Console/RecomputeActiveFromStockTest.php`.
- **Commit:** `d25558a` (final test version).

### Deferred (Documented as Decisions)

**D-04-02-03 — Progress bar deferred**
- **Plan asked for:** Symfony progress bar via `$this->output->progressStart()` / `progressAdvance()` / `progressFinish()` per D-31.
- **Why deferred:** `ActiveFlagService::reconcileAll` chunked-iterates internally and exposes only a final touched-count return value (no per-chunk callback hook). Wrapping a Symfony progress bar around a single call that returns only on completion would be UX theatre — the bar would jump from 0% to 100% in one tick.
- **Fallback chosen:** Single `Reconciled N offers (chunk=K).` info line on success — operator's receipt for the run.
- **Future work:** If operator feedback warrants visible progress, plan 05-OPS would add a per-chunk callback to `ActiveFlagService::reconcileAll(int $iChunkSize, ?Closure $obProgressCallback = null)` and rewire the command to drive Symfony's progress helper. Logged as V2-OPS-04 in the deferred-items list.

## Self-Check: PASSED

**Files claimed created — all FOUND:**

- `console/RecomputeActiveFromStock.php` — FOUND
- `tests/unit/Console/RecomputeActiveFromStockTest.php` — FOUND
- `tests/unit/Console/PluginRegistersConsoleCommandTest.php` — FOUND
- `.planning/phases/04-backend-controller-upload-preview-apply-ui-console/04-02-SUMMARY.md` — FOUND (this file)

**Files claimed modified — all confirmed via git log of feature commits:**

- `Plugin.php` (commit `9f8727a`) — FOUND
- `classes/apply/ActiveFlagService.php` (commit `d25558a`) — FOUND
- `phpstan.neon` (commit `d25558a`) — FOUND
- `Makefile` (commit `d25558a`) — FOUND

**Commits claimed — all FOUND in `git log --oneline -10`:**

- `3dccc5e test(04-02): add failing tests for RecomputeActiveFromStock console command` — FOUND
- `d25558a feat(04-02): implement goodsreceived:recompute_active_from_stock console command` — FOUND
- `b4918d7 test(04-02): add failing tests pinning Plugin::register() console-command wiring` — FOUND
- `9f8727a feat(04-02): wire RecomputeActiveFromStock via Plugin::register()` — FOUND

**TDD gate compliance:** RED `test(04-02)` commits precede GREEN `feat(04-02)` commits in git log for both tasks. ✓

**Suite snapshot at close:** 159 passed (770 assertions). 0 failed. 0 skipped.

## Threat Flags

None — the new surface (artisan CLI command) is fully covered by the plan's threat register (T-04-02-01..05). No new boundaries / endpoints / file access patterns introduced beyond what the plan anticipated.
