---
phase: 03-apply-layer-orchestrators
plan: 01
subsystem: testing
tags: [settings, accessor, memoization, dry, grep-gate, qa-09, apply-09, php8.4, pest4, makefile]

# Dependency graph
requires:
  - phase: 01-schema-scaffold-settings-permissions
    provides: Settings model (4 boolean fields, Multisite trait, SETTINGS_CODE)
  - phase: 01-schema-scaffold-settings-permissions
    provides: GoodsReceivedTestCase with flushPluginSingletons() hook
provides:
  - SettingsAccessor singleton with 4 memoized boolean getters + flush()
  - QA-09 grep gate (Makefile target + Pest mirror)
  - First populated body line of flushPluginSingletons() (D-03 wiring)
affects: [03-03-stockapply, 03-04-activeflag, 03-05-initialreset, 03-06-parseandpersist-orch, 03-07-apply-orch, 04-backend-controller]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Static singleton + private static $arCache memoization (atomic bulk-fill on first read)"
    - "Defense-in-depth grep gate: Makefile target + Pest mirror enforce same invariant via different paths"
    - "Hermetic schema setup mirroring EanMatcherTestCase: SettingsAccessorTestCase creates minimal system_settings table in setUp + drops in tearDown"

key-files:
  created:
    - classes/support/SettingsAccessor.php
    - tests/unit/Support/SettingsAccessorTest.php
    - tests/unit/Support/SettingsAccessorIsSoleConsumerOfSettingsGetTest.php
  modified:
    - tests/GoodsReceivedTestCase.php
    - tests/unit/TearDownFlushesSingletonsTest.php
    - Makefile

key-decisions:
  - "Hermetic system_settings schema in test setUp (same approach as EanMatcherTestCase) — full module migrate is broken on SQLite per plan 02-06 forensic note"
  - "TearDownFlushesSingletonsTest 'body is empty' Phase-1 assertion replaced with positive 'body contains SettingsAccessor::flush()' Phase-3 assertion (the test self-documented at L53-54 that this swap was expected)"
  - "Pint passed on first run; phpstan-baseline.neon SHA unchanged (no new baseline drift)"

patterns-established:
  - "Singleton flush hook: every plugin singleton with mutable static state MUST expose flush() and be wired into GoodsReceivedTestCase::flushPluginSingletons() before parent::tearDown()"
  - "Dual-gate invariant enforcement: Makefile target + Pest mirror so the contract survives loss of either"
  - "Negative-path verification of grep gates: temp violator file proves the gate fails when invariant breaks (cleanup mandatory)"

requirements-completed: [APPLY-09, QA-09]

# Metrics
duration: 6min
completed: 2026-04-29
---

# Phase 3 Plan 01: SettingsAccessor + QA-09 Grep Gate Summary

**Memoized SettingsAccessor singleton with 4 boolean getters, dual-gate (Makefile + Pest) DRY enforcement that all Phase 3 Apply services read settings through, and first populated line of the flushPluginSingletons() teardown hook.**

## Performance

- **Duration:** ~6 min
- **Started:** 2026-04-29T19:55:21Z
- **Completed:** 2026-04-29T20:01:00Z (approx)
- **Tasks:** 3
- **Files created:** 3
- **Files modified:** 3

## Accomplishments

- `SettingsAccessor` final class with 4 boolean getters (`isEnabled`, `autoDeactivateOnZero`, `autoActivateOnStock`, `allowInitialReset`) + `flush()` — atomic bulk-fill memoization caps DB reads at 4 per request regardless of apply-loop size (T-03-01-04 mitigation).
- 7-case Pest test (memoization round-trip, flush-then-reread, null→strict-false coercion, 4 per-getter true-path tests) — 100% green.
- `make lint-settings-accessor` Makefile target greps `Settings::get(` across `classes/`, `components/`, `models/`, `Plugin.php` and fails non-zero on any match outside `classes/support/SettingsAccessor.php`. Wired into `make all` between `pint-test` (cheap) and `analyse` (slow).
- `SettingsAccessorIsSoleConsumerOfSettingsGetTest` Pest mirror running the same scan via SPL recursive iterators. Negative path proven by temp-violator round-trip (test went red on violator, green on cleanup).
- `flushPluginSingletons()` body now calls `SettingsAccessor::flush()` — first populated line per D-03. Comment locks the invariant for downstream Phase 3 plans.

## Task Commits

