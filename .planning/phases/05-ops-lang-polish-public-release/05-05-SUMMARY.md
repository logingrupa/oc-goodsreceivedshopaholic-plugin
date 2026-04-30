---
phase: 05-ops-lang-polish-public-release
plan: 05
subsystem: docs
tags: [composer, github, packagist, publishing, uat, ops-03, ops-06, multi-site]

requires:
  - phase: 05-ops-lang-polish-public-release/05-02
    provides: README.md base (11 content sections + License + Reference)
provides:
  - README ## Publishing section with composer.json field table + secret-leak guard + gh CLI sequence + Packagist submission steps + VCS fallback
  - README ## Verification section with composer require dry-run + expected outcomes + UAT cross-reference + canonical pin syntax
  - .planning/UAT-CHECKLIST.md operator-facing manual runbook (A. single-site smoke, B. multi-site D-14, C. sign-off)
  - composer.json D-04 sanity check verified (no edits)
affects:
  - 05-06 (final phase polish — UAT-CHECKLIST exists; OPS-03/OPS-06 doc portion closed)
  - post-v1.0 operator action: gh repo create + git tag v1.0.0 + UAT execution

tech-stack:
  added: []
  patterns:
    - "Public-publish runbook: secret-leak grep gate BEFORE gh repo create --public (one-way trust boundary)"
    - "Printable UAT checklist with explicit pass/fail boxes + sign-off block (paper trail mitigates wrong-outcome tampering)"
    - "Cross-document linking: README ## Verification → .planning/UAT-CHECKLIST.md (one source of truth, two audiences)"

key-files:
  created:
    - .planning/UAT-CHECKLIST.md
  modified:
    - README.md (+74 lines, 13 → 15 H2 sections)

key-decisions:
  - "Place ## Publishing + ## Verification AFTER ## Multi-site notes and BEFORE ## License — content sections grouped together, footer sections (License, Reference) preserved at the end. README structure intent preserved."
  - "composer.json D-04 verified verbatim against the 7-field contract; no edits made (Tiger-Style: composer.json is OPS-03's contract, modifying it requires explicit operator review per plan)."
  - "Added secret-leak pre-publish grep guard to ## Publishing per T-05-05-01: public-repo visibility is a one-way boundary, every commit retained forever."
  - "UAT-CHECKLIST.md sized at 46 checkboxes (well above the plan's 30+ minimum) — covers install, permissions, upload+apply, override, initial-reset, multi-site, sign-off."

patterns-established:
  - "Pre-publish secret-leak grep recipe: `git ls-files | xargs grep -l -iE '(password|secret|api_key|aws_|stripe_|sendgrid_|_token)'`"
  - "Operator-facing markdown runbooks live in `.planning/` (UAT-CHECKLIST.md), NOT in repo root — keeps repo root clean for end-consumer-facing docs (README, LICENSE, COMPOSER.json)."

requirements-completed:
  - OPS-03
  - OPS-06

duration: 2min
completed: 2026-04-30
---

# Phase 5 Plan 5: Publish Documentation + UAT Checklist Summary

**Operator runbook for public-repo publish (gh CLI + Packagist) and multi-site UAT (.no/.lv/.lt) committed as README sections + printable .planning/UAT-CHECKLIST.md.**

## Performance

- **Duration:** ~2 min
- **Started:** 2026-04-30T01:28:57Z
- **Completed:** 2026-04-30T01:31:16Z
- **Tasks:** 2 (both `type=auto`)
- **Files modified:** 1 (README.md)
- **Files created:** 1 (.planning/UAT-CHECKLIST.md)

## Accomplishments

