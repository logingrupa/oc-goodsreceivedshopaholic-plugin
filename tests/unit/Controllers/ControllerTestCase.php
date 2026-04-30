<?php

declare(strict_types=1);

require_once __DIR__.'/../Apply/ApplyTestCase.php';
require_once __DIR__.'/InvoiceUploadTestHelpers.php';

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Shared base for tests/unit/Controllers/* (Phase 4 plans 04-04..04-07).
 *
 * Plan 04-07 / D-37 / D-44 / QA-10. Centralizes permission-gate test
 * scaffolding for the Requires*PermissionTest.php files.
 *
 * BoundaryMockNote — DEVIATION FROM 04-07 PLAN TEXT (Rule 3 blocking issue):
 * the plan's literal `\BackendAuth::shouldReceive('userHasAccess')->with(...)`
 * approach DOES NOT WORK in this codebase. Mockery-mocking the BackendAuth
 * facade collides with `Backend\Classes\Controller`'s `__construct` (which
 * itself calls `AuthManager::isRoleImpersonator` during instantiation under
 * the test bootstrap). The forensic rationale is documented at length in
 * `InvoiceUploadTestHelpers.php` line 30-46.
 *
 * Adopted approach: extend the established `TestableInvoices` shim with a
 * per-permission ALLOW set instead of the binary `bHasPermission` flag the
 * shim currently carries. The shim's `assertPermission()` override checks
 * the requested key against the allow set and throws AjaxException for
 * misses — same boundary-mock contract, finer granularity needed for
 * QA-10's per-key gate pins.
 *
 * `grantPermission(string $sKey)`: configures a TestableInvoices instance
 * to allow ONLY the named key.
 * `revokeAllPermissions()`: configures a TestableInvoices instance to deny
 * EVERYTHING.
 * `grantOnly(array $arKeys)`: configures a TestableInvoices instance to
 * allow ONLY the listed keys (multi-perm operator simulation).
 *
 * The `makeUploadedFile()` helper mirrors `makeTestUploadedFile()` from
 * InvoiceUploadTestHelpers.php; kept here as a method (not function) so
 * the centralization story matches the plan's intent.
 *
 * setUp(): inherits ApplyTestCase's hermetic schema. tearDown(): closes
 * Mockery if the test set up ANY mocks (defensive — most permission tests
 * here do NOT use Mockery, but the close is safe to call unconditionally).
 */
abstract class ControllerTestCase extends ApplyTestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        if (class_exists(\Mockery::class)) {
            \Mockery::close();
        }
        parent::tearDown();
    }

    /**
     * Configure a TestableInvoices controller so that ONLY $sPermissionKey
     * resolves to allow; every other key throws AjaxException via the
     * shim's `assertPermission` override.
     */
    protected function grantPermission(TestableInvoices $obController, string $sPermissionKey): void
    {
        $obController->arAllowedPermissions = [$sPermissionKey];
    }

    /**
     * Configure a TestableInvoices controller so that EVERY permission
     * check throws AjaxException — the "operator with no perms at all"
     * baseline.
     */
    protected function revokeAllPermissions(TestableInvoices $obController): void
    {
        $obController->arAllowedPermissions = [];
    }

    /**
     * Configure a TestableInvoices controller with the listed perms only.
     * Used to pin "operator holds an UNRELATED permission only" — pass the
     * WRONG key to assert the gate STILL throws.
     *
     * @param  list<string>  $arKeys
     */
    protected function grantOnly(TestableInvoices $obController, array $arKeys): void
    {
        $obController->arAllowedPermissions = array_values($arKeys);
    }

    /**
     * Build a synthetic Symfony UploadedFile pointing at a real file on
     * disk in test mode. `test:true` flag bypasses Symfony's HTTP-upload
     * sanity check (`is_uploaded_file()` returns false in CLI). MIME type
     * is `text/html` for `.htm` extensions; `application/octet-stream`
     * otherwise.
     */
    protected function makeUploadedFile(string $sFixturePath, string $sClientName, ?string $sExtension = null): UploadedFile
    {
        $sExt = $sExtension ?? pathinfo($sFixturePath, PATHINFO_EXTENSION);
        $sMime = strtolower($sExt) === 'htm' ? 'text/html' : 'application/octet-stream';

        return new UploadedFile($sFixturePath, $sClientName, $sMime, null, true);
    }
}
