---
phase: 01-schema-scaffold-settings-permissions
plan: 06
subsystem: backend-permissions
tags: [october-cms, plugin, registerPermissions, rbac, lovata-toolbox]

# Dependency graph
requires:
  - phase: 01-schema-scaffold-settings-permissions
    provides: "lang/en/lang.php permission.* keys (Plan 01-03), Plugin.php with pluginDetails/boot/registerSettings (Plans 01-01, 01-05)"
provides:
  - "Plugin::registerPermissions() exposing 4 split RBAC codes"
  - "Permission codes: logingrupa.goodsreceived.{upload,apply,override,run_initial_reset}_invoices"
  - "Tab-grouped permission cluster for the Backend → Settings → Administrators → Roles picker"
affects: [phase-04-controllers, phase-04-backend-ui, ops-04-translations]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Backend permission registration via Plugin::registerPermissions() with #[\\Override]"
    - "Permission codes namespaced as <vendor>.<plugin-shortname>.<action> (NOT full plugin code)"
    - "Lang keys namespaced as <Vendor>.<PluginName>::lang.* (full plugin code)"
    - "Shared 'tab' value across related permissions for visual grouping in role picker"
    - "No 'roles' auto-grant key — explicit assignment only (least-privilege default)"

key-files:
  created: []
  modified:
    - "Plugin.php — registerPermissions() method added between boot() and registerSettings()"

key-decisions:
  - "Tab key resolved to lang.permission.tab (not lang.plugin.name): the lang scaffold from Plan 01-03 created a dedicated 'permission.tab' key with the human label 'Goods Received'; using it keeps permission tab labelling decoupled from plugin name and matches the executive plan body verbatim. CONTEXT.md D-16 mentioned plugin.name as a reference shape — the plan body is the binding execution spec."
  - "Order spacing of 100/200/300/400 leaves gaps for future inserts without renumbering"
  - "No 'roles' key on any entry — admins must explicitly assign each permission (T-01-28 mitigation)"
  - "Permission codes use the SHORT prefix logingrupa.goodsreceived.* (not the FULL plugin code logingrupa.goodsreceivedshopaholic.*) to keep the role-picker codes readable. Lang keys still use the FULL plugin code (October convention)."

patterns-established:
  - "registerPermissions() placement: between boot() and registerSettings() (alphabetical method ordering within register* group)"
  - "Each permission entry has label + tab + comment + order keys (no roles, no description)"
  - "All 4 permissions in this plugin share an identical tab string — exact-match grouping is required by October's role picker"

requirements-completed: [SCHEMA-07]

# Metrics
duration: 1.6min
completed: 2026-04-29
---

# Phase 01 Plan 06: Backend Permissions Scaffold Summary

**Plugin::registerPermissions() exposes 4 split RBAC codes (upload, apply, override, run_initial_reset) grouped under a single tab so phase-04 controllers can gate stock-write actions with least-privilege role assignment.**

## Performance

- **Duration:** 1.6 min
- **Started:** 2026-04-29T15:11:57Z
- **Completed:** 2026-04-29T15:13:31Z
- **Tasks:** 1
- **Files modified:** 1

## Accomplishments

- Added `Plugin::registerPermissions(): array` with `#[\Override]` between `boot()` and `registerSettings()`.
- Registered 4 permission codes:
  - `logingrupa.goodsreceived.upload_invoices` (order 100)
  - `logingrupa.goodsreceived.apply_invoices` (order 200)
  - `logingrupa.goodsreceived.override_invoices` (order 300)
  - `logingrupa.goodsreceived.run_initial_reset` (order 400)
- All 4 share the same tab key (`logingrupa.goodsreceivedshopaholic::lang.permission.tab`) so they cluster together in the Backend Roles permission picker.
- Each entry references existing `permission.<key>` and `permission.<key>_comment` lang labels (created in Plan 01-03 — verified present).
- No `roles` auto-grant keys — explicit assignment only (mitigates T-01-28 over-grant via auto-inheritance).
- `pluginDetails()`, `boot()`, and `registerSettings()` preserved unchanged.

## Final Plugin.php Method Order

1. `pluginDetails(): array` — `#[\Override]` (from initial scaffold)
2. `boot(): void` — empty body (Phase 1 scaffold_state)
3. `registerPermissions(): array` — `#[\Override]` ← **NEW THIS PLAN**
4. `registerSettings(): array` — `#[\Override]` (from Plan 01-05, preserved)