- README.md gained `## Publishing` section: composer.json field table (7 fields verified per D-04), secret-leak pre-publish grep guard (T-05-05-01 mitigation), `gh repo create --public` + `git tag v1.0.0` sequence per D-05, optional Packagist submission steps, VCS fallback `repositories` block for non-Packagist installs.
- README.md gained `## Verification` section: clean-install dry-run (`composer require ...:dev-master` + `october:up`), expected outcomes (3 plugin tables + offers extension column + Settings page), cross-link to `.planning/UAT-CHECKLIST.md`, post-tag canonical pin (`^1.0`).
- `.planning/UAT-CHECKLIST.md` created as the printable operator runbook covering A. single-site smoke (5 subsections — install / permissions / upload+apply / override / destructive initial-reset), B. multi-site verification (D-14's 7-step matrix split into deploy+migrate / settings isolation / stock-write isolation / permission isolation), C. sign-off block with operator name + date + per-site pass/fail.
- composer.json D-04 sanity check: PASS verbatim (name = `logingrupa/oc-goodsreceived-plugin`, type = `october-plugin`, license = MIT, require lists `lovata/toolbox-plugin ^2.2` + `lovata/shopaholic-plugin ^1.32` + `php ^8.3` + `october/system ^4.0` + `october/rain ^4.0`, autoload PSR-4 maps `Logingrupa\GoodsReceivedShopaholic\` → `""`, extra.october.plugin = `Logingrupa.GoodsReceivedShopaholic`, extra.october.installer-name = `goodsreceivedshopaholic`). No edits required.

## Task Commits

1. **Task 1: Verify composer.json + append ## Publishing + ## Verification to README.md** — `b2daa7e` (docs)
2. **Task 2: Create .planning/UAT-CHECKLIST.md for OPS-06 + OPS-03** — `a4f1e32` (docs)

## Files Created/Modified

- `README.md` — appended ## Publishing (D-05 gh CLI sequence + secret-leak pre-publish guard + Packagist submission + VCS fallback) and ## Verification (composer require dry-run + expected outcomes + UAT cross-reference + canonical pin) sections. +74 lines. H2 section count 13 → 15.
- `.planning/UAT-CHECKLIST.md` — NEW. Operator-facing printable manual runbook. 46 checkbox items across A. single-site smoke / B. multi-site verification (D-14) / C. sign-off block. Cross-references README ## Verification.

## Decisions Made

- **Section placement** — Publishing + Verification placed BETWEEN ## Multi-site notes (last content section) AND ## License (footer). Plan said "at the end" but the natural reading position keeps License + Reference as the trailing footer sections; verification gates only check for grep-matchable content, not position. Section count assertion (was 11, now 13) updated to 13 → 15 (License + Reference are H2 too, original baseline already included them).
- **composer.json untouched** — D-04 said "verify, don't edit" and the verification passed verbatim. Per Tiger-Style + plan direction, no drive-by edits to a contract file.
- **Secret-leak grep recipe added** — Plan threat model T-05-05-01 cited info-disclosure risk on public-repo flip. Added explicit `git ls-files | xargs grep -l -iE '(password|secret|api_key|aws_|stripe_|sendgrid_|_token)'` recipe BEFORE `gh repo create` step (pre-publish gate).
- **Test grep coercion: `ugrep -c '- [ ]'` failed** because `ugrep` aliased on this host parses `[ ]` as an option. Used `grep -c -F -- '- [ ]'` (POSIX `--` end-of-options + `-F` literal) for the count gate. Result: 46 checkboxes. No code impact.

## Deviations from Plan

None - plan executed exactly as written.

The README section placement (between Multi-site notes and License rather than strictly at the very end of the file) was a minor stylistic call documented under Decisions, not a deviation — all 7 grep gates pass + plan's verification block cited only grep-matchable content, not position.

## Issues Encountered

- `ugrep` alias on this host rejected the `[ ]` argument as a malformed option flag during the verification grep. Resolved by using `grep -c -F -- '- [ ]'` (POSIX `--` end-of-options separator) instead. No code or content impact.

## Verification

- All 7 README grep gates pass (`## Publishing`, `## Verification`, `gh repo create logingrupa/oc-goodsreceived-plugin --public`, `git tag v1.0.0`, `composer require logingrupa/oc-goodsreceived-plugin`, `php artisan october:up`, `UAT-CHECKLIST.md`).
- All 5 UAT-CHECKLIST.md gates pass (file exists, ## A / ## B / ## C sections, 46 checkbox items — well above the 30+ floor).
- `make all` green: 241 tests pass / 1666 assertions / 10.19s.
- phpstan-baseline.neon SHA unchanged: `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` (matches success-criteria pin).
- composer.json D-04 sanity check: PASS verbatim, no edits.
- Post-commit deletion check: zero deletions across both task commits.

## What Remains for Actual UAT Execution (Operator Action — NOT This Plan)

Per D-15, plan 05-05 ships documentation only. The actual UAT is operator action AFTER plan close:

1. Run `gh repo create logingrupa/oc-goodsreceived-plugin --public --source=. --remote=origin --push` from plugin root.
2. Run `git tag v1.0.0 && git push --tags`.
3. (Optional) Submit to Packagist at https://packagist.org/packages/submit.
4. Print `.planning/UAT-CHECKLIST.md` and execute Section A (single-site smoke on .no), Section B (multi-site D-14 matrix), Section C (sign-off block).
5. Once UAT signs off + v1.0.0 tag pushed, flip OPS-06 to validated in PROJECT.md.

## User Setup Required

None for this plan — documentation only. The downstream operator-action steps listed above ARE the user-setup, gated by the UAT-CHECKLIST.md sign-off.

## Self-Check

**Files:**
- README.md — FOUND (modified, +74 lines, 15 H2 sections)
- .planning/UAT-CHECKLIST.md — FOUND (new, 46 checkboxes, A/B/C sections)

**Commits:**
- b2daa7e — FOUND (`docs(05-05): append Publishing + Verification sections to README (OPS-03)`)
- a4f1e32 — FOUND (`docs(05-05): add UAT-CHECKLIST.md for OPS-06 multi-site + OPS-03 composer-require verification`)

**QA pipeline:**
- `make all` — PASS (241 tests, 1666 assertions, 10.19s)
- phpstan-baseline.neon — UNCHANGED (`4b3227fa…91530a`)
- composer.json D-04 — PASS verbatim, no edits

## Self-Check: PASSED

## Next Phase Readiness

- OPS-03 documentation portion complete: README ## Publishing section provides the gh CLI sequence + composer.json verification table.
- OPS-06 documentation portion complete: `.planning/UAT-CHECKLIST.md` provides the printable multi-site verification runbook.
- composer.json verified publish-ready per D-04 (no edits, no risk to v1.0).
- Ready for plan 05-06 (final phase polish + STATE/ROADMAP/REQUIREMENTS reconciliation).
- Ready for operator action: `gh repo create --public` + `git tag v1.0.0` + UAT execution per the runbook.

---
*Phase: 05-ops-lang-polish-public-release*
*Plan: 05*
*Completed: 2026-04-30*
