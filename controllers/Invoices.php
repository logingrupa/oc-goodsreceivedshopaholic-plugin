<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Controllers;

use Backend\Classes\Controller;
use BackendAuth;
use BackendMenu;
use Input;
use Lang;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\GoodsReceivedException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\MalformedHtmException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ParseAndPersistOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use October\Rain\Exception\AjaxException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/**
 * Backend controller for Goods Received Invoices (UI-01 / UI-02 / UI-03 /
 * UI-05 / UI-06 / UI-07 / UI-09 — D-01..D-07 / D-09 / D-16 / D-17).
 *
 * Thin per D-03: implements ListController + FormController + RelationController
 * behaviors and delegates ALL business logic to Phase 3 orchestrators
 * (ParseAndPersistOrchestrator + ApplyOrchestrator). Class-level loose permission
 * gate via $requiredPermissions enforces "operators can use this controller at
 * all" (D-02); fine-grained per-action gates ship per AJAX handler:
 *
 *   - `onUpload` — `upload_invoices` (this plan, 04-04)
 *   - `onUpdateLine` — `upload_invoices` (this plan, 04-04)
 *   - `onApply` — `apply_invoices` (plan 04-05)
 *   - `onOverrideShowConfirm` / `onOverrideConfirm` — `override_invoices` (plan 04-06)
 *   - `onInitialResetConfirm` — `run_initial_reset` (plan 04-06)
 *
 * Registered under Settings menu (NOT main nav per locked decision D6 / D-04).
 * The Settings menu wiring lives in Plugin::registerSettings — TWO entries:
 * one for the Settings model (existing) and one for this controller (UI-05).
 *
 * Boundary note (D-04-04-01 / mirror of D-03-07-01 + D-04-02-01): NOT marked
 * `final` so the controller-level `makePartial` rendering seam can be
 * overridden by a `TestableInvoices` shim in Pest unit tests
 * (`tests/unit/Controllers/UploadHandlerTest.php`). Backend\Classes\Controller
 * itself is not final; the rendering pipeline (route → view registrar → Twig)
 * cannot be stood up under SQLite-in-memory unit-test bootstrap, so a
 * deterministic capture-string return from a subclass is the cleanest seam.
 * Production code never subclasses — October's backend dispatcher always
 * routes to this leaf class.
 *
 * @internal The class behaves as if final at the production-code boundary.
 *           Subclassing is sanctioned ONLY for unit-test partial-rendering shims.
 */
class Invoices extends Controller
{
    /** Server-side accept-filter whitelist — D-06 (.htm only, case-folded). */
    private const ALLOWED_EXTENSIONS = ['htm'];

    /** Per-file size limit — D-07 (10 MB). */
    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024;

    /**
     * Pre-parse duplicate-detection regex — D-16. Filename pattern follows
     * the distributor's convention: `Nr_PRO<digits>_<country>_<DDMMYYYY>.HTM`.
     * Case-insensitive (production filenames mix `Nr_PRO` / `nr_pro`).
     */
    private const FILENAME_INVOICE_NUMBER_REGEX = '/^Nr_PRO(\d+)_/i';

    /** @var list<string> */
    public $implement = [
        'Backend.Behaviors.ListController',
        'Backend.Behaviors.FormController',
        'Backend.Behaviors.RelationController',
    ];

    /** @var string */
    public $listConfig = 'config_list.yaml';

    /** @var string */
    public $formConfig = 'config_form.yaml';

    /** @var string */
    public $relationConfig = 'config_relation.yaml';

