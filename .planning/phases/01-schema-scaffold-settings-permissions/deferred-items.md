# Deferred Items — Phase 01

Out-of-scope discoveries during execution. Not blocking; track for follow-up.


## 2026-04-29 — Plan 01-02

### QA tooling not installed at project root

**Discovered during:** Plan 01-02 end-of-plan verification (PHPStan + Pint).

**Issue:** `/home/forge/nailscosmetics.lv/vendor/bin/` is empty — no `phpstan`, `pint`, `rector`, `pest`, `phpmd` binaries. `make analyse` and `make pint-test` fail with `not found` (exit 127).

**Why deferred:** Pre-existing project-root infrastructure gap. Not caused by this plan's changes. The plan's verification commands assumed these binaries exist; they do not.

**Verification still satisfied:**
- `php -l` clean on all 3 model files
- `declare(strict_types=1)` on all 3 files
- All acceptance criteria in plan tasks pass via grep + `php -l`
- No QA-tool-detectable issues anticipated (PSR-12 compliant by hand; full @property docblocks; explicit casts; no `mixed`)

**Action:** Run `composer install` at `/home/forge/nailscosmetics.lv/` with dev dependencies before next plan that depends on PHPStan-level-10 gate (likely Phase 2 service work). Add to ops/setup runbook.

**Owner:** Whoever runs `/gsd:execute-phase` for Phase 02 — must install QA tooling first or surface a checkpoint asking the user to install it.

**Status (2026-04-29, Plan 01-08):** RESOLVED. QA tooling installed at `/home/forge/nailscosmetics.lv/vendor/bin/` (pest 4.4.5, phpstan + Larastan, phpmd 2.15.0, pint 1.26+, rector 2.4.0). All four sub-gates of `make all` now run from the plugin Makefile. Plan 01-08 ran the full gate end-to-end: `make all` exits 0 with `phpstan-baseline.neon` byte-identical (33 bytes, sha256 4b3227fa…). Carry-over to Phase 02: NONE.
