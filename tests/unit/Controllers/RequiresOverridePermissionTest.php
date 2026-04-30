<?php

declare(strict_types=1);

use October\Rain\Exception\AjaxException;

require_once __DIR__.'/ControllerTestCase.php';

uses(ControllerTestCase::class);

/**
 * QA-10 / D-36 / D-37 / D-44 — Permission gate for `onOverrideConfirm`
 * (logingrupa.goodsreceived.override_invoices).
 *
 * Override-and-reimport is the destructive UX branch (UI-10 / D-18..D-21):
 * it ADDS the new invoice's units ON TOP of the prior apply (NOT a delta).
 * Stripping the permission gate here would let an unprivileged operator
 * re-apply already-applied invoices, multiplying stock additions. The
 * permission is held by ops/finance only — narrower than `apply_invoices`.
 * The 3 contracts pinned here:
 *
 *   1) DENY without ANY permission — operator with no perms is blocked at
 *      the gate; `arPermissionsChecked` records the EXPECTED key.
 *   2) PERMIT with ONLY the required permission — operator holding ONLY
 *      `override_invoices` reaches the next-stage validation (the
 *      "Type OVERRIDE exactly to confirm" AjaxException when
 *      `confirm_typed` is missing/wrong; case-sensitive per D-19).
 *   3) DENY with ONLY a WRONG permission — operator holding ONLY
 *      `apply_invoices` is STILL blocked at the override gate.
 *
 * BoundaryMockNote (D-37 / D-44 / D-04-06-01): TestableInvoices shim
 * path; see ControllerTestCase header.
 */

it('rejects onOverrideConfirm when operator has NO permissions (deny baseline)', function (): void {
    $obController = new TestableInvoices();
    $this->revokeAllPermissions($obController);

    $obException = null;
    try {
        $obController->onOverrideConfirm();
    } catch (AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect($arContents)->toBeArray();
    expect($arContents)->toHaveKey('message');
    expect((string) $arContents['message'])->toContain('test stub');

    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.override_invoices']);
});

it('permits onOverrideConfirm when operator holds override_invoices permission ONLY', function (): void {
    $obController = new TestableInvoices();
    $this->grantPermission($obController, 'logingrupa.goodsreceived.override_invoices');

    // Send `confirm_typed=''` so the handler reaches the next-stage gate
    // (typed-OVERRIDE literal check) AFTER the permission gate. That
    // throws the "Type OVERRIDE" AjaxException — proves we got past the
    // perm gate.
    \Input::merge(['confirm_typed' => '', 'prior_invoice_id' => 0]);

    $obException = null;
    try {
        $obController->onOverrideConfirm();
    } catch (AjaxException $obCaught) {
        $obException = $obCaught;
    }

    if ($obException !== null) {
        $sMessage = (string) ($obException->getContents()['message'] ?? '');
        expect($sMessage)->not->toContain('test stub');
    }

    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.override_invoices']);
});

it('rejects onOverrideConfirm when operator holds an UNRELATED permission only', function (): void {
    $obController = new TestableInvoices();
    // Wrong key — apply_invoices is the apply gate, NOT the override gate.
    $this->grantOnly($obController, ['logingrupa.goodsreceived.apply_invoices']);

    $obException = null;
    try {
        $obController->onOverrideConfirm();
    } catch (AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect((string) $arContents['message'])->toContain('test stub');

    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.override_invoices']);
});
