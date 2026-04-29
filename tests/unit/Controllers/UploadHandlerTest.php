<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ParseAndPersistOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Controllers\Invoices;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use Symfony\Component\HttpFoundation\File\UploadedFile;

require_once __DIR__.'/../Apply/ApplyTestCase.php';

uses(ApplyTestCase::class);

/**
 * UI-02 / UI-03 / UI-07 / UI-09 — D-05..D-07 / D-16 / D-17 / D-44.
 *
 * Plan 04-04 Task 1: onUpload AJAX handler + pre-parse duplicate gate.
 *
 * Tiger-Style invariants pinned here:
 *   - Server-side accept filter: extension whitelist (.htm only) + 10 MB
 *     size cap. Per-file boundary checks. Failure of one file does NOT abort
 *     the batch; the foreach catches typed plugin exceptions and Throwable
 *     and pushes per-file error rows for the operator to see.
 *   - Pre-parse duplicate gate: regex `/^Nr_PRO(\d+)_/i` extracts the
 *     invoice_number from the filename BEFORE the parser runs. If a prior
 *     `Invoice@status='applied'` row already exists with that number, the
 *     reject partial is rendered WITHOUT touching the parser at all
 *     (T-04-04-07 mitigation note: this gate is an OPTIMIZATION; the
 *     authoritative duplicate detection still lives inside the orchestrator's
 *     lockForUpdate transaction in plan 03-06).
 *   - Permission gate: `BackendAuth::userHasAccess('logingrupa.goodsreceived.upload_invoices')`
 *     at handler entry; deny ⇒ AjaxException (HTTP 406 in production, throws
 *     here in test context).
 *
 * Auth seam: `BackendAuth` is a backend-side facade (boundary), so
 * facade-mocking via Mockery is sanctioned per CLAUDE.md (mirrors the
 * `App::shouldReceive` / `Log::shouldReceive` pattern used in the
 * PluginBootSelfCheckTest plan 04-01 plays back).
 *
 * Partial-rendering seam: `makePartial()` requires the full backend view
 * resolver pipeline which is not wired in the SQLite-in-memory unit test
 * bootstrap. We subclass `Invoices` with a `TestableInvoices` shim that
 * captures the partial name + view data into a deterministic string —
 * pinning WHICH partial was selected with WHICH data without coupling to
 * Twig render output.
 */

/**
 * Test shim for Invoices controller. Overrides three boundary seams that
 * cannot be stood up under SQLite-in-memory unit-test bootstrap:
 *
 *   1) `makePartial()` — the production implementation walks the view
 *      registrar + Twig renderer (full backend route stack); we capture
 *      inputs into `$arPartialCalls` and return a deterministic sentinel
 *      so tests can pin "which partial was rendered with which payload"
 *      without standing up the full backend view stack.
 *
 *   2) `assertPermission()` — the production check goes through the
 *      `BackendAuth` facade which itself sits on top of the full Backend
 *      AuthManager (impersonation, throttling, session). Mockery-mocking
 *      the facade collides with the Backend\Classes\Controller constructor
 *      (which calls AuthManager::isRoleImpersonator). The shim short-circuits
 *      via a settable boolean — pins the gate without spinning up the full
 *      backend auth subsystem.
 *
 *   3) `resolveBackendUserId()` — same rationale; the production version
 *      reads `BackendAuth::getUser()->id`. The shim returns a settable
 *      integer.
 *
 * Production code paths are unchanged; the shim ONLY exists for unit-test
 * pinning.
 */
final class TestableInvoices extends Invoices
{
    /** @var list<array{name: string, data: array<string, mixed>}> */
    public array $arPartialCalls = [];

    public bool $bHasPermission = true;

    public int $iBackendUserId = 7;

    /** @var list<string> */
    public array $arPermissionsChecked = [];

    /**
     * @param  array<string, mixed>  $vars
     * @param  bool  $throwException
     * @return false|string
     */
    public function makePartial($partial, $vars = [], $throwException = true)
    {
        $this->arPartialCalls[] = ['name' => $partial, 'data' => $vars];

        return 'PARTIAL:'.$partial.':'.(string) json_encode(array_keys($vars));
    }

    #[\Override]
    protected function assertPermission(string $sPermissionKey): void
    {
        $this->arPermissionsChecked[] = $sPermissionKey;
        if (! $this->bHasPermission) {
            throw new \October\Rain\Exception\AjaxException([
                'message' => 'Forbidden (test stub).',
            ]);
        }
    }

    #[\Override]
    protected function resolveBackendUserId(): int
    {
        return $this->iBackendUserId;
    }

    /** @var list<UploadedFile>|null */
    public ?array $arUploadedFiles = null;

    /**
     * Override the production `getUploadedFiles()` (which reads from the
     * `Input::file('files')` facade — backed by Symfony's UploadedFile
     * bag in the live request). The shim returns a pre-bound list so
     * tests can drive the foreach without spinning up a real HTTP
     * request.
     *
     * @return list<UploadedFile>|null
     */
    #[\Override]
    protected function getUploadedFiles(): ?array
    {
        return $this->arUploadedFiles;
    }
}

