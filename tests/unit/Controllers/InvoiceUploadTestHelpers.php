<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ApplyOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ParseAndPersistOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Controllers\Invoices;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Shared test-shim + fixture helpers for Phase 4 plan 04-04 controller tests
 * (UploadHandlerTest, PreUploadDuplicateDetectionTest, UpdateInvoiceLineTest).
 *
 * Loaded via `require_once` from each test file; symbols are global so Pest
 * picks them up regardless of test-file order.
 */

if (! class_exists(TestableInvoices::class, false)) {

    /**
     * Test shim for Invoices controller. Overrides four boundary seams that
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
     *   4) `getUploadedFiles()` — the production version reads from
     *      `Input::file('files')`. Mocking the Input facade (which routes
     *      through the `request` IoC binding) collides with the
     *      Backend\Classes\Controller constructor. The shim returns a
     *      pre-bound list so tests drive the foreach without a real HTTP
     *      request.
     *
     *   5) `resolveParseOrchestrator()` — counter increments per resolve so
     *      tests can pin "orchestrator was NOT invoked" assertions; an
     *      optional `obOrchestratorResolver` closure swaps in tracking
     *      doubles without subclassing the (final) orchestrator.
     *
     * Production code paths are unchanged; the shim ONLY exists for unit-test
     * pinning. The behaviors array is deliberately empty so the controller
     * bootstrap does not resolve ListController's `config_list.yaml` against
     * the test-shim's class path (`guessConfigPathFrom` derives the wrong
     * directory from `class_basename($class)` for the shim).
     */
    final class TestableInvoices extends Invoices
    {
        /** @var list<string> */
        public $implement = [];

        /** @var list<array{name: string, data: array<string, mixed>}> */
        public array $arPartialCalls = [];

        public bool $bHasPermission = true;

        /**
         * Per-permission allow set. Plan 04-07 / D-37 / QA-10 introduces
         * fine-grained per-key gate testing — when this array is NON-NULL
         * the shim's `assertPermission()` uses it INSTEAD of the binary
         * `bHasPermission` flag (the boolean stays for plan 04-04..04-06
         * back-compat). Null = boolean fallback. Empty array = deny all.
         * Non-empty = allow ONLY the listed keys.
         *
         * @var list<string>|null
         */
        public ?array $arAllowedPermissions = null;

        public int $iBackendUserId = 7;

        /** @var list<string> */
        public array $arPermissionsChecked = [];

        /** @var list<UploadedFile>|null */
        public ?array $arUploadedFiles = null;

        /** @var \Closure(): ParseAndPersistOrchestrator|null */
        public ?\Closure $obOrchestratorResolver = null;

        public int $iOrchestratorResolvedCount = 0;

        /**
         * Apply-side orchestrator resolver hook (plan 04-05). Counter pins
         * "orchestrator was/was-not invoked" assertions on the apply path
         * (mirror of $iOrchestratorResolvedCount for the parse path).
         *
         * @var \Closure(): ApplyOrchestrator|null
         */
        public ?\Closure $obApplyOrchestratorResolver = null;

        public int $iApplyOrchestratorResolvedCount = 0;

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

            // Per-key allow set (plan 04-07 / D-37 / QA-10) takes precedence
            // when configured; otherwise fall back to the binary boolean
            // flag established in plan 04-04.
            if ($this->arAllowedPermissions !== null) {
                if (! in_array($sPermissionKey, $this->arAllowedPermissions, true)) {
                    throw new \October\Rain\Exception\AjaxException([
                        'message' => 'Forbidden (test stub: key not in allow set).',
                    ]);
                }

                return;
            }

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

        #[\Override]
        protected function getUploadedFiles(): ?array
        {
            return $this->arUploadedFiles;
        }

        #[\Override]
        protected function resolveParseOrchestrator(): ParseAndPersistOrchestrator
        {
            $this->iOrchestratorResolvedCount++;
            if ($this->obOrchestratorResolver !== null) {
                return ($this->obOrchestratorResolver)();
            }

            return parent::resolveParseOrchestrator();
        }

        #[\Override]
        protected function resolveApplyOrchestrator(): ApplyOrchestrator
        {
            $this->iApplyOrchestratorResolvedCount++;
            if ($this->obApplyOrchestratorResolver !== null) {
                return ($this->obApplyOrchestratorResolver)();
            }

            return parent::resolveApplyOrchestrator();
        }
    }
}

if (! function_exists('makeTestUploadedFile')) {
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
}

if (! function_exists('stageFixtureUpload')) {
    /**
     * Copy the fixture HTM into a temp file with a renamed client name (so
     * the filename-derived invoice_number is stable per test) and return both
     * the temp path and the synthetic UploadedFile pointing at it.
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
}

if (! function_exists('makeTestController')) {
    /**
     * Helper to build a TestableInvoices controller pre-wired with the given
     * permission flag and uploaded-file list.
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
}
