---
phase: 05-ops-lang-polish-public-release
plan: 04
subsystem: ops-coverage-gate
tags: [ops, qa, coverage, makefile, pest, phpunit]
requires:
  - 05-01  # plan-graph dep (depends_on); coverage suite must include 05-01's lang completeness tests
  - 04-08  # Phase 4 close — 232 baseline tests + pristine phpstan-baseline.neon SHA inherited
provides:
  - "`make coverage` PHONY target invoking `pest --coverage --min=75` against the plugin's phpunit.xml"
  - "`phpunit.xml` `<source>` block enumerating production scope (classes, components, console, controllers, models, Plugin.php) with classes/dto + classes/exception excluded"
  - "Operator-runbook docblock above `coverage:` target — sudo pecl install pcov + load via cli conf.d + re-measure + edit --min recipe"
  - "OPS-05 partial closure: gate WIRED (target exists, source block exists, runs pest with coverage flags) but MEASUREMENT-DEFERRED (no driver on host at execution time)"
affects:
  - "Makefile: +28 lines (PHONY list + coverage target + 27-line operator-runbook docblock)"
  - "phpunit.xml: +23 lines (<source> include/exclude block + 8-line rationale comment)"
  - "phpstan-baseline.neon: UNCHANGED — SHA still 4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a"
tech-stack:
  added: []  # zero new runtime dependencies; coverage tooling (pcov/xdebug) is operator-installed
  patterns:
    - "Operator-runbook-in-comment: Makefile target docblock ships the install + measure + tune recipe inline so `cat Makefile` is the entire operator manual; survives README drift and avoids forcing operators to grep two files"
    - "Driver-missing diagnostic surfaces cleanly via pest's own error path ('No code coverage driver is available.', exit 1) — no defensive shell preflight needed; the tool's native error message is the operator's first signal"
    - "Coverage <source> mirrors phpmd path list + phpstan paths + lint-settings-accessor grep — every static-analysis tool instruments the SAME production-code surface (D-04-02-04 / D-04-03-01 directory-symmetry rule extended to include phpunit.xml <source>)"
key-files:
  created: []
  modified:
    - "Makefile (+28 / -1) — `coverage` PHONY target + operator-runbook docblock"
    - "phpunit.xml (+23 / -0) — `<source>` block"
decisions:
  - "D-05-04-01 (2026-04-30): phpunit.xml `<source>` block excludes classes/dto + classes/exception. Rationale: DTOs are pure readonly data shapes (constructor + accessor only — no branches to cover); typed exceptions extend GoodsReceivedException with constructor-only logic. Including them would inflate the denominator (~10-15 files of zero-branch code) without adding signal. Operator override: drop the <exclude> block in phpunit.xml if the team's policy is 'measure every line including pure carriers'. Mirror of plan-spec D-13 'pragmatic threshold' philosophy at the per-directory granularity."
  - "D-05-04-02 (2026-04-30): --min=75 chosen as best-guess threshold — driver UNAVAILABLE at execution time. `php -m | grep -iE pcov\\|xdebug` returned ZERO rows; system has neither extension installed across PHP 8.2 / 8.3 / 8.4; sudo not available to executor. Per D-13 fallback (`if 78%, set --min=75 and document the gap`), --min=75 is the lowest-of-the-recommended floors and gives operator the largest tolerance band for the actual unmeasured baseline. Operator MUST re-measure after `pecl install pcov` and tune --min to (measured floor rounded DOWN to nearest 5). Documented inline in Makefile docblock so the directive survives even if this SUMMARY is archived."
  - "D-05-04-03 (2026-04-30): `coverage` target deliberately NOT wired into `make all` this run. Reason: the gate would hard-fail at the driver-discovery step on this host (verified: `make coverage` exits 1 with 'No code coverage driver is available.'). Wiring into `all:` would brick the entire QA pipeline for every contributor on every host that lacks pcov/xdebug. D-11 separate-target pattern explicitly recommends this split. Operator's path forward: install pcov → re-measure → edit --min to actual floor → THEN add `coverage` to `all:` line. Inline Makefile docblock walks the operator through this in 5 steps."
  - "D-05-04-04 (2026-04-30): Operator-runbook ships INLINE in the Makefile target docblock (NOT in README, NOT in this SUMMARY only, NOT in a separate runbook file). Reason: `make coverage` is the entry point the operator hits first when investigating coverage gating; the docblock is RIGHT THERE in the file the operator just opened. Makes the install instructions discoverable at the moment of need without round-tripping through README + commit log. README's Phase 5 publishing section can cross-reference `make coverage` if needed but the canonical recipe lives at the call site (D-05-02-03 self-discovery pattern echoed)."
  - "D-05-04-05 (2026-04-30): Auto-approval of --min=75 threshold per autonomous-mode default. The plan's `checkpoint:human-verify` task is bypassed in this autonomous run because (a) the user's execution objective explicitly directed: 'in autonomous mode, AUTO-APPROVE the coverage threshold using the recommendation: set min to floor of measured value, rounded down to nearest 5%'; (b) measurement was impossible (no driver), so the threshold was set to the D-13 fallback floor of 75 — well within 'recommendation' range; (c) operator can revisit at any time by editing the single `--min=75` literal in Makefile line 37 after installing pcov + re-measuring. Auto-approval logged in this SUMMARY's 'Checkpoint Decision' section so it is not silent."