    /** @var list<string> */
    public $requiredPermissions = ['logingrupa.goodsreceived.upload_invoices'];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('October.System', 'system', 'settings');
    }

    /**
     * AJAX handler: ingest an array of uploaded `.HTM` files. For each file:
     *   1) Validate extension + size whitelist (D-06 / D-07).
     *   2) Pre-parse duplicate detection on filename invoice_number (UI-09 / D-16).
     *   3) Route through ParseAndPersistOrchestrator::run — which itself does
     *      body-side duplicate detection + persists Invoice + lines in a
     *      single DB::transaction (plan 03-06).
     *   4) Aggregate per-file outcomes into one of three AJAX-target panels:
     *      preview / reject / errors. ONE failing file does NOT abort the
     *      batch; the foreach catches typed plugin exceptions and Throwable
     *      and pushes the per-file error into `$arErrors` so the operator
     *      sees per-file results.
     *
     * Response shape:
     *   [
     *     '#invoicePreviewWrap'  => makePartial('_partials/_preview_lines', [...]),
     *     '#invoiceRejectWrap'   => makePartial('_partials/_reject', [...]),
     *     '#invoiceUploadErrors' => makePartial('_partials/_upload_errors', [...]),
     *   ]
     *
     * Return type is `array<string, mixed>` because `makePartial()` is
     * declared as returning `mixed` upstream (Backend\Classes\Controller
     * inherits ViewMaker without a typed return). October's AJAX dispatcher
     * expects per-selector strings at runtime; the upstream untyped contract
     * keeps the static-analysis surface honest.
     *
     * @return array<string, mixed>
     *
     * @throws AjaxException When permission is missing or no files present.
     */
    public function onUpload(): array
    {
        $this->assertPermission('logingrupa.goodsreceived.upload_invoices');

        $arFiles = $this->getUploadedFiles();
        if ($arFiles === null || $arFiles === []) {
            throw new AjaxException([
                'message' => (string) Lang::get('logingrupa.goodsreceivedshopaholic::lang.upload.no_files'),
            ]);
        }

        $iUserId = $this->resolveBackendUserId();

        /** @var list<array<string, mixed>> $arPreviews */
        $arPreviews = [];
        /** @var list<array<string, mixed>> $arRejects */
        $arRejects = [];
        /** @var list<array{filename: string, message: string}> $arErrors */
        $arErrors = [];

        foreach ($arFiles as $obFile) {
            $this->processSingleUpload($obFile, $iUserId, $arPreviews, $arRejects, $arErrors);
        }

        return [
            '#invoicePreviewWrap'  => $this->makePartial('_partials/_preview_lines', ['invoices' => $arPreviews]),
            '#invoiceRejectWrap'   => $this->makePartial('_partials/_reject', ['rejects' => $arRejects]),
            '#invoiceUploadErrors' => $this->makePartial('_partials/_upload_errors', ['errors' => $arErrors]),
        ];
    }

    /**
     * AJAX handler: update `override_qty` / `override_reason` on a single
     * `InvoiceLine` row — UI-03 / D-09. Triggered by data-track-input
     * bindings on the preview line table inputs. Returns the updated line as
     * a small JSON payload so operator edits are reflected without a full
     * preview re-render.
     *
     * Validation (fail-fast):
     *   - `line_id` required, must resolve to an existing InvoiceLine.
     *   - `override_qty`, when present, must be a non-negative integer.
     *   - `override_reason`, when present, is trimmed; empty string ⇒ NULL
     *     (treated as "clear the override reason").
     *
     * D-09 rationale (custom AJAX handler over RelationController inline-edit):
     * the latter requires the relation panel to render the edit row plus two
     * extra partials + JS scaffold; a single onUpdateLine handler is one
     * method + a few input bindings in `_preview_lines.htm`.
     *
     * Override fields are audit-only metadata; consumed at apply time by
     * StockApplyService reading `override_qty ?? qty` (T-04-04-08 acceptance:
     * the lines table IS the audit trail, so no separate audit row is
     * needed for override edits).
     *
     * @return array<string, mixed>
     *
     * @throws AjaxException
     */
    public function onUpdateLine(): array
    {
        $this->assertPermission('logingrupa.goodsreceived.upload_invoices');

        $iLineId = $this->scalarToInt(Input::get('line_id'));
        if ($iLineId <= 0) {
            throw new AjaxException(['message' => 'line_id is required.']);
        }

        $obLine = InvoiceLine::find($iLineId);
        if (! $obLine instanceof InvoiceLine) {
            throw new AjaxException(['message' => sprintf('InvoiceLine #%d not found.', $iLineId)]);
        }

        $bDirty = false;
        if (Input::has('override_qty')) {
            $iOverrideQty = $this->scalarToInt(Input::get('override_qty'));
            if ($iOverrideQty < 0) {
                throw new AjaxException(['message' => 'override_qty must be non-negative.']);
            }
            $obLine->override_qty = $iOverrideQty;
            $bDirty = true;
        }

        if (Input::has('override_reason')) {
            $mReason = Input::get('override_reason');
            $sReason = is_scalar($mReason) ? trim(strval($mReason)) : '';
            $obLine->override_reason = $sReason !== '' ? $sReason : null;
            $bDirty = true;
        }

        if (! $bDirty) {
            return ['line_id' => $iLineId, 'noop' => true];
        }

        $obLine->saveQuietly();

        return [
            'line_id'         => $iLineId,
            'override_qty'    => $obLine->override_qty,
            'override_reason' => $obLine->override_reason,
        ];
    }

    /**
     * Per-file processing: validate, dup-gate, parse-+-persist or boundary-catch.
     * Results aggregate into the three caller arrays passed by reference so the
     * single-pass foreach in `onUpload` stays at <70 lines / max-1 nesting.
     *
     * @param  list<array<string, mixed>>            $arPreviews
     * @param  list<array<string, mixed>>            $arRejects
     * @param  list<array{filename: string, message: string}>  $arErrors
     */
    private function processSingleUpload(
        UploadedFile $obFile,
        int $iUserId,
        array &$arPreviews,
        array &$arRejects,
        array &$arErrors,
    ): void {
        $sFilename = (string) $obFile->getClientOriginalName();

        try {
            $this->assertHtmFile($obFile);

            $sNumber = $this->extractInvoiceNumberFromFilename($sFilename);
            if ($sNumber !== null) {
                $obPrior = Invoice::where('invoice_number', $sNumber)
                    ->where('status', Invoice::STATUS_APPLIED)
                    ->first();
                if ($obPrior instanceof Invoice) {
                    $arRejects[] = $this->buildRejectPayload($obPrior);

                    return;
                }
            }

            $sHtml = $this->readFileContents($obFile);
            $obOrchestrator = $this->resolveParseOrchestrator();
            $obInvoice = $obOrchestrator->run($sHtml, $sFilename, $iUserId);

            $arPreviews[] = $this->buildPreviewPayload($obInvoice);
        } catch (GoodsReceivedException $obException) {
            $arErrors[] = ['filename' => $sFilename, 'message' => $obException->getMessage()];
        } catch (Throwable $obException) {
            $arErrors[] = ['filename' => $sFilename, 'message' => $obException->getMessage()];
        }
    }

    /**
     * Pre-parse duplicate detection helper — D-16 regex extraction. Returns
     * null when filename does not match the expected pattern; null triggers
     * the full parse path (the body-side InvoiceNumberResolver inside the
     * parser still runs and is the authoritative contract).
     *
     * Visibility: protected so the Pest test shim's reflection invocation
     * remains stable. Reflection is the test-discipline pin per the
     * PluginBootSelfCheckTest::callParseIniSize precedent (D-35).
     */
    protected function extractInvoiceNumberFromFilename(string $sFilename): ?string
    {
        if (preg_match(self::FILENAME_INVOICE_NUMBER_REGEX, $sFilename, $arMatches) !== 1) {
            return null;
        }

        return 'PRO'.$arMatches[1];
    }

    /**
     * Server-side `.htm` extension + size whitelist (D-06 / D-07). Throws a
     * `MalformedHtmException` (typed plugin exception) so the outer foreach
     * catches and reports structurally — same path as parser failures, so
     * the operator sees one consistent per-file error format.
     *
     * @throws MalformedHtmException
     */
    private function assertHtmFile(UploadedFile $obFile): void
    {
        $sExtension = strtolower((string) $obFile->getClientOriginalExtension());
        if (! in_array($sExtension, self::ALLOWED_EXTENSIONS, true)) {
            throw new MalformedHtmException(
                (string) Lang::get(
                    'logingrupa.goodsreceivedshopaholic::lang.upload.bad_extension',
                    ['extension' => $sExtension],
                ),
                ['extension' => $sExtension, 'filename' => (string) $obFile->getClientOriginalName()],
            );
        }
        if ((int) $obFile->getSize() > self::MAX_FILE_SIZE_BYTES) {
            throw new MalformedHtmException(
                (string) Lang::get(
                    'logingrupa.goodsreceivedshopaholic::lang.upload.too_large',
                    ['size' => (int) $obFile->getSize()],
                ),
                ['size' => (int) $obFile->getSize(), 'max' => self::MAX_FILE_SIZE_BYTES],
            );
        }
    }

    /**
     * Permission gate. Protected so the Pest test shim can override (boundary
     * mock — facade-mocking BackendAuth itself collides with the
     * Backend\Classes\Controller constructor's AuthManager calls).
     *
     * @throws AjaxException
     */
    protected function assertPermission(string $sPermissionKey): void
    {
        if (! BackendAuth::userHasAccess($sPermissionKey)) {
            throw new AjaxException([
                'message' => (string) Lang::get('logingrupa.goodsreceivedshopaholic::lang.flash.forbidden'),
            ]);
        }
    }

    /**
     * Backend user-id resolver. Protected so the Pest test shim can override
     * without spinning up a real Backend\Models\User row. Uses the
     * Authenticatable contract's `getAuthIdentifier()` (returns mixed in
     * upstream typing) and `intval()` to coerce — same Eloquent-magic-prop
     * pattern as D-03-03-05 (avoids inline @var, accepts whatever scalar
     * the live row carries).
     */
    protected function resolveBackendUserId(): int
    {
        $obUser = BackendAuth::getUser();
        if ($obUser === null) {
            throw new AjaxException([
                'message' => (string) Lang::get('logingrupa.goodsreceivedshopaholic::lang.flash.forbidden'),
            ]);
        }

        return $this->scalarToInt($obUser->getAuthIdentifier());
    }

    /**
     * Coerce a Larastan-typed `mixed` scalar (Eloquent magic prop / facade
     * input / Authenticatable id) into a strict int. Same defensive pattern
     * as D-03-04-01 — `is_scalar()` narrows for the analyser, `intval()`
     * does the cast. Non-scalar input (object / null / array) ⇒ 0; the
     * caller's `<= 0` guard is the contract enforcement layer.
     */
    private function scalarToInt(mixed $mValue): int
    {
        return is_scalar($mValue) ? intval($mValue) : 0;
    }

    /**
     * Uploaded-files accessor. Protected so the Pest test shim can override
     * to feed pre-built UploadedFile instances without standing up a real
     * HTTP request (Input/request facade mocking collides with the
     * Backend\Classes\Controller constructor).
     *
     * Larastan types `Input::file()` as `UploadedFile|null` for the single-
     * file form and `array<UploadedFile>|null` for the multi-file form;
     * the `<input name="files[]">` widget uses the multi form, so an
     * array is the contracted return at the runtime boundary. Empty /
     * non-array responses (no upload submitted) ⇒ null bubble for the
     * caller's "no files" branch.
     *
     * @return list<UploadedFile>|null
     */
    protected function getUploadedFiles(): ?array
    {
        $mFiles = Input::file('files');
        if (! is_array($mFiles)) {
            return null;
        }

        return array_values($mFiles);
    }

    /**
     * Resolve the orchestrator from the IoC container. Protected so the
     * Pest test shim can swap the parse path for a tracking double without
     * needing to subclass the (final) orchestrator itself.
     *
     * Larastan's `app()` extension narrows the typed return — no defensive
     * `instanceof` guard needed (mirrors D-04-02-02 precedent: PHPStan L10
     * flags `instanceof.alwaysTrue` when the analyser already knows the
     * type). BindingResolutionException remains the only realistic IoC
     * failure mode and is allowed to propagate to the outer foreach's
     * Throwable catch.
     */
    protected function resolveParseOrchestrator(): ParseAndPersistOrchestrator
    {
        return app(ParseAndPersistOrchestrator::class);
    }

    /**
     * Read file contents in a Tiger-Style fail-fast manner. `false` from
     * `file_get_contents` triggers an immediate exception that the outer
     * foreach catches as a per-file error.
     */
    private function readFileContents(UploadedFile $obFile): string
    {
        $sPath = (string) $obFile->getRealPath();
        $mContent = @file_get_contents($sPath);
        if ($mContent === false) {
            throw new \RuntimeException(sprintf('Could not read uploaded file: %s', $obFile->getClientOriginalName()));
        }

        return $mContent;
    }

    /**
     * Build the per-file reject payload for the duplicate-detection partial.
     * Carries enough audit context (prior_applied_at / prior_applied_by /
     * prior_stock_added_units / prior_invoice_id) for the operator to decide
     * whether to proceed via the Override flow (plan 04-06).
     *
     * @return array<string, mixed>
     */
    private function buildRejectPayload(Invoice $obPrior): array
    {
        $mAppliedAt = $obPrior->applied_at;
        $sAppliedAt = $mAppliedAt instanceof \Carbon\Carbon ? $mAppliedAt->toIso8601String() : null;

        return [
            'invoice_number'          => (string) $obPrior->invoice_number,
            'prior_applied_at'        => $sAppliedAt,
            'prior_applied_by'        => $obPrior->applied_by_user_id,
            'prior_stock_added_units' => (int) $obPrior->stock_added_units,
            'prior_invoice_id'        => (int) $obPrior->id,
        ];
    }

    /**
     * Build the per-file preview payload for the line-table partial. Loads
     * lines via direct InvoiceLine::where (NOT via the magic relation) per
     * D-03-07-03 — keeps PHPStan L10 strict-types clean without inline @var.
     *
     * @return array<string, mixed>
     */
    private function buildPreviewPayload(Invoice $obInvoice): array
    {
        $iInvoiceId = (int) $obInvoice->id;

        return [
            'invoice'         => $obInvoice,
            'lines'           => InvoiceLine::where('invoice_id', $iInvoiceId)->orderBy('row_index')->get(),
            'total_units'     => (int) InvoiceLine::where('invoice_id', $iInvoiceId)->sum('qty'),
            'matched_count'   => (int) $obInvoice->matched_lines,
            'unmatched_count' => (int) $obInvoice->unmatched_lines,
        ];
    }
}
