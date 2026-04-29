<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Orchestrator;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\MatchedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedInvoice;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\DuplicateInvoiceException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\GoodsReceivedException;
use Logingrupa\GoodsReceivedShopaholic\Classes\Match\EanMatcherService;
use Logingrupa\GoodsReceivedShopaholic\Classes\Parser\HtmInvoiceParser;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\ImportAuditService;
use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;

/**
 * Upload-side orchestrator for the goods-received import pipeline
 * (APPLY-06 / APPLY-08 / D-21..D-28).
 *
 * Wraps parse → duplicate-check → persist Invoice → batch-match → persist
 * InvoiceLine rows in ONE `DB::transaction`. Apply (stock writes) happens
 * LATER via `ApplyOrchestrator` (plan 03-07) — this orchestrator NEVER
 * touches `offer.quantity`.
 *
 * Transaction boundary lives HERE, not in the controller, so Phase 4 stays
 * thin: the controller only marshals input + handles exceptions. On any
 * parse / persist / match failure inside the transaction, the entire unit
 * rolls back atomically; the orchestrator catches the typed plugin
 * exception OUTSIDE the transaction and logs a reject event AFTER the
 * rollback (logging inside the tx would record a half-committed state and
 * roll the log entry back too). The exception is then re-thrown so callers
 * see the failure cleanly.
 *
 * `runOverride()` is the explicit override-reimport entry point per D-26.
 * The caller (Phase 4 controller behind UI-10 'override_invoices'
 * permission) opts in by passing the prior invoice id; the new Invoice
 * gets `override_of_invoice_id` set + a suffixed `invoice_number` to
 * satisfy the UNIQUE index on the column. The prior invoice keeps its
 * canonical number; the override is a derived label that points back via
 * `override_of_invoice_id`. Add-on-top semantics (D-12) emerge naturally
 * when ApplyOrchestrator runs UNCHANGED on the override invoice — it sees
 * a fresh "parsed" row with new lines and writes additively.
 *
 * Threat coverage (see plan 03-06 threat register):
 *   - T-03-06-01 Tampering — race on duplicate detection between two
 *     concurrent uploads of the same number: `Invoice::lockForUpdate()`
 *     inside `DB::transaction` serializes via row-lock. Second upload
 *     blocks until first commits, then sees the new row + throws
 *     DuplicateInvoiceException.
 *   - T-03-06-02 Tampering — half-persisted invoice (some lines committed,
 *     some not): the entire parse → match → persist sequence sits inside
 *     ONE transaction; any exception triggers full rollback.
 *   - T-03-06-03 Repudiation — lost reject log on rollback: reject logging
 *     happens AFTER the catch outside the transaction wrapper. Order:
 *     tx fails → rollback → catch → logReject → re-throw.
 *   - T-03-06-05 Tampering — DB::statement / raw SQL bypass: this class
 *     uses `Invoice::create()` (Eloquent) + `InvoiceLine::insert(...)`
 *     (Eloquent batched insert). NEVER `DB::statement` or `whereRaw`.
 */
/**
 * Boundary note (D-04-06-01 / mirror of D-03-07-01 + D-04-02-01 + D-04-05-01):
 * NOT marked `final` so the override-and-reimport boundary-mock seam in
 * `tests/unit/Controllers/OverrideConfirmTest.php` can subclass with a
 * tracking-spy that records the runOverride() call args without standing up
 * the full HtmInvoiceParser + EanMatcherService stack against a hermetic
 * SQLite schema. Production callers always resolve via
 * `Invoices::resolveParseOrchestrator()` which goes through the IoC
 * container; the test uses `app()->instance(...)` to swap the spy in for
 * the duration of one test case. Mirrors the third boundary-mock final
 * removal in this plugin (after ImportAuditService 03-07 + ActiveFlagService
 * 04-02 + ApplyOrchestrator 04-05).
 *
 * @internal The class behaves as if final at the production-code boundary.
 *           Subclassing is sanctioned ONLY for unit-test boundary-mock spies.
 */
class ParseAndPersistOrchestrator
{
    /** Internal mode tag for `runWithStrategy()`. */
    private const MODE_NORMAL = 'normal';

    /** Internal mode tag for `runWithStrategy()` when override-reimport. */
    private const MODE_OVERRIDE = 'override';

    private readonly HtmInvoiceParser $obParser;

    private readonly EanMatcherService $obMatcher;

    private readonly ImportAuditService $obAudit;

    /**
     * Constructor with optional injection — production callers use the
     * default zero-arg form; tests can swap in spies/fakes by passing
     * explicit instances. Defaults are constructed in the body (NOT as
     * `new` parameter defaults) so PHPStan's strict-types rules and
     * static analyzers stay happy across PHP 8.3/8.4 dev environments.
     */
    public function __construct(
        ?HtmInvoiceParser $obParser = null,
        ?EanMatcherService $obMatcher = null,
        ?ImportAuditService $obAudit = null,
    ) {
        $this->obParser = $obParser ?? new HtmInvoiceParser();
        $this->obMatcher = $obMatcher ?? new EanMatcherService();
        $this->obAudit = $obAudit ?? new ImportAuditService();
    }

