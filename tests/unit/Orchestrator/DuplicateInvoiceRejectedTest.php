<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\DuplicateInvoiceException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ApplyOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ParseAndPersistOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;

require_once __DIR__.'/../Apply/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * QA-03 DuplicateInvoiceRejectedTest (plan 03-07 task 2).
 *
 * Wires ParseAndPersistOrchestrator + ApplyOrchestrator end-to-end and pins
 * the duplicate-rejection contract: re-parsing an already-applied HTM
 * (same invoice_number) MUST throw DuplicateInvoiceException at parse time.
 *
 * The parse-side duplicate detection is also covered by plan 03-06's
 * ParseAndPersistOrchestratorTest, but THIS test runs the full pipeline:
 * parse → apply → re-parse-same-htm → reject. That guarantees the apply
 * status flip from plan 03-07 actually propagates into the duplicate gate
 * the parser orchestrator reads (status='applied' is the rejection trigger
 * per ParseAndPersistOrchestrator::assertNotDuplicate).
 *
 * Threat coverage: T-03-07-01 (duplicate stock writes from re-uploading
 * the same HTM after Apply — gated at parse time, never reaches Apply).
 */
function readGoodsReceivedFixtureForDup(string $sFixture = 'Nr_PRO033328_no_13042026.HTM'): string
{
    $sPath = __DIR__.'/../../fixtures/invoices/'.$sFixture;
    $sHtml = file_get_contents($sPath);
    if ($sHtml === false) {
        throw new RuntimeException('Could not read fixture: '.$sPath);
    }

    return $sHtml;
}

it('re-parsing an already-applied invoice rejects with DuplicateInvoiceException (QA-03 DuplicateInvoiceRejectedTest)', function (): void {
    $sHtml = readGoodsReceivedFixtureForDup();
    $sFilename = 'Nr_PRO033328_no_13042026.HTM';

    $obParse = new ParseAndPersistOrchestrator();
    $obApply = new ApplyOrchestrator();

    // Round 1: parse + apply.
    $obInvoice = $obParse->run($sHtml, $sFilename, iAppliedByUserId: 1);
    expect($obInvoice)->toBeInstanceOf(Invoice::class);
    expect((string) $obInvoice->status)->toBe(Invoice::STATUS_PARSED);

    $obApply->apply((int) $obInvoice->id, iAppliedByUserId: 1);

    $obAppliedInvoice = Invoice::find($obInvoice->id);
    expect($obAppliedInvoice)->not->toBeNull();
    expect((string) $obAppliedInvoice->status)->toBe(Invoice::STATUS_APPLIED);

    // Round 2: re-parse same HTM → must throw DuplicateInvoiceException.
    $obException = null;
    try {
        $obParse->run($sHtml, $sFilename, iAppliedByUserId: 1);
    } catch (DuplicateInvoiceException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    expect($obException)->toBeInstanceOf(DuplicateInvoiceException::class);

    // Context preserves prior-apply trace for the Phase 4 UX.
    expect($obException->arContext)->toHaveKey('invoice_number');
    expect($obException->arContext)->toHaveKey('prior_invoice_id');
    expect($obException->arContext)->toHaveKey('prior_applied_at');
    expect($obException->arContext)->toHaveKey('prior_applied_by');

    // Sanity: only the original Invoice row exists; the re-parse rolled back.
    expect(Invoice::count())->toBe(1);
});