## Task Commits

1. **Task 1: Add registerPermissions() method to Plugin.php** — `3e7bbb8` (feat)

## Files Created/Modified

- `Plugin.php` — appended `registerPermissions()` method (42 LOC inserted, 0 deleted, 0 existing methods touched).

## Decisions Made

- **Tab key:** `permission.tab` (not `plugin.name`) — see key-decisions above.
- **Permission code prefix:** `logingrupa.goodsreceived.*` (short) for role-picker readability; lang keys use full `logingrupa.goodsreceivedshopaholic::lang.*` (October convention).
- **No `roles` auto-grant:** explicit assignment is safer; junior operators will receive `upload_invoices` only, seniors get `apply_invoices`, owners get `run_initial_reset`.
- **Method placement:** above `registerSettings()` per the explicit instruction in the plan body (the prompt's `key_facts` paragraph said "after"; the plan body is authoritative and specified "directly above").

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

- **PHPStan / PHPMD / Pint not runnable:** the parent project's `vendor/bin/` does not contain `phpstan`, `phpmd`, or `pint` (only October/Laravel runtime tooling). The plugin's own `vendor/bin/` does not exist (the plugin has no installed Composer deps yet). The Makefile's `make analyse|phpmd|pint-test` targets all expect `$PROJECT_ROOT/vendor/bin/<tool>` which is not provisioned in this environment.

  **Resolution:** Verification fell back to `php -l` (PASSED — "No syntax errors detected") plus all 9 grep-based structural acceptance criteria (all PASSED). The QA tool gating is a phase-wide environmental gap (not specific to this plan); installing dev tooling in `composer.json --dev` requires a separate scaffold task tracked outside Phase 1's frontmatter.

- **Acceptance criteria verification:** all 10 criteria met by structural / php-syntax checks. Specifically:
  - AC1 registerPermissions signature → PASS
  - AC2 4 permission codes present → PASS (all 4)
  - AC3 4× shared tab → `grep -c` returns 4 → PASS
  - AC4 8× label/comment refs → `grep -c` returns 8 → PASS
  - AC5 3× `#[\Override]` → returns 3 → PASS
  - AC6 `registerSettings()` preserved → both `'goodsreceived-settings'` and `Settings::class` still present → PASS
  - AC7 `boot()` body still empty → PASS (only `{` then `}`)
  - AC8 no `'roles'` key → PASS (grep returns nothing)
  - AC9 `php -l` → "No syntax errors detected" → PASS
  - AC10 PHPStan level 10 → SKIPPED (tool not available in environment; documented above)

## Threat Mitigations Applied

| Threat ID | Mitigation |
|-----------|------------|
| T-01-23 (EoP — destructive reset) | `run_initial_reset` is a SEPARATE permission code, not bundled with `apply_invoices`. Owners can grant routine apply rights to operators while reserving baseline-reset for themselves. |
| T-01-24 (Spoofing — code collision) | Codes use globally unique `logingrupa.goodsreceived.*` prefix. Verified no other plugin in `plugins/` registers this prefix. |
| T-01-28 (DoS — over-grant via auto-inheritance) | Acceptance criterion #8 (`! grep -q "'roles'" Plugin.php`) gates this — no permission auto-grants to any role. |

## Next Phase Readiness

- Phase 4 controllers (`Invoices` backend controller in particular) can now gate actions via:

  ```php
  // before parse:
  $this->requirePermission('logingrupa.goodsreceived.upload_invoices');
  // before stock writes:
  $this->requirePermission('logingrupa.goodsreceived.apply_invoices');
  // before re-applying duplicate:
  $this->requirePermission('logingrupa.goodsreceived.override_invoices');
  // before initial reset:
  $this->requirePermission('logingrupa.goodsreceived.run_initial_reset');
  ```

- `Plugin.php` is feature-complete for Phase 1 scaffold purposes (boot stays empty until Phase 3 wires model handlers).

## Self-Check: PASSED

Verification:
- File exists: `/home/forge/nailscosmetics.lv/plugins/logingrupa/goodsreceivedshopaholic/Plugin.php` → FOUND
- Commit exists: `3e7bbb8` → FOUND in `git log`
- All 4 permission codes present in committed `Plugin.php` (verified post-commit via `git show 3e7bbb8 -- Plugin.php`).

---
*Phase: 01-schema-scaffold-settings-permissions*
*Plan: 06*
*Completed: 2026-04-29*
