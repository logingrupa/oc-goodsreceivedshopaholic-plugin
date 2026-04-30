<?php

declare(strict_types=1);

use October\Rain\Exception\AjaxException;

require_once __DIR__.'/ControllerTestCase.php';

uses(ControllerTestCase::class);

/**
 * QA-10 / D-36 / D-37 / D-44 — Permission gate for `onApply`
 * (logingrupa.goodsreceived.apply_invoices).
 *
 * The apply handler is the load-bearing write path: it's where the
 * Cache::lock guard fires, where ApplyOrchestrator::apply runs the
 * idempotent stock-increment transaction, and where invoice.status flips
 * from `parsed` → `applied`. Removing the permission gate here would
 * let any backend user trigger stock writes against arbitrary
 * already-parsed invoices. The 3 contracts pinned here:
 *
 *   1) DENY without ANY permission — operator with no perms is blocked
 *      at the gate; `arPermissionsChecked` records the EXPECTED key.
 *   2) PERMIT with ONLY the required permission — operator holding ONLY
 *      `apply_invoices` reaches the next-stage validation (the
 *      "invoice_id required" AjaxException branch when invoice_id is
 *      missing or zero).
 *   3) DENY with ONLY a WRONG permission — operator holding ONLY
 *      `upload_invoices` is STILL blocked at the apply gate.
 *
 * BoundaryMockNote (D-37 / D-44 / D-04-05-01): same TestableInvoices
 * shim path as RequiresUploadPermissionTest — see ControllerTestCase
 * header for the boundary-mock rationale.
 */

it('rejects onApply when operator has NO permissions (deny baseline)', function (): void {
    $obController = new TestableInvoices();
    $this->revokeAllPermissions($obController);

    $obException = null;
    try {
        $obController->onApply();
    } catch (AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect($arContents)->toBeArray();
    expect($arContents)->toHaveKey('message');
    expect((string) $arContents['message'])->toContain('test stub');

    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.apply_invoices']);
});

it('permits onApply when operator holds apply_invoices permission ONLY', function (): void {
    $obController = new TestableInvoices();
    $this->grantPermission($obController, 'logingrupa.goodsreceived.apply_invoices');

    // Send an invalid invoice_id so the handler reaches the next-stage
    // validation AFTER the gate (the "invoice_id required" branch fires
    // on $iInvoiceId <= 0).
    \Input::merge(['invoice_id' => 0]);

    $obException = null;
    try {
        $obController->onApply();
    } catch (AjaxException $obCaught) {
        $obException = $obCaught;
    }

    if ($obException !== null) {
        $sMessage = (string) ($obException->getContents()['message'] ?? '');
        expect($sMessage)->not->toContain('test stub');
    }

    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.apply_invoices']);
});

it('rejects onApply when operator holds an UNRELATED permission only', function (): void {
    $obController = new TestableInvoices();
    // Wrong key — upload_invoices is the upload gate, NOT the apply gate.
    $this->grantOnly($obController, ['logingrupa.goodsreceived.upload_invoices']);

    $obException = null;
    try {
        $obController->onApply();
    } catch (AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect((string) $arContents['message'])->toContain('test stub');

    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.apply_invoices']);
});
