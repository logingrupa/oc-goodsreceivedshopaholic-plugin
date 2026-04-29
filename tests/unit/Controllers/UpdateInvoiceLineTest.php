<?php

declare(strict_types=1);

use Carbon\Carbon;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;

require_once __DIR__.'/../Apply/ApplyTestCase.php';
require_once __DIR__.'/InvoiceUploadTestHelpers.php';

uses(ApplyTestCase::class);

/**
 * UI-03 / D-09 / D-44.
 *
 * Plan 04-04 Task 2: onUpdateLine AJAX handler — per-line override_qty /
 * override_reason inline editing on the preview screen.
 *
 * Pins the contract that operator edits on the preview table's `override_qty`
 * (numeric) and `override_reason` (text) inputs round-trip through a single
 * AJAX call to onUpdateLine without requiring a full preview re-render. The
 * handler is deliberately small (D-09 chose a custom AJAX handler over
 * RelationController inline-edit; rationale: the latter requires the relation
 * panel to render the edit row + scaffold two extra partials, while a single
 * onUpdateLine handler is one method + a few input bindings in
 * `_preview_lines.htm`).
 *
 * Tiger-Style invariants:
 *   - Permission gate at handler entry (same key as onUpload —
 *     `upload_invoices`; D-44 / T-04-04-08).
 *   - Validation is fail-fast: missing/zero `line_id` ⇒ AjaxException;
 *     non-existent `line_id` ⇒ AjaxException ("not found"); negative
 *     `override_qty` ⇒ AjaxException. NO partial-success semantics — either
 *     the row updates, or nothing changes.
 *   - Empty `override_reason` after trim is treated as "clear the field"
 *     (NULL), so an operator can wipe a previously-recorded reason without
 *     leaving an empty string in the audit log.
 *   - `saveQuietly` on the row — Phase 3 stock-write parity (override fields
 *     are audit-only metadata; consumed at apply time by StockApplyService
 *     reading `override_qty ?? qty`).
 *
 * Test seam: `Input::merge([...])` mutates the live request's input bag,
 * which is the canonical way Laravel tests drive `Input::get` /
 * `Input::has` calls without standing up a real HTTP request. The
 * TestableInvoices shim is reused from InvoiceUploadTestHelpers.php for the
 * permission gate.
 */

/**
 * Helper to seed an Invoice + InvoiceLine pair for the update tests. The
 * line carries baseline `qty` + matched offer; tests then mutate the
 * override fields via the AJAX handler and assert the persisted state.
 */
function seedInvoiceLineForUpdate(int $iLineQty = 10): InvoiceLine
{
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = 'PRO_UPDATE_TEST_'.uniqid();
    $obInvoice->status = Invoice::STATUS_PARSED;
    $obInvoice->total_lines = 1;
    $obInvoice->matched_lines = 1;
    $obInvoice->unmatched_lines = 0;
    $obInvoice->stock_added_units = 0;
    $obInvoice->initial_reset_applied = false;
    $obInvoice->parsed_at = Carbon::now();
    $obInvoice->saveQuietly();

    $obLine = new InvoiceLine();
    $obLine->invoice_id = (int) $obInvoice->id;
    $obLine->row_index = 1;
    $obLine->ean = '4752307123456';
    $obLine->qty = $iLineQty;
    $obLine->matched_offer_id = 999;
    $obLine->match_strategy = InvoiceLine::MATCH_STRATEGY_OFFER_CODE;
    $obLine->applied = false;
    $obLine->saveQuietly();

    return $obLine;
}

it('rejects request without upload_invoices permission (D-44)', function (): void {
    $obController = makeTestController(bHasPermission: false, arFiles: null);

    $obException = null;
    try {
        $obController->onUpdateLine();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.upload_invoices']);
});

it('updates override_qty on an InvoiceLine (UI-03 happy path)', function (): void {
    $obLine = seedInvoiceLineForUpdate(iLineQty: 10);
    \Input::merge(['line_id' => $obLine->id, 'override_qty' => 99]);

    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $arResponse = $obController->onUpdateLine();

    expect($arResponse)->toBeArray();
    expect($arResponse)->toHaveKey('line_id');
    expect((int) $arResponse['line_id'])->toBe((int) $obLine->id);
    expect((int) $arResponse['override_qty'])->toBe(99);

    // DB-level pin: row was actually saved, not just echoed back.
    $obFresh = InvoiceLine::find($obLine->id);
    expect($obFresh)->not->toBeNull();
    expect((int) $obFresh->override_qty)->toBe(99);
    // Original qty unchanged — override is additive metadata, NOT a replacement.
    expect((int) $obFresh->qty)->toBe(10);
});

it('updates override_reason on an InvoiceLine (UI-03 reason path)', function (): void {
    $obLine = seedInvoiceLineForUpdate();
    \Input::merge(['line_id' => $obLine->id, 'override_reason' => 'Damaged in transit']);

    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $arResponse = $obController->onUpdateLine();

    expect($arResponse)->toHaveKey('override_reason');
    expect((string) $arResponse['override_reason'])->toBe('Damaged in transit');

    $obFresh = InvoiceLine::find($obLine->id);
    expect((string) $obFresh->override_reason)->toBe('Damaged in transit');
});

it('clears override_reason when given empty string after trim', function (): void {
    $obLine = seedInvoiceLineForUpdate();
    // Pre-set a reason via direct save, then verify the AJAX call wipes it.
    $obLine->override_reason = 'Old reason';
    $obLine->saveQuietly();

    \Input::merge(['line_id' => $obLine->id, 'override_reason' => '   ']);

    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $arResponse = $obController->onUpdateLine();

    expect($arResponse['override_reason'])->toBeNull();
    $obFresh = InvoiceLine::find($obLine->id);
    expect($obFresh->override_reason)->toBeNull();
});

it('rejects negative override_qty (validation gate)', function (): void {
    $obLine = seedInvoiceLineForUpdate();
    \Input::merge(['line_id' => $obLine->id, 'override_qty' => -5]);

    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $obException = null;
    try {
        $obController->onUpdateLine();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect((string) $arContents['message'])->toContain('non-negative');

    // DB row unchanged — fail-fast guard rejected before save.
    $obFresh = InvoiceLine::find($obLine->id);
    expect($obFresh->override_qty)->toBeNull();
});

it('rejects nonexistent line_id (404-style guard)', function (): void {
    \Input::merge(['line_id' => 99999, 'override_qty' => 1]);

    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $obException = null;
    try {
        $obController->onUpdateLine();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect(strtolower((string) $arContents['message']))->toContain('not found');
});

it('rejects missing/zero line_id (defensive guard)', function (): void {
    \Input::merge(['line_id' => 0, 'override_qty' => 1]);

    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $obException = null;
    try {
        $obController->onUpdateLine();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect(strtolower((string) $arContents['message']))->toContain('line_id');
});

it('returns noop response when no override fields are submitted', function (): void {
    $obLine = seedInvoiceLineForUpdate();
    \Input::merge(['line_id' => $obLine->id]);

    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $arResponse = $obController->onUpdateLine();

    expect($arResponse)->toHaveKey('noop');
    expect($arResponse['noop'])->toBeTrue();
});
