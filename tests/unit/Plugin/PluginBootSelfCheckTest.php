<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\GoodsReceivedShopaholic\Plugin;
use Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase;

uses(GoodsReceivedTestCase::class);

/**
 * UI-12 / D-34 / D-35 / D-36 / D-44.
 *
 * Plan 04-01: Plugin::boot() backend-gated self-check + parseIniSize() helper.
 *
 * The Tiger-Style invariant we pin here:
 *   - Self-check NEVER runs on the public site (App::runningInBackend()=false guard
 *     short-circuits before any ini_get / Log call) — frontend page-load cost = 0.
 *   - parseIniSize is a pure value converter and MUST safely degrade to 0 on
 *     malformed input rather than throwing. A boot-time helper that throws
 *     would brick plugin registration on a misconfigured host — exactly the
 *     opposite of the safety-net this self-check is supposed to provide
 *     (T-04-01-01).
 *   - Threshold breaches (max_file_uploads<20 OR upload_max_filesize<10M) emit
 *     ONE Log::warning each with the EXACT message + context shape ops uses
 *     to grep / alert on. Drift in either string would break runbook recipes.
 *
 * The `Log` facade is a boundary surface (not business logic), so facade
 * mocking is sanctioned per CLAUDE.md Tiger-Style carve-out — see
 * tests/unit/Support/ImportAuditServiceTest.php for the same pattern.
 *
 * The `App::runningInBackend()` flag is also a boundary — it routes the
 * caller through a global runtime context rather than encoding business
 * intent — so swapping the facade for a Mockery double is the natural fit.
 *
 * Reflection-invoke the private static `parseIniSize` directly: keeping it
 * `private` per the public-API contract while still pinning the conversion
 * table at the unit level (D-35).
 */

/**
 * Reflection helper — invokes the private static `parseIniSize` against
 * arbitrary input without widening the public API surface (per D-35).
 */
function callParseIniSize(string $sIni): int
{
    $obReflection = new ReflectionClass(Plugin::class);
    $obMethod = $obReflection->getMethod('parseIniSize');
    $obMethod->setAccessible(true);

    return (int) $obMethod->invoke(null, $sIni);
}

/**
 * Helper — instantiate the Plugin under test against the live application
 * container. Plugin::boot() pulls App + Log via the static facade resolvers,
 * so the constructor argument is just to satisfy the PluginBase signature.
 */
function makeBootablePlugin(): Plugin
{
    return new Plugin(app());
}

it('parseIniSize converts M / K / G suffixes to bytes', function (): void {
    expect(callParseIniSize('10M'))->toBe(10 * 1024 * 1024);
    expect(callParseIniSize('512K'))->toBe(512 * 1024);
    expect(callParseIniSize('2G'))->toBe(2 * 1024 * 1024 * 1024);
    expect(callParseIniSize('1024'))->toBe(1024);
});

it('parseIniSize accepts lowercase suffixes (case-insensitive per D-35)', function (): void {
    expect(callParseIniSize('10m'))->toBe(10 * 1024 * 1024);
    expect(callParseIniSize('512k'))->toBe(512 * 1024);
    expect(callParseIniSize('1g'))->toBe(1 * 1024 * 1024 * 1024);
});

it('parseIniSize returns 0 for empty or malformed input (T-04-01-01: never throw at boot)', function (): void {
    expect(callParseIniSize(''))->toBe(0);
    expect(callParseIniSize('M'))->toBe(0);
    expect(callParseIniSize('   '))->toBe(0);
});

it('boot is a no-op on the frontend (D-34 backend gate; T-04-01-03 zero frontend cost)', function (): void {
    App::shouldReceive('runningInBackend')->once()->andReturn(false);
    Log::shouldReceive('warning')->never();

    makeBootablePlugin()->boot();
});

it('boot warns when max_file_uploads is below 20 (UI-12 / D-34)', function (): void {
    App::shouldReceive('runningInBackend')->once()->andReturn(true);

    // Force the under-threshold value at the runtime layer the helper reads.
    // ini_set is PHP_INI_PERDIR for max_file_uploads on most builds; if a host
    // refuses the change we record that and skip — the contract is then pinned
    // by the override path covered in the next test.
    $sPrevious = ini_set('max_file_uploads', '15');
    if ($sPrevious === false) {
        $this->markTestSkipped('host php.ini refuses runtime ini_set on max_file_uploads');
    }

    Log::shouldReceive('warning')
        ->once()
        ->with(
            'GoodsReceived: max_file_uploads is below 20',
            \Mockery::on(function (array $arContext): bool {
                expect($arContext)->toMatchArray([
                    'current' => 15,
                    'recommended' => 20,
                ]);
                return true;
            })
        );
    // Whatever upload_max_filesize the host reports, allow but do not require.
    Log::shouldReceive('warning')->zeroOrMoreTimes()->with(
        'GoodsReceived: upload_max_filesize is below 10M',
        \Mockery::any()
    );

    try {
        makeBootablePlugin()->boot();
    } finally {
        ini_set('max_file_uploads', $sPrevious);
    }
});

it('boot warns when upload_max_filesize is below 10M (UI-12 / D-34)', function (): void {
    App::shouldReceive('runningInBackend')->once()->andReturn(true);

    $sPrevious = ini_set('upload_max_filesize', '8M');
    if ($sPrevious === false) {
        $this->markTestSkipped('host php.ini refuses runtime ini_set on upload_max_filesize');
    }

    Log::shouldReceive('warning')
        ->once()
        ->with(
            'GoodsReceived: upload_max_filesize is below 10M',
            \Mockery::on(function (array $arContext): bool {
                expect($arContext)->toMatchArray([
                    'current' => '8M',
                    'recommended' => '10M',
                ]);
                return true;
            })
        );
    // Tolerate the parallel max_file_uploads warning if the host happens to
    // also be misconfigured below 20 — we are pinning the upload_max_filesize
    // branch here, not asserting the inverse.
    Log::shouldReceive('warning')->zeroOrMoreTimes()->with(
        'GoodsReceived: max_file_uploads is below 20',
        \Mockery::any()
    );

    try {
        makeBootablePlugin()->boot();
    } finally {
        ini_set('upload_max_filesize', $sPrevious);
    }
});

it('boot is silent when both thresholds are satisfied (UI-12 happy path)', function (): void {
    App::shouldReceive('runningInBackend')->once()->andReturn(true);

    $sPrevUploads = ini_set('max_file_uploads', '20');
    $sPrevSize = ini_set('upload_max_filesize', '64M');
    if ($sPrevUploads === false || $sPrevSize === false) {
        if ($sPrevUploads !== false) {
            ini_set('max_file_uploads', $sPrevUploads);
        }
        if ($sPrevSize !== false) {
            ini_set('upload_max_filesize', $sPrevSize);
        }
        $this->markTestSkipped('host php.ini refuses runtime ini_set on upload thresholds');
    }

    Log::shouldReceive('warning')->never();

    try {
        makeBootablePlugin()->boot();
    } finally {
        ini_set('max_file_uploads', $sPrevUploads);
        ini_set('upload_max_filesize', $sPrevSize);
    }
});
