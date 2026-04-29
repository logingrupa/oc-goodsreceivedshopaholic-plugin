<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Support;

use Logingrupa\GoodsReceivedShopaholic\Models\Settings;

/**
 * Single accessor for all plugin Settings reads (APPLY-09 / D-01).
 *
 * Memoized per-request via the static $arCache property: the four boolean
 * settings keys are read from the underlying SettingModel once per process
 * (or once per test, given the flush() hook in GoodsReceivedTestCase), then
 * served from memory for every subsequent call. This bounds DB reads to four
 * regardless of how many lines a 200-line apply iterates over (T-03-01-04).
 *
 * QA-09 invariant: this file is the SOLE caller of `Settings::get(`. Both the
 * `make lint-settings-accessor` Makefile target and the
 * SettingsAccessorIsSoleConsumerOfSettingsGetTest Pest test enforce this — the
 * duplication is intentional so the invariant survives loss of either gate.
 *
 * Cross-test isolation: `flush()` is called from
 * GoodsReceivedTestCase::flushPluginSingletons() (D-03), which runs in
 * tearDown() AFTER flushModelEventListeners() and BEFORE parent::tearDown().
 * That guarantees no test inherits another test's cached settings (T-03-01-01).
 *
 * @see \Logingrupa\GoodsReceivedShopaholic\Tests\GoodsReceivedTestCase::flushPluginSingletons()
 */
final class SettingsAccessor
{
    private const KEY_ENABLED = 'enabled';

    private const KEY_AUTO_DEACTIVATE = 'auto_deactivate_on_zero';

    private const KEY_AUTO_ACTIVATE = 'auto_activate_on_stock';

    private const KEY_ALLOW_RESET = 'allow_initial_reset';

    /**
     * Per-request memo cache keyed by Settings field name. Null = uninitialized;
     * once populated, all four keys are present (the cache is filled atomically
     * in get()).
     *
     * @var array<string, bool>|null
     */
    private static ?array $arCache = null;

    public static function isEnabled(): bool
    {
        return self::get(self::KEY_ENABLED);
    }

    public static function autoDeactivateOnZero(): bool
    {
        return self::get(self::KEY_AUTO_DEACTIVATE);
    }

    public static function autoActivateOnStock(): bool
    {
        return self::get(self::KEY_AUTO_ACTIVATE);
    }

    public static function allowInitialReset(): bool
    {
        return self::get(self::KEY_ALLOW_RESET);
    }

    /**
     * Drop the in-memory cache. Called from
     * GoodsReceivedTestCase::flushPluginSingletons() between tests (D-03), and
     * available to operational code that legitimately needs to re-read after a
     * settings save (currently none — SettingModel save flow handles its own
     * cache, this is the request-level memo on top of it).
     */
    public static function flush(): void
    {
        self::$arCache = null;
    }

    /**
     * Fetch a single boolean key, populating the cache atomically on first call.
     *
     * The four-key bulk-fill is deliberate: an apply loop reading a mix of
     * isEnabled() / autoDeactivateOnZero() / autoActivateOnStock() should not
     * produce four separate DB roundtrips on first access. Filling them all at
     * once on the first call keeps the worst case at one priming read per
     * request.
     */
    private static function get(string $sKey): bool
    {
        self::$arCache ??= [
            self::KEY_ENABLED => (bool) Settings::get(self::KEY_ENABLED),
            self::KEY_AUTO_DEACTIVATE => (bool) Settings::get(self::KEY_AUTO_DEACTIVATE),
            self::KEY_AUTO_ACTIVATE => (bool) Settings::get(self::KEY_AUTO_ACTIVATE),
            self::KEY_ALLOW_RESET => (bool) Settings::get(self::KEY_ALLOW_RESET),
        ];

        return self::$arCache[$sKey];
    }
}
