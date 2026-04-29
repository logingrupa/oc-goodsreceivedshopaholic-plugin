<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedInvoice;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvalidQuantityException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\MalformedHtmException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Parser\HtmInvoiceParser;

uses(\Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase::class);

/**
 * Plan 02-05 Task 2: HtmInvoiceParser real-fixture pin tests.
 *
 * Rolls up the QA-01 sub-cases into a single test file (per CONTEXT.md D-31):
 *   - HandlesUnquotedAttributesTest  — `<TR CLASS=R20>` UPPERCASE UNQUOTED parses
 *   - StripsBomBeforeParseTest       — leading UTF-8 BOM does not break loadHTML
 *   - HandlesBothR20AndR21RowsTest   — both row classes are extracted
 *   - HandlesCRLFLineEndingsTest     — CRLF and LF parse identically
 *   - RejectsMalformedHtmTest        — zero-rows / libxml-fatal → MalformedHtmException
 *
 * Plus two non-QA-01 invariants that round out coverage:
 *   - Invalid 13-digit EAN guard      — D-16 lenient: skip + record, never throw
 *   - Decimal qty bubble through      — QuantityNormalizer throws propagate, parser
 *                                       does not catch (T-02-05-05 mitigation proof)
 *
 * Hermetic note (D-32): tests read EXCLUSIVELY from `tests/fixtures/invoices/`.
 */

/**
 * Build a minimal HTML envelope containing the body invoice-number marker
 * (so InvoiceNumberResolver succeeds) plus the supplied data rows. Each
 * row spec is `[class, ean, qty]` with prices held constant — enough to
 * exercise the parser's row-level branches without dragging in fixture I/O.
 *
 * @param  list<array{class: string, ean: string, qty: string}>  $arRowSpecs
 */
function buildSyntheticInvoiceHtml(array $arRowSpecs, string $sInvoiceNumber = 'PRO999999'): string
{
    $sRows = '';

    foreach ($arRowSpecs as $iIndex => $arSpec) {
        $sRowIndex = (string) ($iIndex + 1);
        $sRows .= sprintf(
            '<TR CLASS=%s>'
            ."\n".'<TD CLASS="%sC0"><SPAN></SPAN></TD>'
            ."\n".'<TD CLASS="%sC1">%s</TD>'
            ."\n".'<TD CLASS="%sC2">%s</TD>'
            ."\n".'<TD CLASS="%sC2">Item %s</TD>'
            ."\n".'<TD CLASS="%sC1">PCE</TD>'
            ."\n".'<TD CLASS="%sC1">%s</TD>'
            ."\n".'<TD CLASS="%sC1">1,00</TD>'
            ."\n".'<TD CLASS="%sC1">0,00</TD>'
            ."\n".'<TD CLASS="%sC1">1,00</TD>'
            ."\n".'<TD CLASS="%sC9">%s,00</TD>'
            ."\n".'<TD><SPAN></SPAN></TD>'
            ."\n".'<TD></TD>'
            ."\n".'</TR>'."\n",
            $arSpec['class'],
            $arSpec['class'],
            $arSpec['class'], $sRowIndex,
            $arSpec['class'], $arSpec['ean'],
            $arSpec['class'], $sRowIndex,
            $arSpec['class'],
            $arSpec['class'], $arSpec['qty'],
            $arSpec['class'],
            $arSpec['class'],
            $arSpec['class'],
            $arSpec['class'], $arSpec['qty'],
        );
    }

    return <<<HTML
<HTML><BODY>
<P>Invoice No: {$sInvoiceNumber}</P>
<TABLE>
{$sRows}
</TABLE>
</BODY></HTML>
HTML;
}

it('HandlesUnquotedAttributesTest — parses real fixture with <TR CLASS=R20> unquoted attributes', function (): void {
    $sFixturePath = __DIR__.'/../../fixtures/invoices/Nr_PRO033328_no_13042026.HTM';
    $sHtml = file_get_contents($sFixturePath);

    expect($sHtml)->not->toBeFalse();

    /** @var string $sHtml */
    $obParsed = (new HtmInvoiceParser())->parse($sHtml, 'Nr_PRO033328_no_13042026.HTM');

    expect($obParsed)->toBeInstanceOf(ParsedInvoice::class);
    expect($obParsed->invoice_number)->toBe('PRO033328');
    expect(count($obParsed->lines))->toBe(25);
    expect($obParsed->lines[0]->row_index)->toBe(1);
    expect($obParsed->lines[0]->ean)->toBe('4752307000097');
    expect($obParsed->lines[0]->qty)->toBe(5);
    expect($obParsed->lines[0]->unit)->toBe('PCE');
    expect($obParsed->lines[0]->unit_price)->toBe(3.86);
});

