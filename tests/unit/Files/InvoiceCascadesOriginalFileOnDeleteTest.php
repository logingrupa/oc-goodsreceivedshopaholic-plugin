<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;

require_once __DIR__.'/FilesTestCase.php';

uses(FilesTestCase::class);

/**
 * Bug-fix coverage — `Invoice::beforeDelete` cascades the `original_file`
 * polymorphic attachment.
 *
 * Counter-example pinned by this test: previously, deleting an Invoice
 * left an orphan row in `system_files`. If auto-increment later recycled
 * the freed Invoice id, a fresh Invoice silently inherited the orphan
 * file via the morph relation — and `attachOriginalFile()` refused to
 * overwrite. The download button on the new Invoice's detail page then
 * served a file unrelated to the parsed line set (real-world divergence
 * observed on production: invoice id=2 shipped PRO033328 contents while
 * row metadata claimed PRO026712).
 */

it('deletes the polymorphic system_files row when the Invoice is deleted', function (): void {
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = 'PRO-CASCADE-1';
    $obInvoice->status = Invoice::STATUS_PARSED;
    $obInvoice->save();

    DB::table('system_files')->insert([
        'disk_name'       => '69aaa0000000000000000001.htm',
        'file_name'       => 'Nr_PRO-CASCADE-1.HTM',
        'file_size'       => 1024,
        'content_type'    => 'text/html',
        'field'           => 'original_file',
        'attachment_type' => Invoice::class,
        'attachment_id'   => (int) $obInvoice->id,
        'is_public'       => true,
        'sort_order'      => 0,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    expect($obInvoice->original_file)->not->toBeNull();

    $obInvoice->delete();

    $iRemaining = DB::table('system_files')
        ->where('attachment_type', Invoice::class)
        ->where('attachment_id', (int) $obInvoice->id)
        ->count();

    expect($iRemaining)->toBe(0);
});

it('is a safe no-op when the Invoice has no attached file', function (): void {
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = 'PRO-CASCADE-2';
    $obInvoice->status = Invoice::STATUS_PARSED;
    $obInvoice->save();

    expect(fn () => $obInvoice->delete())->not->toThrow(\Throwable::class);
});
