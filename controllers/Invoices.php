<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Controllers;

use Backend;
use Backend\Classes\Controller;
use BackendAuth;
use BackendMenu;
use Cache;
use Flash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Input;
use Lang;
use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\InitialResetService;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\ApplyAlreadyDoneException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\GoodsReceivedException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InitialResetNotAllowedException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\MalformedHtmException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ApplyOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator\ParseAndPersistOrchestrator;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\SettingsAccessor;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use Lovata\Shopaholic\Models\Offer;
use Lovata\Shopaholic\Models\Product;
use October\Rain\Exception\AjaxException;
use Redirect;
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

    /**
     * Cache::lock TTL (seconds) for `onApply` debouncing — D-13. 60s is well
     * above the apply-orchestrator's typical wall time; the lock is released
     * in a `finally` block immediately after the orchestrator returns or
     * throws, so the TTL is a SAFETY-NET against a process crash mid-apply,
     * NOT the load-bearing release path. Source-grep pinned by
     * `ApplyDoubleClickDebounceTest` (literal `APPLY_LOCK_TTL_SECONDS = 60`
     * + lock-key shape `apply-invoice-`).
     */
    private const APPLY_LOCK_TTL_SECONDS = 60;

    /**
     * Typed-confirmation literal for the override-and-reimport gate (UI-10 /
     * D-19). Operator must type this string EXACTLY (case-sensitive strict
     * equality) before runOverride is invoked. Source-grep pinned by
     * `OverrideConfirmTest::onOverrideConfirm rejects when typed string is
     * wrong case` (the lowercase 'override' rejection assertion).
     */
    private const OVERRIDE_LITERAL = 'OVERRIDE';

    /**
     * Typed-confirmation literal for the initial-reset gate (UI-08 / D-24).
     * Operator must type this string EXACTLY (case-sensitive) before
     * InitialResetService::reset is invoked. Source-grep pinned by
     * `InitialResetConfirmTest::onInitialResetConfirm rejects when typed
     * string is wrong case`.
     */
    private const RESET_LITERAL = 'RESET';

    /**
     * Reject-reason discriminator written into the per-file reject payload
     * by `buildRejectPayload()` and consumed by the `_reject.htm` partial
     * to branch the operator-visible UI between the two pre-parse rejection
     * cases:
     *
     *   - DUPLICATE_APPLIED: prior `Invoice@status='applied'` exists; the
     *     operator must use the Override-and-re-import flow if reapply is
     *     intended (D-16 / D-17 / UI-09 — pre-existing contract).
     *
     *   - PARSED_PENDING_APPLY: prior `Invoice@status='parsed'` exists; the
     *     operator must apply or discard the existing parse before
     *     re-uploading. Added to close the parsed-status duplicate hole that
     *     previously surfaced the unique-index `QueryException` to the UI.
     */
    public const REJECT_REASON_DUPLICATE_APPLIED = 'duplicate_applied';

    public const REJECT_REASON_PARSED_PENDING = 'parsed_pending_apply';

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
        BackendMenu::setContext('Lovata.Shopaholic', 'shopaholic-menu-main', 'goodsreceived');
    }

    /**
     * Override the FormController `update` page action so a missing Invoice
     * row redirects back to the list with a flash message instead of
     * rendering the half-initialized form (which throws "Form behavior has
     * not been initialized" inside `formRender()` because `initForm()` was
     * never called).
     *
     * Reproduction: `/back/.../invoices/update/2` after invoice id=2 was
     * deleted. The default FormController::update wraps `formFindModelObject`
     * in a try/catch and on miss calls `handleError($ex)` — which flashes
     * the model-not-found message but does NOT short-circuit the response.
     * The action returns void, the view template runs, the partials call
     * `formRender()`, that throws because `formWidget` is null. Operator
     * sees the raw stack trace.
     *
     * Behavior here: catch the not-found case, flash the lang message, and
     * return a redirect Response object. October's controller dispatcher
     * honors a Response return from a page action — the view template is
     * skipped entirely, no half-initialized form is rendered.
     *
     * @param  int|string|null  $recordId
     * @param  string|null  $context
     * @return mixed
     */
    public function update($recordId = null, $context = null)
    {
        $iId = $this->scalarToInt($recordId);
        if ($iId <= 0 || ! Invoice::where('id', $iId)->exists()) {
            Flash::error((string) Lang::get(
                'logingrupa.goodsreceivedshopaholic::lang.apply.invoice_not_found',
                ['id' => $iId],
            ));

            return Redirect::to(Backend::url('logingrupa/goodsreceivedshopaholic/invoices'));
        }

        $obFormController = $this->asExtension('FormController');
        if ($obFormController instanceof \Backend\Behaviors\FormController) {
            $obFormController->update($iId, $context);
        }

        return null;
    }

    /**
     * Page action: render the multi-file upload tab. Mirrors the list action's
     * tab navigation so operators can switch between Invoices / Upload /
     * Settings without leaving the controller.
     */
    public function upload(): void
    {
        $this->pageTitle = (string) Lang::get('logingrupa.goodsreceivedshopaholic::lang.tabs.upload');
    }

    /**
     * AJAX handler: render the upload form inside an October popup. Triggered
     * by the list toolbar button (data-control="popup" data-handler="…"). The
     * upload form's file input keeps its existing onUpload AJAX wiring; once
     * a file is parsed, the apply modal opens via $.popup() and this upload
     * popup hides itself (handler chain stays in `_upload_form.htm`).
     *
     * Defense-in-depth: gated on the same upload_invoices permission as the
     * onUpload handler that follows, so an operator without rights cannot
     * even open the modal.
     *
     * @return array<string, mixed>
     *
     * @throws \October\Rain\Exception\ApplicationException When permission missing.
     */
    public function onLoadUploadModal(): array
    {
        $this->assertPermission('logingrupa.goodsreceived.upload_invoices');

        $mPartial = $this->makePartial('_partials/upload_modal');

        return [
            'result' => is_string($mPartial) ? $mPartial : '',
        ];
    }

    /**
     * AJAX handler: render the original `.HTM` file contents in an October
     * popup modal (instead of opening as a new browser page). Triggered by
     * the Preview button on the Invoice update form (`models/invoice/
     * _field_original_file.htm`).
     *
     * Permission-gated on `upload_invoices` — same scope as the controller's
     * default access (any operator who can see invoices can preview the
     * original receipt).
     *
     * @return array<string, mixed>
     *
     * @throws \October\Rain\Exception\ApplicationException When permission missing
     *                                                      or invoice/file not found.
     */
    public function onPreviewHtmFile(): array
    {
        $this->assertPermission('logingrupa.goodsreceived.upload_invoices');

        $iInvoiceId = $this->scalarToInt(Input::get('invoice_id'));
        if ($iInvoiceId === 0) {
            throw new \October\Rain\Exception\ApplicationException('invoice_id is required.');
        }

        $obInvoice = Invoice::find($iInvoiceId);
        if (!($obInvoice instanceof Invoice)) {
            throw new \October\Rain\Exception\ApplicationException('Invoice not found.');
        }

        $obFile = $obInvoice->original_file;
        if ($obFile === null) {
            throw new \October\Rain\Exception\ApplicationException('No original .HTM file attached.');
        }

        $mFileName = $obFile->file_name ?? 'invoice.htm';
        $sFilename = is_scalar($mFileName) ? (string) $mFileName : 'invoice.htm';
        $mFileUrl = $obFile->getPath();
        $sFileUrl = is_scalar($mFileUrl) ? (string) $mFileUrl : '';

        $mPartial = $this->makePartial('_partials/htm_preview', [
            'invoice_id' => $iInvoiceId,
            'filename'   => $sFilename,
            'file_url'   => $sFileUrl,
        ]);

        return [
            'result' => is_string($mPartial) ? $mPartial : '',
        ];
    }

    /**
     * Page action: render the Settings tab. Resolves the current site's
     * Settings model row and exposes it to the view (settings.htm) which
     * renders the four-toggle form via October's FormController behavior
     * stand-in pattern.
     */
    public function settings(): void
    {
        $this->pageTitle = (string) Lang::get('logingrupa.goodsreceivedshopaholic::lang.tabs.settings');
    }

    /**
     * AJAX handler: ingest an array of uploaded `.HTM` files. For each file:
     *   1) Validate extension + size whitelist (D-06 / D-07).
     *   2) Pre-parse duplicate detection on filename invoice_number (UI-09 / D-16).
     *   3) Route through ParseAndPersistOrchestrator::run — which itself does
     *      body-side duplicate detection + persists Invoice + lines in a
     *      single DB::transaction (plan 03-06).
     *   4) Attach the uploaded HTM to Invoice.original_file via attachOne
     *      (BUG 4 / UI-06 / D-28 — Chunk B).
     *   5) Aggregate per-file outcomes into preview / reject / errors panels
     *      AND render the unified apply modal under the canonical `result`
     *      key. ONE failing file does NOT abort the batch; the foreach
     *      catches typed plugin exceptions and Throwable so the operator
     *      sees per-file results.
     *
     * UX redesign 2026-04-30 (response shape includes `result`):
     *   When at least ONE invoice parses successfully, the response carries
     *   a `result` key with the rendered `_apply_modal.htm` markup. The
     *   upload form's file input has `data-request-success="$.popup({
     *   content: data.result, size: 'huge' });"` so the popup widget opens
     *   automatically client-side after successful upload. The legacy
     *   `#invoicePreviewWrap` / `#invoiceRejectWrap` / `#invoiceUploadErrors`
     *   selector keys are RETAINED for the rejects + errors panels — those
     *   still render INLINE on the upload page (operator must see duplicate
     *   rejects + per-file errors regardless of modal state).
     *
     * Response shape:
     *   [
     *     '#invoicePreviewWrap'  => makePartial('_partials/preview_lines', [...]),
     *     '#invoiceRejectWrap'   => makePartial('_partials/reject', [...]),
     *     '#invoiceUploadErrors' => makePartial('_partials/upload_errors', [...]),
     *     'result'               => makePartial('_partials/apply_modal', [...]) | '',
     *   ]
     *
     * The `result` key is empty string when no parse succeeded (errors-only
     * batch); the client-side `data-request-success` checks for non-empty
     * before opening the popup.
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

        $mModal = count($arPreviews) > 0
            ? $this->makePartial('_partials/apply_modal', ['invoices' => $arPreviews])
            : '';

        $this->flashFailOnlyOutcome($arPreviews, $arRejects, $arErrors);

        return [
            '#invoicePreviewWrap'  => $this->makePartial('_partials/preview_lines', ['invoices' => $arPreviews]),
            '#invoiceRejectWrap'   => $this->makePartial('_partials/reject', ['rejects' => $arRejects]),
            '#invoiceUploadErrors' => $this->makePartial('_partials/upload_errors', ['errors' => $arErrors]),
            'result'               => is_string($mModal) ? $mModal : '',
        ];
    }

    /**
     * Operator-visible flash for fail-only upload batches — without it the
     * upload popup would dismiss silently while only the in-popup reject /
     * errors panel patched into #invoiceRejectWrap / #invoiceUploadErrors
     * renders, which the operator can miss entirely if the popup
     * auto-closes (UAT feedback 2026-05-02). No flash when at least one
     * file parsed — the apply popup itself is the success surface.
     *
     * @param  list<mixed>                                   $arPreviews
     * @param  list<array<string, mixed>>                    $arRejects
     * @param  list<array{filename: string, message: string}> $arErrors
     */
    private function flashFailOnlyOutcome(array $arPreviews, array $arRejects, array $arErrors): void
    {
        if (count($arPreviews) > 0) {
            return;
        }

        if (count($arRejects) > 0) {
            $sFlashKey = $this->resolveRejectFlashKey($arRejects);
            Flash::warning((string) Lang::get($sFlashKey));

            return;
        }

        if (count($arErrors) > 0) {
            Flash::error($this->buildErrorOnlyFlashMessage($arErrors));
        }
    }

    /**
     * Compose a descriptive flash for fail-only batches: include the error
     * count and the first filename so the operator gets something actionable
     * before reading the (also-rendered) per-file panel below. When ANY
     * error message matches the boundary-sanitizer's generic
     * `upload.unexpected_error` text, append a log-pointer hint so the
     * operator knows the full Throwable was logged server-side rather than
     * silently swallowed.
     *
     * @param  list<array{filename: string, message: string}> $arErrors
     */
    private function buildErrorOnlyFlashMessage(array $arErrors): string
    {
        $sFirstFilename = $arErrors === [] ? '' : $arErrors[0]['filename'];
        $iCount = count($arErrors);

        $sUnexpectedSentinel = (string) Lang::get(
            'logingrupa.goodsreceivedshopaholic::lang.upload.unexpected_error',
        );
        $bHasUnexpected = false;
        foreach ($arErrors as $arErr) {
            if ($arErr['message'] === $sUnexpectedSentinel) {
                $bHasUnexpected = true;
                break;
            }
        }

        $sKey = $bHasUnexpected
            ? 'logingrupa.goodsreceivedshopaholic::lang.flash.upload_failed_unexpected'
            : 'logingrupa.goodsreceivedshopaholic::lang.flash.upload_failed_detail';

        return (string) Lang::get($sKey, [
            'count'    => $iCount,
            'filename' => $sFirstFilename,
        ]);
    }

    /**
     * Pick the flash key for a fail-only batch of rejects. If EVERY reject
     * carries reason=parsed_pending_apply, surface the apply-or-discard
     * message; otherwise fall back to the existing applied-duplicate message
     * (which also covers mixed batches — operator still needs the override
     * flow for at least one file).
     *
     * @param  list<array<string, mixed>> $arRejects
     */
    private function resolveRejectFlashKey(array $arRejects): string
    {
        $bAllParsedPending = true;
        foreach ($arRejects as $arReject) {
            $mReason = $arReject['reject_reason'] ?? null;
            $sReason = is_string($mReason) ? $mReason : self::REJECT_REASON_DUPLICATE_APPLIED;
            if ($sReason !== self::REJECT_REASON_PARSED_PENDING) {
                $bAllParsedPending = false;
                break;
            }
        }

        return $bAllParsedPending
            ? 'logingrupa.goodsreceivedshopaholic::lang.flash.upload_rejected_parsed_pending'
            : 'logingrupa.goodsreceivedshopaholic::lang.flash.upload_rejected_duplicate';
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
     * AJAX handler: render the Apply confirmation modal listing total units,
     * matched offer count, and unmatched line count for an invoice the
     * operator is about to apply (UI-04 / D-12).
     *
     * Total-units math sums `COALESCE(override_qty, qty)` across matched
     * lines because StockApplyService applies `override_qty ?? qty` per line
     * (Phase 3 plan 03-03). DB::raw avoids the N+1 hydration cost of a
     * Collection iteration.
     *
     * Response shape (canonical popup widget contract — see
     * modules/backend/assets/foundation/controls/popup/README.md):
     *   ['result' => makePartial('_partials/apply_confirm', [...])]
     *
     * October popup.js (line 93) reads ONLY `data.result` via
     * `self.setContent(data.result)`. Returning the assoc-array with `result`
     * key is equivalent to returning the partial as a string (Larajax wraps
     * strings to `['result' => $s]` — vendor/larajax/larajax/src/Classes/AjaxResponse.php:126-128)
     * and keeps the typed array return contract intact. The prior multi-key
     * shape (`#applyConfirm` + `partial` + `result`) routed `#applyConfirm`
     * through dataWithUpdateSelectors as a patchDom op against a non-existent
     * DOM anchor (anchor div removed in commit 3a945cd) — silent no-op but
     * violates the canonical contract.
     *
     * @return array<string, mixed>
     *
     * @throws AjaxException
     */
    public function onApplyShowConfirm(): array
    {
        $this->assertPermission('logingrupa.goodsreceived.apply_invoices');

        $iInvoiceId = $this->scalarToInt(Input::get('invoice_id'));
        $obInvoice = Invoice::find($iInvoiceId);
        if (! $obInvoice instanceof Invoice) {
            throw new AjaxException([
                'message' => (string) Lang::get(
                    'logingrupa.goodsreceivedshopaholic::lang.apply.invoice_not_found',
                    ['id' => $iInvoiceId],
                ),
            ]);
        }

        $iTotalUnits = (int) InvoiceLine::where('invoice_id', $iInvoiceId)
            ->whereNotNull('matched_offer_id')
            ->sum(DB::raw('COALESCE(override_qty, qty)'));

        $mPartial = $this->makePartial('_partials/apply_confirm', [
            'invoice'         => $obInvoice,
            'total_units'     => $iTotalUnits,
            'offer_count'     => (int) $obInvoice->matched_lines,
            'unmatched_count' => (int) $obInvoice->unmatched_lines,
        ]);

        return [
            'result' => is_string($mPartial) ? $mPartial : '',
        ];
    }

    /**
     * AJAX handler: execute apply via ApplyOrchestrator. Cache::lock prevents
     * double-click double-apply (D-13 / T-04-05-01). The orchestrator itself
     * uses Invoice::lockForUpdate() inside its DB::transaction (Phase 3 D-24)
     * — the Cache::lock here is a CHEAPER first line of defense that fails
     * fast on the SECOND click instead of waiting for the DB row lock to
     * release.
     *
     * The `try/finally` is the load-bearing pattern (T-04-05-02 mitigation):
     * even if ApplyOrchestrator throws ANY exception, the finally releases
     * the cache lock. The inner try/catch around the orchestrator call is
     * for the typed `ApplyAlreadyDoneException` only — every other exception
     * propagates with the lock still released by the outer finally.
     *
     * Response shape:
     *   - lock-not-acquired: ['#applyResult' => makePartial('_partials/apply_in_progress')]
     *   - already-applied:   ['#applyResult' => makePartial('_partials/apply_already_done', [...]),
     *                         'redirect' => Backend::url('.../invoices/update/{id}')]
     *   - success:           ['#applyResult' => makePartial('_partials/apply_success', [...]),
     *                         'redirect' => Backend::url('.../invoices/update/{id}')]
     *
     * UX redesign 2026-04-30 — success + already-applied branches add the
     * `redirect` key so Larajax navigates to the invoice detail page after
     * apply. Operator lands on the audit panel + applied lines + Flash
     * banner instead of reloading the list and hunting for the row.
     *
     * @return array<string, mixed>
     *
     * @throws AjaxException
     */
    public function onApply(): array
    {
        $this->assertPermission('logingrupa.goodsreceived.apply_invoices');

        $iInvoiceId = $this->scalarToInt(Input::get('invoice_id'));
        if ($iInvoiceId <= 0) {
            throw new AjaxException([
                'message' => (string) Lang::get('logingrupa.goodsreceivedshopaholic::lang.apply.invoice_id_required'),
            ]);
        }

        $iUserId = $this->resolveBackendUserId();

        // UX redesign 2026-04-30 — persist modal-form edits BEFORE the
        // ApplyOrchestrator runs. Two POST payloads land here from
        // `_apply_modal.htm`:
        //   - `override_qty[<lineId>]` — per-row number input. NULL value
        //     (placeholder cleared by operator) means "use parsed qty";
        //     non-empty integer overrides. StockApplyService reads
        //     `override_qty ?? qty` so this just lands on the right column.
        //   - `notes` — free-text textarea on the Invoice row.
        // Both saves run BEFORE Cache::lock acquisition because the lock
        // guards the orchestrator's idempotency contract, not the metadata
        // edits. A partial-save scenario (override saved, lock not acquired)
        // is fine — the next click re-issues the same edits + acquires.
        $this->persistApplyModalEdits($iInvoiceId);

        $obLock = Cache::lock(sprintf('apply-invoice-%d', $iInvoiceId), self::APPLY_LOCK_TTL_SECONDS);
        if (! $obLock->get()) {
            Flash::warning((string) Lang::get('logingrupa.goodsreceivedshopaholic::lang.flash.apply_in_progress'));

            return [
                '#applyResult' => $this->makePartial('_partials/apply_in_progress'),
            ];
        }

        try {
            return $this->runApplyUnderLock($iInvoiceId, $iUserId);
        } finally {
            $obLock->release();
        }
    }

    /**
     * AJAX handler — bulk apply across operator-selected list rows.
     *
     * UAT 2026-05-05: operator uploaded 16 `.HTM` files, applied one
     * via the in-popup button, then could not reach the remaining 15
     * parsed invoices' Apply buttons (modal closed, re-upload blocked
     * by the parsed-pending duplicate gate). The per-row Apply button
     * on the detail page (commit f371976) unblocks the workflow but
     * forces 15 round-trips. This bulk handler accepts the list-widget
     * `checked[]` POST and applies every selected `parsed`-status row
     * in one request.
     *
     * Status-skip contract (per UAT guidance): rows with status !=
     * `parsed` are SILENTLY skipped. Operator can safely shift-click
     * across mixed rows; already-applied rows are no-op rather than
     * raising an error. `failed` and `rejected_duplicate` rows are
     * also skipped (operator must explicitly retry those via the
     * detail page).
     *
     * Per-row apply runs through the same Cache::lock + orchestrator
     * path as `onApply`, so every Tiger-Style invariant from the
     * single-apply contract is honoured (idempotency,
     * lockForUpdate, audit log). Lock TTL is per invoice id, so
     * parallel single-applies from another operator on a row not in
     * this batch are not blocked.
     *
     * @return array<string, mixed>
     *
     * @throws AjaxException
     */
    public function onApplyBulk(): array
    {
        $this->assertPermission('logingrupa.goodsreceived.apply_invoices');

        $mChecked = Input::get('checked');
        $arCheckedIds = $this->normalizeCheckedIds($mChecked);
        if ($arCheckedIds === []) {
            throw new AjaxException([
                'message' => (string) Lang::get(
                    'logingrupa.goodsreceivedshopaholic::lang.apply.bulk_no_selection',
                ),
            ]);
        }

        $iUserId = $this->resolveBackendUserId();

        $arParsedIds = $this->fetchParsedInvoiceIds($arCheckedIds);

        $iApplied = 0;
        $iSkipped = count($arCheckedIds) - count($arParsedIds);
        $iFailed = 0;
        $arErrorIds = [];
        $iTotalUnits = 0;

        foreach ($arParsedIds as $iInvoiceId) {
            $obLock = Cache::lock(sprintf('apply-invoice-%d', $iInvoiceId), self::APPLY_LOCK_TTL_SECONDS);
            if (! $obLock->get()) {
                $iFailed++;
                $arErrorIds[] = $iInvoiceId;
                continue;
            }
            try {
                $obResult = $this->resolveApplyOrchestrator()->apply($iInvoiceId, $iUserId);
                $iApplied++;
                $iTotalUnits += (int) $obResult->units_added;
            } catch (ApplyAlreadyDoneException $obException) {
                $iSkipped++;
            } catch (Throwable $obException) {
                Log::error('goodsreceived.apply_bulk.failed', [
                    'invoice_id' => $iInvoiceId,
                    'exception'  => (string) $obException,
                ]);
                $iFailed++;
                $arErrorIds[] = $iInvoiceId;
            } finally {
                $obLock->release();
            }
        }

        Flash::success((string) Lang::get(
            'logingrupa.goodsreceivedshopaholic::lang.flash.apply_bulk_summary',
            [
                'applied' => $iApplied,
                'skipped' => $iSkipped,
                'failed'  => $iFailed,
                'units'   => $iTotalUnits,
            ],
        ));

        if ($iFailed > 0) {
            return [];
        }

        return [
            'redirect' => Backend::url('logingrupa/goodsreceivedshopaholic/invoices'),
        ];
    }

    /**
     * Filter `list<int>` of candidate invoice ids down to the ones whose
     * status is `parsed`. Extracted from `onApplyBulk` so PHPStan L10
     * sees the typed return shape rather than the mixed-typed pluck()
     * collection (which trips `cast.int` on the foreach cast).
     *
     * @param  list<int>  $arCandidateIds
     * @return list<int>
     */
    private function fetchParsedInvoiceIds(array $arCandidateIds): array
    {
        if ($arCandidateIds === []) {
            return [];
        }

        $arOut = [];
        $obRows = Invoice::whereIn('id', $arCandidateIds)
            ->where('status', Invoice::STATUS_PARSED)
            ->get(['id']);
        foreach ($obRows as $obInvoice) {
            if (! $obInvoice instanceof Invoice) {
                continue;
            }
            $arOut[] = (int) $obInvoice->id;
        }

        return $arOut;
    }

    /**
     * Coerce the raw `checked[]` POST payload into `list<int>`. October's
     * list widget posts an array of stringified integer ids; defensively
     * filter non-numeric entries (no malformed-POST UI error — clamp to
     * empty list and let the caller handle the empty-selection case).
     *
     * @param  mixed  $mChecked
     * @return list<int>
     */
    private function normalizeCheckedIds(mixed $mChecked): array
    {
        if (! is_array($mChecked)) {
            return [];
        }

        $arOut = [];
        foreach ($mChecked as $mId) {
            if (! is_scalar($mId)) {
                continue;
            }
            $iId = (int) $mId;
            if ($iId > 0) {
                $arOut[] = $iId;
            }
        }

        return array_values(array_unique($arOut));
    }

    /**
     * AJAX handler — delete an invoice with stock-rollback semantics.
     *
     *   - status=applied  → reverse the stock writes (subtract every
     *                       matched line's `override_qty ?? qty` from
     *                       `Offer.quantity`), run ActiveFlagService so
     *                       offers/products go inactive at zero, then
     *                       cascade-delete the invoice + lines.
     *   - status=parsed   → just delete (no stock was written).
     *   - RESET marker    → just delete (no real stock writes — initial
     *                       reset is recorded but cannot be undone via
     *                       this handler).
     *
     * Override chain guard: refuses to delete an invoice that has been
     * overridden by a later applied invoice (would break the audit
     * chain). Operator must delete the override first.
     *
     * Permission gate: `apply_invoices` (same surface as forward apply
     * — rollback is the inverse operation, requires the same trust).
     *
     * @return array<string, mixed>
     *
     * @throws AjaxException
     */
    public function onDelete(): array
    {
        $this->assertPermission('logingrupa.goodsreceived.apply_invoices');

        $iInvoiceId = $this->scalarToInt(Input::get('invoice_id'));
        if ($iInvoiceId <= 0) {
            throw new AjaxException([
                'message' => (string) Lang::get(
                    'logingrupa.goodsreceivedshopaholic::lang.apply.invoice_id_required',
                ),
            ]);
        }

        $obInvoice = Invoice::find($iInvoiceId);
        if (! $obInvoice instanceof Invoice) {
            throw new AjaxException([
                'message' => (string) Lang::get(
                    'logingrupa.goodsreceivedshopaholic::lang.apply.invoice_not_found',
                    ['id' => $iInvoiceId],
                ),
            ]);
        }

        $bHasOverrides = Invoice::where('override_of_invoice_id', $iInvoiceId)->exists();
        if ($bHasOverrides) {
            throw new AjaxException([
                'message' => (string) Lang::get(
                    'logingrupa.goodsreceivedshopaholic::lang.exception.delete_blocked_by_override',
                ),
            ]);
        }

        DB::transaction(function () use ($obInvoice): void {
            if ((string) $obInvoice->status === Invoice::STATUS_APPLIED && ! (bool) $obInvoice->initial_reset_applied) {
                $this->reverseAppliedStockWrites($obInvoice);
            }
            $obInvoice->delete();
        });

        Flash::success((string) Lang::get(
            'logingrupa.goodsreceivedshopaholic::lang.flash.invoice_deleted',
            ['number' => (string) $obInvoice->invoice_number],
        ));

        return [
            'redirect' => Backend::url('logingrupa/goodsreceivedshopaholic/invoices'),
        ];
    }

    /**
     * Subtract each matched line's applied units from the parent offer's
     * `Offer.quantity`. Mirrors `StockApplyService::apply` in reverse:
     *   - For each matched InvoiceLine, decrement `Offer.quantity` by
     *     `override_qty ?? qty` via saveQuietly (no event spam).
     *   - Reconcile via ActiveFlagService so an offer that returns to
     *     zero deactivates per the auto-deactivate setting.
     *
     * Negative results are allowed (Lovata permits negative quantity for
     * back-order semantics). The operator's signal that something went
     * wrong is a negative value showing on the storefront — not a thrown
     * exception in the rollback path.
     */
    private function reverseAppliedStockWrites(Invoice $obInvoice): void
    {
        $arLines = InvoiceLine::where('invoice_id', (int) $obInvoice->id)
            ->whereNotNull('matched_offer_id')
            ->get();

        $arAffectedOfferIds = [];
        foreach ($arLines as $obLine) {
            if (! $obLine instanceof InvoiceLine) {
                continue;
            }
            $iOfferId = $this->scalarToInt($obLine->matched_offer_id);
            if ($iOfferId <= 0) {
                continue;
            }
            $mOverride = $obLine->override_qty;
            $mQty = $obLine->qty;
            $iUnits = $mOverride !== null
                ? $this->scalarToInt($mOverride)
                : $this->scalarToInt($mQty);
            if ($iUnits <= 0) {
                continue;
            }
            $obOffer = Offer::find($iOfferId);
            if (! $obOffer instanceof Offer) {
                continue;
            }
            $obOffer->quantity = $this->scalarToInt($obOffer->quantity) - $iUnits;
            $obOffer->saveQuietly();
            $arAffectedOfferIds[] = $iOfferId;
        }

        if ($arAffectedOfferIds !== []) {
            $this->resolveActiveFlagService()->reconcile(array_values(array_unique($arAffectedOfferIds)));
        }
    }

    /**
     * Resolve ActiveFlagService from the IoC container. Protected so the
     * Pest test shim can swap the seam (mirrors `resolveApplyOrchestrator`
     * + `resolveParseOrchestrator`).
     */
    protected function resolveActiveFlagService(): \Logingrupa\GoodsReceivedShopaholic\Classes\Apply\ActiveFlagService
    {
        return app(\Logingrupa\GoodsReceivedShopaholic\Classes\Apply\ActiveFlagService::class);
    }

    /**
     * Persist the `override_qty[]` POST array + `notes` field that arrive
     * from `_apply_modal.htm`. Idempotent — safe to call again with the
     * same payload (line saves use saveQuietly, notes save uses saveQuietly
     * — neither emits side-effect events).
     *
     * `override_qty` shape after PHP form decoding: `array<int|string,
     * scalar>` keyed by InvoiceLine id. Empty-string values map to NULL
     * (operator cleared the override → fall back to parsed qty per the
     * `override_qty ?? qty` contract in StockApplyService). Non-numeric
     * scalars or negatives reject silently (clamped to NULL) — server-side
     * defense against malformed POSTs without bubbling a UI error for what
     * is operator-correctable noise.
     *
     * Visibility: protected so the Pest TestableInvoices shim could
     * override the seam if a future test wants to swap the persistence
     * path; production callers always reach this through `onApply`.
     */
    protected function persistApplyModalEdits(int $iInvoiceId): void
    {
        $this->persistOverrideQtyEdits($iInvoiceId);
        $this->persistNotesEdit($iInvoiceId);
    }

    /**
     * Per-line `override_qty[<lineId>]` POST array → InvoiceLine.override_qty
     * column. Walks the array, validates each value, saves only the lines
     * that actually changed (saveQuietly avoids touching `updated_at` on
     * unchanged rows).
     */
    private function persistOverrideQtyEdits(int $iInvoiceId): void
    {
        $mPayload = Input::get('override_qty');
        if (! is_array($mPayload)) {
            return;
        }

        foreach ($mPayload as $mLineId => $mValue) {
            $iLineId = (int) $mLineId;
            if ($iLineId <= 0) {
                continue;
            }

            $obLine = InvoiceLine::where('invoice_id', $iInvoiceId)
                ->where('id', $iLineId)
                ->first();
            if (! $obLine instanceof InvoiceLine) {
                continue;
            }

            $mNewOverride = $this->normalizeOverrideQtyValue($mValue);
            if ($obLine->override_qty === $mNewOverride) {
                continue;
            }

            $obLine->override_qty = $mNewOverride;
            $obLine->saveQuietly();
        }
    }

    /**
     * Single `notes` POST field → Invoice.notes column. Trims + treats empty
     * as NULL (DB column is nullable per Phase 1 schema).
     */
    private function persistNotesEdit(int $iInvoiceId): void
    {
        if (! Input::has('notes')) {
            return;
        }

        $mNotes = Input::get('notes');
        $sNotes = is_scalar($mNotes) ? trim((string) $mNotes) : '';
        $mFinal = $sNotes !== '' ? $sNotes : null;

        $obInvoice = Invoice::find($iInvoiceId);
        if (! $obInvoice instanceof Invoice) {
            return;
        }

        if ($obInvoice->notes === $mFinal) {
            return;
        }

        $obInvoice->notes = $mFinal;
        $obInvoice->saveQuietly();
    }

    /**
     * Coerce the raw POST value to NULL or a non-negative integer.
     * Rejects: non-numeric, negative, decimal with non-zero fraction,
     * empty string, scientific notation. Empty string means "no override"
     * (the modal's clear-input UX → fall back to parsed qty).
     */
    private function normalizeOverrideQtyValue(mixed $mValue): ?int
    {
        if ($mValue === null) {
            return null;
        }

        if (! is_scalar($mValue)) {
            return null;
        }

        $sValue = trim((string) $mValue);
        if ($sValue === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $sValue) !== 1) {
            return null;
        }

        return (int) $sValue;
    }

    /**
     * AJAX handler: render the OVERRIDE warning modal (UI-10 / D-18).
     *
     * The modal carries the literal warning copy ("ADDITIVELY on top of the
     * prior apply" / "NOT a delta calculation") plus a typed-input field that
     * the operator must fill with the literal `OVERRIDE` (case-sensitive).
     * Permission-gated by `override_invoices` (D-20). The prior_invoice_id is
     * threaded through so the typed-confirm submit can route to runOverride
     * with the right linkage.
     *
     * Response shape (canonical popup widget contract — popup.js:93 reads
     * `data.result`):
     *   ['result' => makePartial('_partials/override_confirm', [...])]
     *
     * @return array<string, mixed>
     *
     * @throws AjaxException
     */
    public function onOverrideShowConfirm(): array
    {
        $this->assertPermission('logingrupa.goodsreceived.override_invoices');

        $iPriorInvoiceId = $this->scalarToInt(Input::get('prior_invoice_id'));
        if ($iPriorInvoiceId <= 0) {
            throw new AjaxException(['message' => 'prior_invoice_id is required.']);
        }

        $mPartial = $this->makePartial('_partials/override_confirm', [
            'prior_invoice_id' => $iPriorInvoiceId,
        ]);

        return [
            'result' => is_string($mPartial) ? $mPartial : '',
        ];
    }

    /**
     * AJAX handler: process the typed-OVERRIDE submission. Validates the
     * literal then routes through ParseAndPersistOrchestrator::runOverride
     * (UI-10 / D-19 / D-21).
     *
     * The new Invoice's `override_of_invoice_id` points at the prior; the
     * operator subsequently clicks Apply on the new row to execute the
     * ADD-ON-TOP write (Phase 3 D-12 — apply orchestrator runs unchanged).
     * Permission-gated by `override_invoices` (D-20).
     *
     * Strict case-sensitive comparison on the OVERRIDE literal: `===` on the
     * raw input string before any orchestrator wiring runs (T-04-06-01
     * mitigation — server-side gate is the source of truth, client-side
     * check is UX only).
     *
     * Response shape on happy path:
     *   ['#invoicePreviewWrap' => makePartial('_partials/preview_lines', [...]),
     *    '#invoiceRejectWrap'  => '']
     *
     * @return array<string, mixed>
     *
     * @throws AjaxException
     */
    public function onOverrideConfirm(): array
    {
        $this->assertPermission('logingrupa.goodsreceived.override_invoices');

        $mTyped = Input::get('confirm_typed');
        $sTyped = is_scalar($mTyped) ? strval($mTyped) : '';
        if ($sTyped !== self::OVERRIDE_LITERAL) {
            throw new AjaxException([
                'message' => sprintf('Type %s exactly to confirm override.', self::OVERRIDE_LITERAL),
            ]);
        }

        $iPriorInvoiceId = $this->scalarToInt(Input::get('prior_invoice_id'));
        if ($iPriorInvoiceId <= 0) {
            throw new AjaxException(['message' => 'prior_invoice_id is required.']);
        }

        $arFiles = $this->getUploadedFiles();
        if ($arFiles === null || $arFiles === []) {
            throw new AjaxException(['message' => 'No file provided for override.']);
        }
        $obFile = $arFiles[0];

        $this->assertHtmFile($obFile);
        $iUserId = $this->resolveBackendUserId();
        $sHtml = $this->readFileContents($obFile);
        $sFilename = (string) $obFile->getClientOriginalName();

        $obOrchestrator = $this->resolveParseOrchestrator();
        $obInvoice = $obOrchestrator->runOverride($sHtml, $sFilename, $iPriorInvoiceId, $iUserId);

        $this->attachOriginalFile($obInvoice, $obFile);

        return [
            '#invoicePreviewWrap' => $this->makePartial('_partials/preview_lines', [
                'invoices' => [$this->buildPreviewPayload($obInvoice)],
            ]),
            '#invoiceRejectWrap' => '',
        ];
    }

    /**
     * AJAX handler: render the RESET warning modal with pre-mutation
     * offer + product counts (UI-08 / D-22 / D-23).
     *
     * Two-gate guard mirrors InitialResetService::assertAllowed: the
     * SettingsAccessor::allowInitialReset() toggle must be on AND no prior
     * Invoice with initial_reset_applied=true may exist. Both branches throw
     * AjaxException with `reason` in `arContents` so the controller can
     * render distinct error UX per cause (T-04-06-02 defense-in-depth).
     * Permission-gated by `run_initial_reset` (D-25 / T-04-06-03).
     *
     * Snapshot counts (Offer::count() + Product::count()) are surfaced to
     * the operator BEFORE the destructive op runs — the modal lists them
     * verbatim so the operator sees exact catalog scale before typing
     * RESET.
     *
     * Response shape (canonical popup widget contract — popup.js:93 reads
     * `data.result`):
     *   ['result' => makePartial('_partials/initial_reset_confirm', [...])]
     *
     * @return array<string, mixed>
     *
     * @throws AjaxException
     */
    public function onInitialResetShowConfirm(): array
    {
        $this->assertPermission('logingrupa.goodsreceived.run_initial_reset');

        $this->assertInitialResetAllowed();

        $iOfferCount = (int) Offer::count();
        $iProductCount = (int) Product::count();

        $mPartial = $this->makePartial('_partials/initial_reset_confirm', [
            'offer_count'   => $iOfferCount,
            'product_count' => $iProductCount,
        ]);

        return [
            'result' => is_string($mPartial) ? $mPartial : '',
        ];
    }

    /**
     * AJAX handler: validate the typed RESET literal, then run InitialReset
     * BEFORE ApplyOrchestrator::apply on the uploaded invoice (UI-08 / D-24).
     *
     * Order is contract: parse-and-persist → reset → apply. The new Invoice
     * is parsed first (so we have an Invoice id to flip
     * `initial_reset_applied=true` on); InitialResetService::reset zeros
     * every offer + deactivates every product; THEN ApplyOrchestrator::apply
     * increments stock from the new invoice's matched lines. After this,
     * the offer's quantity reflects the NEW invoice's qty (NOT
     * prior_qty + qty), which is the test-pinned behavior in
     * `InitialResetConfirmTest::onInitialResetConfirm with literal RESET
     * runs reset BEFORE apply`.
     *
     * Permission-gated by `run_initial_reset` (D-25). Reset itself runs the
     * two-gate guard a second time (defense-in-depth — T-04-06-02), so
     * even if the operator bypasses the controller's guard via curl the
     * service still refuses to run.
     *
     * Response shape on happy path:
     *   ['#applyResult' => makePartial('_partials/apply_success', [...])]
     *
     * @return array<string, mixed>
     *
     * @throws AjaxException
     */
    public function onInitialResetConfirm(): array
    {
        $this->assertPermission('logingrupa.goodsreceived.run_initial_reset');

        $mTyped = Input::get('confirm_typed');
        $sTyped = is_scalar($mTyped) ? strval($mTyped) : '';
        if ($sTyped !== self::RESET_LITERAL) {
            throw new AjaxException([
                'message' => sprintf('Type %s exactly to confirm initial reset.', self::RESET_LITERAL),
            ]);
        }

        $iUserId = $this->resolveBackendUserId();
        $arFiles = $this->getUploadedFiles();

        // No file → reset-only path (zero stock + deactivate products,
        // no invoice import). The reset service still requires an Invoice
        // row to flip the one-shot bit on, so a sentinel marker invoice
        // is created with status=applied and
        // invoice_number=RESET_NO_INVOICE_<YmdHis_uniq>. The unique-index
        // is honoured by the timestamp + 4-digit suffix.
        if ($arFiles === null || $arFiles === []) {
            return $this->runInitialResetOnly($iUserId);
        }

        $obFile = $arFiles[0];
        $this->assertHtmFile($obFile);
        $sHtml = $this->readFileContents($obFile);
        $sFilename = (string) $obFile->getClientOriginalName();

        return $this->runInitialResetThenApply($sHtml, $sFilename, $iUserId, $obFile);
    }

    /**
     * Helper: should the initial-reset section be visible on the upload form?
     *
     * Public so `preview.htm` (October compiled-template engine, supports
     * `<?php ?>` blocks in .htm files) can call it inline:
     *
     *   $bShow = $this->shouldShowInitialReset();
     *
     * Two-gate visibility per D-22: SettingsAccessor::allowInitialReset()
     * must be true AND no prior Invoice with initial_reset_applied=true
     * may exist. Either branch returns false to hide the section. The
     * AJAX handler `onInitialResetShowConfirm` re-checks the same gates
     * server-side (T-04-06-02 defense-in-depth) so a stale view does
     * not bypass the gate.
     */
    public function shouldShowInitialReset(): bool
    {
        if (! SettingsAccessor::allowInitialReset()) {
            return false;
        }

        return ! Invoice::where('initial_reset_applied', true)->exists();
    }

    /**
     * Two-gate guard for initial reset (D-22 / T-04-06-02). Surfaces the
     * disposition cause via the `reason` key in AjaxException's `arContents`
     * so the controller can render distinct error UX per branch:
     *   - reason='settings_disabled' → operator must enable the toggle
     *   - reason='already_applied'   → one-shot already consumed; nothing to do
     *
     * Mirrors InitialResetService::assertAllowed(); the controller-side
     * gate exists as defense-in-depth so a misconfigured site never even
     * renders the modal (the service-side gate is the authoritative
     * contract per Phase 3 D-17).
     *
     * @throws AjaxException
     */
    private function assertInitialResetAllowed(): void
    {
        if (! SettingsAccessor::allowInitialReset()) {
            throw new AjaxException([
                'message' => (string) Lang::get(
                    'logingrupa.goodsreceivedshopaholic::lang.exception.initial_reset_not_allowed',
                ),
                'reason' => 'settings_disabled',
            ]);
        }

        if (Invoice::where('initial_reset_applied', true)->exists()) {
            throw new AjaxException([
                'message' => (string) Lang::get(
                    'logingrupa.goodsreceivedshopaholic::lang.exception.initial_reset_not_allowed',
                ),
                'reason' => 'already_applied',
            ]);
        }
    }

    /**
     * Compose the parse → reset → apply triad for the initial-reset confirm
     * handler. Extracted as a private helper to keep `onInitialResetConfirm`
     * under the 70-line / max-1-nesting Tiger-Style cap.
     *
     * Each step's failure mode propagates: orchestrator typed exceptions
     * surface as AjaxException via October's dispatcher; the only catch
     * here is the typed `InitialResetNotAllowedException` from the
     * service-side gate, which is rethrown as an AjaxException carrying
     * the same `reason` payload as `assertInitialResetAllowed` for
     * consistent UI handling.
     *
     * @return array<string, mixed>
     *
     * @throws AjaxException
     */
    private function runInitialResetThenApply(string $sHtml, string $sFilename, int $iUserId, UploadedFile $obFile): array
    {
        $obParse = $this->resolveParseOrchestrator();
        $obInvoice = $obParse->run($sHtml, $sFilename, $iUserId);

        $this->attachOriginalFile($obInvoice, $obFile);

        $obReset = $this->resolveInitialResetService();
        try {
            $obReset->reset($obInvoice);
        } catch (InitialResetNotAllowedException $obException) {
            throw new AjaxException([
                'message' => $obException->getMessage(),
                'reason' => $obException->arContext['reason'] ?? 'unknown',
            ]);
        } catch (Throwable $obException) {
            Log::error('goodsreceived.initial_reset.unexpected', [
                'invoice_id' => (int) $obInvoice->id,
                'filename'   => $sFilename,
                'exception'  => (string) $obException,
            ]);
            throw new AjaxException([
                'message' => (string) Lang::get(
                    'logingrupa.goodsreceivedshopaholic::lang.exception.initial_reset_unexpected',
                    ['id' => (int) $obInvoice->id],
                ),
            ]);
        }

        $obApply = $this->resolveApplyOrchestrator();
        $obResult = $obApply->apply((int) $obInvoice->id, $iUserId);

        Flash::success((string) Lang::get(
            'logingrupa.goodsreceivedshopaholic::lang.flash.apply_success',
            [
                'id'     => (int) $obInvoice->id,
                'units'  => (int) $obResult->units_added,
                'offers' => (int) $obResult->offers_touched,
            ],
        ));

        return [
            '#applyResult' => $this->makePartial('_partials/apply_success', [
                'invoice_id' => (int) $obInvoice->id,
                'result'     => $obResult,
            ]),
        ];
    }

    /**
     * File-less initial reset — zero stock + deactivate products, NO
     * invoice import. Operator typed RESET but did not select a `.HTM`
     * file, signaling "I want a clean baseline; the next upload will
     * be the first stock event." The reset service requires an Invoice
     * row to flip the one-shot bit on, so a sentinel marker invoice is
     * created with status=applied and a synthetic invoice_number so
     * the UNIQUE index is honoured.
     *
     * @return array<string, mixed>
     *
     * @throws AjaxException
     */
    private function runInitialResetOnly(int $iUserId): array
    {
        $sNow = \Carbon\Carbon::now();
        $sSentinel = sprintf(
            'RESET_NO_INVOICE_%s_%04d',
            $sNow->format('YmdHis'),
            random_int(0, 9999),
        );

        $obInvoice = new Invoice();
        $obInvoice->invoice_number = $sSentinel;
        $obInvoice->status = Invoice::STATUS_APPLIED;
        $obInvoice->total_lines = 0;
        $obInvoice->matched_lines = 0;
        $obInvoice->unmatched_lines = 0;
        $obInvoice->stock_added_units = 0;
        $obInvoice->parsed_at = $sNow;
        $obInvoice->applied_at = $sNow;
        $obInvoice->applied_by_user_id = $iUserId;
        $obInvoice->initial_reset_applied = false;
        $obInvoice->source_filename = $sSentinel;
        $obInvoice->saveQuietly();

        $obReset = $this->resolveInitialResetService();
        try {
            $obReset->reset($obInvoice);
        } catch (InitialResetNotAllowedException $obException) {
            throw new AjaxException([
                'message' => $obException->getMessage(),
                'reason' => $obException->arContext['reason'] ?? 'unknown',
            ]);
        } catch (Throwable $obException) {
            // Boundary sanitizer (same pattern as upload error path):
            // any non-typed Throwable from the reset transaction (e.g.
            // QueryException with bound SQL params) must NOT surface raw
            // to the operator-visible flash. Log full exception for ops
            // forensics; show a generic message + invoice id so the
            // operator can correlate via the Invoices list.
            Log::error('goodsreceived.initial_reset.unexpected', [
                'invoice_id' => (int) $obInvoice->id,
                'sentinel'   => $sSentinel,
                'exception'  => (string) $obException,
            ]);
            throw new AjaxException([
                'message' => (string) Lang::get(
                    'logingrupa.goodsreceivedshopaholic::lang.exception.initial_reset_unexpected',
                    ['id' => (int) $obInvoice->id],
                ),
            ]);
        }

        Flash::success((string) Lang::get(
            'logingrupa.goodsreceivedshopaholic::lang.flash.initial_reset_only_success',
            ['id' => (int) $obInvoice->id],
        ));

        return [
            '#applyResult' => $this->makePartial('_partials/apply_success', [
                'invoice_id' => (int) $obInvoice->id,
                'result'     => null,
            ]),
        ];
    }

    /**
     * Resolve the InitialResetService from the IoC container. Protected so
     * the Pest test shim can swap the reset path for a tracking double
     * (mirrors `resolveApplyOrchestrator` from plan 04-05 D-04-05-01 +
     * `resolveParseOrchestrator` from plan 04-04 D-04-04-02).
     */
    protected function resolveInitialResetService(): InitialResetService
    {
        return app(InitialResetService::class);
    }

    /**
     * Inner apply path executed inside the Cache::lock try/finally. Catches
     * `ApplyAlreadyDoneException` to render the structured already-done
     * partial (T-04-05-04); all other exceptions propagate so the outer
     * finally still releases the lock.
     *
     * @return array<string, mixed>
     */
    private function runApplyUnderLock(int $iInvoiceId, int $iUserId): array
    {
        $obOrchestrator = $this->resolveApplyOrchestrator();

        try {
            $obResult = $obOrchestrator->apply($iInvoiceId, $iUserId);

            Flash::success((string) Lang::get(
                'logingrupa.goodsreceivedshopaholic::lang.flash.apply_success',
                [
                    'id'     => $iInvoiceId,
                    'units'  => (int) $obResult->units_added,
                    'offers' => (int) $obResult->offers_touched,
                ],
            ));

            return [
                '#applyResult' => $this->makePartial('_partials/apply_success', [
                    'invoice_id' => $iInvoiceId,
                    'result'     => $obResult,
                ]),
                'redirect' => Backend::url('logingrupa/goodsreceivedshopaholic/invoices/update/'.$iInvoiceId),
            ];
        } catch (ApplyAlreadyDoneException $obException) {
            Flash::info((string) Lang::get('logingrupa.goodsreceivedshopaholic::lang.flash.apply_already_done'));

            return [
                '#applyResult' => $this->makePartial('_partials/apply_already_done', [
                    'context' => $obException->arContext,
                ]),
                'redirect' => Backend::url('logingrupa/goodsreceivedshopaholic/invoices/update/'.$iInvoiceId),
            ];
        }
    }

    /**
     * Resolve the apply orchestrator from the IoC container. Protected so the
     * Pest test shim can swap the apply path for a tracking double without
     * needing to subclass the (now non-final) orchestrator itself —
     * D-04-05-01 opens ApplyOrchestrator from `final` for boundary-mock
     * support, mirroring D-03-07-01 (ImportAuditService) + D-04-02-01
     * (ActiveFlagService) precedent.
     *
     * Larastan's `app()` extension narrows the typed return — no defensive
     * `instanceof` guard needed (mirrors D-04-02-02). Production callers
     * always resolve via this hook; the shim subclass overrides it directly
     * without forging the IoC binding.
     */
    protected function resolveApplyOrchestrator(): ApplyOrchestrator
    {
        return app(ApplyOrchestrator::class);
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

            // Pre-parse short-circuit: ONLY the applied-prior case rejects
            // here. A prior `status='parsed'` row is treated as "operator
            // re-uploading to refresh the preview" — we let the orchestrator
            // run, delete the prior parsed row inside its transaction, and
            // return a fresh preview. UAT 2026-05-05: the prior reject path
            // for parsed-pending blocked re-upload after the operator
            // closed the modal, leaving an unreachable orphan invoice row.
            $sNumber = $this->extractInvoiceNumberFromFilename($sFilename);
            if ($sNumber !== null) {
                $obPrior = Invoice::where('invoice_number', $sNumber)
                    ->where('status', Invoice::STATUS_APPLIED)
                    ->first();
                if ($obPrior instanceof Invoice) {
                    $arRejects[] = $this->buildRejectPayload($obPrior, self::REJECT_REASON_DUPLICATE_APPLIED);

                    return;
                }
            }

            $sHtml = $this->readFileContents($obFile);
            $obOrchestrator = $this->resolveParseOrchestrator();
            $obInvoice = $obOrchestrator->run($sHtml, $sFilename, $iUserId);

            $this->attachOriginalFile($obInvoice, $obFile);

            $arPreviews[] = $this->buildPreviewPayload($obInvoice);
        } catch (GoodsReceivedException $obException) {
            // GoodsReceivedException carries operator-safe lang.exception.*
            // messages by contract — render as-is.
            $arErrors[] = ['filename' => $sFilename, 'message' => $obException->getMessage()];
        } catch (Throwable $obException) {
            // Any non-plugin Throwable (QueryException / PDOException /
            // connection errors / framework bugs) may carry SQL fragments,
            // bound params, or driver/connection details inside
            // `getMessage()`. Boundary sanitizer per project CLAUDE.md:
            // surface a generic operator-facing message and emit the full
            // exception (including stack) to the server-side log channel
            // for forensic follow-up. Never let raw driver text reach the
            // upload errors panel.
            Log::error('goodsreceived.upload.unexpected', [
                'filename'  => $sFilename,
                'exception' => (string) $obException,
            ]);
            $arErrors[] = [
                'filename' => $sFilename,
                'message'  => (string) Lang::get(
                    'logingrupa.goodsreceivedshopaholic::lang.upload.unexpected_error',
                ),
            ];
        }
    }

    /**
     * BUG 4 fix — attach the uploaded `.HTM` file to `Invoice.original_file`
     * (October's `attachOne` relation defined on the Invoice model) so the
     * detail page's File widget surfaces the source artefact for audit /
     * re-parse (UI-06 / D-28). October's `System\Models\File` resolves the
     * UploadedFile via `fromPost()` (vendor/october/rain/src/Database/Attach/File.php:105)
     * — copies the temp file to the configured disk, sets file_name /
     * file_size / content_type / disk_name, then the `attachOne`
     * relationship saves the row with the right attachment_type +
     * attachment_id pointer.
     *
     * Replace-stale semantics (BUG fix — file/metadata divergence):
     *   The old idempotent guard (`return early if original_file !== null`)
     *   was unsafe. After a backend cleanup that deleted invoice rows but
     *   did not cascade to `system_files`, a freshly created Invoice could
     *   inherit an orphan attachment whose attachment_id was reused by
     *   auto-increment. The early-return then preserved the orphan file
     *   and the new file was silently dropped, so the Invoice metadata
     *   (invoice_number, source_filename, lines) referenced one HTM while
     *   the download button served a different one. Replace-on-attach
     *   eliminates that divergence: any pre-existing attachment is
     *   detached + deleted FIRST, then the freshly uploaded file is
     *   attached. The detach is logged so post-mortem audit can trace
     *   which orphan was reaped.
     *
     * Boundary placement: attach happens AFTER ParseAndPersistOrchestrator's
     * `DB::transaction` returns (orchestrator owns DB write atomicity for
     * Invoice + InvoiceLine). The file save is a separate concern (system
     * files table + disk write) and a failure here would NOT roll back the
     * parsed Invoice — that's by design: an unattached file is a
     * cosmetic-only audit gap, not a data-integrity violation.
     *
     * Visibility: protected so the Pest TestableInvoices shim can override
     * to skip the real System\Models\File disk write under the hermetic
     * SQLite-in-memory bootstrap (system_files table is not created in
     * the ApplyTestCase schema slice). Production code path is unchanged.
     */
    protected function attachOriginalFile(Invoice $obInvoice, UploadedFile $obFile): void
    {
        $this->detachExistingOriginalFile($obInvoice);

        $obSystemFile = new \System\Models\File();
        $obSystemFile->fromPost($obFile);
        $obInvoice->original_file()->add($obSystemFile);
    }

    /**
     * Detach + delete any pre-existing `original_file` row attached to the
     * given Invoice so a re-attach never produces stale-file divergence.
     *
     * Logged at warning level when something is actually reaped; silent
     * no-op when the relation is null. The relation is refreshed after
     * delete so a subsequent `original_file()->add(...)` call sees a clean
     * slot (October caches eager-loaded morph relations on the model
     * instance).
     *
     * Visibility: protected so a focused unit test can drive it via
     * Reflection without depending on the disk-write side of `fromPost`.
     */
    protected function detachExistingOriginalFile(Invoice $obInvoice): void
    {
        $obExisting = $obInvoice->original_file;
        if ($obExisting === null) {
            return;
        }

        $mDisk = $obExisting->getAttribute('disk_name');
        $mName = $obExisting->getAttribute('file_name');
        \Log::warning(
            'logingrupa.goodsreceivedshopaholic: replacing stale original_file before attach',
            [
                'invoice_id'    => $this->scalarToInt($obInvoice->id),
                'old_disk_name' => is_scalar($mDisk) ? (string) $mDisk : '',
                'old_file_name' => is_scalar($mName) ? (string) $mName : '',
            ],
        );

        $obExisting->delete();
        $obInvoice->reloadRelations('original_file');
    }

    /**
     * Pre-parse duplicate detection helper — D-16 regex extraction. Returns
     * null when filename does not match the expected pattern; null triggers
     * the full parse path (the body-side InvoiceNumberResolver inside the
     * parser still runs and is the authoritative contract).
     *
     * Visibility: protected so the Pest test shim's reflection invocation
     * remains stable. Reflection is the test-discipline pin —
     * `callExtractInvoiceNumberFromFilename` in
     * tests/unit/Controllers/PreUploadDuplicateDetectionTest.php drives
     * the regex contract directly without widening the public API surface.
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
     * Build the per-file reject payload for the pre-parse duplicate-detection
     * partial. Carries enough audit context (prior_applied_at /
     * prior_applied_by / prior_stock_added_units / prior_invoice_id /
     * prior_parsed_at) for the operator to decide the next action — which
     * branches by `reject_reason`:
     *
     *   - REJECT_REASON_DUPLICATE_APPLIED: route through the Override flow
     *     (plan 04-06).
     *
     *   - REJECT_REASON_PARSED_PENDING: apply or discard the existing parsed
     *     invoice before re-upload — closes the pre-apply duplicate hole that
     *     previously surfaced the unique-index `QueryException` to the UI.
     *
     * @return array<string, mixed>
     */
    private function buildRejectPayload(
        Invoice $obPrior,
        string $sReason = self::REJECT_REASON_DUPLICATE_APPLIED,
    ): array {
        $mAppliedAt = $obPrior->applied_at;
        $sAppliedAt = $mAppliedAt instanceof \Carbon\Carbon ? $mAppliedAt->toIso8601String() : null;

        $mParsedAt = $obPrior->parsed_at;
        $sParsedAt = $mParsedAt instanceof \Carbon\Carbon ? $mParsedAt->toIso8601String() : null;

        return [
            'reject_reason'           => $sReason,
            'invoice_number'          => (string) $obPrior->invoice_number,
            'prior_applied_at'        => $sAppliedAt,
            'prior_applied_by'        => $obPrior->applied_by_user_id,
            'prior_stock_added_units' => (int) $obPrior->stock_added_units,
            'prior_invoice_id'        => (int) $obPrior->id,
            'prior_parsed_at'         => $sParsedAt,
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

        $obLines = InvoiceLine::where('invoice_id', $iInvoiceId)
            ->orderBy('row_index')
            ->get();

        $arOfferIds = $obLines->pluck('matched_offer_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $arCurrentQtyMap = !empty($arOfferIds)
            ? Offer::whereIn('id', $arOfferIds)->pluck('quantity', 'id')->all()
            : [];

        return [
            'invoice'             => $obInvoice,
            'lines'               => $obLines,
            'total_units'         => (int) InvoiceLine::where('invoice_id', $iInvoiceId)->sum('qty'),
            'matched_count'       => (int) $obInvoice->matched_lines,
            'unmatched_count'     => (int) $obInvoice->unmatched_lines,
            'current_qty_map'     => $arCurrentQtyMap,
            'matched_product_map' => $this->buildMatchedProductMap($obLines, $arOfferIds),
        ];
    }

    /**
     * Build line_id => matched offer/product lookup for the apply modal's
     * "Matched Product" column.
     *
     * Two DB queries regardless of line count: offers by id, products by id.
     * Falls back to offer.name when product_id is missing or the product row
     * has been deleted; falls back to '' when neither is reachable.
     *
     * @param  iterable<mixed> $obLines
     * @param  array<mixed>    $arOfferIds
     *
     * @return array<int, array{product_name: string, product_id: int, offer_id: int, strategy: string}>
     */
    private function buildMatchedProductMap(iterable $obLines, array $arOfferIds): array
    {
        if (empty($arOfferIds)) {
            return [];
        }

        $arOfferRows = Offer::whereIn('id', $arOfferIds)
            ->select('id', 'product_id', 'name')
            ->get()
            ->keyBy('id')
            ->all();

        $arProductIds = [];
        foreach ($arOfferRows as $obOffer) {
            /** @phpstan-ignore-next-line property.notFound — Lovata Offer lacks IDE-helper PHPDoc; columns verified at DB layer */
            $iProductId = (int) ($obOffer->product_id ?? 0);
            if ($iProductId > 0) {
                $arProductIds[$iProductId] = true;
            }
        }

        $arProductRows = !empty($arProductIds)
            ? Product::whereIn('id', array_keys($arProductIds))
                ->select('id', 'name')
                ->get()
                ->keyBy('id')
                ->all()
            : [];

        $arMap = [];
        /** @var InvoiceLine $obLine */
        foreach ($obLines as $obLine) {
            $iLineId = (int) $obLine->id;
            $iOfferId = (int) ($obLine->matched_offer_id ?? 0);
            if ($iOfferId === 0 || !isset($arOfferRows[$iOfferId])) {
                continue;
            }
            $obOffer = $arOfferRows[$iOfferId];
            /** @phpstan-ignore-next-line property.notFound — Lovata Offer lacks IDE-helper PHPDoc */
            $iProductId = (int) ($obOffer->product_id ?? 0);
            $arMap[$iLineId] = [
                'product_name' => $this->resolveMatchedDisplayName($obOffer, $arProductRows, $iProductId),
                'product_id'   => $iProductId,
                'offer_id'     => $iOfferId,
                'strategy'     => (string) ($obLine->match_strategy ?? ''),
            ];
        }

        return $arMap;
    }

    /**
     * Display name for the matched-product column. Prefers the Product.name
     * (parent product) over Offer.name (variant); falls back to '' if neither
     * row is reachable from the partial-row pluck.
     *
     * @param  array<mixed> $arProductRows  product_id => Product (id/name keyed)
     */
    private function resolveMatchedDisplayName(mixed $obOffer, array $arProductRows, int $iProductId): string
    {
        if ($iProductId > 0 && isset($arProductRows[$iProductId])) {
            $obProductRow = $arProductRows[$iProductId];
            /** @phpstan-ignore-next-line property.notFound — Lovata Product lacks IDE-helper PHPDoc */
            return (string) $obProductRow->name;
        }
        /** @phpstan-ignore-next-line property.notFound — Lovata Offer lacks IDE-helper PHPDoc */
        return (string) $obOffer->name;
    }
}