    /**
     * Normal upload entry point. Persists the parsed invoice with
     * `status='parsed'` and rejects re-uploads of an already-applied
     * invoice number with `DuplicateInvoiceException`.
     *
     * @throws DuplicateInvoiceException
     * @throws GoodsReceivedException Any plugin-typed parse / match failure
     */
    public function run(string $sHtmlContent, string $sSourceFilename, int $iAppliedByUserId): Invoice
    {
        return $this->runWithStrategy(
            $sHtmlContent,
            $sSourceFilename,
            $iAppliedByUserId,
            self::MODE_NORMAL,
            null,
        );
    }

    /**
     * Override-reimport entry point. Persists a NEW Invoice with
     * `override_of_invoice_id` set to `$iPriorInvoiceId` and a suffixed
     * `invoice_number` (`<orig>-OVR-<priorId>`) so the UNIQUE index on
     * `invoice_number` is satisfied while the override stays linked to
     * the prior invoice via the FK pointer. NEVER throws
     * DuplicateInvoiceException — the prior invoice IS the explicit
     * reference here.
     *
     * @throws GoodsReceivedException Any plugin-typed parse / match failure
     */
    public function runOverride(
        string $sHtmlContent,
        string $sSourceFilename,
        int $iPriorInvoiceId,
        int $iAppliedByUserId,
    ): Invoice {
        return $this->runWithStrategy(
            $sHtmlContent,
            $sSourceFilename,
            $iAppliedByUserId,
            self::MODE_OVERRIDE,
            $iPriorInvoiceId,
        );
    }

    /**
     * Shared transaction wrapper. Routes both `run()` and `runOverride()`
     * through the same parse → check → persist → match → persist-lines
     * sequence; the only branch is duplicate-check (skipped in override
     * mode) and the persisted invoice number / override pointer.
     *
     * @throws GoodsReceivedException Any plugin-typed parse / match failure
     */
    private function runWithStrategy(
        string $sHtml,
        string $sFilename,
        int $iUserId,
        string $sMode,
        ?int $iPriorInvoiceId,
    ): Invoice {
        try {
            /** @var Invoice $obInvoice */
            $obInvoice = DB::transaction(
                fn (): Invoice => $this->doParseAndPersist($sHtml, $sFilename, $iUserId, $sMode, $iPriorInvoiceId),
            );

            return $obInvoice;
        } catch (GoodsReceivedException $obException) {
            // Tx already rolled back here; reject log records the failure
            // outcome cleanly without sitting inside the (now-doomed)
            // transaction context. Re-throw so callers see the typed
            // failure unchanged.
            $this->obAudit->logReject(
                get_class($obException),
                array_merge(
                    $obException->arContext,
                    ['source_filename' => $sFilename, 'mode' => $sMode],
                ),
            );

            throw $obException;
        }
    }

    /**
     * The transactional unit: parse, optionally guard against duplicate,
     * persist invoice header, batch-match all EANs, persist lines, update
     * counters, audit. Any throw inside this body triggers full rollback.
     *
     * @throws DuplicateInvoiceException
     * @throws GoodsReceivedException Any plugin-typed parse / match failure
     */
    private function doParseAndPersist(
        string $sHtml,
        string $sFilename,
        int $iUserId,
        string $sMode,
        ?int $iPriorInvoiceId,
    ): Invoice {
        $obParsed = $this->obParser->parse($sHtml, $sFilename);

        if ($sMode === self::MODE_NORMAL) {
            $this->assertNotDuplicate($obParsed->invoice_number);
        }

        $obInvoice = $this->persistInvoice($obParsed, $iUserId, $sMode, $iPriorInvoiceId);

        $arMatched = $this->matchAllLines($obParsed);
        $this->persistLines((int) $obInvoice->id, $arMatched);
        $this->updateInvoiceCounters($obInvoice, $arMatched);

        $this->obAudit->logParse((int) $obInvoice->id, $obParsed);

        return $obInvoice;
    }

