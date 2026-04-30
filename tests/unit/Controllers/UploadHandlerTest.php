<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use Symfony\Component\HttpFoundation\File\UploadedFile;

require_once __DIR__.'/../Apply/ApplyTestCase.php';
require_once __DIR__.'/InvoiceUploadTestHelpers.php';

uses(ApplyTestCase::class);

/**
 * UI-02 / UI-03 / UI-07 / UI-09 — D-05..D-07 / D-16 / D-17 / D-44.
 *
 * Plan 04-04 Task 1: onUpload AJAX handler — extension/size whitelist,
 * multi-file foreach, per-file boundary catch + error aggregation.
 *
 * Tiger-Style invariants pinned here:
 *   - Server-side accept filter: extension whitelist (.htm only) + 10 MB
 *     size cap. Per-file boundary checks. Failure of one file does NOT abort
 *     the batch; the foreach catches typed plugin exceptions and Throwable
 *     and pushes per-file error rows for the operator to see.
 *   - Permission gate: `BackendAuth::userHasAccess('logingrupa.goodsreceived.upload_invoices')`
 *     at handler entry; deny ⇒ AjaxException (HTTP 406 in production, throws
 *     here in test context).
 *
 * Test seams (TestableInvoices shim) live in InvoiceUploadTestHelpers.php;
 * see that file's header for the boundary-mock rationale (mirrors
 * D-03-07-01 + D-04-02-01 precedent).
 */

it('rejects request without upload_invoices permission (D-44 / T-04-04-06)', function (): void {
    $obController = makeTestController(bHasPermission: false, arFiles: null);
    $obException = null;
    try {
        $obController->onUpload();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect($arContents)->toBeArray();
    expect($arContents)->toHaveKey('message');
    expect((string) $arContents['message'])->not->toBe('');
    expect(Invoice::count())->toBe(0);
    expect($obController->arPermissionsChecked)->toBe(['logingrupa.goodsreceived.upload_invoices']);
});

it('parses + persists a valid HTM file and renders preview partial (UI-02 happy path)', function (): void {
    $arStaged = stageFixtureUpload('Nr_PRO033328_no_13042026.HTM');
    $obController = makeTestController(bHasPermission: true, arFiles: [$arStaged['file']]);
    $arResponse = $obController->onUpload();

    expect($arResponse)->toBeArray();
    expect($arResponse)->toHaveKey('#invoicePreviewWrap');
    expect($arResponse)->toHaveKey('#invoiceRejectWrap');
    expect($arResponse)->toHaveKey('#invoiceUploadErrors');
    // UX redesign 2026-04-30: response carries `result` key with the
    // rendered apply modal markup. Upload form's
    // `data-request-success="$.popup({ content: data.result })"` opens the
    // popup overlay automatically when this key is non-empty.
    expect($arResponse)->toHaveKey('result');
    expect((string) $arResponse['result'])->toContain('_partials/apply_modal');
    expect((string) $arResponse['#invoicePreviewWrap'])->toContain('_partials/preview_lines');

    // Real Invoice was persisted via ParseAndPersistOrchestrator.
    expect(Invoice::count())->toBe(1);
    expect((string) Invoice::query()->first()?->invoice_number)->toBe('PRO033328');
    expect(InvoiceLine::count())->toBe(21);

    // Preview partial received a non-empty `invoices` array.
    $arPreviewCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/preview_lines') {
            $arPreviewCall = $arCall;
            break;
        }
    }
    expect($arPreviewCall)->not->toBeNull();
    expect($arPreviewCall['data'])->toHaveKey('invoices');
    expect($arPreviewCall['data']['invoices'])->toBeArray();
    expect(count($arPreviewCall['data']['invoices']))->toBe(1);

    @unlink($arStaged['path']);
});

it('attaches the uploaded HTM to Invoice.original_file (BUG 4 — UI-06 / D-28)', function (): void {
    // BUG 4 fix: onUpload happy path MUST call attachOriginalFile so the
    // detail page's File widget surfaces the source artefact for audit /
    // re-parse. The production implementation calls
    // `$obInvoice->original_file()->add(System\Models\File::fromPost($obFile))`
    // — this test pins the seam invocation contract via the TestableInvoices
    // shim's `arAttachOriginalFileCalls` recorder (the actual
    // System.File / disk write is excluded from the hermetic schema slice).
    $arStaged = stageFixtureUpload('Nr_PRO033328_no_13042026.HTM');
    $obController = makeTestController(bHasPermission: true, arFiles: [$arStaged['file']]);

    expect($obController->arAttachOriginalFileCalls)->toBe([]);

    $obController->onUpload();

    expect($obController->arAttachOriginalFileCalls)->toBeArray();
    expect(count($obController->arAttachOriginalFileCalls))->toBe(1);

    $arCall = $obController->arAttachOriginalFileCalls[0];
    expect($arCall['filename'])->toBe('Nr_PRO033328_no_13042026.HTM');
    expect((int) $arCall['invoice_id'])->toBe((int) Invoice::query()->first()?->id);

    @unlink($arStaged['path']);
});