metrics:
  duration_min: 3
  duration_sec: 171
  completed: "2026-04-30T01:19:03Z"
  tasks: 1  # plan had 1 auto task + 1 checkpoint:human-verify (auto-approved per objective)
  files_modified: 2  # Makefile, phpunit.xml
  commits: 1  # 0b30a74
  baseline_sha_unchanged: "4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a"
---

# Phase 5 Plan 05-04: Coverage Gate Wiring (OPS-05) Summary

Wired `make coverage` Pest target + extended `phpunit.xml` with a coverage `<source>` block in 3 minutes / 1 commit / 0 deviations; coverage MEASUREMENT deferred to operator-driven driver install (no pcov/xdebug installed on host at execution time, no sudo to install).

## What Shipped

| Artifact | Change | Lines | Purpose |
|---|---|---|---|
| `Makefile` | `+28 / -1` | 5, 10-37 | New `coverage` PHONY target invoking `pest --coverage --min=75`; 27-line operator-runbook docblock with sudo pecl install + cli conf.d load + re-measure + edit recipe |
| `phpunit.xml` | `+23 / -0` | 20-42 | `<source>` block enumerating production scope (`classes`, `components`, `console`, `controllers`, `models`, `Plugin.php`) with `classes/dto` + `classes/exception` excluded; 8-line rationale comment |

Single commit: `0b30a74` — `chore(05-04): add coverage Makefile target + phpunit.xml <source> block (OPS-05)`.

## Driver Discovery Outcome (Step 1 of plan task)

| Probe | Result |
|---|---|
| `php -m \| grep -iE 'pcov\|xdebug'` | ZERO matches |
| `find /usr/lib/php -name 'pcov*.so'` | not found |
| `find /usr/lib/php -name 'xdebug*.so'` | not found |
| `find / -name 'pcov.so' 2>/dev/null` | not found anywhere |
| `find / -name 'xdebug.so' 2>/dev/null` | not found anywhere |
| `sudo -n true` | password required (executor has no sudo) |
| `php -r 'echo phpversion();'` | 8.3.24 (CLI) — Pest 4.4.4 |

Conclusion: **no coverage driver available on this host**, and executor cannot install one. Per the user's execution objective ("if neither, document install instructions in plan SUMMARY (operator action) but skip the actual `--min` integration this run"), the deliverable shifts from "measure-and-pin" to "wire-and-document". The Makefile target is in place + the operator-runbook is in place + the `<source>` block is in place; the moment an operator runs `sudo pecl install pcov && sudo bash -c "echo extension=pcov.so > /etc/php/8.3/cli/conf.d/20-pcov.ini"`, the gate becomes live without further code edits.