1. **Task 1: SettingsAccessor + memoization (APPLY-09)** — `25474e6` (feat, TDD)
2. **Task 2: TestCase teardown wiring (D-03)** — `7e6afa1` (feat)
3. **Task 3: QA-09 grep gate (Makefile target + Pest mirror)** — `f28260c` (feat, TDD)

## Files Created/Modified

- `classes/support/SettingsAccessor.php` (101 lines) — final class, declare(strict_types=1), 4 boolean getters, flush(), private get($sKey) with atomic 4-key bulk-fill memoization. SOLE caller of `Settings::get(`.
- `tests/unit/Support/SettingsAccessorTest.php` (139 lines) — 7 cases (4 per-getter, memoization round-trip, null-to-false coercion, false-path) using a hermetic `SettingsAccessorTestCase` with `system_settings` table (id, item, value, site_id, site_root_id, site_group_id) created in setUp + dropped in tearDown.
- `tests/unit/Support/SettingsAccessorIsSoleConsumerOfSettingsGetTest.php` (78 lines) — Pest mirror of the Makefile grep gate using SPL `RecursiveIteratorIterator`; excludes the accessor file itself, assertion message lists offenders for actionable failure output.
- `tests/GoodsReceivedTestCase.php` — `flushPluginSingletons()` body replaced (Phase-1 placeholder → `SettingsAccessor::flush()` call); docblock comment notes future plans MAY add lines but MUST NOT remove this one.
- `tests/unit/TearDownFlushesSingletonsTest.php` — Phase-1 "body is empty" assertion (which Phase 1 self-documented as failing-by-design at L53-54 once Phase 2/3 populated the hook) replaced with positive Phase-3 assertion pinning `SettingsAccessor::flush()` substring + uniqueness count check.
- `Makefile` — `.PHONY` list cleaned up (dropped unused `lint`, added `pint-test` + `lint-settings-accessor`); new `lint-settings-accessor` target with actionable failure output (prints offending lines); `all:` chain now `pint-test → lint-settings-accessor → analyse → phpmd → test`.

## QA Gate Results

| Gate | Result | Notes |
|------|--------|-------|
| `make lint-settings-accessor` | exit 0 | 0 offenders outside SettingsAccessor.php |
| `make pint-test` | pass | `{"result":"pass"}` |
| `make analyse` (PHPStan L10 + Larastan) | clean | baseline SHA unchanged |
| `make phpmd` | clean | no new violations |
| `make test` (Pest 4) | 100 passed / 277 assertions | up from 92 / 264 in Phase 2 (8 new cases this plan + the modified TearDownFlushesSingletons test) |
| `make all` total | exit 0 | 4.376s |

## phpstan-baseline.neon SHA

| When | SHA-256 |
|------|---------|
| Before plan 03-01 | `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` |
| After plan 03-01 | `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` |

**Unchanged** — no new PHPStan baseline drift.

## QA-09 Invariant Confirmation

`Settings::get(` literal occurrences (across runtime surface — `classes/`, `components/`, `models/`, `Plugin.php`):