it('skips attach when orchestrator parse fails (no Invoice row to attach to)', function (): void {
    // BUG 4 fix: the attach call sits AFTER orchestrator->run() inside the
    // try block, so a parse failure short-circuits before the attach runs.
    // No Invoice exists; nothing to attach. This pin guards the ordering.
    $sBrokenPath = (string) tempnam(sys_get_temp_dir(), 'gr-broken-');
    rename($sBrokenPath, $sBrokenPath.'.htm');
    $sBrokenPath .= '.htm';
    file_put_contents($sBrokenPath, '<html><body>no rows here</body></html>');

    $obFile = new UploadedFile(
        $sBrokenPath,
        'Nr_PRO000002_no_01012026.HTM',
        'text/html',
        null,
        true,
    );

    $obController = makeTestController(bHasPermission: true, arFiles: [$obFile]);
    $obController->onUpload();

    expect($obController->arAttachOriginalFileCalls)->toBe([]);
    expect(Invoice::count())->toBe(0);

    @unlink($sBrokenPath);
});

it('iterates over multiple files and aggregates results (UI-02 multi-file)', function (): void {
    $arStaged1 = stageFixtureUpload('Nr_PRO026712_no_28112024.HTM', 'Nr_PRO026712_no_28112024.HTM');
    $arStaged2 = stageFixtureUpload('Nr_PRO029691_no_09072025.HTM', 'Nr_PRO029691_no_09072025.HTM');
    $obController = makeTestController(
        bHasPermission: true,
        arFiles: [$arStaged1['file'], $arStaged2['file']],
    );
    $arResponse = $obController->onUpload();

    expect($arResponse)->toHaveKey('#invoicePreviewWrap');
    expect(Invoice::count())->toBe(2);

    $arPreviewCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/preview_lines') {
            $arPreviewCall = $arCall;
            break;
        }
    }
    expect($arPreviewCall)->not->toBeNull();
    expect(count($arPreviewCall['data']['invoices']))->toBe(2);

    @unlink($arStaged1['path']);
    @unlink($arStaged2['path']);
});

it('returns empty result key when no invoices parsed (errors-only batch — UX redesign)', function (): void {
    // UX redesign 2026-04-30 — the `result` key carries the apply modal
    // markup ONLY when at least one invoice parsed successfully. An
    // errors-only batch returns `result => ''` so the upload form's
    // `data-request-success` JS check (`if (data.result) {...}`) skips the
    // popup open. This pins that contract.
    $sTempPath = (string) tempnam(sys_get_temp_dir(), 'gr-evil-');
    file_put_contents($sTempPath, 'not html');
    $obFile = new UploadedFile($sTempPath, 'evil.exe', 'application/octet-stream', null, true);
    $obController = makeTestController(bHasPermission: true, arFiles: [$obFile]);
    $arResponse = $obController->onUpload();

    expect($arResponse)->toHaveKey('result');
    expect((string) $arResponse['result'])->toBe('');

    @unlink($sTempPath);
});

it('rejects non-HTM extension server-side and reports per-file error (D-06)', function (): void {
    $sTempPath = (string) tempnam(sys_get_temp_dir(), 'gr-evil-');
    file_put_contents($sTempPath, 'not html');
    $obFile = new UploadedFile($sTempPath, 'evil.exe', 'application/octet-stream', null, true);
    $obController = makeTestController(bHasPermission: true, arFiles: [$obFile]);
    $arResponse = $obController->onUpload();

    expect($arResponse)->toHaveKey('#invoiceUploadErrors');
    $arErrorCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/upload_errors') {
            $arErrorCall = $arCall;
            break;
        }
    }
    expect($arErrorCall)->not->toBeNull();
    $arErrors = $arErrorCall['data']['errors'];
    expect($arErrors)->toBeArray();
    expect(count($arErrors))->toBe(1);
    expect((string) $arErrors[0]['filename'])->toBe('evil.exe');
    expect(strtolower((string) $arErrors[0]['message']))->toContain('extension');
    expect(Invoice::count())->toBe(0);

    @unlink($sTempPath);
});

