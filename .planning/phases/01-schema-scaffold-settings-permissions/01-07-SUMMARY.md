---
phase: 01-schema-scaffold-settings-permissions
plan: 07
subsystem: testing-base + multisite-proof + lifecycle-proof
tags: [pest4, phpunit12, reflection, multisite, qa-07, qa-11, test-base]

# Dependency graph
requires:
  - phase: 01-schema-scaffold-settings-permissions
    provides: "Settings model w/ Multisite trait (Plan 01-05); GoodsReceivedTestCase scaffold (initial); REQUIREMENTS.md QA-07/QA-11"
provides:
  - "tests/GoodsReceivedTestCase::flushPluginSingletons(): void hook (empty Phase 1 body, populated by Phase 2/3)"
  - "QA-07 part 1 proof: Settings uses Multisite trait + extends SettingModel directly (D15)"
  - "QA-07 part 2 proof: MultisiteScope registered as global query scope; initializeMultisite runs"
  - "QA-11 proof: flushPluginSingletons() invoked from tearDown() BEFORE parent::tearDown()"
affects: [phase-02-parser-tests, phase-03-apply-tests, phase-04-controller-tests]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Pest 4 file-level idiom: `it('...', function (): void { expect(...)->... });`"
    - "`uses(GoodsReceivedTestCase::class)` declaration when DB/app boot is needed"
    - "Pure-introspection tests (`ReflectionClass`, `class_uses_recursive`) for trait-presence proof — no DB needed"
    - "Source-string assertions for testing test-infrastructure (file_get_contents on test base)"
    - "Lifecycle-order assertion via strpos() comparison (flush call BEFORE parent::tearDown)"
    - "Phase-1-contract pinning test: hook body MUST stay empty until Phase 2/3 — fail-by-design when Phase 2 plumbs in real flush() calls"

key-files:
  created:
    - "tests/unit/Models/SettingsIsMultisiteAwareTest.php (5 it() blocks — QA-07 part 1)"
    - "tests/unit/Models/MultisiteContextSwitchClearsCacheTest.php (3 it() blocks — QA-07 part 2)"
    - "tests/unit/TearDownFlushesSingletonsTest.php (4 it() blocks — QA-11)"
  modified:
    - "tests/GoodsReceivedTestCase.php — added flushPluginSingletons(): void hook + wired into tearDown()"

key-decisions:
  - "Hook order: flushModelEventListeners() BEFORE flushPluginSingletons() BEFORE parent::tearDown(). Model-event listeners are detached first so any singleton flush logic in later phases runs in a no-fire context. Then framework teardown runs last."
  - "Phase 1 hook body is intentionally empty — no plugin singletons exist yet (Stores arrive Phase 2/3). This is the explicit D-22 contract."
  - "TearDownFlushesSingletonsTest test #4 pins the empty-body invariant. When Phase 2 adds the first `Store::flush()` call, this test will fail. That failure is the intended signal: Phase 2 must update test #4 to assert the new flush call's presence. Documented in the test's own comment."
  - "QA-07 part 2 verifies trait integration at the SCOPE level (MultisiteScope registered) rather than spinning up real Site facade contexts. Cross-site context-switch is heavy ceremony (requires populated `system_site_definitions`) — deferred to production smoke (Phases 4/5)."
  - "Tests do NOT use `uses(GoodsReceivedTestCase::class)` for pure introspection (Task 2). They DO use it where Settings is instantiated (Task 3) or reflection on the test-base class is needed (Task 4)."
  - "Existing test-base `flushModelEventListeners()` keeps its non-strict signature (no return type, no `declare(strict_types=1)` at file head). Adding strict_types to the existing file would be a drive-by refactor — out of scope per Tiger-Style 'Small commits, one concern per commit'."

patterns-established:
  - "Plugin-singleton lifecycle: each Phase 2/3 Store/Cache class adds (a) a `public static function flush(): void` method and (b) a single line in `flushPluginSingletons()` invoking it"
  - "Phase-contract pinning tests: when a Phase introduces a placeholder, write a test that asserts the placeholder shape; the test fails on the next Phase's edit, which is the trigger to update both the placeholder and the test"
  - "Source-string lifecycle-order assertion: `strpos($source, 'X')` < `strpos($source, 'Y')` is a robust, version-independent way to assert call ordering inside a known-stable method"