/**
 * Build a synthetic UploadedFile pointing at a real file on disk in test
 * mode. `test:true` flag bypasses Symfony's HTTP-upload sanity check
 * (`is_uploaded_file()` returns false in CLI). The MIME type is set to
 * `text/html` for `.htm` extensions.
 */
function makeTestUploadedFile(string $sFixturePath, string $sClientName, ?string $sExtension = null): UploadedFile
{
    $sExt = $sExtension ?? pathinfo($sFixturePath, PATHINFO_EXTENSION);
    $sMime = strtolower($sExt) === 'htm' ? 'text/html' : 'application/octet-stream';

    return new UploadedFile($sFixturePath, $sClientName, $sMime, null, true);
}

/**
 * Copy the fixture HTM into a temp file with a renamed client name (so the
 * filename-derived invoice_number is stable per test) and return both the
 * temp path and the synthetic UploadedFile pointing at it.
 *
 * @return array{path: string, file: UploadedFile}
 */
function stageFixtureUpload(string $sClientName, string $sFixture = 'Nr_PRO033328_no_13042026.HTM'): array
{
    $sFixturePath = __DIR__.'/../../fixtures/invoices/'.$sFixture;
    $sTempPath = (string) tempnam(sys_get_temp_dir(), 'gr-upload-');
    copy($sFixturePath, $sTempPath);

    return [
        'path' => $sTempPath,
        'file' => makeTestUploadedFile($sTempPath, $sClientName),
    ];
}

/**
 * Helper to build a TestableInvoices controller pre-wired with the given
 * permission flag and uploaded-file list. Returns the shim instance so the
 * caller can assert on `arPartialCalls` after invoking `onUpload()`.
 *
 * @param  list<UploadedFile>|null  $arFiles
 */
function makeTestController(bool $bHasPermission, ?array $arFiles, int $iUserId = 7): TestableInvoices
{
    $obController = new TestableInvoices();
    $obController->bHasPermission = $bHasPermission;
    $obController->iBackendUserId = $iUserId;
    $obController->arUploadedFiles = $arFiles;

    return $obController;
}

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
    expect((string) $arResponse['#invoicePreviewWrap'])->toContain('_partials/_preview_lines');

    // Real Invoice was persisted via ParseAndPersistOrchestrator.
    expect(Invoice::count())->toBe(1);
    expect((string) Invoice::query()->first()?->invoice_number)->toBe('PRO033328');
    expect(InvoiceLine::count())->toBe(21);

    // Preview partial received a non-empty `invoices` array.
    $arPreviewCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/_preview_lines') {
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
        if ($arCall['name'] === '_partials/_preview_lines') {
            $arPreviewCall = $arCall;
            break;
        }
    }
    expect($arPreviewCall)->not->toBeNull();
    expect(count($arPreviewCall['data']['invoices']))->toBe(2);

    @unlink($arStaged1['path']);
    @unlink($arStaged2['path']);
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
        if ($arCall['name'] === '_partials/_upload_errors') {
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
    $obFile = new class($sTempPath, 'huge.htm', 'text/html', null, true) extends UploadedFile
    {
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
        if ($arCall['name'] === '_partials/_upload_errors') {
            $arErrorCall = $arCall;
            break;
        }
    }
    expect($arErrorCall)->not->toBeNull();
    $arErrors = $arErrorCall['data']['errors'];
    expect(count($arErrors))->toBe(1);
    expect(strtolower((string) $arErrors[0]['message']))->toContain('size');
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
    // Bind a fake orchestrator that always throws GoodsReceivedException
    // — pins the per-file boundary catch path.
    \App::bind(ParseAndPersistOrchestrator::class, function () {
        return new class extends ParseAndPersistOrchestrator
        {
            public function run(string $sHtmlContent, string $sSourceFilename, int $iAppliedByUserId): \Logingrupa\GoodsReceivedShopaholic\Models\Invoice
            {
                throw new \Logingrupa\GoodsReceivedShopaholic\Classes\Exception\MalformedHtmException(
                    'Synthetic parse failure',
                    ['source_filename' => $sSourceFilename],
                );
            }
        };
    });

    $arStaged = stageFixtureUpload('failing.htm');
    $obController = makeTestController(bHasPermission: true, arFiles: [$arStaged['file']]);
    $arResponse = $obController->onUpload();

    expect($arResponse)->toHaveKey('#invoiceUploadErrors');
    $arErrorCall = null;
    foreach ($obController->arPartialCalls as $arCall) {
        if ($arCall['name'] === '_partials/_upload_errors') {
            $arErrorCall = $arCall;
            break;
        }
    }
    expect($arErrorCall)->not->toBeNull();
    $arErrors = $arErrorCall['data']['errors'];
    expect(count($arErrors))->toBe(1);
    expect((string) $arErrors[0]['filename'])->toBe('failing.htm');
    expect((string) $arErrors[0]['message'])->toContain('Synthetic parse failure');

    @unlink($arStaged['path']);
});
