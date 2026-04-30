<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Controllers;

use Backend\Classes\Controller;
use BackendAuth;
use BackendMenu;
use Cache;
use Illuminate\Support\Facades\DB;
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
        BackendMenu::setContext('Lovata.Shopaholic', 'shopaholic-menu', 'goodsreceived');
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
     * Render the shared tab navigation for the Invoices controller. Used by
     * `index.htm`, `upload.htm`, `settings.htm`, and `_list_toolbar.htm` —
     * inline HTML helper avoids partial-resolution edge cases with widget
     * contexts (the list-toolbar partial renders with `$this` bound to the
     * list widget, not the controller, so nested makePartial calls fail).
     */
    public function renderInvoicesTabs(string $sActive): string
    {
        $sUrlList = (string) \Backend::url('logingrupa/goodsreceivedshopaholic/invoices');
        $sUrlUpload = (string) \Backend::url('logingrupa/goodsreceivedshopaholic/invoices/upload');
        $sUrlSettings = (string) \Backend::url('logingrupa/goodsreceivedshopaholic/invoices/settings');

        $sLabelList = (string) Lang::get('logingrupa.goodsreceivedshopaholic::lang.tabs.invoices');
        $sLabelUpload = (string) Lang::get('logingrupa.goodsreceivedshopaholic::lang.tabs.upload');
        $sLabelSettings = (string) Lang::get('logingrupa.goodsreceivedshopaholic::lang.tabs.settings');

        $sClassList = $sActive === 'invoices' ? 'active' : '';
        $sClassUpload = $sActive === 'upload' ? 'active' : '';
        $sClassSettings = $sActive === 'settings' ? 'active' : '';

        return <<<HTML
<ul class="nav nav-tabs" style="margin-bottom: 1rem;">
    <li class="{$sClassList}"><a href="{$sUrlList}"><i class="icon-list"></i> {$sLabelList}</a></li>
    <li class="{$sClassUpload}"><a href="{$sUrlUpload}"><i class="icon-upload"></i> {$sLabelUpload}</a></li>
    <li class="{$sClassSettings}"><a href="{$sUrlSettings}"><i class="icon-cog"></i> {$sLabelSettings}</a></li>
</ul>
HTML;
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
     *     '#invoicePreviewWrap'  => makePartial('_partials/preview_lines', [...]),
     *     '#invoiceRejectWrap'   => makePartial('_partials/reject', [...]),
     *     '#invoiceUploadErrors' => makePartial('_partials/upload_errors', [...]),
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
            '#invoicePreviewWrap'  => $this->makePartial('_partials/preview_lines', ['invoices' => $arPreviews]),
            '#invoiceRejectWrap'   => $this->makePartial('_partials/reject', ['rejects' => $arRejects]),
            '#invoiceUploadErrors' => $this->makePartial('_partials/upload_errors', ['errors' => $arErrors]),
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
     *   - already-applied:   ['#applyResult' => makePartial('_partials/apply_already_done', [...])]
     *   - success:           ['#applyResult' => makePartial('_partials/apply_success', [...])]
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

        $obLock = Cache::lock(sprintf('apply-invoice-%d', $iInvoiceId), self::APPLY_LOCK_TTL_SECONDS);
        if (! $obLock->get()) {
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

        return [
            '#invoicePreviewWrap' => $this->makePartial('_partials/preview_lines', [
                'invoices' => [$this->buildPreviewPayload($obInvoice)],
            ]),
            '#invoiceRejectWrap' => '',
        ];
    }

    /**
     * AJAX handler: render the RESET warning modal with pre-mutation snapshot
     * counts (UI-08 / D-22 / D-23).
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

        $arFiles = $this->getUploadedFiles();
        if ($arFiles === null || $arFiles === []) {
            throw new AjaxException(['message' => 'No file provided for initial reset apply.']);
        }
        $obFile = $arFiles[0];

        $this->assertHtmFile($obFile);
        $iUserId = $this->resolveBackendUserId();
        $sHtml = $this->readFileContents($obFile);
        $sFilename = (string) $obFile->getClientOriginalName();

        return $this->runInitialResetThenApply($sHtml, $sFilename, $iUserId);
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
    private function runInitialResetThenApply(string $sHtml, string $sFilename, int $iUserId): array
    {
        $obParse = $this->resolveParseOrchestrator();
        $obInvoice = $obParse->run($sHtml, $sFilename, $iUserId);

        $obReset = $this->resolveInitialResetService();
        try {
            $obReset->reset($obInvoice);
        } catch (InitialResetNotAllowedException $obException) {
            throw new AjaxException([
                'message' => $obException->getMessage(),
                'reason' => $obException->arContext['reason'] ?? 'unknown',
            ]);
        }

        $obApply = $this->resolveApplyOrchestrator();
        $obResult = $obApply->apply((int) $obInvoice->id, $iUserId);

        return [
            '#applyResult' => $this->makePartial('_partials/apply_success', [
                'invoice_id' => (int) $obInvoice->id,
                'result'     => $obResult,
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

            return [
                '#applyResult' => $this->makePartial('_partials/apply_success', [
                    'invoice_id' => $iInvoiceId,
                    'result'     => $obResult,
                ]),
            ];
        } catch (ApplyAlreadyDoneException $obException) {
            return [
                '#applyResult' => $this->makePartial('_partials/apply_already_done', [
                    'context' => $obException->arContext,
                ]),
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