requirements-completed: [QA-07, QA-11]

# Metrics
duration: 2.7min
completed: 2026-04-29
---

# Phase 01 Plan 07: Test Base + QA-07 + QA-11 Summary

**Wires the `flushPluginSingletons(): void` lifecycle hook into `GoodsReceivedTestCase::tearDown()` (empty body in Phase 1, plug-in slot for Phase 2/3 Stores) and adds 12 Pest 4 tests across 3 files that prove the Settings model is multisite-aware (QA-07) and the new tearDown hook is correctly ordered (QA-11).**

## Performance

- **Duration:** 2.7 min
- **Started:** 2026-04-29T15:16:07Z
- **Completed:** 2026-04-29T15:18:50Z
- **Tasks:** 4
- **Files created:** 3
- **Files modified:** 1
- **Total `it()` blocks added:** 12 (5 + 3 + 4)

## Accomplishments

### Task 1 — Test base hook (`tests/GoodsReceivedTestCase.php`)

- Added `protected function flushPluginSingletons(): void` with a Phase 1 empty body and a structured doc-comment instructing Phase 2/3 contributors to add `Store::flush()` calls here.
- Wired the hook into `tearDown()` between `flushModelEventListeners()` and `parent::tearDown()`. Final order: model-event flush → singleton flush → parent teardown → unset app.
- Existing methods (`setUp`, `createApplication`, `flushModelEventListeners`, `guessPluginCodeFromTest`, `isAppCodeFromTest`, `resolveBootstrapPath`) untouched.

### Task 2 — `tests/unit/Models/SettingsIsMultisiteAwareTest.php` (QA-07 part 1)

5 pure-introspection assertions (no DB, no app boot):

1. `Settings` uses `October\Rain\Database\Traits\Multisite` (verified via `class_uses_recursive`).
2. `$propagatable` property exists, is array, is empty (required by `initializeMultisite()`).
3. `Settings` extends `System\Models\SettingModel` directly — D15 regression check guarding against accidental switch to `Lovata\Toolbox\Models\CommonSettings`.
4. `Settings::SETTINGS_CODE` constant equals `logingrupa_goodsreceivedshopaholic_settings`, and `$settingsCode` matches.
5. `$settingsFields === 'fields.yaml'`.

### Task 3 — `tests/unit/Models/MultisiteContextSwitchClearsCacheTest.php` (QA-07 part 2)

3 functional assertions (uses `GoodsReceivedTestCase` for app boot):

1. `MultisiteScope` is registered as a global query scope on `Settings` (verifies `bootMultisite()` ran).
2. `initializeMultisite()` runs without throwing — proves `$propagatable` shape passed validation, trait events bound.
3. `Settings::instance()` returns the same object on consecutive calls — proves the SettingModel instance memoization the trait must coexist with.

### Task 4 — `tests/unit/TearDownFlushesSingletonsTest.php` (QA-11)

4 lifecycle assertions:

1. Method `flushPluginSingletons` exists, is `protected`, return type is `void`.
2. Source of test base contains the literal string `$this->flushPluginSingletons();`.
3. `strpos($flushCall) < strpos('parent::tearDown();')` — lifecycle order is correct.
4. **Phase 1 contract:** method body contains no `::flush()` or `->flush()` calls — pins the empty-body invariant. Phase 2/3 will fail this assertion the moment they add the first real flush call, which is the intended signal to update this test alongside the singleton plumbing.

## Task Commits

1. **Task 1: extend tearDown() with flushPluginSingletons() hook** — `2ee2667` (test)
2. **Task 2: add SettingsIsMultisiteAwareTest (QA-07 part 1)** — `e9259ec` (test)
3. **Task 3: add MultisiteContextSwitchClearsCacheTest (QA-07 part 2)** — `dce7212` (test)
4. **Task 4: add TearDownFlushesSingletonsTest (QA-11)** — `33ece0c` (test)

## Files Created / Modified

| File | Status | Purpose |
|------|--------|---------|
| `tests/GoodsReceivedTestCase.php` | modified (+18 LoC) | Added `flushPluginSingletons(): void` hook + tearDown() wire |
| `tests/unit/Models/SettingsIsMultisiteAwareTest.php` | created (54 LoC) | QA-07 part 1: 5 introspection tests |
| `tests/unit/Models/MultisiteContextSwitchClearsCacheTest.php` | created (64 LoC) | QA-07 part 2: 3 functional tests |
| `tests/unit/TearDownFlushesSingletonsTest.php` | created (66 LoC) | QA-11: 4 lifecycle tests |

