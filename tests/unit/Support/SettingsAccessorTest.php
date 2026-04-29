<?php

declare(strict_types=1);

use Logingrupa\GoodsReceivedShopaholic\Classes\Support\SettingsAccessor;
use Logingrupa\GoodsReceivedShopaholic\Models\Settings;
use Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase;

/**
 * APPLY-09: SettingsAccessor — single, memoized accessor for all plugin
 * Settings reads. Per D-01: 4 boolean getters + flush(). Per D-03: flush()
 * is invoked from GoodsReceivedTestCase::flushPluginSingletons() so cross-test
 * bleed is impossible.
 *
 * This suite asserts:
 *   - Each getter returns the strict boolean value of the corresponding key.
 *   - Memoization: a second read after a Settings mutation returns the FIRST
 *     cached value (proves the cache is real).
 *   - flush() reverts to fresh reads.
 *   - null / falsy values coerce to strict false (=== false, not == false).
 *
 * Schema bootstrap rationale (mirror EanMatcherTestCase pattern):
 * SettingModel reads/writes `system_settings`. We create that table with
 * exactly the columns October's SettingModel touches (id, item, value,
 * site_id, site_root_id, site_group_id) — a hermetic minimal slice that
 * decouples this test from System module migration order (SQLite cannot
 * drop indexed columns; running the full Lovata.Shopaholic migration chain
 * fails — see EanMatcherTestCase::setUp comment for full forensic trace).
 */
abstract class SettingsAccessorTestCase extends GoodsReceivedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Schema::create('system_settings', function ($obTable): void {
            $obTable->increments('id');
            $obTable->string('item')->nullable()->index();
            $obTable->mediumtext('value')->nullable();
            // Multisite trait writes these even when not configured; nullable
            // so a single-site SettingModel save() doesn't trip NOT NULL.
            $obTable->unsignedInteger('site_id')->nullable();
            $obTable->unsignedInteger('site_root_id')->nullable();
            $obTable->unsignedInteger('site_group_id')->nullable();
        });
    }

    protected function tearDown(): void
    {
        \Schema::dropIfExists('system_settings');
        parent::tearDown();
    }
}

uses(SettingsAccessorTestCase::class);

beforeEach(function (): void {
    // The TestCase tearDown also flushes; this is belt-and-braces for the FIRST test.
    SettingsAccessor::flush();

    // SettingModel caches the loaded instance per process; clear it so the
    // freshly-migrated `system_settings` table is read from cleanly.
    Settings::clearInternalCache();

    // Reset all 4 keys to false (strict) so each test starts from a clean baseline.
    Settings::set('enabled', false);
    Settings::set('auto_deactivate_on_zero', false);
    Settings::set('auto_activate_on_stock', false);
    Settings::set('allow_initial_reset', false);

    // Clear the accessor cache AGAIN so subsequent reads see the reset values
    // (not anything that might have been read between flush() and Settings::set()).
    SettingsAccessor::flush();
});

it('returns true from isEnabled when Settings.enabled=true', function (): void {
    Settings::set('enabled', true);
    SettingsAccessor::flush();

    expect(SettingsAccessor::isEnabled())->toBe(true);
});

it('returns false from isEnabled when Settings.enabled=false', function (): void {
    Settings::set('enabled', false);
    SettingsAccessor::flush();

    expect(SettingsAccessor::isEnabled())->toBe(false);
});

it('returns true from autoDeactivateOnZero when Settings.auto_deactivate_on_zero=true', function (): void {
    Settings::set('auto_deactivate_on_zero', true);
    SettingsAccessor::flush();

    expect(SettingsAccessor::autoDeactivateOnZero())->toBe(true);
});

it('returns true from autoActivateOnStock when Settings.auto_activate_on_stock=true', function (): void {
    Settings::set('auto_activate_on_stock', true);
    SettingsAccessor::flush();

    expect(SettingsAccessor::autoActivateOnStock())->toBe(true);
});

it('returns true from allowInitialReset when Settings.allow_initial_reset=true', function (): void {
    Settings::set('allow_initial_reset', true);
    SettingsAccessor::flush();

    expect(SettingsAccessor::allowInitialReset())->toBe(true);
});

it('memoizes — second call returns first cached value even if Settings changed mid-request', function (): void {
    Settings::set('enabled', true);
    SettingsAccessor::flush(); // Prime cache from this state on next read.

    expect(SettingsAccessor::isEnabled())->toBe(true);

    // Mutate Settings *after* the cache was populated. Bypass any SettingModel
    // instance cache too (Settings::set() round-trips via the cached instance,
    // so the in-memory object reflects the new value — but our accessor cache
    // holds the bool from the prior read).
    Settings::set('enabled', false);

    // Cache must still return the prior value — that is the memoization contract.
    expect(SettingsAccessor::isEnabled())->toBe(true);

    // After flush(), the next read must reflect the mutation.
    SettingsAccessor::flush();
    expect(SettingsAccessor::isEnabled())->toBe(false);
});

it('coerces null Settings::get value to strict false', function (): void {
    Settings::set('enabled', null);
    SettingsAccessor::flush();

    $bResult = SettingsAccessor::isEnabled();

    expect($bResult)->toBe(false);    // strict: must be exactly false (===)
    expect($bResult)->toBeBool();     // strict: must be a real bool, not 0 / null / ''
});
