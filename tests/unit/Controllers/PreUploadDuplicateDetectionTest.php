<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Logingrupa\GoodsReceivedShopaholic\Controllers\Invoices;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;

require_once __DIR__.'/../Apply/ApplyTestCase.php';
require_once __DIR__.'/InvoiceUploadTestHelpers.php';

uses(ApplyTestCase::class);

/**
 * UI-09 / D-16 / D-17 / T-04-04-07.
 *
 * Plan 04-04 Task 1 (paired): pre-parse duplicate detection.
 *
 * Pins the contract that a filename whose `Nr_PRO<digits>_` matches a prior
 * `Invoice@status='applied'` row short-circuits the orchestrator: NO parse,
 * NO transaction, NO new Invoice rows. The reject partial renders with the
 * prior invoice's audit context (applied_at, applied_by, stock_added_units,
 * prior_invoice_id) so the operator sees what was previously applied.
 *
 * Tiger-Style invariants:
 *   - The gate is an OPTIMIZATION layer; the orchestrator's body-side
 *     `lockForUpdate` duplicate detection (plan 03-06) remains the
 *     authoritative contract enforcer. Both gates together survive race
 *     conditions (T-03-06-01) AND skip needless parsing.
 *   - The gate treats BOTH `status='applied'` AND `status='parsed'` as
 *     pre-parse rejection cases (Option B selected for the parsed-status
 *     duplicate hole — see .planning/debug/parsed-status-duplicate-leaks-sql.md).
 *     The two cases differ ONLY in the `reject_reason` discriminator on the
 *     reject payload:
 *       * `Invoices::REJECT_REASON_DUPLICATE_APPLIED` ⇒ Override flow.
 *       * `Invoices::REJECT_REASON_PARSED_PENDING`    ⇒ apply or discard
 *         the existing parse before re-upload.
 *     Routing the parsed-status case through the same short-circuit gate
 *     prevents the orchestrator from hitting the `invoice_number` UNIQUE
 *     index and surfacing a raw `QueryException` (with SQL/connection
 *     details) to the operator-visible flash.
 *
 * Reflection target: the helper `extractInvoiceNumberFromFilename` is
 * private; reflection-invoke pins the regex contract without widening the
 * public API surface (pattern reused from PluginBootSelfCheckTest's
 * `parseIniSize` reflection — D-35).
 */

/**
 * Reflection helper — invoke the private `extractInvoiceNumberFromFilename`
 * against arbitrary inputs. Tests the regex `/^Nr_PRO(\d+)_/i` directly
 * without coupling to the public AJAX entry surface.
 */
function callExtractInvoiceNumberFromFilename(string $sFilename): ?string
{
    $obReflection = new ReflectionClass(Invoices::class);
    $obMethod = $obReflection->getMethod('extractInvoiceNumberFromFilename');
    $obMethod->setAccessible(true);

    /** @var string|null $mResult */
    $mResult = $obMethod->invoke(new Invoices(), $sFilename);

    return $mResult;
}

/**
 * Seed a prior `applied` Invoice for duplicate-gate tests. `saveQuietly`
 * keeps model handlers silent — the audit fields are set explicitly.
 */
function seedPriorAppliedInvoice(string $sNumber, int $iStockAdded = 42, int $iAppliedBy = 99): Invoice
{
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = $sNumber;
    $obInvoice->status = Invoice::STATUS_APPLIED;
    $obInvoice->total_lines = 21;
    $obInvoice->matched_lines = 21;
    $obInvoice->unmatched_lines = 0;
    $obInvoice->stock_added_units = $iStockAdded;
    $obInvoice->initial_reset_applied = false;
    $obInvoice->parsed_at = Carbon::now()->subDay();
    $obInvoice->applied_at = Carbon::now()->subHour();
    $obInvoice->applied_by_user_id = $iAppliedBy;
    $obInvoice->saveQuietly();

    return $obInvoice;
}

it('extracts invoice number from filename via /^Nr_PRO(\d+)_/i regex (D-16)', function (): void {
    expect(callExtractInvoiceNumberFromFilename('Nr_PRO033328_no_01012026.HTM'))->toBe('PRO033328');
    expect(callExtractInvoiceNumberFromFilename('Nr_PRO000001_no_13042026.HTM'))->toBe('PRO000001');
    expect(callExtractInvoiceNumberFromFilename('nr_pro999_lv_05052025.HTM'))->toBe('PRO999');
    expect(callExtractInvoiceNumberFromFilename('NR_PRO12345_lt_01012026.htm'))->toBe('PRO12345');
});

it('returns null for filenames that do not match the pattern (D-16 fallthrough)', function (): void {
    expect(callExtractInvoiceNumberFromFilename('random.HTM'))->toBeNull();
    expect(callExtractInvoiceNumberFromFilename('INVOICE_001.htm'))->toBeNull();
    expect(callExtractInvoiceNumberFromFilename('Nr_PROabc_lv.HTM'))->toBeNull();
    expect(callExtractInvoiceNumberFromFilename(''))->toBeNull();
});