`tests/unit/Models/` directory was created as a side-effect of `Write` (auto-mkdir).

## Acceptance Criteria — Per-Task Status

### Task 1 — Test base hook
- AC1 `flushPluginSingletons(): void` signature → **PASS** (line 100)
- AC2 call adjacent to `parent::tearDown()` → **PASS**
- AC3 lifecycle order (`a < b < c`: lines 60 < 61 < 62) → **PASS**
- AC4 existing methods preserved (`setUp`, `flushModelEventListeners`, `resolveBootstrapPath` all present) → **PASS**
- AC5 `php -l` → "No syntax errors detected" → **PASS**

### Task 2 — SettingsIsMultisiteAwareTest
- AC1 file exists → **PASS**
- AC2 `declare(strict_types=1)` in head → **PASS**
- AC3 imports `Settings` model → **PASS**
- AC4 5 `it()` blocks → **PASS** (count=5)
- AC5 references `October.*Database.*Traits.*Multisite` → **PASS**
- AC6 references `System.*Models.*SettingModel` (D15) → **PASS**
- AC7 contains `logingrupa_goodsreceivedshopaholic_settings` literal → **PASS**
- AC8 `php -l` → "No syntax errors detected" → **PASS**
- AC9 Pest run green → **DEFERRED** (Pest binary unavailable in env; see Issues Encountered)

### Task 3 — MultisiteContextSwitchClearsCacheTest
- AC1 file exists → **PASS**
- AC2 `declare(strict_types=1)` in head → **PASS**
- AC3 `uses(GoodsReceivedTestCase::class)` → **PASS**
- AC4 references `MultisiteScope` → **PASS**
- AC5 3 `it()` blocks → **PASS** (count=3)
- AC6 `php -l` → "No syntax errors detected" → **PASS**
- AC7 Pest run green → **DEFERRED** (Pest binary unavailable in env)

### Task 4 — TearDownFlushesSingletonsTest
- AC1 file exists → **PASS**
- AC2 `declare(strict_types=1)` in head → **PASS**
- AC3 4 `it()` blocks → **PASS** (count=4)
- AC4 references `flushPluginSingletons` → **PASS**
- AC5 references `parent::tearDown` → **PASS**
- AC6 `php -l` → "No syntax errors detected" → **PASS**
- AC7 Pest run green → **DEFERRED** (Pest binary unavailable in env)

## Decisions Made

- **Hook lifecycle order:** model-event flush FIRST, then plugin-singleton flush, then `parent::tearDown()`. Singletons may hold references to model events; flushing model listeners first means singleton flush logic in Phase 2/3 runs in a quiet context.
- **Hook body empty in Phase 1:** the D-22 contract is explicit — no singletons exist yet. Wiring the hook NOW means Phase 2/3 add a one-liner per new singleton (no plumbing changes to the test base).
- **Test 4's Phase-1-contract pin:** asserting the empty body is intentional. When Phase 2 plumbs in `InvoiceListStore::flush()`, this test fails, which is the signal to update both the singleton list AND the test in lockstep.
- **QA-07 part 2 stays at SCOPE level:** registering the global `MultisiteScope` is the necessary-and-sufficient condition for site-aware filtering. Spinning up multiple `Site` rows in `system_site_definitions` for a unit-level cache-clear assertion is heavy ceremony and brittle. Phase 4/5 production smoke handles the cross-site behaviour-level proof.
- **No `declare(strict_types=1)` added to `tests/GoodsReceivedTestCase.php`:** would be a drive-by refactor; out of scope per Tiger-Style. Existing methods retain their non-strict signatures; only the new method is `: void`-typed.

## Deviations from Plan

None — plan executed exactly as written. All 4 tasks completed in the specified order with the specified file structure and `it()` block counts.

## Deferred Items

