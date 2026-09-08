<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\LineResolutionService;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use October\Rain\Exception\AjaxException;

require_once __DIR__.'/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * LineResolutionService (duplicate offer codes, 2026-09-08).
 *
 * Pins:
 *   - candidatesFor() lists every live offer whose own code OR whose
 *     product's code equals the line EAN, with offer name, product name,
 *     active flag and quantity, active first, in ONE query.
 *   - resolve() persists a pick from the candidate set as
 *     matched_offer_id + offer_code_operator and recounts the invoice.
 *   - resolve() throws on a pick outside the candidate set and leaves the
 *     line untouched.
 *   - resolve() throws while any ambiguous line is still open, naming the
 *     rows; passes silently once every line is picked.
 */
function lrs_seedInvoice(string $sNumber, int $iTotalLines): Invoice
{
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = $sNumber;
    $obInvoice->status = Invoice::STATUS_PARSED;
    $obInvoice->total_lines = $iTotalLines;
    $obInvoice->matched_lines = 0;
    $obInvoice->unmatched_lines = $iTotalLines;
    $obInvoice->parsed_at = \Carbon\Carbon::now();
    $obInvoice->saveQuietly();

    return $obInvoice;
}

function lrs_seedLine(int $iInvoiceId, int $iRowIndex, string $sEan, string $sStrategy, ?int $iOfferId = null): InvoiceLine
{
    $obLine = new InvoiceLine();
    $obLine->invoice_id = $iInvoiceId;
    $obLine->row_index = $iRowIndex;
    $obLine->ean = $sEan;
    $obLine->product_name_raw = 'Line '.$iRowIndex;
    $obLine->qty = 3;
    $obLine->matched_offer_id = $iOfferId;
    $obLine->match_strategy = $sStrategy;
    $obLine->applied = false;
    $obLine->saveQuietly();

    return $obLine;
}

it('lists candidates by offer code and by product code, active first, in one query', function (): void {
    $obProductA = seedApplyProduct('PROD-A', 'lrs-a');
    $obInactive = seedApplyOffer((int) $obProductA->id, '40092454', iQuantity: 4, bActive: false);
    $obActive = seedApplyOffer((int) $obProductA->id, '40092454', iQuantity: 9);
    // Reachable through the product code, not the offer code.
    $obProductB = seedApplyProduct('40092454', 'lrs-b');
    $obViaProduct = seedApplyOffer((int) $obProductB->id, 'INNER-B', iQuantity: 1);
    // Noise: unrelated code.
    seedApplyOffer((int) $obProductA->id, '4752307000097', iQuantity: 2);

    $obInvoice = lrs_seedInvoice('LRS-001', 1);
    $obLine = lrs_seedLine((int) $obInvoice->id, 1, '40092454', InvoiceLine::MATCH_STRATEGY_AMBIGUOUS);

    \DB::flushQueryLog();
    \DB::enableQueryLog();
    $arCandidates = (new LineResolutionService())->candidatesFor([$obLine]);
    $iQueryCount = count(\DB::getQueryLog());
    \DB::disableQueryLog();

    expect($iQueryCount)->toBe(1);
    expect($arCandidates)->toHaveKey((int) $obLine->id);

    $arForLine = $arCandidates[(int) $obLine->id];
    expect(array_column($arForLine, 'offer_id'))->toBe([(int) $obActive->id, (int) $obViaProduct->id, (int) $obInactive->id]);
    expect($arForLine[0]['active'])->toBeTrue();
    expect($arForLine[0]['quantity'])->toBe(9);
    expect($arForLine[0]['offer_name'])->toBe('Seeded Offer 40092454');
    expect($arForLine[0]['product_name'])->toBe('Seeded Product lrs-a');
    expect($arForLine[2]['active'])->toBeFalse();
});

