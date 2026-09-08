<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase;

/**
 * Render tests for `_partials/_unmatched_lines.htm` (UAT 2026-09-08: one
 * unmatched row among 45 was invisible, the operator only saw a count).
 *
 * The partial is shared by the upload apply modal, the apply confirm modal
 * and the invoice summary block, so it is pinned once here. Hermetic: no DB,
 * InvoiceLine stubs are plain attribute bags. `trans()` under the test
 * harness returns the raw lang key (plugin namespace not registered), which
 * doubles as proof the heading key is wired.
 */
uses(GoodsReceivedTestCase::class);

/**
 * @param  list<InvoiceLine>  $arLines
 */
function ulp_renderPartial(array $arLines): string
{
    /** @noinspection PhpUnusedLocalVariableInspection */
    $lines = $arLines; // partial reads `$lines`.

    ob_start();
    require __DIR__.'/../../../controllers/invoices/_partials/_unmatched_lines.htm';

    return (string) ob_get_clean();
}

function ulp_makeLine(int $iRowIndex, string $sEan, string $sName, int $iQty): InvoiceLine
{
    $obLine = new InvoiceLine();
    $obLine->row_index = $iRowIndex;
    $obLine->ean = $sEan;
    $obLine->product_name_raw = $sName;
    $obLine->qty = $iQty;
    $obLine->match_strategy = InvoiceLine::MATCH_STRATEGY_NONE;

    return $obLine;
}

it('names every unmatched line by row number, EAN, product and qty under the lang heading', function (): void {
    $sOutput = ulp_renderPartial([
        ulp_makeLine(38, '4752307000523', 'WANTED Q5 UV/LED Būvējošais gels, 5ml, Caramel Silk', 2),
        ulp_makeLine(41, '4751039489330', 'Bubble <Gum>', 7),
    ]);

    expect($sOutput)->toContain('unmatched_heading');
    expect($sOutput)->toContain('#38');
    expect($sOutput)->toContain('<code>4752307000523</code>');
    expect($sOutput)->toContain('Caramel Silk');
    expect($sOutput)->toContain('(2)');
    expect($sOutput)->toContain('#41');
    expect($sOutput)->toContain('<code>4751039489330</code>');
    expect($sOutput)->toContain('Bubble &lt;Gum&gt;');
    expect($sOutput)->toContain('(7)');
});

it('renders nothing when there are no unmatched lines', function (): void {
    expect(trim(ulp_renderPartial([])))->toBe('');
});