    /**
     * Duplicate-detection gate. `lockForUpdate()` serializes concurrent
     * uploads of the same `invoice_number` via row-lock — the SECOND
     * upload blocks until the first commits, then sees the prior row +
     * throws here. Only `status='applied'` is treated as a hard duplicate;
     * a prior `status='parsed'` row (uploaded but not yet applied) is NOT
     * a duplicate per D-22 — the operator can re-upload to refresh the
     * preview. The final commit is gated by the UNIQUE index, which
     * prevents two `parsed` rows with the same number from co-existing.
     *
     * @throws DuplicateInvoiceException
     */
    private function assertNotDuplicate(string $sInvoiceNumber): void
    {
        $obPrior = Invoice::where('invoice_number', $sInvoiceNumber)->lockForUpdate()->first();
        if (! $obPrior instanceof Invoice) {
            return;
        }
        if ((string) $obPrior->status !== Invoice::STATUS_APPLIED) {
            return;
        }

        $mPriorAppliedAt = $obPrior->applied_at;
        $sPriorAppliedAt = $mPriorAppliedAt instanceof Carbon
            ? $mPriorAppliedAt->toIso8601String()
            : null;

        throw new DuplicateInvoiceException(
            (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.exception.duplicate_invoice'),
            [
                'invoice_number' => $sInvoiceNumber,
                'prior_invoice_id' => (int) $obPrior->id,
                'prior_applied_at' => $sPriorAppliedAt,
                'prior_applied_by' => $obPrior->applied_by_user_id,
            ],
        );
    }

    /**
     * Persist the invoice header. In override mode the `invoice_number` is
     * suffixed (`<orig>-OVR-<priorId>`) to satisfy the UNIQUE index while
     * `override_of_invoice_id` points back to the canonical prior invoice.
     */
    private function persistInvoice(
        ParsedInvoice $obParsed,
        int $iUserId,
        string $sMode,
        ?int $iPriorInvoiceId,
    ): Invoice {
        $sInvoiceNumber = $obParsed->invoice_number;
        $iOverrideOf = null;

        if ($sMode === self::MODE_OVERRIDE && $iPriorInvoiceId !== null) {
            $sInvoiceNumber = sprintf('%s-OVR-%d', $obParsed->invoice_number, $iPriorInvoiceId);
            $iOverrideOf = $iPriorInvoiceId;
        }

        // Construct + save explicitly (NOT Invoice::create) — Larastan
        // types `Model::create` as `Eloquent\Model`, which doesn't satisfy
        // the typed `Invoice` return without inline `@var` (forbidden by
        // project rules per phpstan.neon comments). The new+save form
        // returns a typed `Invoice` and fires the same model events.
        // Convert ParsedInvoice's DateTimeImmutable to Carbon — Invoice
        // model's $dates array maps invoice_date to Carbon, and Larastan
        // sees the property type as Carbon|null. Carbon::instance accepts
        // any DateTimeInterface and returns Carbon, satisfying the
        // assign.propertyType check without inline @var.
        $obInvoice = new Invoice();
        $obInvoice->invoice_number = $sInvoiceNumber;
        $obInvoice->invoice_date = $obParsed->invoice_date !== null
            ? Carbon::instance($obParsed->invoice_date)
            : null;
        $obInvoice->country_code = $obParsed->country_code;
        $obInvoice->source_filename = $obParsed->source_filename;
        $obInvoice->status = Invoice::STATUS_PARSED;
        $obInvoice->total_lines = count($obParsed->lines);
        $obInvoice->applied_by_user_id = $iUserId;
        $obInvoice->parsed_at = Carbon::now();
        $obInvoice->override_of_invoice_id = $iOverrideOf;
        $obInvoice->save();

        return $obInvoice;
    }

    /**
     * Resolve every parsed line's EAN to an offer id via the matcher
     * service. Returns a list of MatchedLine DTOs preserving input order.
     *
     * @return list<MatchedLine>
     */
    private function matchAllLines(ParsedInvoice $obParsed): array
    {
        $arEans = array_map(static fn ($obLine) => $obLine->ean, $obParsed->lines);
        $arMatchMap = $this->obMatcher->matchBatch($arEans);

        return $this->obMatcher->buildMatchedLines($obParsed->lines, $arMatchMap);
    }

    /**
     * Batched `InvoiceLine::insert([...rows...])` — single INSERT statement
     * for the whole list (no per-row events fire; lines have no model
     * handlers in Phase 3). Empty list is a no-op so an invoice with zero
     * matched lines does not trigger a phantom INSERT.
     *
     * @param  list<MatchedLine>  $arMatched
     */
    private function persistLines(int $iInvoiceId, array $arMatched): void
    {
        if ($arMatched === []) {
            return;
        }

        $sNow = Carbon::now()->toDateTimeString();
        $arRows = [];
        foreach ($arMatched as $obMatched) {
            $arRows[] = [
                'invoice_id' => $iInvoiceId,
                'row_index' => $obMatched->line->row_index,
                'ean' => $obMatched->line->ean,
                'product_name_raw' => $obMatched->line->product_name_raw,
                'qty' => $obMatched->line->qty,
                'unit_price' => $obMatched->line->unit_price,
                'matched_offer_id' => $obMatched->matched_offer_id,
                'match_strategy' => $obMatched->match_strategy,
                'applied' => false,
                'created_at' => $sNow,
                'updated_at' => $sNow,
            ];
        }

        InvoiceLine::insert($arRows);
    }

    /**
     * Update the invoice header's matched/unmatched counters from the
     * resolved match results. `saveQuietly` — counter updates do not need
     * to fire any model handlers; the prior `Invoice::create()` save
     * already counts as the "row created" event.
     *
     * @param  list<MatchedLine>  $arMatched
     */
    private function updateInvoiceCounters(Invoice $obInvoice, array $arMatched): void
    {
        $iMatched = 0;
        $iUnmatched = 0;
        foreach ($arMatched as $obM) {
            if ($obM->matched_offer_id !== null) {
                $iMatched++;

                continue;
            }
            $iUnmatched++;
        }

        $obInvoice->matched_lines = $iMatched;
        $obInvoice->unmatched_lines = $iUnmatched;
        $obInvoice->saveQuietly();
    }
}
