<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\DuplicateInvoiceException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\MalformedHtmException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ParseAndPersistOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;

require_once __DIR__.'/../Apply/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * Plan 03-06 — ParseAndPersistOrchestrator unit tests (APPLY-06 / APPLY-08).
 *
 * Pins the load-bearing invariants:
 *   1) Happy path — parse a real fixture → persist Invoice@status=parsed +
 *      one InvoiceLine row per parsed line (21 for Nr_PRO033328).
 *   2) Duplicate detection — pre-applied invoice with the same number causes
 *      DuplicateInvoiceException with prior-apply context.
 *   3) Override-reimport — runOverride() creates a NEW Invoice with
 *      override_of_invoice_id pointer + suffixed invoice_number; never
 *      throws DuplicateInvoiceException.
 *   4) Parse failure rolls back — malformed HTM aborts the transaction;
 *      neither Invoice nor InvoiceLine rows survive.
 *   5) Reject is logged AFTER rollback — Log::warning fires post-rollback so
 *      the audit trail records the failure, not a half-committed state.
 */
function readGoodsReceivedFixture(string $sFixture = 'Nr_PRO033328_no_13042026.HTM'): string
{
    $sPath = __DIR__.'/../../fixtures/invoices/'.$sFixture;
    $sHtml = file_get_contents($sPath);
    if ($sHtml === false) {
        throw new RuntimeException('Could not read fixture: '.$sPath);
    }

    return $sHtml;
}

function makePriorAppliedInvoice(string $sNumber = 'PRO033328'): Invoice
{
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = $sNumber;
    $obInvoice->status = Invoice::STATUS_APPLIED;
    $obInvoice->total_lines = 21;
    $obInvoice->matched_lines = 0;
    $obInvoice->unmatched_lines = 21;
    $obInvoice->stock_added_units = 0;
    $obInvoice->initial_reset_applied = false;
    $obInvoice->applied_at = \Carbon\Carbon::now();
    $obInvoice->applied_by_user_id = 99;
    $obInvoice->saveQuietly();

    return $obInvoice;
}

it('persists Invoice@status=parsed and 21 InvoiceLine rows for a valid HTM (APPLY-06 happy path)', function (): void {
    $sHtml = readGoodsReceivedFixture();

    $obOrchestrator = new ParseAndPersistOrchestrator();
    $obInvoice = $obOrchestrator->run($sHtml, 'Nr_PRO033328_no_13042026.HTM', iAppliedByUserId: 1);

    expect($obInvoice)->toBeInstanceOf(Invoice::class);
    expect((string) $obInvoice->invoice_number)->toBe('PRO033328');
    expect((string) $obInvoice->status)->toBe(Invoice::STATUS_PARSED);
    expect((int) $obInvoice->total_lines)->toBe(21);
    expect((int) $obInvoice->matched_lines)->toBe(0);
    expect((int) $obInvoice->unmatched_lines)->toBe(21);

    expect(Invoice::count())->toBe(1);
    expect(InvoiceLine::where('invoice_id', $obInvoice->id)->count())->toBe(21);

    $obFirstLine = InvoiceLine::where('invoice_id', $obInvoice->id)->orderBy('row_index')->first();
    expect($obFirstLine)->not->toBeNull();
    expect((string) $obFirstLine->ean)->toBe('4752307000097');
    expect((int) $obFirstLine->qty)->toBe(5);
    expect((string) $obFirstLine->match_strategy)->toBe('none');
    expect((bool) $obFirstLine->applied)->toBeFalse();
});

