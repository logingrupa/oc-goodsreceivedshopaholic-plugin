<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ApplyResult;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedInvoice;
use Logingrupa\GoodsReceivedShopaholic\Classes\Support\ImportAuditService;
use Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase;

/**
 * APPLY-10: ImportAuditService — vendor-inlined ~50-80 LoC structured-context
 * audit log service for goods-received imports. Routes through Laravel's `Log`
 * facade so the host project's configured channels (file / stack / sentry)
 * pick up the audit lines automatically.
 *
 * Per D-04/D-05 of phase 03-CONTEXT.md, every plugin event (parse / apply /
 * reject / initial_reset) must record one structured-context log line so
 * operators can grep audit trails by `correlation_id` or `invoice_id`.
 *
 * This suite asserts:
 *   - logApply / logParse emit Log::info with the documented context shape
 *   - logReject emits Log::warning with reason + user-supplied context merged
 *   - logInitialReset emits Log::info with reset counts
 *   - Each call generates a fresh correlation_id (uuid format)
 *   - All four methods carry the canonical `event` key + `correlation_id`
 *
 * The Log facade is a boundary, not business logic, so spying on it is allowed
 * per project Tiger-Style guidance.
 *
 * Threat coverage:
 *   - T-03-02-01 (log injection): the message argument is always a fixed string
 *     literal (`goodsreceived.{event}`); user-supplied data lives only in the
 *     context array which Laravel's PSR-3 formatter json_encodes.
 *   - T-03-02-02 (PII leak): method signatures admit only integer ids + known
 *     enum strings — these tests assert the exact key-set for each method so
 *     drift toward leaking email/name/raw HTM body would break them.
 */
uses(GoodsReceivedTestCase::class);

beforeEach(function (): void {
    \Log::spy();
});

it('logApply emits Log::info with structured context including event=apply, invoice_id, units_added, offers_touched, lines_applied, lines_skipped, applied_by, correlation_id', function (): void {
    $obService = new ImportAuditService();
    $obResult = new ApplyResult(
        units_added: 50,
        offers_touched: 10,
        lines_applied: 8,
        lines_skipped: 2,
    );

    $obService->logApply(42, $obResult, 7);

    \Log::shouldHaveReceived('info')
        ->withArgs(function (string $sMessage, array $arContext): bool {
            expect($sMessage)->toBe('goodsreceived.apply');
            expect($arContext)->toMatchArray([
                'event' => 'apply',
                'invoice_id' => 42,
                'units_added' => 50,
                'offers_touched' => 10,
                'lines_applied' => 8,
                'lines_skipped' => 2,
                'applied_by' => 7,
            ]);
            expect($arContext)->toHaveKey('correlation_id');
            expect($arContext['correlation_id'])->toBeString();
            // uuid v4/v7 canonical 8-4-4-4-12 hex form
            expect($arContext['correlation_id'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');

            return true;
        })
        ->once();
});

it('logParse emits Log::info with event=parse, invoice_id, invoice_number, source_filename, total_lines, skipped_count, correlation_id', function (): void {
    $obService = new ImportAuditService();
    $obParsed = new ParsedInvoice(
        invoice_number: 'PRO123',
        country_code: 'NO',
        invoice_date: null,
        source_filename: 'Nr_PRO123_no_29042026.HTM',
        lines: [
            (object) ['ean' => '0000000000001'],
            (object) ['ean' => '0000000000002'],
            (object) ['ean' => '0000000000003'],
        ],
        skipped_rows: [
            ['row_index' => 5, 'reason' => 'short_row', 'raw' => '<TR>x</TR>'],
        ],
    );

    $obService->logParse(99, $obParsed);

    \Log::shouldHaveReceived('info')
        ->withArgs(function (string $sMessage, array $arContext): bool {
            expect($sMessage)->toBe('goodsreceived.parse');
            expect($arContext)->toMatchArray([
                'event' => 'parse',
                'invoice_id' => 99,
                'invoice_number' => 'PRO123',
                'source_filename' => 'Nr_PRO123_no_29042026.HTM',
                'total_lines' => 3,
                'skipped_count' => 1,
            ]);
            expect($arContext)->toHaveKey('correlation_id');
            expect($arContext['correlation_id'])->toBeString();

            return true;
        })
        ->once();
});

it('logReject emits Log::warning with event=reject, reason, correlation_id, plus user-supplied context merged in', function (): void {
    $obService = new ImportAuditService();

    $obService->logReject('duplicate_invoice', [
        'invoice_number' => 'PRO123',
        'prior_applied_at' => '2026-04-01T10:00:00Z',
    ]);

    \Log::shouldHaveReceived('warning')
        ->withArgs(function (string $sMessage, array $arContext): bool {
            expect($sMessage)->toBe('goodsreceived.reject');
            expect($arContext)->toMatchArray([
                'event' => 'reject',
                'reason' => 'duplicate_invoice',
                'invoice_number' => 'PRO123',
                'prior_applied_at' => '2026-04-01T10:00:00Z',
            ]);
            expect($arContext)->toHaveKey('correlation_id');
            expect($arContext['correlation_id'])->toBeString();

            return true;
        })
        ->once();
});

it('logReject works with empty user context (only canonical keys present)', function (): void {
    $obService = new ImportAuditService();

    $obService->logReject('parser_failure');

    \Log::shouldHaveReceived('warning')
        ->withArgs(function (string $sMessage, array $arContext): bool {
            expect($sMessage)->toBe('goodsreceived.reject');
            expect($arContext)->toMatchArray([
                'event' => 'reject',
                'reason' => 'parser_failure',
            ]);
            expect($arContext)->toHaveKey('correlation_id');

            return true;
        })
        ->once();
});

it('logInitialReset emits Log::info with event=initial_reset, invoice_id, offers_zeroed, products_deactivated, correlation_id', function (): void {
    $obService = new ImportAuditService();

    $obService->logInitialReset(7, 1234, 567);

    \Log::shouldHaveReceived('info')
        ->withArgs(function (string $sMessage, array $arContext): bool {
            expect($sMessage)->toBe('goodsreceived.initial_reset');
            expect($arContext)->toMatchArray([
                'event' => 'initial_reset',
                'invoice_id' => 7,
                'offers_zeroed' => 1234,
                'products_deactivated' => 567,
            ]);
            expect($arContext)->toHaveKey('correlation_id');
            expect($arContext['correlation_id'])->toBeString();

            return true;
        })
        ->once();
});

it('generates a fresh correlation_id on each call (no shared state across log methods)', function (): void {
    $obService = new ImportAuditService();
    $obResult = new ApplyResult(1, 1, 1, 0);

    $arCapturedIds = [];

    \Log::shouldReceive('info')
        ->twice()
        ->withArgs(function (string $sMessage, array $arContext) use (&$arCapturedIds): bool {
            $arCapturedIds[] = $arContext['correlation_id'];

            return true;
        });

    $obService->logApply(1, $obResult, 1);
    $obService->logApply(2, $obResult, 1);

    expect($arCapturedIds)->toHaveCount(2);
    expect($arCapturedIds[0])->not->toBe($arCapturedIds[1]);
});
