---
phase: 05-ops-lang-polish-public-release
plan: 02
subsystem: docs
tags: [readme, runbook, operator-docs, ops, markdown]

# Dependency graph
requires:
  - phase: 01-schema-scaffold-settings-permissions
    provides: 4 permissions registered in Plugin.php; 4 Settings toggles in models/settings/fields.yaml
  - phase: 03-apply-layer-orchestrators
    provides: ImportAuditService Log::* event keys, InitialResetService snapshot semantics, ParseAndPersistOrchestrator override-and-reimport flow
  - phase: 04-backend-controller-upload-preview-apply-ui-console
    provides: Upload/Preview/Apply UX, RecomputeActiveFromStock console command, typed-OVERRIDE/RESET confirmation gates, multi-file upload limits
provides:
  - README.md operator-facing runbook (383 lines, 13 H2 sections)
  - Documented disable-1C-XML-quantity-import procedure (D-13 out-of-band step)
  - Documented initial-reset pre-flight checklist + snapshot rollback recipe
  - Documented Log::* event key map + correlation_id grep recipe
  - Documented multi-site (per-site DB, per-site Settings, per-site reset) isolation
affects:
  - plan 05-05 (will append ## Publishing + ## Verification + UAT-CHECKLIST.md)
  - plan 05-06 (final QA gate references README as the canonical operator artifact)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Operator-facing README at plugin root, GitHub-renderable plain markdown"
    - "Tables-over-prose for settings/permissions/log-keys/error-scenarios"
    - "Verbatim-quoted warning copy for typed-confirmation gates (D-12 OVERRIDE, initial-reset RESET)"

key-files:
  created:
    - "README.md"
  modified: []

key-decisions:
  - "D-05-02-01 (2026-04-30): README structure follows D-01's 11 sections exactly + adds Table of Contents + License + Reference (13 H2 total). Section count >= 10 per plan acceptance; ToC is navigation-only, License + Reference are publish-readiness boilerplate that don't add new content."
  - "D-05-02-02 (2026-04-30): Forbidden-token grep added to verification (`.env`, hostnames, DB creds). T-05-02-01 mitigation — README that accidentally references `.env DB_PASSWORD=...` would leak prod infra. Explicit grep rejects forbidden tokens; current README passes."
  - "D-05-02-03 (2026-04-30): D-13 1C XML disable instruction includes a `grep` recipe (`grep -RnE 'quantity\\\\s*=' plugins/logingrupa/extendshopaholic/classes/import/`) instead of a single fixed file path. ExtendShopaholic's settings location may differ between releases; the grep recipe is a self-discovery instruction that survives ExtendShopaholic upstream renames."
  - "D-05-02-04 (2026-04-30): Snapshot rollback recipe documented as MANUAL DB operation (no automated CLI in v1). The plan acknowledged this trade-off; v2 backlog item `php artisan goodsreceived:rollback_initial_reset --invoice=<id>` captured inline. Operators warned to take a DB backup BEFORE rolling back (rollback itself is a destructive secondary operation)."
  - "D-05-02-05 (2026-04-30): Markdown tables used for: 4 settings, 4 permissions, 5 Log::* event keys, 4 multi-site rows, console-command auto-flag truth-table, common-error-scenarios first-line-debug. Plan rationale: 'operators read tables faster than prose'. ~30% of file is tabular; satisfies the plan's table-liberal recommendation."

patterns-established:
  - "Pattern: D-anchored cross-reference in section headings (e.g. '## Override and re-import (D12 — add-on-top semantics)'). Future README sections that surface a locked decision should anchor in the heading so operators searching for 'D-12' or 'D-13' land directly on the right section."
  - "Pattern: Permission key + setting key documented verbatim (no abbreviation, no rename in docs). Operators copy/paste-ready; matches the actual values in Plugin.php / fields.yaml."
  - "Pattern: Log::* event keys documented in the same table format the rest of the plugin's troubleshooting will use. Future plugins in the Logingrupa namespace should mirror this 4-column shape (Event key | Level | When fired | Context fields | grep recipe)."

requirements-completed: [OPS-01]

# Metrics
duration: 5min
completed: 2026-04-30
---

# Phase 5 Plan 02: README Operator Runbook Summary

**383-line operator-facing README at the plugin root covering installation, 4 settings, 4 split permissions, upload/preview/apply workflow, D-12 override-and-reimport add-on-top semantics, one-shot initial-reset runbook with snapshot rollback recipe, D-13 disable-1C-XML-quantity-import out-of-band step, console command, 5 Log::* event keys with correlation_id grep recipe, and multi-site (per-site DB, per-site Settings) isolation notes.**

## Performance

- **Duration:** ~5 min
- **Started:** 2026-04-30T01:01:04Z
- **Completed:** 2026-04-30T01:05:25Z
- **Tasks:** 1 / 1
- **Files modified:** 1 created (README.md), 0 changed

## Accomplishments

- Plugin root now has the canonical operator runbook — first plain-English entry point for anyone outside the GSD planning loop.
- All 14 must-have strings verified present (4 permission keys, 5 Log::* event keys, OVERRIDE + RESET typed-confirmation literals, ExtendShopaholic D-13 reference, console command name, snapshot table name).
- `make all` still green: 241/241 tests, 1666 assertions, 9.66s — README is non-code so no QA tool touches it, but the gate was re-run to prove no accidental file change broke the suite.
- `phpstan-baseline.neon` SHA UNCHANGED at `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a` — Phase 5 still introduces zero new L10 baseline-suppressed errors.
- 13 H2 sections (>= 10 per acceptance), 383 lines (within 250-400 target band), zero forbidden tokens (`.env` / hostnames / DB credentials).
- D-12 add-on-top warning copy quoted **verbatim** as the plan required.

## Task Commits

1. **Task 1: Write README.md sections 1-11 per D-01** — `eeddc03` (docs)

**Plan metadata:** appended to this commit per D-04 of execute-plan workflow (single-task plan, no separate metadata commit needed; STATE.md / ROADMAP.md / REQUIREMENTS.md updates ship in a follow-up docs commit per the standard final-commit step).

## Files Created/Modified

- `README.md` — 383-line operator-facing runbook. 13 H2 sections (Table of Contents, Installation, Configuration — Settings, Permissions, Operator workflow (Upload → Preview → Apply), Override and re-import (D12 — add-on-top semantics), Initial-reset runbook (one-shot baseline), GRN-canonical stock writer — disabling 1C XML quantity import (D13), Console command — recompute active flags from stock, Troubleshooting — `Log::*` event key map, Multi-site notes, License, Reference).

## REQ-ID Cross-References

| Doc section | REQ-ID(s) sourced from | Plan phase that closed the underlying contract |
|-------------|------------------------|------------------------------------------------|
| Installation | SCHEMA-01..04 (3 plugin tables + offers.active_managed_by column) | Phase 1 (plans 01-02..01-05) |
| Configuration — Settings | SCHEMA-07 (4 Settings toggles + per-site Multisite) | Phase 1 (plan 01-06) |
| Permissions | SCHEMA-08 / UI-01 / QA-10 (4 split permissions + per-handler enforcement + dedicated gate tests) | Phase 1 (plan 01-07) + Phase 4 (plans 04-04..04-07) |
| Operator workflow | UI-02..06 (multi-file upload + preview + apply + Cache::lock debounce + lang-keyed copy) | Phase 4 (plans 04-04 + 04-05) |
| Override and re-import (D12) | UI-10 / APPLY-06 (typed-OVERRIDE gate + override_of_invoice_id + -OVR-N suffix + add-on-top apply) | Phase 3 (plan 03-06) + Phase 4 (plan 04-06) |
| Initial-reset runbook | UI-08 / APPLY-05 (typed-RESET gate + snapshot-before-write + parse → reset → apply triad) | Phase 3 (plan 03-05) + Phase 4 (plan 04-06) |
| GRN-canonical stock writer (D13) | (cross-plugin invariant — explicit out-of-band step) | (no plugin code change; documentation-only enforcement) |
| Console command | UI-11 (`goodsreceived:recompute_active_from_stock --chunk=500` + ActiveFlagService::reconcileAll + provenance skip) | Phase 4 (plan 04-02) |
| Troubleshooting (Log::*) | APPLY-10 (ImportAuditService 5-event surface + correlation_id) + UI-12 (Plugin boot self-check) | Phase 3 (plan 03-02) + Phase 4 (plan 04-01) |
| Multi-site notes | OPS-06 (.no/.lv/.lt deployment isolation; verified manually per UAT) | Phase 5 (this README + UAT in 05-05) |

OPS-01 fully closed for sections 1-11 of D-01. Plan 05-05 will append two operational sections to the same README (`## Publishing` for OPS-03 Composer publish + `## Verification` for OPS-06 manual UAT checklist link).

## Decisions Made

See the 5 D-05-02-* entries in the frontmatter `key-decisions` field above. Brief rationale recap:

- **D-05-02-01:** 13 H2 sections shipped (>= 10 acceptance) — ToC + License + Reference are navigation/publish-readiness boilerplate, not new content above D-01's 11.
- **D-05-02-02:** Forbidden-token grep (`.env` / hostnames / creds) added to verify step (T-05-02-01 mitigation).
- **D-05-02-03:** D-13 disable instruction uses `grep` self-discovery recipe instead of a fixed file path (survives ExtendShopaholic upstream renames).
- **D-05-02-04:** Snapshot rollback documented as manual DB operation; v2 backlog item for automated rollback CLI captured inline.
- **D-05-02-05:** Markdown tables used liberally (~30% of file) per plan's table-over-prose guidance.

## Deviations from Plan

None — plan executed exactly as written. The optional 12th section was deliberately NOT added per D-01's "do NOT add a 12th, do NOT consolidate" guidance. License + Reference + Table of Contents are non-content navigation/publish-readiness scaffolding (not new D-01 sections).

## Issues Encountered

None. README content cleanly assembled from the plan's `<interfaces>` block, the 4 referenced source files (Plugin.php, fields.yaml, RecomputeActiveFromStock.php, ImportAuditService.php), and the locked decision history (D-12, D-13, D-21..D-26).

## Self-Check

**Files claimed:** `README.md` (383 lines) — present at plugin root.
**Commits claimed:** `eeddc03` — present in `git log`.
**Plan acceptance:** 14/14 must-have strings present, 13/10+ H2 sections, 0 forbidden tokens, 0 emoji, line count 383 within 250-400 band.
**`make all`:** 241/241 tests green in 9.66s.
**`phpstan-baseline.neon` SHA:** UNCHANGED at `4b3227fab5b697264e8532b59f5cdd96c86a0ff1fa484cc1a869af36ae91530a`.

## Self-Check: PASSED

## What plan 05-05 still needs to add to this README

Per D-01's section-ordering rationale and the plan output spec, two more sections will be appended to this README in plan 05-05 (OPS-03 + OPS-06 — they share the README artifact rather than spawning new doc files):

1. **`## Publishing`** — operator/devops-facing Composer publish steps (gh repo create --public, git tag v1.0.0, optional Packagist submission). Per OPS-03 / D-04..D-06.
2. **`## Verification`** — link to the multi-site UAT checklist (a separate `UAT-CHECKLIST.md` file ships in 05-05). Per OPS-06 / D-14..D-15.

When 05-05 ships, the README will reach ~440-480 lines and 15 H2 sections.

## Next Phase Readiness

- README is publish-grade for sections 1-11. Anyone who runs `composer require logingrupa/oc-goodsreceived-plugin` today gets a usable runbook.
- Phase 5 progress: 2/6 plans complete (05-01 lang populate + 05-02 README runbook). 4 plans remain (05-03 PROJECT.md update, 05-04 coverage gate, 05-05 publishing + UAT, 05-06 final QA gate).
- v1.0 milestone now at 34/38 plans = 89%.
- No blockers. Phase 5 plan 05-03 (PROJECT.md update — OPS-02) is the next-cheapest plan in the remaining set.

---
*Phase: 05-ops-lang-polish-public-release*
*Completed: 2026-04-30*
