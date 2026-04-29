---
phase: 01-schema-scaffold-settings-permissions
plan: 08
subsystem: testing
tags: [phpstan, larastan, pint, phpmd, pest, qa-gate, ci]

requires:
  - phase: 01-schema-scaffold-settings-permissions
    provides: "All scaffolded artifacts from plans 01-01..01-07 (4 migrations, 4 models, fields.yaml, lang files, fixtures, 12 tests, Plugin.php with permissions+settings registration)"
provides:
  - "Phase-1 final QA verdict: GREEN with 1 inline Rule-1 fix (PHPDoc cleanup)"
  - "Confirmed phpstan-baseline.neon byte-identical (33 bytes, sha256 4b3227fa…) — no waivers added"
  - "Confirmed `make all` exits 0 in 1.733s on cumulative Phase-1 codebase"
  - "Resolution of Plan 01-02's deferred 'QA tooling not installed' item"
affects: [02-htm-parser, 03-import-services, all-future-phases]

tech-stack:
  added: []
  patterns:
    - "Strict PHPDoc hygiene: @method @mixin pseudo-class lines forbidden (PHPStan level 10 rejects them)"
    - "phpstan-baseline.neon is locked at 33 bytes — new code adds nothing, no waivers"

key-files:
  created:
    - ".planning/phases/01-schema-scaffold-settings-permissions/01-08-SUMMARY.md"
  modified:
    - "models/Invoice.php (PHPDoc cleanup)"
    - "models/InvoiceLine.php (PHPDoc cleanup)"
    - "models/InitialResetSnapshot.php (PHPDoc cleanup)"
    - "models/Settings.php (PHPDoc cleanup)"
    - ".planning/phases/01-schema-scaffold-settings-permissions/deferred-items.md (resolution note)"

key-decisions:
  - "Drop @method newQuery()/query() and @mixin \\Eloquent PHPDoc lines: PHPStan level 10 rejects them (October Builder is not generic; \\Eloquent is an ide-helper hint class, not real). Real @property blocks remain — those drive type inference."
  - "phpstan-baseline.neon stays untouched: contract verified before AND after fix (33 bytes, sha256 4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a)."

patterns-established:
  - "QA gate pattern: run each sub-gate individually for clean diagnosis, then run composite `make all` as smoke confirmation."
  - "Baseline contract verification: capture sha256 pre-gate, compare post-gate, require zero diff."

requirements-completed: []

duration: 1m34s
completed: 2026-04-29
---

# Phase 1 Plan 08: Final QA Gate Summary

**`make all` exits 0 across the full Phase-1 codebase after one Rule-1 fix that removed 11 IDE-helper PHPDoc artifacts breaking PHPStan level 10; phpstan-baseline.neon byte-identical (zero waivers added).**

## Performance

- **Duration:** 1m 34s
- **Started:** 2026-04-29T17:52:11Z
- **Completed:** 2026-04-29T17:53:45Z
- **Tasks:** 5
- **Files modified:** 4 source (models cleanup) + 1 deferred-items note + 1 SUMMARY

## Accomplishments

- All 4 sub-gates of `make all` pass: pint-test, phpstan analyse (level 10), phpmd, pest
- 12 tests passing, 23 assertions, 0.43s test runtime, 1.733s composite gate runtime
- `phpstan-baseline.neon` remains at the locked 33-byte stub — no waivers introduced for any new Phase-1 code
- One Rule-1 deviation handled inline: stale IDE-helper PHPDoc tags removed from 4 model files

## Gate Results

| # | Gate | Command | Exit | Output Marker | Duration (alone) |
|---|------|---------|------|---------------|------------------|
| 1 | Pint (PSR-12 + ordered_imports) | `make pint-test` | 0 | `{"result":"pass"}` | <1s |
| 2 | PHPStan level 10 + Larastan | `make analyse` | 0 (after fix) | `[OK] No errors` (5/5 files) | ~5s |
| 3 | PHPMD (Lovata thresholds) | `make phpmd` | 0 | empty (success) | <1s |
| 4 | Pest 4 / PHPUnit 12 | `make test` | 0 | `Tests: 12 passed (23 assertions)` | 0.43s |
| 5 | Composite gate | `make all` | 0 | all 4 sub-gates green | 1.733s real |

