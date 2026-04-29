<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvoiceNumberMissingException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Parser\InvoiceNumberResolver;

uses(\Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase::class);

/**
 * Plan 02-04 Task 2: InvoiceNumberResolver real-fixture pin tests.
 *
 * Asserts (per CONTEXT.md D-18..D-20, plan behavior list):
 *   - Body marker resolution wins over filename (T-02-04-01 silent-swap guard)
 *   - All 3 hermetic fixtures resolve via body to canonical PRO<num> identifier
 *   - Filename fallback yields invoice_number + country_code + invoice_date
 *   - Country code is lowercased regardless of filename casing
 *   - Both-miss case throws InvoiceNumberMissingException with arContext flags
 *   - Malformed filename date (99999999) treated as filename miss → throw
 *
 * Hermetic note (D-32): tests read EXCLUSIVELY from `tests/fixtures/invoices/`
 * — never `<project_root>/storage/app/uploads/`.
 */

dataset('fixtures', [
    ['Nr_PRO026712_no_28112024.HTM', 'PRO026712'],
    ['Nr_PRO029691_no_09072025.HTM', 'PRO029691'],
    ['Nr_PRO033328_no_13042026.HTM', 'PRO033328'],
]);

it('extracts canonical number from body of all three real fixtures', function (string $sFilename, string $sExpected): void {
    $sFixturePath = __DIR__.'/../../fixtures/invoices/'.$sFilename;
    $sHtml = file_get_contents($sFixturePath);

    expect($sHtml)->not->toBeFalse();

    /** @var string $sHtml */
    $arResult = InvoiceNumberResolver::resolve($sHtml, $sFilename);

    expect($arResult['invoice_number'])->toBe($sExpected);
    expect($arResult['resolved_via'])->toBe('body');
})->with('fixtures');

it('extracts PRO033328 from real fixture body even with empty filename', function (): void {
    $sFixturePath = __DIR__.'/../../fixtures/invoices/Nr_PRO033328_no_13042026.HTM';
    $sHtml = file_get_contents($sFixturePath);

    expect($sHtml)->not->toBeFalse();

    /** @var string $sHtml */
    $arResult = InvoiceNumberResolver::resolve($sHtml, '');

    expect($arResult['invoice_number'])->toBe('PRO033328');
    expect($arResult['resolved_via'])->toBe('body');
    expect($arResult['country_code'])->toBeNull();
    expect($arResult['invoice_date'])->toBeNull();
});

it('populates country_code and invoice_date from filename even when body wins', function (): void {
    $sFixturePath = __DIR__.'/../../fixtures/invoices/Nr_PRO033328_no_13042026.HTM';
    $sHtml = file_get_contents($sFixturePath);

    expect($sHtml)->not->toBeFalse();

    /** @var string $sHtml */
    $arResult = InvoiceNumberResolver::resolve($sHtml, 'Nr_PRO033328_no_13042026.HTM');

    expect($arResult['resolved_via'])->toBe('body');
    expect($arResult['country_code'])->toBe('no');
    expect($arResult['invoice_date'])->not->toBeNull();
    expect($arResult['invoice_date']->format('Y-m-d'))->toBe('2026-04-13');
});

it('falls back to filename when body has no marker', function (): void {
    $arResult = InvoiceNumberResolver::resolve(
        '<html><body>no marker here</body></html>',
        'Nr_PRO033328_no_13042026.HTM',
    );

    expect($arResult['resolved_via'])->toBe('filename');
    expect($arResult['invoice_number'])->toBe('PRO033328');
    expect($arResult['country_code'])->toBe('no');
    expect($arResult['invoice_date']->format('Y-m-d'))->toBe('2026-04-13');
});

it('lowercases country code from uppercase filename', function (): void {
    $arResult = InvoiceNumberResolver::resolve('', 'Nr_PRO000001_NO_01012026.HTM');

    expect($arResult['country_code'])->toBe('no');
    expect($arResult['invoice_number'])->toBe('PRO000001');
});

it('throws InvoiceNumberMissingException when body and filename both miss', function (): void {
    try {
        InvoiceNumberResolver::resolve('', 'random_garbage.txt');
        $this->fail('expected InvoiceNumberMissingException not thrown');
    } catch (InvoiceNumberMissingException $obException) {
        expect($obException->arContext)->toMatchArray([
            'source_filename' => 'random_garbage.txt',
            'tried_body' => true,
            'tried_filename' => true,
        ]);
    }
});

it('throws when filename date is malformed (overflow guard)', function (): void {
    expect(fn () => InvoiceNumberResolver::resolve('', 'Nr_PRO000001_no_99999999.HTM'))
        ->toThrow(InvoiceNumberMissingException::class);
});

it('throws when filename pattern does not match at all', function (): void {
    expect(fn () => InvoiceNumberResolver::resolve('', 'invoice.htm'))
        ->toThrow(InvoiceNumberMissingException::class);
});

it('strips path components via basename in arContext', function (): void {
    try {
        InvoiceNumberResolver::resolve('', '/tmp/uploads/random_garbage.txt');
        $this->fail('expected InvoiceNumberMissingException not thrown');
    } catch (InvoiceNumberMissingException $obException) {
        expect($obException->arContext['source_filename'])->toBe('random_garbage.txt');
    }
});
