---
phase: 05-ops-lang-polish-public-release
plan: 03
subsystem: project-doc
tags: [docs, ops-02, milestone-v1, key-decisions]
requires:
  - REQUIREMENTS.md ## Traceability (source-of-truth for REQ status)
  - 05-CONTEXT.md (D-02, D-03 — locked decisions to surface in PROJECT.md)
provides:
  - PROJECT.md ## Key Decisions D11-D15 rows (locked, validated 2026-04-29)
  - PROJECT.md ## Requirements ### Validated section populated with 50 closed v1 reqs grouped by phase
  - PROJECT.md ## Requirements ### Active section honestly lists the 6 still-open v1 reqs with rationale
affects: []
tech-stack:
  added: []
  patterns:
    - "no-silent-flip rule: REQ status in PROJECT.md must mirror REQUIREMENTS.md ## Traceability — never mass-validate Pending reqs (T-05-03-01 mitigation)"
    - "every D-row carries a date AND a phase/plan reference where the decision was implemented (T-05-03-02 mitigation for repudiation by future engineers)"
key-files:
  created:
    - .planning/phases/05-ops-lang-polish-public-release/05-03-SUMMARY.md
  modified:
    - .planning/PROJECT.md
decisions:
  - "Honored T-05-03-01 mitigation: did NOT mass-flip Pending REQs to Validated. QA-07 and QA-11 remain Active with explicit '(REQUIREMENTS.md still shows Pending; needs Phase 1 plan 01-07 follow-up)' annotation. OPS-03/05/06 remain Active with explicit '(scheduled Phase 5 plan 05-NN)' annotation. OPS-02 (this plan) listed as 'closing via THIS plan; flips to Validated upon plan 05-03 commit + verifier sign-off' — leaves the actual flip to the verifier or plan 05-06 wrap-up rather than self-marking as Validated mid-execution."
  - "Validated count is 50 (not 56 as the plan's must-have truth #2 stated). Reason: must-have truths assumed all v1 reqs were closed at 05-03 write-time, but REQUIREMENTS.md traceability rows for QA-07/QA-11/OPS-03/05/06 are still Pending. Marking 50 (truthful) over 56 (silent-flip) preserves engineering integrity. The plan's <interfaces> section explicitly anticipated this divergence with a 'reality check at write time' instruction — that path was followed."
  - "Used a phase-grouped + bold-headed checklist format under ### Validated rather than a flat alphabetical list. Reason: REQUIREMENTS.md groups reqs by phase; PROJECT.md must mirror that grouping for navigability (must-have truth #5)."
metrics:
  duration: ~10min
  completed: 2026-04-30
  tasks-executed: 1/1
  commits: 1
---

# Phase 5 Plan 03: PROJECT.md Lock D11-D15 + Mark v1 Reqs Validated Summary

PROJECT.md updated: 5 new Key Decision rows (D11-D15) appended with `Locked, validated 2026-04-29` outcome strings; 50 of 56 v1 requirements moved from Active → Validated grouped by phase; placeholder `(None yet — ship to validate)` removed; remaining 6 v1 reqs (4 OPS + 2 QA) honestly enumerated under Active with phase/plan references for closure.

## What Shipped

### Key Decisions table — appended D11-D15

Five new rows after D10 in `## Key Decisions`. Each carries the verbatim decision text + rationale + outcome from the plan's `<interfaces>` block:

| ID  | Decision | Outcome string |
|-----|----------|----------------|
| D11 | GitHub repo: PUBLIC | Locked, validated 2026-04-29 |
| D12 | Override-and-reimport = ADD-ON-TOP | Locked, validated 2026-04-29; shipped Phase 3 plans 03-06 + 03-07 + Phase 4 plan 04-06 |
| D13 | GRN owns offer.quantity; user disables 1C XML qty out-of-band | Locked, validated 2026-04-29; documented in README via plan 05-02 |
| D14 | Vendor-inline ImportAuditService (~50-80 LoC) | Locked, validated 2026-04-29; shipped Phase 3 plan 03-02 (96 raw / 65 code lines within ≤100 LoC ceiling) |
| D15 | Settings extends SettingModel direct + manual MultisiteInterface + MultisiteHelperTrait | Locked, validated 2026-04-29; shipped Phase 1 plan 01-05 |

### Requirements section — Active → Validated migration

- `### Validated` previously: `(None yet — ship to validate)` placeholder
- `### Validated` now: 50 v1 reqs as `[x]` checklist items, grouped under 5 phase headers (Phase 1: 8 SCHEMA, Phase 2: 7 PARSE + 2 MATCH + 2 QA, Phase 3: 10 APPLY + 6 QA, Phase 4: 12 UI + 1 QA, Phase 5: OPS-01 + OPS-04). Each carries a brief description.
- `### Active` previously: 15 placeholder `[ ]` items from project scaffold (e.g., "Backend upload page accepts...")
- `### Active` now: 6 honestly-still-open v1 reqs (OPS-02, OPS-03, OPS-05, OPS-06, QA-07, QA-11) with per-req closure path noted (plan 05-03..05-06 + Phase 1 plan 01-07 follow-up).

### Footer timestamp updated

Added milestone v1.0 close pass note dated 2026-04-30; preserved 2026-04-29 scaffold note as "Previously updated".

