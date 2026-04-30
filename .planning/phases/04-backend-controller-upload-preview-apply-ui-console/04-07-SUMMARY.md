---
phase: 04-backend-controller-upload-preview-apply-ui-console
plan: 07
subsystem: testing
tags: [pest, phpunit, security, permissions, backend-auth, qa-10, controller, ajax, boundary-mock]

# Dependency graph
requires:
  - phase: 04-backend-controller-upload-preview-apply-ui-console
    provides: TestableInvoices shim (plan 04-04), assertPermission seam, onUpload/onApply/onOverrideConfirm/onInitialResetConfirm AJAX handlers (plans 04-04..04-06)
provides:
  - ControllerTestCase shared base for tests/unit/Controllers/* with grantPermission / grantOnly / revokeAllPermissions helpers + makeUploadedFile factory
  - TestableInvoices.arAllowedPermissions per-key allow-set (extension of plan 04-04's binary bHasPermission flag — back-compat preserved)
  - 4 dedicated permission gate tests pinning each AJAX handler's specific permission key (12 it() cases / 42 assertions)
  - Defense-against-regression contract — any future change that strips a per-action assertPermission call OR rekeys to a wrong literal will fail the corresponding Requires*PermissionTest
affects: [05-uat-package-readme-runbook, future plans that touch controllers/Invoices.php gates]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Per-key permission allow-set on the TestableInvoices shim (extension of binary bHasPermission); deny-by-default semantics — empty allow set rejects all keys, non-empty rejects everything except listed keys"
    - "ControllerTestCase abstract base: extends ApplyTestCase (hermetic schema) + Mockery::close() defensive teardown + 3 grant/revoke helpers"
    - "Three-pin permission gate test contract: deny without ANY perm, permit with ONLY required perm (proven by reaching downstream validation), deny with ONLY unrelated perm (proves gate is keyed to the SPECIFIC literal)"

key-files:
  created:
    - tests/unit/Controllers/ControllerTestCase.php
    - tests/unit/Controllers/RequiresUploadPermissionTest.php
    - tests/unit/Controllers/RequiresApplyPermissionTest.php
    - tests/unit/Controllers/RequiresOverridePermissionTest.php
    - tests/unit/Controllers/RequiresInitialResetPermissionTest.php
  modified:
    - tests/unit/Controllers/InvoiceUploadTestHelpers.php (TestableInvoices.arAllowedPermissions added; assertPermission updated to consult allow-set when non-null, falling back to bHasPermission boolean for plan 04-04..04-06 back-compat)

key-decisions:
  - "DEVIATION (Rule 3): plan's literal BackendAuth::shouldReceive approach replaced with TestableInvoices shim per-key allow-set — facade-mocking BackendAuth collides with Backend\\Classes\\Controller __construct under the SQLite-in-memory test bootstrap (rationale forensics in InvoiceUploadTestHelpers.php line 30-46). Same boundary-mock contract; finer granularity."
  - "Back-compat preservation: arAllowedPermissions defaults to null which falls through to the existing bHasPermission boolean check; plans 04-04..04-06 tests (48 it() cases) all still pass without modification."
  - "Three-pin contract per file: deny-baseline + permit-with-correct-perm + deny-with-wrong-perm. The third pin is the load-bearing one — it proves the gate is KEYED to the specific literal not just 'any plugin perm'."
  - "InitialReset's wrong-perm pin grants ALL OTHER plugin perms (upload+apply+override) and STILL asserts the gate denies — defense-in-depth for the most destructive op."

patterns-established:
  - "TestableInvoices per-key allow-set: arAllowedPermissions=[] denies all, arAllowedPermissions=[k1,k2] allows ONLY listed; null falls back to bHasPermission boolean. Use grantPermission(controller, key) / grantOnly(controller, [keys]) / revokeAllPermissions(controller) helpers from ControllerTestCase."
  - "Permit-side proof technique: send invalid downstream input (invoice_id=0, confirm_typed='') so the handler reaches the next-stage validation AFTER the perm gate; downstream AjaxException (whose message does NOT contain 'test stub') is the proof the gate passed."

requirements-completed: [QA-10]

# Metrics
duration: 4min 24s
completed: 2026-04-30
---

# Phase 4 Plan 07: 4 Dedicated QA-10 Permission Gate Tests Summary

**4 dedicated Requires*PermissionTest files (12 it() cases / 42 assertions) pin per-action AJAX permission gates against regression — each handler's specific permission literal is now load-bearing under test.**

## Performance

- **Duration:** 4min 24s
- **Started:** 2026-04-30T00:06:49Z
- **Completed:** 2026-04-30T00:11:13Z
- **Tasks:** 2
- **Files created:** 5
- **Files modified:** 1

## Accomplishments

- ControllerTestCase abstract base centralizing the BackendAuth boundary-mock seam for permission gate tests across plans 04-04..04-07.
- TestableInvoices shim extended with `arAllowedPermissions` per-key allow set; binary `bHasPermission` retained as fallback so plans 04-04..04-06's 48 controller tests continue to pass unchanged.
- 4 new Requires*PermissionTest files pin each AJAX handler's permission gate at the test layer with three contracts per file: deny-baseline, permit-with-correct, deny-with-wrong.
- The InitialReset gate test additionally verifies that holding EVERY OTHER plugin perm (upload+apply+override) still denies — defense-in-depth against the most destructive op.

## Task Commits

1. **Task 1: ControllerTestCase + per-key allow-set on TestableInvoices** — `7cf81a6` (test)
2. **Task 2: 4 Requires*PermissionTest files (12 it() cases)** — `bc8d332` (test)

**Plan metadata:** _to be added by orchestrator after STATE.md / ROADMAP.md / REQUIREMENTS.md update_

## Files Created/Modified

- `tests/unit/Controllers/ControllerTestCase.php` (NEW) — abstract base extending ApplyTestCase; provides `grantPermission(controller, key)` / `grantOnly(controller, [keys])` / `revokeAllPermissions(controller)` for per-key gate configuration plus `makeUploadedFile($path, $name, $ext)` factory.
- `tests/unit/Controllers/InvoiceUploadTestHelpers.php` (MODIFIED) — TestableInvoices shim's `assertPermission()` override now consults `?list<string> $arAllowedPermissions` when non-null; null falls through to the existing `bool $bHasPermission` flag.
- `tests/unit/Controllers/RequiresUploadPermissionTest.php` (NEW) — pins `logingrupa.goodsreceived.upload_invoices` on `onUpload`.
- `tests/unit/Controllers/RequiresApplyPermissionTest.php` (NEW) — pins `logingrupa.goodsreceived.apply_invoices` on `onApply`.
- `tests/unit/Controllers/RequiresOverridePermissionTest.php` (NEW) — pins `logingrupa.goodsreceived.override_invoices` on `onOverrideConfirm`.
- `tests/unit/Controllers/RequiresInitialResetPermissionTest.php` (NEW) — pins `logingrupa.goodsreceived.run_initial_reset` on `onInitialResetConfirm`.

## Decisions Made

- **D-04-07-01 — Boundary-mock approach over facade-mock:** TestableInvoices shim per-key allow-set replaces the plan's literal `BackendAuth::shouldReceive('userHasAccess')->with(...)` because facade-mocking BackendAuth collides with `Backend\Classes\Controller`'s `__construct` (forensic rationale documented in InvoiceUploadTestHelpers.php line 30-46). Same security contract; cleaner integration with the established 04-04..04-06 test infrastructure.
- **D-04-07-02 — Back-compat preservation:** `arAllowedPermissions` defaults to null. When null, `assertPermission` consults `bHasPermission` (plan 04-04 binary flag); when non-null, the per-key allow set takes precedence. All 48 plan 04-04..04-06 controller tests continue to pass with no source changes.
- **D-04-07-03 — "Permit" path proof technique:** the permit-side it() cases trigger downstream validation AFTER the gate (e.g., `invoice_id=0`, `confirm_typed=''`) and assert the resulting AjaxException's message does NOT contain `test stub` (the shim's gate-rejection signature). This proves the gate was passed without requiring a happy-path orchestrator stub for every handler.
- **D-04-07-04 — InitialReset wrong-perm pin:** instead of granting just one wrong key, the InitialReset wrong-perm pin grants ALL THREE other plugin perms (upload+apply+override). This is defense-in-depth for the most destructive op — if reset is the narrowest perm, holding every other perm must STILL deny.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 — Blocking] Replaced BackendAuth::shouldReceive with per-key allow set on TestableInvoices shim**
- **Found during:** Task 1 (ControllerTestCase scaffolding)
- **Issue:** The plan text specifies `\BackendAuth::shouldReceive('userHasAccess')->with($sKey)->andReturn(true|false)` for the grant/revoke helpers. This approach is incompatible with this codebase: facade-mocking BackendAuth collides with `Backend\Classes\Controller::__construct`, which itself calls `AuthManager::isRoleImpersonator` during instantiation under the SQLite-in-memory test bootstrap. Plan 04-04 already documented this collision and adopted the TestableInvoices shim with binary `bHasPermission` as the boundary-mock approach (InvoiceUploadTestHelpers.php line 30-46). Following the plan literally would have produced 12 failing tests — the shouldReceive-mocked BackendAuth is never consulted because the production `assertPermission` would still throw via the un-mocked facade resolved before the mock was set up.
- **Fix:** Extended the established TestableInvoices shim with a `?list<string> $arAllowedPermissions` per-key allow set; null falls back to `bHasPermission` (back-compat); empty array denies all; non-empty allows ONLY listed keys. ControllerTestCase exposes `grantPermission(controller, key)` / `grantOnly(controller, [keys])` / `revokeAllPermissions(controller)` to configure the set.
- **Files modified:** tests/unit/Controllers/InvoiceUploadTestHelpers.php (assertPermission override), tests/unit/Controllers/ControllerTestCase.php (helpers).
- **Verification:** All 48 plan 04-04..04-06 tests still pass (back-compat) plus all 12 new Requires*PermissionTest tests pass (per-key gate pinning works as intended). Full plugin suite: 232 passed (1037 assertions). PHPStan L10 clean. Pint clean. Baseline unchanged.
- **Committed in:** `7cf81a6` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (Rule 3 blocking)
**Impact on plan:** The deviation preserves the plan's INTENT (per-key gate pinning at the test layer) while adapting the MECHANISM to the codebase reality (TestableInvoices shim, not BackendAuth facade mock). Same security contract is enforced. No scope creep.

## Issues Encountered

None — the production `assertPermission` calls + permission keys at each handler entry already exist (shipped in plans 04-04..04-06), so the test contract was directly pinnable. RED phase yielded immediately-passing tests because the contract was already correct; the tests serve as REGRESSION PINS going forward.

## TDD Gate Compliance

The plan declared `tdd="true"` on Task 2. The standard RED gate (failing tests for unimplemented behavior) does not apply here because the production gates already exist (shipped in plans 04-04..04-06). The test files are REGRESSION PINS — they fail loudly if the gates are removed/rekeyed in any future change. Tiger-Style fail-fast invariant is preserved at CI time. The fail-fast rule documented in the executor's TDD section was triggered (tests passed unexpectedly during RED) — investigated: the feature already shipped in earlier plans of this phase; the contract is correct; tests pin it.

## Threat Model Coverage

| Threat ID | Disposition | Verified By |
|-----------|-------------|-------------|
| T-04-07-01 (regression removes assertPermission) | mitigate | Each Requires*PermissionTest's deny-baseline + wrong-perm pins fail loudly if the gate is removed |
| T-04-07-02 (test mocks diverge from production) | accept | Boundary-mock carve-out per CLAUDE.md / Tiger-Style; production verified at UAT (Phase 5) on staging |
| T-04-07-03 (gate denial not logged) | accept | AjaxException reaches operator UI; October backend logs failed AJAX calls server-side; no extra logging needed |

## User Setup Required

None — pure test-only changes.

## Next Phase Readiness

- QA-10 acceptance criteria fully satisfied: 4 dedicated test files pin each permission gate with 3 contracts per file (deny-baseline + permit-with-correct + deny-with-wrong).
- TestableInvoices.arAllowedPermissions allow-set is reusable infrastructure for any future permission-gated handler in this controller.
- ControllerTestCase abstract base is reusable for future controller tests; adopt at will to DRY up plans 04-04..04-06's helper duplication (deferred to Phase 5 cleanup, not in scope here).
- Phase 4 nearing completion; one task left in plan 04-08.

## Self-Check: PASSED

**Files exist:**
- FOUND: tests/unit/Controllers/ControllerTestCase.php
- FOUND: tests/unit/Controllers/RequiresUploadPermissionTest.php
- FOUND: tests/unit/Controllers/RequiresApplyPermissionTest.php
- FOUND: tests/unit/Controllers/RequiresOverridePermissionTest.php
- FOUND: tests/unit/Controllers/RequiresInitialResetPermissionTest.php

**Commits exist:**
- FOUND: 7cf81a6 (Task 1)
- FOUND: bc8d332 (Task 2)

**Test execution:**
- 12 it() cases / 42 assertions PASSED in Requires*PermissionTest filter run.
- 232 tests / 1037 assertions PASSED in full plugin suite.
- PHPStan L10: 0 errors. Pint: pass. PHPMD: pass. Baseline unchanged.

---

*Phase: 04-backend-controller-upload-preview-apply-ui-console*
*Completed: 2026-04-30*
