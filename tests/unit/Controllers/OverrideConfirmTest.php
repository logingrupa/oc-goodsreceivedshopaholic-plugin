<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ParseAndPersistOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;

require_once __DIR__.'/../Apply/ApplyTestCase.php';
require_once __DIR__.'/InvoiceUploadTestHelpers.php';

uses(ApplyTestCase::class);

/**
 * UI-10 / D-18..D-21 / D-04-06-01..D-04-06-05.
 *
 * Plan 04-06 Task 1: typed-OVERRIDE confirmation gate for the
 * override-and-reimport flow.
 *
 * Tiger-Style invariants pinned here:
 *   - Permission gate at handler entry (`override_invoices`); deny ⇒ AjaxException.
 *   - Server-side strict equality on the literal string `OVERRIDE` (T-04-06-01) —
 *     case-sensitive, not case-folded; lowercased / mistyped / missing entries
 *     all reject BEFORE the orchestrator is even called.
 *   - On confirm, route through ParseAndPersistOrchestrator::runOverride which
 *     produces a NEW Invoice with `override_of_invoice_id` set + suffixed
 *     `invoice_number` (Phase 3 plan 03-06 contract). The operator subsequently
 *     clicks Apply on the new row; ApplyOrchestrator runs unchanged and the
 *     ADD-ON-TOP semantics emerge (D-12 / D-21).
 *
 * Test seams reuse the TestableInvoices shim from InvoiceUploadTestHelpers
 * (plan 04-04 D-04-04-02 — boundary-mocking via protected hooks instead of
 * facade-mocking BackendAuth + Input + app(), which collides with
 * Backend\Classes\Controller's __construct). The handler reads uploaded
 * files through `getUploadedFiles()` so the shim's `arUploadedFiles` array
 * drives the foreach without standing up a real HTTP request.
 */

it('rejects onOverrideShowConfirm without override_invoices permission (D-04-06-01)', function (): void {
    $obController = makeTestController(bHasPermission: false, arFiles: null);
    \Input::merge(['prior_invoice_id' => 42]);

    $obException = null;
    try {
        $obController->onOverrideShowConfirm();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.override_invoices']);
});

it('onOverrideShowConfirm renders modal partial keyed at #overrideConfirm (D-18)', function (): void {
    $obController = makeTestController(bHasPermission: true, arFiles: null);
    \Input::merge(['prior_invoice_id' => 99]);

    $arResponse = $obController->onOverrideShowConfirm();

    expect($arResponse)->toBeArray();
    expect($arResponse)->toHaveKey('#overrideConfirm');
    expect((string) $arResponse['#overrideConfirm'])->toContain('_partials/override_confirm');

    $arPartialCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/override_confirm') {
            $arPartialCall = $arCall;
            break;
        }
    }
    expect($arPartialCall)->not->toBeNull();
    expect((int) $arPartialCall['data']['prior_invoice_id'])->toBe(99);
});