## Verification Results

Automated verify command from plan ran clean:

```
=== D-rows D11-D15 count === 5
=== "Locked, validated 2026-04-29" strings === 5
=== Validated section header === ### Validated
=== SCHEMA-[x] count (expected 8) === 8
=== PARSE-[x] count (expected 7) === 7
=== MATCH-[x] count (expected 2) === 2
=== APPLY-[x] count (expected 10) === 10
=== UI-[x] count (expected 12) === 12
=== OPS-[x] count (expected 2) === 2
=== QA-[x] count (expected 9) === 9
=== Active section [ ] count (expected 6) === 6
=== "(None yet — ship to validate)" placeholder === REMOVED
```

Validated total = 8 + 7 + 2 + 10 + 12 + 2 + 9 = **50**. Active total = **6**. Sum = **56** (= REQUIREMENTS.md `## Traceability` total). Conservation of REQs holds — no double-counting, no silent skips.

## Deviations from Plan

### Plan must-have truth #2 partially deviated (truth-over-spec)

**1. [Rule 1 — Bug] Plan must-have stated "Validated section lists ALL 53 v1 requirements"; actual count is 50, not 53/56**

- **Found during:** Task 1 reality check (plan's `<interfaces>` section explicitly instructed: *"verify status at write time — REQUIREMENTS.md may still show Pending"*)
- **Issue:** Plan's must-have truth #2 + truth #3 assumed all v1 reqs were closed by plan 05-03 execution time. Reality per `.planning/REQUIREMENTS.md ## Traceability` table at write time: QA-07 + QA-11 + OPS-03 + OPS-05 + OPS-06 still show **Pending**. OPS-02 itself closes via THIS plan but cannot be marked Validated mid-execution (chicken-and-egg).
- **Fix:** Marked 50 truthful Validated entries (Phases 1-4 fully closed + OPS-01 + OPS-04 from Phase 5). Kept the 6 Pending reqs in Active with explicit per-req closure-path annotations. Renamed Active section's intro from plan's suggested "All v1 requirements validated as of milestone v1.0 close" to honest "The 6 v1 requirements still open as of plan 05-03 write-time" + per-req rationale.
- **Files modified:** `.planning/PROJECT.md`
- **Why this is the right call:** plan's `<interfaces>` block explicitly anticipated this divergence with the *"reality check at write time"* instruction and the *"do not silently mark Pending requirements as validated"* directive. T-05-03-01 mitigation in plan's threat register pinned this exact concern. Following the silent-flip path would have created the very tampering risk the threat register flagged.

### No other deviations

- D11-D15 rows: copied verbatim from plan `<interfaces>` block (zero edits to decision text or rationale).
- Phase grouping headers: preserved from REQUIREMENTS.md (must-have truth #5 honored).

## Auth Gates

None — pure markdown edit, no external systems touched.

## Files Touched

```
modified  .planning/PROJECT.md                                                +90 / -19 lines
created   .planning/phases/05-ops-lang-polish-public-release/05-03-SUMMARY.md
```

No PHP, no migrations, no tests. `make all` not re-run (markdown-only change; out of all QA tool scope per plan verification block).

## Threat Mitigations Applied

| Threat ID | Disposition | What was done |
|-----------|-------------|---------------|
| T-05-03-01 (Tampering — PROJECT.md drifts from REQUIREMENTS.md) | mitigated | Reality-checked each REQ status against REQUIREMENTS.md `## Traceability` before flipping; left QA-07/QA-11/OPS-03/05/06 in Active with explicit pending annotations rather than silently mass-validating. |
| T-05-03-02 (Repudiation — future engineer doubts D11-D15) | mitigated | Each D-row carries `2026-04-29` date AND a phase/plan reference where the decision was implemented (D12 → "shipped Phase 3 plans 03-06 + 03-07 + Phase 4 plan 04-06", D14 → "shipped Phase 3 plan 03-02", D15 → "shipped Phase 1 plan 01-05"). |
| T-05-03-03 (Information Disclosure — rationale exposes proprietary logic in PUBLIC PROJECT.md) | accepted | D11-D15 rationale is operator-facing engineering tradeoffs; no IP risk. |

## Known Stubs

None. Pure docs change.

## Self-Check: PASSED

- [x] PROJECT.md `## Key Decisions` table contains D11, D12, D13, D14, D15 (verified: `grep -c '^| D1[12345]' .planning/PROJECT.md` → 5)
- [x] All 5 new D-rows carry `Locked, validated 2026-04-29` (verified: `grep -F 'Locked, validated 2026-04-29' .planning/PROJECT.md | wc -l` → 5)
- [x] `### Validated` section populated (50 reqs verified by per-prefix grep counts above)
- [x] `### Active` section replaced (6 honest open items, not 15 scaffold placeholders)
- [x] `(None yet — ship to validate)` placeholder removed
- [x] Phase grouping headers preserved (Phase 1/2/3/4/5 bold sub-headings under Validated)
- [x] No silent flipping of Pending QA-07 / QA-11 / OPS-03 / OPS-05 / OPS-06 — each annotated with closure path under Active
- [x] SUMMARY.md created at `.planning/phases/05-ops-lang-polish-public-release/05-03-SUMMARY.md`
- [x] Footer timestamp updated to 2026-04-30 with milestone close pass note