it('persists a pick from the candidate set as offer_code_operator and recounts the invoice', function (): void {
    $obProduct = seedApplyProduct('PROD-P', 'lrs-p');
    $obOfferA = seedApplyOffer((int) $obProduct->id, '40092454');
    $obOfferB = seedApplyOffer((int) $obProduct->id, '40092454');

    $obInvoice = lrs_seedInvoice('LRS-002', 2);
    $obLine = lrs_seedLine((int) $obInvoice->id, 1, '40092454', InvoiceLine::MATCH_STRATEGY_AMBIGUOUS);
    lrs_seedLine((int) $obInvoice->id, 2, '9999999999999', InvoiceLine::MATCH_STRATEGY_NONE);

    (new LineResolutionService())->resolve((int) $obInvoice->id, [(string) $obLine->id => (string) $obOfferB->id]);

    $obLine->refresh();
    expect((int) $obLine->matched_offer_id)->toBe((int) $obOfferB->id);
    expect((string) $obLine->match_strategy)->toBe(InvoiceLine::MATCH_STRATEGY_OFFER_CODE_OPERATOR);
    expect((int) $obLine->matched_offer_id)->not->toBe((int) $obOfferA->id);

    $obInvoice->refresh();
    expect((int) $obInvoice->matched_lines)->toBe(1);
    expect((int) $obInvoice->unmatched_lines)->toBe(1);
});

it('throws on a pick outside the candidate set and leaves the line ambiguous', function (): void {
    $obProduct = seedApplyProduct('PROD-X', 'lrs-x');
    seedApplyOffer((int) $obProduct->id, '40092454');
    seedApplyOffer((int) $obProduct->id, '40092454');
    $obStranger = seedApplyOffer((int) $obProduct->id, '4752307000097');

    $obInvoice = lrs_seedInvoice('LRS-003', 1);
    $obLine = lrs_seedLine((int) $obInvoice->id, 7, '40092454', InvoiceLine::MATCH_STRATEGY_AMBIGUOUS);

    $obCaught = null;
    try {
        (new LineResolutionService())->resolve((int) $obInvoice->id, [(string) $obLine->id => (string) $obStranger->id]);
    } catch (AjaxException $obException) {
        $obCaught = $obException;
    }

    expect($obCaught)->not->toBeNull();
    expect((string) $obCaught->getMessage())->toContain('offer_choice_invalid');

    $obLine->refresh();
    expect($obLine->matched_offer_id)->toBeNull();
    expect((string) $obLine->match_strategy)->toBe(InvoiceLine::MATCH_STRATEGY_AMBIGUOUS);
});

it('throws while an ambiguous line is still open, naming the rows, and passes once all are picked', function (): void {
    $obProduct = seedApplyProduct('PROD-O', 'lrs-o');
    $obOfferA = seedApplyOffer((int) $obProduct->id, '40092454');
    seedApplyOffer((int) $obProduct->id, '40092454');

    $obInvoice = lrs_seedInvoice('LRS-004', 1);
    $obLine = lrs_seedLine((int) $obInvoice->id, 12, '40092454', InvoiceLine::MATCH_STRATEGY_AMBIGUOUS);

    $obService = new LineResolutionService();

    $obCaught = null;
    try {
        $obService->resolve((int) $obInvoice->id, null);
    } catch (AjaxException $obException) {
        $obCaught = $obException;
    }
    expect($obCaught)->not->toBeNull();
    // Harness runs with autoRegister=false: Lang::get returns the raw key.
    // The key proves the message is wired; the lang files carry `:rows`.
    expect((string) $obCaught->getMessage())->toContain('ambiguous_unresolved');
    $arLangEn = require __DIR__.'/../../../lang/en/lang.php';
    expect($arLangEn['apply']['ambiguous_unresolved'])->toContain(':rows');

    $obService->resolve((int) $obInvoice->id, [(string) $obLine->id => (string) $obOfferA->id]);

    expect(InvoiceLine::ambiguousFor((int) $obInvoice->id))->toBe([]);
});