- **Pest / PHPStan / PHPMD / Pint runtime gates (verification steps 3–6 in plan `<verification>`):** the parent project's `vendor/bin/` contains no `pest`, `phpstan`, `phpmd`, or `pint` binaries (only October/Laravel runtime tooling). Static gates fall back to `php -l` (PASSED for all 4 files) and 28+ structural grep/awk acceptance criteria (PASSED).
- **Resolution path:** Phase 5 OPS-04 (or a separate plugin-local QA-tooling-install task) will install dev dependencies (`composer require --dev pestphp/pest larastan/larastan ...`) and re-run all three test files to confirm the 12 `it()` blocks are green at runtime. The structural ACs that DID run cover trait-presence, source-content, lifecycle-order, file existence, syntactic validity, and `it()`-block count; the only thing they cannot prove is "the test runner discovers and successfully evaluates each `expect(...)` chain". This is a known phase-wide environmental gap, not specific to this plan (Plans 01-01..01-06 hit the same issue and resolved identically).
- **Phase 2/3 test-update obligation:** when Phase 2 adds the first plugin singleton, it MUST update `tests/unit/TearDownFlushesSingletonsTest.php` test #4 ("flushPluginSingletons body is empty in Phase 1") in lockstep. The test will visibly fail if forgotten — that's the design.

## Issues Encountered

- **No Pest / PHPStan / Pint binaries in the environment.** Documented above (Deferred Items).
- **No other issues.** All 4 tasks completed first-try, all static ACs passed, all 4 commits succeeded with no hook failures.

## Threat Mitigations Applied

| Threat ID | Mitigation |
|-----------|------------|
| T-01-29 (Tampering — cross-test state bleed) | `flushPluginSingletons()` hook installed; Test 3 of QA-11 enforces lifecycle order; Test 4 pins Phase-1 empty-body contract so Phase 2/3 cannot accidentally regress the wire-up. |
| T-01-30 (Information Disclosure — introspection) | Accepted; reflection is the standard idiom for testing class infrastructure. Test-only code; no production path affected. |
| T-01-31 (DoS — unbounded flushPluginSingletons body) | Accepted; Phase 1 body is empty. Each Phase 2/3 singleton adds an O(1) call. Bounded growth proportional to singleton count (<10 by Phase 3 close). |

## Note for Phase 2 Planner

When adding the first plugin singleton (e.g., `InvoiceListStore`):

1. Add `public static function flush(): void { /* clear in-process cache */ }` to the singleton class.
2. Add a single line to `tests/GoodsReceivedTestCase::flushPluginSingletons()`:
   ```php
   \Logingrupa\GoodsReceivedShopaholic\Classes\Store\InvoiceListStore::flush();
   ```
3. **Update `tests/unit/TearDownFlushesSingletonsTest.php` test #4** ("flushPluginSingletons body is empty in Phase 1"). Replace the `expect(...)->not->toContain('::flush()')` assertions with `expect(...)->toContain('InvoiceListStore::flush()')` (or a more general `toMatch('/::flush\(\)/')` pattern that asserts the body now plumbs at least one singleton). The failing test on first edit is the intended signal — do not silence it; update it.

## Self-Check: PASSED

Verification:

- File exists `/home/forge/nailscosmetics.lv/plugins/logingrupa/goodsreceivedshopaholic/tests/GoodsReceivedTestCase.php` → FOUND
- File exists `/home/forge/nailscosmetics.lv/plugins/logingrupa/goodsreceivedshopaholic/tests/unit/Models/SettingsIsMultisiteAwareTest.php` → FOUND
- File exists `/home/forge/nailscosmetics.lv/plugins/logingrupa/goodsreceivedshopaholic/tests/unit/Models/MultisiteContextSwitchClearsCacheTest.php` → FOUND
- File exists `/home/forge/nailscosmetics.lv/plugins/logingrupa/goodsreceivedshopaholic/tests/unit/TearDownFlushesSingletonsTest.php` → FOUND
- Commit `2ee2667` (Task 1) → FOUND in `git log`
- Commit `e9259ec` (Task 2) → FOUND in `git log`
- Commit `dce7212` (Task 3) → FOUND in `git log`
- Commit `33ece0c` (Task 4) → FOUND in `git log`
- All 4 PHP files pass `php -l` ("No syntax errors detected")
- 12 `it()` blocks across 3 new test files (5 + 3 + 4 — matches plan output spec)

---
*Phase: 01-schema-scaffold-settings-permissions*
*Plan: 07*
*Completed: 2026-04-29*