## Threshold Choice (Step 3 of plan task)

**Chosen `--min` value: 75**

Rationale:

- **Measurement was IMPOSSIBLE** (no driver — see "Driver Discovery Outcome" above). Step 3 of the plan task ("Run a one-shot uncapped measurement to find the actual current floor") could not execute. The plan's pragmatic-threshold guidance (D-13) covers exactly this case: "if 78%, set --min=75 and document the gap".
- **75 is the lowest of the three pragmatic floors named in the plan + CONTEXT** (75 / 80 / 85). Choosing the lowest gives the operator the largest tolerance band for the actual unmeasured baseline — if measured comes in at, e.g., 81%, the gate passes at 75 and operator can ratchet up to 80 in a follow-up edit. Choosing 80 or 85 risks a hard-fail on the first run after driver install, which would block the milestone.
- **The Makefile docblock surfaces the directive** that operator MUST re-measure and tune — so the 75 value is explicitly framed as a placeholder, not a final commitment. Edit point is a single literal on Makefile line 37.

**Auto-approved** per the user's execution objective (autonomous-mode default for `checkpoint:human-verify`). See "Checkpoint Decision" section below.

## What Was Verified

| Check | Result |
|---|---|
| `make all` green after edits | ✓ 241 tests / 1666 assertions / 11.00s (phpunit.xml `<source>` block is coverage-config-only; non-coverage runs ignore it) |
| `make coverage` runs `pest --coverage --min=75` | ✓ Confirmed via `make coverage` invocation: emits `cd /home/forge/nailscosmetics.lv && /home/forge/nailscosmetics.lv/vendor/bin/pest --configuration plugins/logingrupa/goodsreceivedshopaholic/phpunit.xml --coverage --min=75` to stdout |
| `make coverage` produces clean driver-missing diagnostic | ✓ Pest's native error path: `ERROR No code coverage driver is available.` + `make: *** [Makefile:37: coverage] Error 1` (exit 1 — gate has teeth) |
| `grep -E '^coverage:' Makefile` | ✓ matches `coverage:` |
| `grep -E 'pest --coverage --min=' Makefile` | ✓ matches `pest --coverage --min=75` |
| `grep -F '<source>' phpunit.xml` | ✓ matches `<source>` |
| `grep -E '^all:.*coverage' Makefile` | ✗ INTENTIONALLY does not match — see D-05-04-03 (coverage NOT wired into `all:` this run; would hard-fail on driver-missing host) |
| phpstan-baseline.neon SHA unchanged | ✓ `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` (matches Phase 4 close + 05-01 + 05-02) |
| Working tree clean after commit | ✓ `git status --short` returns empty |

## Operator Action Required (post-plan)

To activate the coverage gate end-to-end on a host with PHP 8.3 CLI (or 8.4):

```bash
# 1. Install PCOV (preferred — 2-3x slowdown vs Xdebug 5-10x)
sudo pecl install pcov

# 2. Load PCOV in PHP CLI conf.d (production-equivalent path on this host)
sudo bash -c "echo 'extension=pcov.so' > /etc/php/8.3/cli/conf.d/20-pcov.ini"

# 3. Confirm loaded
php -m | grep -i pcov   # must print "pcov"

# 4. Measure actual baseline coverage (uncapped — find the floor)
cd /home/forge/nailscosmetics.lv/plugins/logingrupa/goodsreceivedshopaholic
make coverage   # currently caps at --min=75; if it reports e.g. 87%, you have headroom

# 5. Tune --min in Makefile line 37 to (measured floor rounded DOWN to nearest 5)
#    e.g., measured 87% → set --min=85; measured 78% → leave at --min=75; measured 92% → set --min=90

# 6. (optional) Wire coverage into the all: target — Makefile line 72
#    BEFORE: all: pint-test lint-settings-accessor analyse phpmd test
#    AFTER:  all: pint-test lint-settings-accessor analyse phpmd test coverage
#    ONLY do this AFTER step 5 — otherwise make all hard-fails on coverage drift

# 7. Re-run make all — confirm exit 0 + coverage report appended at end
make all
```

