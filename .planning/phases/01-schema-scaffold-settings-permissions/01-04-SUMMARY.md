---
phase: 01-schema-scaffold-settings-permissions
plan: 04
subsystem: testing

tags: [hermetic-fixtures, htm-parser, distributor-data, parser-pin, pest, phpunit]

# Dependency graph
requires: []
provides:
  - "tests/fixtures/invoices/Nr_PRO026712_no_28112024.HTM (earliest, 2024-11-28, 90802 B)"
  - "tests/fixtures/invoices/Nr_PRO029691_no_09072025.HTM (mid, 2025-07-09, 32368 B)"
  - "tests/fixtures/invoices/Nr_PRO033328_no_13042026.HTM (latest, 2026-04-13, 39223 B)"
  - "tests/fixtures/invoices/.gitkeep (zero-byte placeholder so dir ships)"
  - "Hermetic test guarantee: Phase 2 parser tests pin against these fixtures and never reach into production storage/app/uploads/invoices/"
affects:
  - "Phase 2 (Parser): HtmInvoiceParser tests, InvoiceNumberResolver filename-fallback tests (PARSE-04)"
  - "Phase 2 (Matcher/Apply) integration tests using realistic fixture EAN/qty rows"
  - "Future grep-gate `! grep -r 'storage/app/uploads/invoices' tests/` to enforce hermeticity"

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Hermetic test fixtures: real production data copied byte-identically into tests/fixtures/, pinned by parser tests, never read at runtime from the live uploads dir"
    - "Date-spread fixture selection: 3 files spanning the distributor's 18-month emission window to surface format drift"
    - ".gitkeep convention to ensure directory ships even if a future .gitignore pattern would match HTM files"

key-files:
  created:
    - "tests/fixtures/invoices/Nr_PRO026712_no_28112024.HTM"
    - "tests/fixtures/invoices/Nr_PRO029691_no_09072025.HTM"
    - "tests/fixtures/invoices/Nr_PRO033328_no_13042026.HTM"
    - "tests/fixtures/invoices/.gitkeep"
  modified: []

key-decisions:
  - "Used plain `cp` (no `-p`/`--preserve`) — content matters; mtime/permissions don't"
  - "Verified byte-identity via `cmp` against production source for all 3 files (silent exit, identical bytes)"
  - "Filenames preserved exactly so `InvoiceNumberResolver` filename-fallback test (PARSE-04) keeps a realistic pattern"

patterns-established:
  - "Hermetic fixture copy: production .HTM data lives at tests/fixtures/invoices/ and is the SOLE source for parser tests; production uploads dir is for live operator uploads only"
  - "Byte-identity verification (cmp) is part of the acceptance criteria for any future fixture refresh — fixtures must remain bit-exact reflections of distributor output"

requirements-completed: []

# Metrics
duration: 2min
completed: 2026-04-29
---

# Phase 1 Plan 4: Hermetic HTM Fixture Copy Summary

**Three real distributor HTM delivery receipts (Nov-2024, Jul-2025, Apr-2026) copied byte-identically to tests/fixtures/invoices/, unblocking hermetic Phase 2 parser tests**

## Performance

- **Duration:** ~1 min
- **Started:** 2026-04-29T14:43:49Z
- **Completed:** 2026-04-29T14:44:49Z
- **Tasks:** 1 (auto)
- **Files created:** 4 (3 HTM + 1 .gitkeep)

## Accomplishments
- Created `tests/fixtures/invoices/` directory inside the plugin
- Copied 3 representative production HTM receipts spanning 18 months (D-20 selection) — byte-identical to source verified by `cmp`
- Added `.gitkeep` so the fixture directory ships in git regardless of any future ignore pattern
- Phase 2 parser tests now have a hermetic fixture set; no test will ever need to reach into `<project_root>/storage/app/uploads/invoices/`

## Task Commits

Each task was committed atomically:

1. **Task 1: Create tests/fixtures/invoices/ + copy 3 fixtures + .gitkeep** - `48a86cb` (test)