it('StripsBomBeforeParseTest — double-BOM input still parses cleanly', function (): void {
    $sFixturePath = __DIR__.'/../../fixtures/invoices/Nr_PRO033328_no_13042026.HTM';
    $sFixture = file_get_contents($sFixturePath);

    expect($sFixture)->not->toBeFalse();
    /** @var string $sFixture */
    expect(substr($sFixture, 0, 3))->toBe("\xEF\xBB\xBF");

    $sDoubleBom = "\xEF\xBB\xBF".$sFixture;

    $obParsed = (new HtmInvoiceParser())->parse($sDoubleBom, 'Nr_PRO033328_no_13042026.HTM');

    expect($obParsed->invoice_number)->toBe('PRO033328');
    expect(count($obParsed->lines))->toBeGreaterThan(0);
    expect($obParsed->lines[0]->ean)->toBe('4752307000097');
});

it('HandlesBothR20AndR21RowsTest — extracts data from both R20 and R21 row classes', function (): void {
    $sHtml = buildSyntheticInvoiceHtml([
        ['class' => 'R20', 'ean' => '4752307000097', 'qty' => '3'],
        ['class' => 'R21', 'ean' => '4752307000165', 'qty' => '5'],
    ]);

    $obParsed = (new HtmInvoiceParser())->parse($sHtml, 'Nr_PRO999999_no_01012026.HTM');

    expect(count($obParsed->lines))->toBe(2);
    expect($obParsed->lines[0]->ean)->toBe('4752307000097');
    expect($obParsed->lines[0]->qty)->toBe(3);
    expect($obParsed->lines[1]->ean)->toBe('4752307000165');
    expect($obParsed->lines[1]->qty)->toBe(5);
});

it('HandlesBothR20AndR21RowsTest — real fixture with mixed R20/R21 yields combined row count', function (): void {
    $sFixturePath = __DIR__.'/../../fixtures/invoices/Nr_PRO026712_no_28112024.HTM';
    $sHtml = file_get_contents($sFixturePath);

    expect($sHtml)->not->toBeFalse();

    /** @var string $sHtml */
    $obParsed = (new HtmInvoiceParser())->parse($sHtml, 'Nr_PRO026712_no_28112024.HTM');

    // Real fixture has 147 R20 + 7 R21 = 154 data rows.
    expect(count($obParsed->lines))->toBe(154);
    expect($obParsed->invoice_number)->toBe('PRO026712');
});

it('HandlesCRLFLineEndingsTest — CRLF and LF inputs yield identical parse results', function (): void {
    $sFixturePath = __DIR__.'/../../fixtures/invoices/Nr_PRO033328_no_13042026.HTM';
    $sCrlf = file_get_contents($sFixturePath);

    expect($sCrlf)->not->toBeFalse();
    /** @var string $sCrlf */
    expect(str_contains($sCrlf, "\r\n"))->toBeTrue();

    $sLf = str_replace("\r\n", "\n", $sCrlf);

    $obFromCrlf = (new HtmInvoiceParser())->parse($sCrlf, 'Nr_PRO033328_no_13042026.HTM');
    $obFromLf = (new HtmInvoiceParser())->parse($sLf, 'Nr_PRO033328_no_13042026.HTM');

    expect(count($obFromCrlf->lines))->toBe(count($obFromLf->lines));
    expect($obFromCrlf->lines[0]->ean)->toBe($obFromLf->lines[0]->ean);
    expect($obFromCrlf->lines[0]->qty)->toBe($obFromLf->lines[0]->qty);
});

it('RejectsMalformedHtmTest — HTML without any R20/R21 rows throws MalformedHtmException', function (): void {
    $sHtml = '<HTML><BODY><P>Invoice No: PRO000001</P><P>No data rows here.</P></BODY></HTML>';

    expect(fn () => (new HtmInvoiceParser())->parse($sHtml, 'Nr_PRO000001_no_01012026.HTM'))
        ->toThrow(MalformedHtmException::class);
});

it('skips row with invalid 12-digit EAN without throwing (D-16 lenient)', function (): void {
    $sHtml = buildSyntheticInvoiceHtml([
        ['class' => 'R20', 'ean' => '123', 'qty' => '5'],
    ]);

    $obParsed = (new HtmInvoiceParser())->parse($sHtml, 'Nr_PRO999999_no_01012026.HTM');

    expect(count($obParsed->lines))->toBe(0);
    expect(count($obParsed->skipped_rows))->toBe(1);
    expect($obParsed->skipped_rows[0]['reason'])->toBe('invalid_ean');
    expect($obParsed->skipped_rows[0]['row_index'])->toBe(1);
});

it('throws InvalidQuantityException on decimal qty in a row (T-02-05-05 bubble-through)', function (): void {
    $sHtml = buildSyntheticInvoiceHtml([
        ['class' => 'R20', 'ean' => '4752307000097', 'qty' => '5,12'],
    ]);

    expect(fn () => (new HtmInvoiceParser())->parse($sHtml, 'Nr_PRO999999_no_01012026.HTM'))
        ->toThrow(InvalidQuantityException::class);
});
