<?php

declare(strict_types=1);

use BackendMenu;
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

/**
 * Backend-path tests against the LIVE `ini_get` values.
 *
 * `max_file_uploads` and `upload_max_filesize` are PHP_INI_PERDIR — runtime
 * `ini_set()` returns `false` for these directives in CLI/PHPUnit, so we
 * cannot synthetically force an under-threshold value. Instead we read the
 * host's actual values, derive whether each warning is expected, and assert
 * the contract is honoured for the configuration the test process is
 * actually running under. Combined, these three cases pin all three boot()
 * branches for whichever host configuration the suite executes against
 * (under-threshold OR healthy), and the parseIniSize unit cases pin the
 * conversion table independently of the runtime config (per D-35).
 */
it('boot honours max_file_uploads threshold against live ini value (UI-12 / D-34)', function (): void {
    $iLiveMaxUploads = (int) ini_get('max_file_uploads');
    $sLiveUploadSize = (string) ini_get('upload_max_filesize');

    App::shouldReceive('runningInBackend')->once()->andReturn(true);
    BackendMenu::shouldReceive('registerCallback')->once();

    if ($iLiveMaxUploads < 20) {
        Log::shouldReceive('warning')
            ->once()
            ->with(
                'GoodsReceived: max_file_uploads is below 20',
                \Mockery::on(function (array $arContext) use ($iLiveMaxUploads): bool {
                    expect($arContext)->toMatchArray([
                        'current'     => $iLiveMaxUploads,
                        'recommended' => 20,
                    ]);
                    return true;
                })
            );
    } else {
        Log::shouldReceive('warning')->with(
            'GoodsReceived: max_file_uploads is below 20',
            \Mockery::any()
        )->never();
    }

    // Do not over-constrain the parallel branch — pinned in its own test.
    Log::shouldReceive('warning')->zeroOrMoreTimes()->with(
        'GoodsReceived: upload_max_filesize is below 10M',
        \Mockery::any()
    );

    makeBootablePlugin()->boot();

    // Sanity-pin: contract requires we read max_file_uploads AS AN INTEGER,
    // and the live value either trips or clears the 20-threshold.
    expect($iLiveMaxUploads)->toBeInt();
    expect($sLiveUploadSize)->toBeString();
});

it('boot honours upload_max_filesize threshold against live ini value (UI-12 / D-34)', function (): void {
    $sLiveUploadSize = (string) ini_get('upload_max_filesize');
    $iLiveBytes = ($sLiveUploadSize === '') ? 0 : (function (string $sIni): int {
        $obReflection = new ReflectionClass(Plugin::class);
        $obMethod = $obReflection->getMethod('parseIniSize');
        $obMethod->setAccessible(true);
        return (int) $obMethod->invoke(null, $sIni);
    })($sLiveUploadSize);

    App::shouldReceive('runningInBackend')->once()->andReturn(true);
    BackendMenu::shouldReceive('registerCallback')->once();

    if ($iLiveBytes < 10 * 1024 * 1024) {
        Log::shouldReceive('warning')
            ->once()
            ->with(
                'GoodsReceived: upload_max_filesize is below 10M',
                \Mockery::on(function (array $arContext) use ($sLiveUploadSize): bool {
                    expect($arContext)->toMatchArray([
                        'current'     => $sLiveUploadSize,
                        'recommended' => '10M',
                    ]);
                    return true;
                })
            );
    } else {
        Log::shouldReceive('warning')->with(
            'GoodsReceived: upload_max_filesize is below 10M',
            \Mockery::any()
        )->never();
    }

    Log::shouldReceive('warning')->zeroOrMoreTimes()->with(
        'GoodsReceived: max_file_uploads is below 20',
        \Mockery::any()
    );

    makeBootablePlugin()->boot();
});

it('boot is silent when both thresholds are satisfied — verified against live ini (UI-12 happy path)', function (): void {
    $iLiveMaxUploads = (int) ini_get('max_file_uploads');
    $sLiveUploadSize = (string) ini_get('upload_max_filesize');
    $obReflection = new ReflectionClass(Plugin::class);
    $obMethod = $obReflection->getMethod('parseIniSize');
    $obMethod->setAccessible(true);
    $iLiveBytes = (int) $obMethod->invoke(null, $sLiveUploadSize);

    if ($iLiveMaxUploads < 20 || $iLiveBytes < 10 * 1024 * 1024) {
        $this->markTestSkipped(sprintf(
            'host runtime is below thresholds — max_file_uploads=%d, upload_max_filesize=%s; happy-path silence cannot be asserted on this PHP_INI_PERDIR-locked host',
            $iLiveMaxUploads,
            $sLiveUploadSize
        ));
    }

    App::shouldReceive('runningInBackend')->once()->andReturn(true);
    BackendMenu::shouldReceive('registerCallback')->once();
    Log::shouldReceive('warning')->never();

    makeBootablePlugin()->boot();
});
