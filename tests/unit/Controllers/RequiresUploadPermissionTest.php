<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use October\Rain\Exception\AjaxException;

require_once __DIR__.'/ControllerTestCase.php';

uses(ControllerTestCase::class);

/**
 * QA-10 / D-36 / D-37 / D-44 — Permission gate for `onUpload`
 * (logingrupa.goodsreceived.upload_invoices).
 *
 * The upload handler is the SOLE entry point that the multi-file `.HTM`
 * upload widget hits; without this gate, any backend user could overwhelm
 * the parser with arbitrary HTM payloads and trigger bulk
 * ParseAndPersistOrchestrator transactions. The 3 contracts pinned here:
 *
 *   1) DENY without ANY permission — operator with no perms at all is
 *      blocked at the gate; `arPermissionsChecked` records the EXPECTED
 *      key, proving the call site uses the right literal.
 *   2) PERMIT with ONLY the required permission — operator holding ONLY
 *      `upload_invoices` reaches the next-stage validation (the "no files"
 *      AjaxException branch) — that proves the gate was passed. Any
 *      AjaxException whose message does NOT contain "test stub" is
 *      acceptable here (the shim's stub message is the gate's tell).
 *   3) DENY with ONLY a WRONG permission — operator holding ONLY a
 *      different perm key (e.g., `apply_invoices`) is STILL blocked, which
 *      proves the gate is keyed to the SPECIFIC literal not "any plugin
 *      perm".
 *
 * BoundaryMockNote (D-37 / D-44 / D-04-04-02): test approach uses the
 * `TestableInvoices` shim's `arAllowedPermissions` allow set — see
 * `ControllerTestCase` header for the BackendAuth-facade-mock-collides-
 * with-Backend\\Classes\\Controller-__construct rationale.
 */

it('rejects onUpload when operator has NO permissions (deny baseline)', function (): void {
    $obController = new TestableInvoices();
    $this->revokeAllPermissions($obController);

    $obException = null;
    try {
        $obController->onUpload();
    } catch (AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect($arContents)->toBeArray();
    expect($arContents)->toHaveKey('message');
    // The shim's gate-rejection message is the tell that the gate fired
    // (vs. a downstream validation failure).
    expect((string) $arContents['message'])->toContain('test stub');

    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.upload_invoices']);
    expect(Invoice::count())->toBe(0);
});

it('permits onUpload when operator holds upload_invoices permission ONLY', function (): void {
    $obController = new TestableInvoices();
    $this->grantPermission($obController, 'logingrupa.goodsreceived.upload_invoices');

    $obException = null;
    try {
        // No files staged — onUpload reaches the "no_files" AjaxException
        // branch AFTER the permission gate (proves we got past the gate).
        $obController->onUpload();
    } catch (AjaxException $obCaught) {
        $obException = $obCaught;
    }

    // Either no exception at all OR an exception that is NOT the gate
    // exception — both prove the gate passed.
    if ($obException !== null) {
        $sMessage = (string) ($obException->getContents()['message'] ?? '');
        expect($sMessage)->not->toContain('test stub');
    }

    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.upload_invoices']);
});

it('rejects onUpload when operator holds an UNRELATED permission only', function (): void {
    $obController = new TestableInvoices();
    // Wrong key — apply_invoices is the apply gate, NOT the upload gate.
    $this->grantOnly($obController, ['logingrupa.goodsreceived.apply_invoices']);

    $obException = null;
    try {
        $obController->onUpload();
    } catch (AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect((string) $arContents['message'])->toContain('test stub');

    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.upload_invoices']);
    expect(Invoice::count())->toBe(0);
});