it('rejects file > 10 MB and reports per-file size error (D-07)', function (): void {
    // Build an UploadedFile whose getSize() reports > 10 MB without actually
    // writing 11 MB to disk. Anonymous subclass overrides getSize().
    $sTempPath = (string) tempnam(sys_get_temp_dir(), 'gr-toobig-');
    file_put_contents($sTempPath, '<html></html>'); // valid extension on disk
    $obFile = new class ($sTempPath, 'huge.htm', 'text/html', null, true) extends UploadedFile {
        public function getSize(): int
        {
            return 11 * 1024 * 1024;
        }
    };
    $obController = makeTestController(bHasPermission: true, arFiles: [$obFile]);
    $arResponse = $obController->onUpload();

    expect($arResponse)->toHaveKey('#invoiceUploadErrors');
    $arErrorCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/upload_errors') {
            $arErrorCall = $arCall;
            break;
        }
    }
    expect($arErrorCall)->not->toBeNull();
    $arErrors = $arErrorCall['data']['errors'];
    expect(count($arErrors))->toBe(1);
    // Lang::get returns the key path in unit-bootstrap (translations are
    // not fully loaded by the SQLite-in-memory test harness); the key
    // `logingrupa.goodsreceivedshopaholic::lang.upload.too_large` itself
    // pins the size-error branch — both `too_large` and `upload.too_large`
    // are unique to the size-cap throw site (`assertHtmFile`'s second
    // guard). The bad_extension test's assertion succeeds because the
    // matching key fragment naturally contains "extension".
    expect(strtolower((string) $arErrors[0]['message']))->toContain('too_large');
    expect(Invoice::count())->toBe(0);

    @unlink($sTempPath);
});

it('throws AjaxException when no files are uploaded (defensive guard)', function (): void {
    $obController = makeTestController(bHasPermission: true, arFiles: null);
    $obException = null;
    try {
        $obController->onUpload();
    } catch (\October\Rain\Exception\AjaxException $obCaught) {
        $obException = $obCaught;
    }

    expect($obException)->not->toBeNull();
    $arContents = $obException->getContents();
    expect($arContents)->toHaveKey('message');
    expect(Invoice::count())->toBe(0);
});

it('routes non-fatal orchestrator failures into per-file error array (boundary catch)', function (): void {
    // Stage a syntactically broken `.htm` file that satisfies the extension
    // whitelist + size cap (so the controller proceeds past assertHtmFile)
    // but fails the parser's body-side validation. Filename uses the
    // PRO-number convention so InvoiceNumberResolver does not mask the
    // MalformedHtmException with InvoiceNumberMissingException — same
    // forensic rationale as D-03-06-05.
    $sBrokenPath = (string) tempnam(sys_get_temp_dir(), 'gr-broken-');
    rename($sBrokenPath, $sBrokenPath.'.htm');
    $sBrokenPath .= '.htm';
    file_put_contents($sBrokenPath, '<html><body>no rows here</body></html>');

    $obFile = new UploadedFile(
        $sBrokenPath,
        'Nr_PRO000001_no_01012026.HTM',
        'text/html',
        null,
        true,
    );

    $obController = makeTestController(bHasPermission: true, arFiles: [$obFile]);
    $arResponse = $obController->onUpload();

    expect($arResponse)->toHaveKey('#invoiceUploadErrors');
    $arErrorCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/upload_errors') {
            $arErrorCall = $arCall;
            break;
        }
    }
    expect($arErrorCall)->not->toBeNull();
    $arErrors = $arErrorCall['data']['errors'];
    expect(count($arErrors))->toBe(1);
    expect((string) $arErrors[0]['filename'])->toBe('Nr_PRO000001_no_01012026.HTM');
    // Plugin-typed MalformedHtmException carries the parser's lang-keyed
    // message — assert the catch path was the typed-exception arm by
    // checking that the message is non-empty (the boundary catch publishes
    // $obException->getMessage() into the per-file error row).
    expect((string) $arErrors[0]['message'])->not->toBe('');
    expect(\Logingrupa\GoodsReceivedShopaholic\Models\Invoice::count())->toBe(0);

    @unlink($sBrokenPath);
});