_(SUMMARY commit follows separately when committed by orchestrator/this agent.)_

## Files Created/Modified

| Path | Bytes | MD5 | Role |
|------|------:|-----|------|
| `tests/fixtures/invoices/Nr_PRO026712_no_28112024.HTM` | 90802 | `1238482b804180275cd8a7a405d04144` | Earliest fixture (2024-11-28) — original distributor format baseline |
| `tests/fixtures/invoices/Nr_PRO029691_no_09072025.HTM` | 32368 | `839bd8b3748292a0ec6e3ac7e4dbf779` | Mid-2025 fixture (2025-07-09) — captures mid-period drift |
| `tests/fixtures/invoices/Nr_PRO033328_no_13042026.HTM` | 39223 | `c1e09ffaa6e77c2f855c2d5e91bbc813` | Latest fixture (2026-04-13) — current distributor format |
| `tests/fixtures/invoices/.gitkeep` | 0 | n/a | Empty placeholder so dir ships in git |

**Byte-identity verification (cmp):** all 3 HTM files exit 0 against their production sources at `/home/forge/nailscosmetics.lv/storage/app/uploads/invoices/`.

## Decisions Made

- **No metadata preservation:** Used plain `cp` rather than `cp -p`. The HTM payload (BOM, CRLF, decimal-comma, unquoted attributes) is what parser tests pin against; mtime/permissions are irrelevant.
- **`.gitkeep` despite committed fixtures:** Even though all 3 .HTM files are committed (so the dir exists implicitly), the `.gitkeep` is conventional documentation that the dir is intentional and must persist if fixtures are ever rotated out.
- **Followed plan exactly:** No selection changes, no extra fixtures, no modifications to file content.

## Deviations from Plan

None - plan executed exactly as written.

All 10 acceptance criteria passed on first attempt:
1. AC1 dir exists - PASS
2. AC2 PRO026712 present - PASS
3. AC3 PRO029691 present - PASS
4. AC4 PRO033328 present - PASS
5. AC5 .gitkeep present - PASS
6. AC6 byte-identity (cmp x3) - PASS
7. AC7 sizes >1KB (3x) - PASS (90802, 32368, 39223 bytes)
8. AC8 .gitkeep zero bytes - PASS
9. AC9 exactly 3 .HTM files - PASS
10. AC10 filename pattern matches - PASS (all 3 match `Nr_PRO[0-9]+_[a-z]{2}_[0-9]{8}\.HTM$`)

## Threat Flags

None. The threat model in the plan is fully satisfied:
- T-01-15 (Information Disclosure, accept) — fixtures contain only public-storefront-grade data; repo is intentionally public per D11
- T-01-16 (Tampering, mitigate) — `cmp` verification confirms byte-identity to production
- T-01-17 (Repudiation, accept) — provenance recorded in this SUMMARY (commit `48a86cb`) and in CONTEXT.md D-20

No new security-relevant surface introduced.

## Self-Check: PASSED

Verified post-write:

```text
FOUND: tests/fixtures/invoices/Nr_PRO026712_no_28112024.HTM
FOUND: tests/fixtures/invoices/Nr_PRO029691_no_09072025.HTM
FOUND: tests/fixtures/invoices/Nr_PRO033328_no_13042026.HTM
FOUND: tests/fixtures/invoices/.gitkeep
FOUND commit: 48a86cb
```

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Phase 2 (Parser/Matcher/Apply) can now reference fixtures via `__DIR__ . '/../../fixtures/invoices/<file>.HTM'` from any test file under `tests/`
- Phase 2 should add a hermetic-gate guard test (e.g., `! grep -r 'storage/app/uploads/invoices' tests/`) to lock in the constraint
- Phase 2's `InvoiceNumberResolver` filename-fallback path can use these real filenames to pin the regex (`Nr_PRO[0-9]+_[a-z]{2}_[0-9]{8}\.HTM$`)

---
*Phase: 01-schema-scaffold-settings-permissions*
*Plan: 04*
*Completed: 2026-04-29*