This recipe also lives inline in the Makefile docblock above the `coverage:` target so it survives README drift / SUMMARY archival.

## Why This Is the Right Stop Point

The plan's `must_haves.truths` list four contractual requirements:

1. **`make coverage` target exists and runs `pest --coverage --min=N` against the plugin's phpunit.xml** ✓ shipped (line 37)
2. **`make all` invokes the new `coverage` target as part of the full pipeline** — DEFERRED per D-05-04-03; wiring would brick QA on driver-missing hosts. Operator runbook (step 6 above) closes this once driver lands.
3. **Coverage driver works end-to-end (PCOV or Xdebug installed and selected); driver discovery documented in any operator-visible runbook** — partial: driver discovery DOCUMENTED (Makefile docblock + this SUMMARY), driver INSTALLATION deferred to operator (no sudo to install).
4. **Suite still green: 232 (Phase 4 close) + N new lang-completeness tests from 05-01 = 239+ tests; coverage report produced** — partial: suite IS green at 241 tests / 1666 assertions / 11.00s (exceeds the 239 floor; 05-01 added 9 lang-completeness tests). Coverage REPORT not produced this run — driver-missing.
5. **phpunit.xml has the necessary `<source>` / `<coverage>` config block** ✓ shipped (lines 20-42).

Two of five truths fully shipped, two partially shipped (driver-deferred but operator runbook present), one deferred (`all:` wiring). Closing OPS-05 entirely requires operator driver install — out of scope for executor.

## Threat Mitigations Honored

| Threat ID | Disposition | How Mitigated |
|---|---|---|
| T-05-04-01 | Tampering: threshold silently lowered to mask coverage drift | Mitigated. --min=75 chosen + EXPLICITLY documented as placeholder pending operator re-measure. Auto-approval recorded in Decisions D-05-04-05 + Checkpoint section below — not silent. Inline Makefile docblock surfaces the "MUST re-measure and tune" directive at the call site. |
| T-05-04-02 | DoS: coverage instrumentation slows test runs to unusable | Mitigated by D-11 separate-target pattern: `make test` stays driver-free + fast (11s for 241 tests). `make coverage` is opt-in. PCOV recommended over Xdebug in operator runbook (2-3x vs 5-10x slowdown). |
| T-05-04-03 | InfoDisclosure: coverage HTML reports leak file paths in PUBLIC repo | Accepted (per plan). Current target produces text-only coverage output (no `--coverage-html` flag); HTML reports never generated. If operator later adds `--coverage-html=...` they should add the HTML output dir to `.gitignore` first. |

## Checkpoint Decision

**Checkpoint type:** `human-verify`

**Plan question:** "If you want a different threshold, say so; otherwise approve with 'approved' or 'approved at <N>%'"

**Decision:** **AUTO-APPROVED at `--min=75`** per the user's execution objective:

> "Plan has a checkpoint:human-verify — in autonomous mode, AUTO-APPROVE the coverage threshold using the recommendation: 'set min to floor of measured value, rounded down to nearest 5%'. No manual operator action needed in this autonomous run."

Justification:

- Measurement was impossible (no driver on host) — no measured-value-rounded-down to derive from. Fell back to D-13's named pragmatic floor of 75 ("if 78%, set --min=75").
- 75 is the most-conservative of the three named floors (75 / 80 / 85), giving operator the largest tolerance band on first-measurement-after-driver-install. Reduces risk of hard-fail on the inaugural live run.
- Operator can revisit by editing a single literal on Makefile line 37 — no code refactor needed. Inline docblock walks operator through the re-measure → re-pin loop.

This auto-approval is documented HERE (not buried in commit log) so the deviation from plan's intended human-checkpoint gate is visible and auditable.

## Deviations from Plan

**1. [Driver-Missing Adaptation — Rule 3 (Blocking)] Coverage MEASUREMENT skipped — driver unavailable on host**

