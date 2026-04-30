<?php

declare(strict_types=1);

use October\Rain\Exception\AjaxException;

require_once __DIR__.'/ControllerTestCase.php';

uses(ControllerTestCase::class);

/**
 * QA-10 / D-36 / D-37 / D-44 — Permission gate for `onInitialResetConfirm`
 * (logingrupa.goodsreceived.run_initial_reset).
 *
 * Initial reset is the MOST destructive op the plugin exposes: it zeros
 * EVERY offer's quantity and deactivates EVERY product, then runs an
 * apply on top. It is a one-shot per-site baseline op (UI-08 / D-22..D-25)
 * and is gated by the NARROWEST permission key — typically held by a
 * single ops user during initial onboarding. The 3 contracts pinned here:
 *
 *   1) DENY without ANY permission — operator with no perms is blocked
 *      at the gate; `arPermissionsChecked` records the EXPECTED key.
 *   2) PERMIT with ONLY the required permission — operator holding ONLY
 *      `run_initial_reset` reaches the next-stage validation (the
 *      "Type RESET exactly to confirm" AjaxException branch when
 *      `confirm_typed` is missing/wrong; case-sensitive per D-24).
 *   3) DENY with ONLY a WRONG permission — operator holding ONLY
 *      `apply_invoices` (or any other plugin perm) is STILL blocked at
 *      the initial-reset gate. CRITICAL: this prevents a misconfigured
 *      operator from running the destructive op via API request even
 *      when they hold every OTHER plugin perm.
 *
 * BoundaryMockNote (D-37 / D-44 / D-04-06-03): TestableInvoices shim
 * path; see ControllerTestCase header.
 */

it('rejects onInitialResetConfirm when operator has NO permissions (deny baseline)', function (): void {
    $obController = new TestableInvoices();
    $this->revokeAllPermissions($obController);

    $obException = null;
    try {
        $obController->onInitialResetConfirm();
    } catch (AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect($arContents)->toBeArray();
    expect($arContents)->toHaveKey('message');
    expect((string) $arContents['message'])->toContain('test stub');

    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.run_initial_reset']);
});

it('permits onInitialResetConfirm when operator holds run_initial_reset permission ONLY', function (): void {
    $obController = new TestableInvoices();
    $this->grantPermission($obController, 'logingrupa.goodsreceived.run_initial_reset');

    // Send `confirm_typed=''` so the handler reaches the next-stage gate
    // (typed-RESET literal check) AFTER the permission gate. That throws
    // the "Type RESET" AjaxException — proves we got past the perm gate.
    \Input::merge(['confirm_typed' => '']);

    $obException = null;
    try {
        $obController->onInitialResetConfirm();
    } catch (AjaxException $obCaught) {
        $obException = $obCaught;
    }

    if ($obException !== null) {
        $sMessage = (string) ($obException->getContents()['message'] ?? '');
        expect($sMessage)->not->toContain('test stub');
    }

    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.run_initial_reset']);
});

it('rejects onInitialResetConfirm when operator holds an UNRELATED permission only', function (): void {
    $obController = new TestableInvoices();
    // Wrong key — apply_invoices is the apply gate, NOT the reset gate.
    // CRITICAL pin: holding every OTHER plugin perm must STILL block reset.
    $this->grantOnly($obController, [
        'logingrupa.goodsreceived.upload_invoices',
        'logingrupa.goodsreceived.apply_invoices',
        'logingrupa.goodsreceived.override_invoices',
    ]);

    $obException = null;
    try {
        $obController->onInitialResetConfirm();
    } catch (AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect((string) $arContents['message'])->toContain('test stub');

    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.run_initial_reset']);
});