```
classes/support/SettingsAccessor.php:18: * QA-09 invariant: this file is the SOLE caller of `Settings::get(`. Both the
classes/support/SettingsAccessor.php:93:            self::KEY_ENABLED => (bool) Settings::get(self::KEY_ENABLED),
classes/support/SettingsAccessor.php:94:            self::KEY_AUTO_DEACTIVATE => (bool) Settings::get(self::KEY_AUTO_DEACTIVATE),
classes/support/SettingsAccessor.php:95:            self::KEY_AUTO_ACTIVATE => (bool) Settings::get(self::KEY_AUTO_ACTIVATE),
classes/support/SettingsAccessor.php:96:            self::KEY_ALLOW_RESET => (bool) Settings::get(self::KEY_ALLOW_RESET),
```

5 occurrences, **all in `classes/support/SettingsAccessor.php`** (1 docblock + 4 actual reads). 0 offenders.

## Decisions Made

- **Hermetic schema setup pattern reused.** Mirrors `EanMatcherTestCase` (per plan 02-06): full October module migration is broken on SQLite (drop-column-with-index issue). The test creates only the columns the SettingModel touches (id, item, value, site_id, site_root_id, site_group_id) and drops the table in tearDown. This keeps each test file decoupled from upstream migration order.
- **Invariant lock comment in `flushPluginSingletons()`.** The docblock now explicitly states "subsequent Phase 3 plans MAY add lines but MUST NOT remove this one" — protects T-03-01-01 mitigation against accidental drift in later plans.
- **`make all` chain order.** `lint-settings-accessor` placed AFTER `pint-test` (~1s) and BEFORE `analyse` (~slow). Cheap-fast checks first; ~50ms grep catches drift before the long PHPStan run.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Updated TearDownFlushesSingletonsTest "body is empty" assertion**
- **Found during:** Task 2 verification.
- **Issue:** Plan task 2 done-criterion required "Existing test `tests/unit/TearDownFlushesSingletonsTest.php` still passes." But that test (written in Phase 1) contains an assertion `expect($sBody)->not->toContain('::flush()')` whose lines 52-54 explicitly state: "when Phase 2/3 add real flush() calls the test will fail by design, prompting an update of THIS test (acceptable) at that time." The plan's done criterion contradicts the test's self-documented Phase-3 update protocol.
- **Fix:** Replaced the Phase-1 negative assertion with a Phase-3 positive assertion: `expect($sBody)->toContain('SettingsAccessor::flush()')` plus a uniqueness count check (`substr_count === 1`) to catch accidental duplication. Updated the surrounding docblock to record the swap.
- **Files modified:** `tests/unit/TearDownFlushesSingletonsTest.php`
- **Verification:** All 4 cases of the file pass (`pest --filter='TearDownFlushesSingletons'` → 4 passed, 10 assertions). The plan's `grep -c 'SettingsAccessor::flush' tests/GoodsReceivedTestCase.php` verification returns 2 (one docstring reference + one actual call); the plan asserted "1" but practical correctness is satisfied — exactly one `SettingsAccessor::flush()` *invocation* is in the body, which is what matters.
- **Committed in:** `7e6afa1` (Task 2 commit).

---

**Total deviations:** 1 auto-fixed (Rule 3 — blocking).
**Impact on plan:** No scope creep; the swap was already pre-authorized by Phase 1's self-documented Phase-3 update protocol. The plan's done-criterion just hadn't been updated to reflect that.

## Issues Encountered

- **First test run failed with `no such table: system_settings`.** Resolved by adopting the hermetic schema setUp pattern from `EanMatcherTestCase` (autoMigrate=true is broken on SQLite for full Lovata module migrations — see plan 02-06 forensic note). Added `SettingsAccessorTestCase` abstract class inside the test file with a minimal `system_settings` table create in setUp + drop in tearDown. Resolution time: ~3 min. No production impact.

## Threat Surface Update

All four mitigations from `<threat_model>` now active:
- T-03-01-01 (Information disclosure via cached cross-test bleed) — mitigated by `flush()` in tearDown.
- T-03-01-02 (Tampering via scattered `Settings::get` bypass) — mitigated by dual-gate (Makefile + Pest).
- T-03-01-03 (Repudiation: settings change mid-request) — accepted by design (memoization is the contract).
- T-03-01-04 (DoS: hot loop calling Settings::get) — mitigated by atomic 4-key bulk-fill memoization.

No new threat surface introduced.

## Next Phase Readiness

- **Wave 2 plans (03-03 StockApply, 03-04 ActiveFlag, 03-05 InitialReset)** can now read settings via `SettingsAccessor::*` calls. The grep gate enforces they MUST.
- **Wave 1 sibling plans** (03-02 ImportAuditService) are independent of this plan — no blockers.
- **Important for Phase 3 downstream:** every NEW plugin singleton (e.g., ImportAuditService if it ends up stateful) MUST add a `flush()` call to `GoodsReceivedTestCase::flushPluginSingletons()` AFTER the existing `SettingsAccessor::flush()` line — never before, never replacing.

## Self-Check: PASSED

Verified at completion:
- `classes/support/SettingsAccessor.php` exists (FOUND, 101 lines).
- `tests/unit/Support/SettingsAccessorTest.php` exists (FOUND, 139 lines).
- `tests/unit/Support/SettingsAccessorIsSoleConsumerOfSettingsGetTest.php` exists (FOUND, 78 lines).
- Commit `25474e6` (Task 1) found in `git log --oneline`.
- Commit `7e6afa1` (Task 2) found in `git log --oneline`.
- Commit `f28260c` (Task 3) found in `git log --oneline`.
- `make all` exits 0 (verified at completion).
- `phpstan-baseline.neon` SHA unchanged (`4b3227fa…`).
- `Settings::get(` literal scan: 5 occurrences, all in `SettingsAccessor.php`. 0 offenders.

---
*Phase: 03-apply-layer-orchestrators*
*Plan: 01*
*Completed: 2026-04-29*