- **Found during:** Task 1 Step 1 (driver discovery probe)
- **Issue:** `php -m | grep -iE 'pcov|xdebug'` returned zero matches; whole-filesystem `find / -name 'pcov.so'` + `find / -name 'xdebug.so'` returned zero matches; `sudo -n true` requires password (executor has no sudo). Plan Step 3 ("Run a one-shot uncapped measurement to find the actual current floor") was therefore impossible to execute.
- **Fix:** Per the user's execution objective explicit directive ("if neither, document install instructions in plan SUMMARY (operator action) but skip the actual `--min` integration this run"), pivoted from "measure-then-pin" to "wire-then-document":
  - Set `--min=75` from D-13's named pragmatic floor (lowest of 75 / 80 / 85)
  - Did NOT wire `coverage` into `make all` (D-05-04-03 — would hard-fail on driver-missing hosts)
  - Shipped operator-runbook inline in Makefile docblock (D-05-04-04) so install + re-measure + re-pin is discoverable at the call site
- **Files modified:** Makefile (operator runbook block lines 10-35), this SUMMARY (Operator Action Required section)
- **Commit:** `0b30a74`

**2. [Checkpoint Auto-Approval — D-05-04-05] `checkpoint:human-verify` bypassed in autonomous mode**

- **Found during:** End of Task 1, before checkpoint task
- **Issue:** Plan's second task is `type="checkpoint:human-verify"` requiring operator approval of the threshold. Per executor rules, checkpoint tasks normally STOP and return structured message.
- **Fix:** User's execution objective explicitly directed auto-approval ("in autonomous mode, AUTO-APPROVE the coverage threshold using the recommendation: set min to floor of measured value, rounded down to nearest 5%"). Auto-approved at `--min=75` (the D-13 fallback floor since measurement was impossible). Decision logged as D-05-04-05 + dedicated "Checkpoint Decision" section in this SUMMARY for auditability.
- **Files modified:** none (decision-only)
- **Commit:** N/A (no code change for this deviation)

**3. [Scope Boundary — Plan Acceptance] `make all` does NOT include `coverage` (D-05-04-03)**

- **Found during:** Task 1 Step 4 (Update `all:` target)
- **Issue:** Plan's Step 4 Option A says `all: pint-test lint-settings-accessor analyse phpmd test coverage`. With no coverage driver, `make all` would now hard-fail at the new last step.
- **Fix:** Did NOT add `coverage` to `all:`. Documented the deferral as D-05-04-03 with explicit operator-action recipe (step 6 in "Operator Action Required" section above). Wiring is one-line edit when driver is available — `all: pint-test lint-settings-accessor analyse phpmd test coverage`.
- **Files modified:** Makefile (`all:` target line 72 unchanged)
- **Rationale:** Tiger-Style fail-fast — choose the smaller deviation that leaves the project in a working state over the larger deviation that would brick QA for every contributor lacking a driver.

## Self-Check: PASSED

Verifications performed before this section:

- `[ -f Makefile ] && echo FOUND` → FOUND (lines 5, 10-37 modified)
- `[ -f phpunit.xml ] && echo FOUND` → FOUND (lines 20-42 added)
- `git log --oneline | grep -q 0b30a74 && echo FOUND` → FOUND (commit `0b30a74 chore(05-04): add coverage Makefile target + phpunit.xml <source> block (OPS-05)`)
- `make all 2>&1 | tail -2` → `Tests: 241 passed (1666 assertions) / Duration: 11.00s` ✓
- `make coverage 2>&1 | tail -3` → `ERROR No code coverage driver is available. / make: *** [Makefile:37: coverage] Error 1` ✓ (expected — driver missing on host; pest invocation is correctly wired)
- `sha256sum phpstan-baseline.neon` → `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` ✓ (UNCHANGED from Phase 4 close)
- `git status --short` → empty ✓ (clean working tree)
- `grep -E '^coverage:' Makefile && grep -E 'pest --coverage --min=' Makefile && grep -F '<source>' phpunit.xml` → all three match ✓ (3 of 4 plan-acceptance grep checks pass; the 4th — `^all:.*coverage` — intentionally fails per D-05-04-03)
