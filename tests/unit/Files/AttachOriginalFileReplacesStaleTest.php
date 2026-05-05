<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Logingrupa\GoodsReceivedShopaholic\Controllers\Invoices;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;

require_once __DIR__.'/FilesTestCase.php';

uses(FilesTestCase::class);

/**
 * Pin the BUG-fix contract for `Invoices::attachOriginalFile()`:
 * a pre-existing `original_file` attachment MUST be detached + deleted
 * BEFORE a new file is attached. The previous code returned early on
 * non-null relation, which let an orphan attachment masquerade as the
 * Invoice's source file after auto-increment recycled an id.
 *
 * The test exercises only the protected `detachExistingOriginalFile`
 * helper via Reflection so it does not depend on the disk-write side
 * of `System\Models\File::fromPost()`. The `add()` step is covered by
 * October's existing `attachOne` test surface.
 *
 * Test isolation:
 *   - The Backend\Classes\Controller constructor pulls the auth manager
 *     and registers asset paths — neither needs to run for this seam.
 *     We therefore instantiate via `ReflectionClass::newInstanceWithoutConstructor`.
 */
function callDetachExisting(Invoices $obController, Invoice $obInvoice): void
{
    $obReflectionMethod = new \ReflectionMethod(Invoices::class, 'detachExistingOriginalFile');
    $obReflectionMethod->setAccessible(true);
    $obReflectionMethod->invoke($obController, $obInvoice);
}

function makeBareInvoicesController(): Invoices
{
    $obReflectionClass = new \ReflectionClass(Invoices::class);
    /** @var Invoices $obController */
    $obController = $obReflectionClass->newInstanceWithoutConstructor();

    return $obController;
}

it('deletes the existing system_files row when an original_file is already attached', function (): void {
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = 'PRO-REATTACH-1';
    $obInvoice->status = Invoice::STATUS_PARSED;
    $obInvoice->save();

    $iStaleId = DB::table('system_files')->insertGetId([
        'disk_name'       => '69eee0000000000000000001.htm',
        'file_name'       => 'stale.htm',
        'file_size'       => 100,
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

    callDetachExisting(makeBareInvoicesController(), $obInvoice);

    expect(DB::table('system_files')->where('id', $iStaleId)->count())->toBe(0);
});

it('logs a warning that names the reaped disk_name and file_name', function (): void {
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = 'PRO-REATTACH-2';
    $obInvoice->status = Invoice::STATUS_PARSED;
    $obInvoice->save();

    DB::table('system_files')->insert([
        'disk_name'       => '69eee0000000000000000002.htm',
        'file_name'       => 'stale-named.htm',
        'file_size'       => 200,
        'content_type'    => 'text/html',
        'field'           => 'original_file',
        'attachment_type' => Invoice::class,
        'attachment_id'   => (int) $obInvoice->id,
        'is_public'       => true,
        'sort_order'      => 0,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $arLogged = [];
    Log::listen(function ($mLevel, $sMessage = null, $arContext = []) use (&$arLogged): void {
        // October ships a back-compat shim so $mLevel may be a MessageLogged
        // object on some bootstraps and a string on others.
        if (is_object($mLevel) && property_exists($mLevel, 'level')) {
            $arLogged[] = ['level' => $mLevel->level, 'message' => $mLevel->message, 'context' => $mLevel->context];

            return;
        }

        $arLogged[] = ['level' => $mLevel, 'message' => $sMessage, 'context' => $arContext];
    });

    callDetachExisting(makeBareInvoicesController(), $obInvoice);

    $arWarnings = array_values(array_filter(
        $arLogged,
        static fn (array $arEntry): bool => ($arEntry['level'] ?? null) === 'warning',
    ));

    expect($arWarnings)->not->toBe([]);
    $arHit = $arWarnings[0];
    expect($arHit['message'])->toContain('replacing stale original_file before attach');
    expect($arHit['context']['old_disk_name'] ?? '')->toBe('69eee0000000000000000002.htm');
    expect($arHit['context']['old_file_name'] ?? '')->toBe('stale-named.htm');
});

it('is a safe no-op when the Invoice has no attached file', function (): void {
    $obInvoice = new Invoice();
    $obInvoice->invoice_number = 'PRO-REATTACH-3';
    $obInvoice->status = Invoice::STATUS_PARSED;
    $obInvoice->save();

    expect($obInvoice->original_file)->toBeNull();
    expect(fn () => callDetachExisting(makeBareInvoicesController(), $obInvoice))->not->toThrow(\Throwable::class);
});