### PHPStan baseline contract

| | Pre-gate | Post-gate | Δ |
|---|---|---|---|
| **Bytes** | 33 | 33 | 0 |
| **sha256** | `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` | `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` | identical |
| **`git diff phpstan-baseline.neon`** | empty | empty | none |

Locked engineering quality bar (PROJECT.md "Static Analysis (HARD GATE)") upheld: new code adds nothing to the baseline.

## Task Commits

This plan is verify-only; no per-task commits. Plan 01-08 itself produced exactly one source-code commit (the deviation fix) plus the metadata commit at the end.

1. **Task 1: pint-test** — no commit (verification only, exit 0)
2. **Task 2: phpstan analyse** — initially exit 2 with 11 errors; fixed at root and committed:
   - `5c558dc` — `fix(01-08): drop IDE-helper PHPDoc lines that break PHPStan level 10`
3. **Task 3: phpmd** — no commit (verification only, exit 0)
4. **Task 4: pest test suite** — no commit (verification only, exit 0)
5. **Task 5: `make all` smoke** — no commit (verification only, exit 0)

**Plan metadata commit:** (will be added next) `docs(01-08): complete final QA gate plan summary`

## Files Created/Modified

- `models/Invoice.php` — removed 4 stray PHPDoc lines (`@method newQuery()`, `@method query()`, blank, `@mixin \Eloquent`)
- `models/InvoiceLine.php` — removed 4 stray PHPDoc lines (same pattern)
- `models/InitialResetSnapshot.php` — removed 4 stray PHPDoc lines (same pattern)
- `models/Settings.php` — removed 4 stray PHPDoc lines (`@method newQuery()`, `@mixin \System\Models\SettingModel`, `@mixin \Eloquent`)
- `.planning/phases/01-schema-scaffold-settings-permissions/deferred-items.md` — appended resolution note: QA tooling now installed, prior gap from Plan 01-02 closed
- `.planning/phases/01-schema-scaffold-settings-permissions/01-08-SUMMARY.md` — this file

## Decisions Made

- **Remove IDE-helper PHPDoc lines instead of suppressing in PHPStan baseline.** PHPStan level 10 docs explicitly say "fix the underlying cause, do not just make the error go away." The lines (`@method static \October\Rain\Database\Builder|<Model> newQuery()`, `@mixin \Eloquent`) provide IDE intellisense via `barryvdh/ide-helper` but break under strict static analysis: October's Builder is non-generic, and `\Eloquent` is a hint class that does not exist at runtime. Reference QA plugins (`postnordshippingshopaholic`, `campaignpricingshopaholic`) confirm these annotations are not used in the Lovata stack. The real `@property` docblocks remain intact — those provide the type information PHPStan and IDEs rely on.
- **No baseline regeneration permitted.** Locked rule from PROJECT.md and the plan's `<phpstan_baseline_contract>`. Verified twice (pre- and post-gate) that the file is byte-identical.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Removed 11 IDE-helper PHPDoc errors flagged by PHPStan level 10**

- **Found during:** Task 2 (`make analyse` initial run)
- **Issue:** `make analyse` exited 2 with 11 errors across `models/Invoice.php:57`, `models/InvoiceLine.php:50`, `models/InitialResetSnapshot.php:43`, `models/Settings.php:31`. Two error identifiers:
  - `generics.notGeneric` — `@method static \October\Rain\Database\Builder|<Model> newQuery()` references a non-generic class as if it were generic
  - `class.notFound` — `@mixin \Eloquent` references a hint class produced by `barryvdh/ide-helper` that doesn't exist at runtime
- **Root cause:** Plan 01-02 model scaffolds left in stray IDE-helper artifacts (likely auto-generated). Reference QA plugins do not use these annotations.
- **Fix:** Deleted the 4-line `@method`/`@mixin` blocks from each of the 4 model files. Real `@property` docblocks (used by PHPStan for type inference) preserved.
- **Files modified:** `models/Invoice.php`, `models/InvoiceLine.php`, `models/InitialResetSnapshot.php`, `models/Settings.php`
- **Verification:** Re-ran `make pint-test` (still pass), `make analyse` (now `[OK] No errors`, exit 0), `phpstan-baseline.neon` byte-identical, `make all` exit 0
- **Committed in:** `5c558dc` (separate fix commit, distinct from the SUMMARY metadata commit)

