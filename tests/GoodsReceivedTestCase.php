<?php

namespace Logingrupa\GoodsReceivedShopaholic\Tests;

use Backend\Classes\AuthManager;
use Illuminate\Foundation\Testing\TestCase;
use October\Rain\Database\Model as ActiveRecord;

/**
 * PHPUnit 12 / Pest 4 compatible test case for October CMS plugins.
 *
 * October's PluginTestCase declares setUp() as public, which conflicts
 * with PHPUnit 12's protected setUp(). This class reimplements the same
 * bootstrap logic with correct visibility.
 */
abstract class GoodsReceivedTestCase extends TestCase
{
    use \October\Tests\Concerns\InteractsWithAuthentication;
    use \October\Tests\Concerns\PerformsMigrations;
    use \October\Tests\Concerns\PerformsRegistrations;

    protected $autoMigrate = false;
    protected $autoRegister = false;

    public function createApplication()
    {
        $sBootstrapPath = $this->resolveBootstrapPath();
        $app = require $sBootstrapPath;
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $app->singleton('auth', function ($app) {
            $app['auth.loaded'] = true;
            return AuthManager::instance();
        });

        return $app;
    }

    protected function setUp(): void
    {
        $this->pluginTestCaseMigratedPlugins = [];
        $this->pluginTestCaseLoadedPlugins = [];

        parent::setUp();

        if ($this->autoRegister === true) {
            $this->loadCurrentPlugin();
        }

        if ($this->autoMigrate === true) {
            $this->migrateModules();
            $this->migrateCurrentPlugin();
        }

        \Mail::pretend();
    }

    protected function tearDown(): void
    {
        $this->flushModelEventListeners();
        $this->flushPluginSingletons();
        parent::tearDown();
        unset($this->app);
    }

    protected function flushModelEventListeners()
    {
        foreach (get_declared_classes() as $class) {
            if ($class == \October\Rain\Database\Pivot::class) {
                continue;
            }

            $reflectClass = new \ReflectionClass($class);
            if (
                !$reflectClass->isInstantiable() ||
                !$reflectClass->isSubclassOf(\October\Rain\Database\Model::class) ||
                $reflectClass->isSubclassOf(\October\Rain\Database\Pivot::class)
            ) {
                continue;
            }

            $class::flushEventListeners();
        }

        ActiveRecord::flushEventListeners();
    }

    /**
     * Flush per-test plugin singletons to prevent cross-test bleed.
     *
     * Each new singleton (Accessors, Stores, Caches) MUST add a static `flush()`
     * method and a corresponding line here. Subsequent Phase 3 plans MAY add
     * lines but MUST NOT remove the SettingsAccessor::flush() call.
     *
     * Called from tearDown() AFTER flushModelEventListeners() so any model events
     * are detached before singleton state is wiped, and BEFORE parent::tearDown()
     * so the framework teardown still happens after our cleanup. (Per QA-11 / D-22.)
     */
    protected function flushPluginSingletons(): void
    {
        // Phase 3 plan 03-01 (APPLY-09 / D-03): drop SettingsAccessor's
        // request-scoped memo cache so the next test starts from a clean read.
        \Logingrupa\GoodsReceivedShopaholic\Classes\Support\SettingsAccessor::flush();
    }

    protected function guessPluginCodeFromTest()
    {
        return 'Logingrupa.GoodsReceivedShopaholic';
    }

    protected function isAppCodeFromTest()
    {
        return false;
    }

    /**
     * Resolve the bootstrap/app.php path by searching upward from __DIR__.
     * Works from both the standard plugin path and git worktree paths.
     */
    private function resolveBootstrapPath(): string
    {
        $sDirectory = __DIR__;

        for ($iLevel = 0; $iLevel < 15; $iLevel++) {
            $sCandidate = $sDirectory . '/bootstrap/app.php';
            if (file_exists($sCandidate)) {
                return $sCandidate;
            }
            $sDirectory = dirname($sDirectory);
        }

        return __DIR__ . '/../../../../bootstrap/app.php';
    }
}