it('short-circuits parse when prior applied invoice exists (UI-09 happy path)', function (): void {
    $obPrior = seedPriorAppliedInvoice('PRO033328', iStockAdded: 105, iAppliedBy: 42);

    $arStaged = stageFixtureUpload('Nr_PRO033328_no_13042026.HTM');
    $obController = makeTestController(bHasPermission: true, arFiles: [$arStaged['file']]);
    $arResponse = $obController->onUpload();

    // Orchestrator was NEVER resolved — the dup-gate short-circuit returned
    // before processSingleUpload reached the parse path. Counter pin
    // proves the optimization (D-16) is honored: zero parser invocations,
    // zero file reads, zero transaction overhead.
    expect($obController->iOrchestratorResolvedCount)->toBe(0);

    // Reject partial captured prior invoice context.
    expect($arResponse)->toHaveKey('#invoiceRejectWrap');
    $arRejectCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/reject') {
            $arRejectCall = $arCall;
            break;
        }
    }
    expect($arRejectCall)->not->toBeNull();
    $arRejects = $arRejectCall['data']['rejects'];
    expect($arRejects)->toBeArray();
    expect(count($arRejects))->toBe(1);
    expect((string) $arRejects[0]['invoice_number'])->toBe('PRO033328');
    expect((int) $arRejects[0]['prior_invoice_id'])->toBe((int) $obPrior->id);
    expect((int) $arRejects[0]['prior_stock_added_units'])->toBe(105);
    expect((int) $arRejects[0]['prior_applied_by'])->toBe(42);

    // Only the seeded prior persists; no new invoice or lines were written.
    expect(Invoice::count())->toBe(1);
    expect(InvoiceLine::count())->toBe(0);

    @unlink($arStaged['path']);
});

it('short-circuits with parsed_pending reason when prior status is parsed (Option B)', function (): void {
    // Seed prior with status='parsed' — the parsed-status duplicate hole.
    // Pre-fix: gate did NOT short-circuit; orchestrator hit the UNIQUE index
    // and raw QueryException (with SQL fragments) leaked to the UI flash.
    // Post-fix (Option B): the gate short-circuits with reject_reason
    // `parsed_pending_apply` so the operator gets a friendly "apply or
    // discard before re-upload" panel and zero exception bubble-up.
    $obPrior = new Invoice();
    $obPrior->invoice_number = 'PRO033328';
    $obPrior->status = Invoice::STATUS_PARSED;
    $obPrior->total_lines = 21;
    $obPrior->matched_lines = 0;
    $obPrior->unmatched_lines = 21;
    $obPrior->stock_added_units = 0;
    $obPrior->initial_reset_applied = false;
    $obPrior->parsed_at = Carbon::now()->subHour();
    $obPrior->saveQuietly();

    $arStaged = stageFixtureUpload('Nr_PRO033328_no_13042026.HTM');
    $obController = makeTestController(bHasPermission: true, arFiles: [$arStaged['file']]);
    $obController->onUpload();

    // Orchestrator was NEVER resolved — the gate now short-circuits parsed
    // rows the same way it short-circuits applied ones. Counter pin proves
    // zero parser invocations, zero file reads, zero transaction overhead
    // and (the actual bug fix) zero QueryException bubble-up to the UI.
    expect($obController->iOrchestratorResolvedCount)->toBe(0);

    // Reject partial received exactly one entry with the parsed_pending
    // discriminator and the prior invoice id wired through for the
    // "Open existing parse" link in `_reject.htm`.
    $arRejectCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/reject') {
            $arRejectCall = $arCall;
            break;
        }
    }
    expect($arRejectCall)->not->toBeNull();
    $arRejects = $arRejectCall['data']['rejects'];
    expect($arRejects)->toBeArray();
    expect(count($arRejects))->toBe(1);
    expect((string) $arRejects[0]['reject_reason'])->toBe(Invoices::REJECT_REASON_PARSED_PENDING);
    expect((string) $arRejects[0]['invoice_number'])->toBe('PRO033328');
    expect((int) $arRejects[0]['prior_invoice_id'])->toBe((int) $obPrior->id);

    // Only the seeded prior persists; gate prevented any new write.
    expect(Invoice::count())->toBe(1);
    expect(InvoiceLine::count())->toBe(0);

    @unlink($arStaged['path']);
});

it('emits applied-duplicate reject_reason when prior status is applied', function (): void {
    $obPrior = seedPriorAppliedInvoice('PRO033328', iStockAdded: 7, iAppliedBy: 11);

    $arStaged = stageFixtureUpload('Nr_PRO033328_no_13042026.HTM');
    $obController = makeTestController(bHasPermission: true, arFiles: [$arStaged['file']]);
    $obController->onUpload();

    $arRejectCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/reject') {
            $arRejectCall = $arCall;
            break;
        }
    }
    expect($arRejectCall)->not->toBeNull();
    $arRejects = $arRejectCall['data']['rejects'];
    expect(count($arRejects))->toBe(1);
    expect((string) $arRejects[0]['reject_reason'])->toBe(Invoices::REJECT_REASON_DUPLICATE_APPLIED);
    expect((int) $arRejects[0]['prior_invoice_id'])->toBe((int) $obPrior->id);

    @unlink($arStaged['path']);
});

it('duplicate-check query filters on BOTH invoice_number AND status (sanity contract pin)', function (): void {
    seedPriorAppliedInvoice('PRO033328');

    // Capture executed SQL for the gate query.
    DB::enableQueryLog();
    DB::flushQueryLog();

    $arStaged = stageFixtureUpload('Nr_PRO033328_no_13042026.HTM');
    $obController = makeTestController(bHasPermission: true, arFiles: [$arStaged['file']]);
    $obController->onUpload();

    $arQueries = DB::getQueryLog();
    DB::disableQueryLog();

    // Find the gate query — first SELECT against the invoices table that
    // filters on both invoice_number and status.
    $bFoundGateQuery = false;
    foreach ($arQueries as $arEntry) {
        $sQuery = strtolower((string) $arEntry['query']);
        if (! str_contains($sQuery, 'logingrupa_goods_received_invoices')) {
            continue;
        }
        if (! str_starts_with(ltrim($sQuery), 'select')) {
            continue;
        }
        if (str_contains($sQuery, '"invoice_number"') && str_contains($sQuery, '"status"')) {
            $bFoundGateQuery = true;
            break;
        }
    }
    expect($bFoundGateQuery)->toBeTrue();

    @unlink($arStaged['path']);
});