it('throws DuplicateInvoiceException when invoice_number is already applied (APPLY-06 dup check)', function (): void {
    $obPrior = makePriorAppliedInvoice('PRO033328');
    $sHtml = readGoodsReceivedFixture();

    $obOrchestrator = new ParseAndPersistOrchestrator();
    $obException = null;

    try {
        $obOrchestrator->run($sHtml, 'Nr_PRO033328_no_13042026.HTM', iAppliedByUserId: 1);
    } catch (DuplicateInvoiceException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect($obException)->toBeInstanceOf(DuplicateInvoiceException::class);
    expect($obException->arContext)->toHaveKey('invoice_number');
    expect($obException->arContext['invoice_number'])->toBe('PRO033328');
    expect($obException->arContext)->toHaveKey('prior_invoice_id');
    expect((int) $obException->arContext['prior_invoice_id'])->toBe((int) $obPrior->id);
    expect($obException->arContext)->toHaveKey('prior_applied_at');
    expect($obException->arContext)->toHaveKey('prior_applied_by');

    // Only the pre-seeded prior invoice persists — orchestrator's tx rolled back.
    expect(Invoice::count())->toBe(1);
    expect(InvoiceLine::count())->toBe(0);
});

it('runOverride creates a NEW invoice with override_of_invoice_id pointer, NO duplicate-exception (APPLY-08 D-27)', function (): void {
    $obPrior = makePriorAppliedInvoice('PRO033328');
    $sHtml = readGoodsReceivedFixture();

    $obOrchestrator = new ParseAndPersistOrchestrator();
    $obNew = $obOrchestrator->runOverride(
        $sHtml,
        'Nr_PRO033328_no_13042026.HTM',
        iPriorInvoiceId: (int) $obPrior->id,
        iAppliedByUserId: 1,
    );

    expect($obNew)->toBeInstanceOf(Invoice::class);
    expect((int) $obNew->id)->not->toBe((int) $obPrior->id);
    expect((string) $obNew->status)->toBe(Invoice::STATUS_PARSED);
    expect((int) $obNew->override_of_invoice_id)->toBe((int) $obPrior->id);
    expect((string) $obNew->invoice_number)->toBe('PRO033328-OVR-'.(int) $obPrior->id);

    expect(Invoice::count())->toBe(2);
    expect(InvoiceLine::where('invoice_id', $obNew->id)->count())->toBe(21);
});

it('parse failure rolls back the transaction — no Invoice or InvoiceLine row remains (D-22 atomicity)', function (): void {
    // Filename carries a PRO number so InvoiceNumberResolver succeeds via
    // filename fallback; HTML body has zero R20/R21 rows + zero header tags
    // → HtmInvoiceParser throws MalformedHtmException with reason
    // 'no_rows_extracted' (D-17).
    $sHtmlBroken = '<html><body>nothing here, zero rows</body></html>';

    $obOrchestrator = new ParseAndPersistOrchestrator();

    expect(fn () => $obOrchestrator->run($sHtmlBroken, 'Nr_PRO000001_no_01012026.HTM', iAppliedByUserId: 1))
        ->toThrow(MalformedHtmException::class);

    expect(Invoice::count())->toBe(0);
    expect(InvoiceLine::count())->toBe(0);
});

it('logs reject via ImportAuditService AFTER tx rollback on parse failure (boundary contract)', function (): void {
    Log::spy();

    // Same shape as the rollback test — filename PRO-number unblocks the
    // resolver so the failure surfaces as MalformedHtmException at the
    // row-extraction step (the failure boundary plan 03-06 contracts on).
    $sHtmlBroken = '<html><body>nothing here, zero rows</body></html>';
    $sFilename = 'Nr_PRO000002_no_01012026.HTM';

    $obOrchestrator = new ParseAndPersistOrchestrator();

    try {
        $obOrchestrator->run($sHtmlBroken, $sFilename, iAppliedByUserId: 1);
    } catch (MalformedHtmException $obCaught) {
        // Expected — assert below that the reject was logged.
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $sMessage, array $arContext) use ($sFilename): bool {
            if ($sMessage !== 'goodsreceived.reject') {
                return false;
            }
            if (($arContext['source_filename'] ?? null) !== $sFilename) {
                return false;
            }
            if (($arContext['mode'] ?? null) !== 'normal') {
                return false;
            }

            return ($arContext['event'] ?? null) === 'reject';
        })
        ->once();

    // Sanity: rollback held — no Invoice rows committed.
    expect(Invoice::count())->toBe(0);
});