it('onOverrideShowConfirm rejects when prior_invoice_id is missing or zero', function (): void {
    $obController = makeTestController(bHasPermission: true, arFiles: null);
    \Input::merge(['prior_invoice_id' => 0]);

    $obException = null;
    try {
        $obController->onOverrideShowConfirm();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect(strtolower((string) $obException->getContents()['message']))->toContain('prior_invoice_id');
});

it('rejects onOverrideConfirm without override_invoices permission (D-20)', function (): void {
    $obController = makeTestController(bHasPermission: false, arFiles: null);

    $obException = null;
    try {
        $obController->onOverrideConfirm();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.override_invoices']);
});

it('onOverrideConfirm rejects when typed string is missing (T-04-06-01 server-side)', function (): void {
    $obController = makeTestController(bHasPermission: true, arFiles: null);
    \Input::merge(['confirm_typed' => '', 'prior_invoice_id' => 42]);

    $obException = null;
    try {
        $obController->onOverrideConfirm();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect((string) $obException->getContents()['message'])->toContain('OVERRIDE');
});

it('onOverrideConfirm rejects when typed string is wrong case (D-19 case-sensitive)', function (): void {
    $obController = makeTestController(bHasPermission: true, arFiles: null);
    \Input::merge(['confirm_typed' => 'override', 'prior_invoice_id' => 42]);

    $obException = null;
    try {
        $obController->onOverrideConfirm();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect((string) $obException->getContents()['message'])->toContain('OVERRIDE');
});

it('onOverrideConfirm with literal OVERRIDE proceeds to runOverride (UI-10 happy path)', function (): void {
    // Seed a prior applied Invoice — the override target.
    $obPrior = new Invoice();
    $obPrior->invoice_number = 'PRO033328';
    $obPrior->status = Invoice::STATUS_APPLIED;
    $obPrior->total_lines = 21;
    $obPrior->matched_lines = 21;
    $obPrior->unmatched_lines = 0;
    $obPrior->stock_added_units = 105;
    $obPrior->initial_reset_applied = false;
    $obPrior->parsed_at = \Carbon\Carbon::now()->subDay();
    $obPrior->applied_at = \Carbon\Carbon::now()->subHour();
    $obPrior->applied_by_user_id = 7;
    $obPrior->saveQuietly();

    // Stage a fixture file for re-upload.
    $arStaged = stageFixtureUpload('Nr_PRO033328_no_13042026.HTM');

    \Input::merge([
        'confirm_typed'    => 'OVERRIDE',
        'prior_invoice_id' => (int) $obPrior->id,
    ]);

    // Bind a tracking spy orchestrator that records the runOverride call args
    // and returns a synthetic new Invoice with override_of_invoice_id set.
    $iCalls = 0;
    $arRecorded = ['html' => '', 'filename' => '', 'prior_id' => 0, 'user_id' => 0];
    $obSpy = new class ($iCalls, $arRecorded) extends ParseAndPersistOrchestrator {
        public int $iCallCount = 0;

        /** @var array<string, mixed> */
        public array $arRecorded = [];

        /**
         * @param  array<string, mixed>  $arRecorded
         */
        public function __construct(int &$iCalls, array &$arRecorded)
        {
            parent::__construct();
            $this->iCallCount = &$iCalls;
            $this->arRecorded = &$arRecorded;
        }

        #[\Override]
        public function runOverride(
            string $sHtmlContent,
            string $sSourceFilename,
            int $iPriorInvoiceId,
            int $iAppliedByUserId,
        ): Invoice {
            $this->iCallCount++;
            $this->arRecorded = [
                'html'     => $sHtmlContent,
                'filename' => $sSourceFilename,
                'prior_id' => $iPriorInvoiceId,
                'user_id'  => $iAppliedByUserId,
            ];

            $obInvoice = new Invoice();
            $obInvoice->invoice_number = sprintf('PRO033328-OVR-%d', $iPriorInvoiceId);
            $obInvoice->status = Invoice::STATUS_PARSED;
            $obInvoice->total_lines = 0;
            $obInvoice->matched_lines = 0;
            $obInvoice->unmatched_lines = 0;
            $obInvoice->stock_added_units = 0;
            $obInvoice->override_of_invoice_id = $iPriorInvoiceId;
            $obInvoice->initial_reset_applied = false;
            $obInvoice->parsed_at = \Carbon\Carbon::now();
            $obInvoice->applied_by_user_id = $iAppliedByUserId;
            $obInvoice->saveQuietly();

            return $obInvoice;
        }
    };
    app()->instance(ParseAndPersistOrchestrator::class, $obSpy);

    try {
        $obController = makeTestController(bHasPermission: true, arFiles: [$arStaged['file']], iUserId: 99);

        $arResponse = $obController->onOverrideConfirm();

        expect($obSpy->iCallCount)->toBe(1);
        expect((string) $obSpy->arRecorded['filename'])->toBe('Nr_PRO033328_no_13042026.HTM');
        expect((int) $obSpy->arRecorded['prior_id'])->toBe((int) $obPrior->id);
        expect((int) $obSpy->arRecorded['user_id'])->toBe(99);
        expect((string) $obSpy->arRecorded['html'])->not->toBe('');

        expect($arResponse)->toHaveKey('#invoicePreviewWrap');
        expect((string) $arResponse['#invoicePreviewWrap'])->toContain('_partials/preview_lines');
    } finally {
        app()->forgetInstance(ParseAndPersistOrchestrator::class);
        @unlink($arStaged['path']);
    }
});

it('onOverrideConfirm rejects when no file is uploaded', function (): void {
    $obController = makeTestController(bHasPermission: true, arFiles: null);
    \Input::merge(['confirm_typed' => 'OVERRIDE', 'prior_invoice_id' => 1]);

    $obException = null;
    try {
        $obController->onOverrideConfirm();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect(strtolower((string) $obException->getContents()['message']))->toContain('file');
});