---

**Total deviations:** 1 auto-fixed (Rule 1, bug)
**Impact on plan:** Mandatory inline fix to satisfy the locked PHPStan-level-10-clean / no-baseline-additions contract. No scope creep — same set of files this plan was already scoped to verify.

## Issues Encountered

- PHPStan initial failure documented under Deviations above. Resolved by removing the offending lines (the only fix consistent with both the level-10 contract AND the locked "baseline adds nothing" rule).

## Deferred Items Update

The `deferred-items.md` entry from Plan 01-02 ("QA tooling not installed at project root") is now **RESOLVED**. The QA toolchain is fully present at `/home/forge/nailscosmetics.lv/vendor/bin/`:

- `pest` v4.4.5
- `phpstan` (with Larastan extension)
- `phpmd` v2.15.0
- `pint` v1.26+
- `rector` v2.4.0

Plan 01-08 ran the full `make all` chain end-to-end. Carry-over to Phase 02: NONE.

## ROADMAP.md Phase-1 Success Criteria Review

| # | Criterion | Verified by | Status |
|---|---|---|---|
| 1 | 3 plugin tables + `offers.active_managed_by` column | Plan 01-01 acceptance criteria (migrations) + Plan 01-02 model `@property` blocks | met |
| 2 | 4 toggles persist per-site (Multisite trait) | Plan 01-05 (Settings registration) + Plan 01-07 (`MultisiteContextSwitchClearsCacheTest` + `SettingsIsMultisiteAwareTest`) | met |
| 3 | 4 split permissions registered | Plan 01-06 (`Plugin::registerPermissions()`) | met |
| 4 | `tearDown()` flushes singletons | Plan 01-07 (`TearDownFlushesSingletonsTest`, 4 tests) | met |
| 5 | EAN `string(13)` + `invoice_number UNIQUE` | Plan 01-01 acceptance #4 (EAN) + #5 (UNIQUE constraint) | met |

All 5 phase-level criteria verified by upstream plans; Plan 01-08's role was the cumulative QA-gate confirmation.

## Working-Tree State for Orchestrator

`git status --short` after Plan 01-08 completion (before this SUMMARY commit):

```
 M .planning/STATE.md                              ← orchestrator-owned, untouched by 01-08
 M .planning/phases/01-schema-scaffold-settings-permissions/deferred-items.md
?? .planning/phases/01-schema-scaffold-settings-permissions/01-08-SUMMARY.md
```

Phase-1 production artifacts (already committed in commits `8b48227..293194f`):
- 4 migrations under `updates/`
- 4 models under `models/` (+ `models/settings/fields.yaml`)
- 4 lang directories under `lang/{en,lv,no,ru}/`
- 3 HTM fixtures + `.gitkeep` under `tests/fixtures/invoices/`
- Extended `Plugin.php` (settings + 4 permissions)
- Extended `tests/GoodsReceivedTestCase.php` with `flushPluginSingletons()` hook
- 3 test files under `tests/unit/` (12 tests, 23 assertions)
- `updates/version.yaml` 1.0.1 block

## Next Phase Readiness

- Phase 1 is GREEN — orchestrator may proceed to commit/finalize phase
- All locked engineering quality gates upheld (PSR-12, PHPStan level 10, PHPMD clean, Pest green, baseline locked)
- Phase 02 (HTM parser) can begin from a clean static-analysis baseline; any new code that fails level 10 must be fixed at source — the precedent is set

## Self-Check: PASSED

- `make pint-test` exit 0 — verified
- `make analyse` exit 0 — verified (after fix `5c558dc`)
- `make phpmd` exit 0 — verified
- `make test` exit 0, 12 tests passed — verified
- `make all` exit 0 — verified
- `phpstan-baseline.neon` 33 bytes, sha256 unchanged — verified
- `git diff phpstan-baseline.neon` empty — verified
- Fix commit `5c558dc` present in `git log --oneline` — verified
- All Phase-1 artifacts present on disk — verified

---
*Phase: 01-schema-scaffold-settings-permissions*
*Completed: 2026-04-29*
