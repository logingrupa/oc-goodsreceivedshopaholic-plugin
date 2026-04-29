<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ApplyResult;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedInvoice;

/**
 * Vendor-inlined audit log service for goods-received imports (APPLY-10 / D-04).
 * Routes through Laravel's `Log` facade so host channels (file/stack/sentry)
 * pick up entries. NO soft-dep on Logingrupa.ExtendShopaholic per D-14. Per
 * D-05, every entry carries `event` (apply/parse/reject/initial_reset) + a
 * fresh `correlation_id` (uuid v7 — time-ordered, via Laravel 12 Str::uuid7).
 * Threats: T-03-02-01 log injection (context json_encoded by PSR-3, message
 * is a literal); T-03-02-02 PII leak (only int ids + ENUM/parser strings —
 * email/name/raw HTM body NEVER logged); T-03-02-04 cross-call join deferred.
 */
/**
 * NOTE on `final` removal: the class is a logging boundary (Log::* facade
 * wrapper); subclassing is permitted as a TEST DOUBLE seam (e.g.
 * `FailingAuditService` in PartialFailureRollsBackEverythingTest injects a
 * controlled exception inside the apply transaction). Production callers
 * still construct `new ImportAuditService()` directly via the orchestrator's
 * default constructor argument; no production code subclasses this.
 */
class ImportAuditService
{
    public function logApply(int $iInvoiceId, ApplyResult $obResult, int $iAppliedByUserId): void
    {
        Log::info('goodsreceived.apply', $this->buildApplyContext($iInvoiceId, $obResult, $iAppliedByUserId));
    }

    public function logParse(int $iInvoiceId, ParsedInvoice $obParsed): void
    {
        Log::info('goodsreceived.parse', $this->buildParseContext($iInvoiceId, $obParsed));
    }

    /**
     * @param  array<string, mixed>  $arContext  Extra context (invoice_number, prior_applied_at, …)
     *                                           Service-controlled keys are merged LAST so they win.
     */
    public function logReject(string $sReason, array $arContext = []): void
    {
        Log::warning('goodsreceived.reject', array_merge($arContext, [
            'event' => 'reject',
            'reason' => $sReason,
            'correlation_id' => $this->correlationId(),
        ]));
    }

    public function logInitialReset(int $iInvoiceId, int $iOffersZeroed, int $iProductsDeactivated): void
    {
        Log::info('goodsreceived.initial_reset', [
            'event' => 'initial_reset',
            'invoice_id' => $iInvoiceId,
            'offers_zeroed' => $iOffersZeroed,
            'products_deactivated' => $iProductsDeactivated,
            'correlation_id' => $this->correlationId(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildApplyContext(int $iInvoiceId, ApplyResult $obResult, int $iAppliedByUserId): array
    {
        return [
            'event' => 'apply',
            'invoice_id' => $iInvoiceId,
            'units_added' => $obResult->units_added,
            'offers_touched' => $obResult->offers_touched,
            'lines_applied' => $obResult->lines_applied,
            'lines_skipped' => $obResult->lines_skipped,
            'applied_by' => $iAppliedByUserId,
            'correlation_id' => $this->correlationId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildParseContext(int $iInvoiceId, ParsedInvoice $obParsed): array
    {
        return [
            'event' => 'parse',
            'invoice_id' => $iInvoiceId,
            'invoice_number' => $obParsed->invoice_number,
            'source_filename' => $obParsed->source_filename,
            'total_lines' => count($obParsed->lines),
            'skipped_count' => count($obParsed->skipped_rows),
            'correlation_id' => $this->correlationId(),
        ];
    }

    /** Laravel 12 ships uuid7 (time-ordered) — preferred over v4 for audit trails. */
    private function correlationId(): string
    {
        return (string) Str::uuid7();
    }
}
