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
