<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Logingrupa\GoodsReceivedShopaholic\Console\RecomputeActiveFromStock;
use Logingrupa\GoodsReceivedShopaholic\Models\Settings;
use System\Classes\PluginBase;

/**
 * Class Plugin
 * @package Logingrupa\GoodsReceivedShopaholic
 * @author Logingrupa
 */
class Plugin extends PluginBase
{
    /**
     * Required plugins
     * @var list<string>
     */
    public $require = [
        'Lovata.Toolbox',
        'Lovata.Shopaholic',
    ];

    /**
     * Returns information about this plugin
     * @return array<string, string>
     */
    #[\Override]
    public function pluginDetails(): array
    {
        return [
            'name'        => 'logingrupa.goodsreceivedshopaholic::lang.plugin.name',
            'description' => 'logingrupa.goodsreceivedshopaholic::lang.plugin.description',
            'author'      => 'Logingrupa',
            'icon'        => 'icon-truck',
        ];
    }

    /**
     * Register method, called when the plugin is first registered (UI-11 / D-33).
     *
     * Wires the artisan console command `goodsreceived:recompute_active_from_stock`
     * which reconciles every offer's `active` flag from current `quantity` while
     * honoring the `active_managed_by='operator'` provenance gate. See
     * `console/RecomputeActiveFromStock.php` for the contract details.
     *
     * The first argument to `registerConsoleCommand` is October's IoC binding
     * key (dotted alias — matches the `storeextender.sqlimport` style); the
     * artisan dispatcher consumes the command's own `$signature` property
     * which carries the colon-separated `goodsreceived:recompute_active_from_stock`
     * name.
     */
    #[\Override]
    public function register(): void
    {
        $this->registerConsoleCommand(
            'goodsreceived:recompute_active_from_stock',
            RecomputeActiveFromStock::class,
        );
    }

    /**
     * Boot method, called right before the request route.
     *
     * Backend-gated runtime self-check (UI-12 / D-34, D-35): warn-log when the
     * host's PHP upload prerequisites fall below the multi-file `.HTM` import
     * thresholds. Runs ONLY when the request is dispatched to the backend so
     * frontend page-loads incur zero `ini_get` / `Log::warning` overhead
     * (T-04-01-03).
     *
     * Threshold rationale:
     *   - `max_file_uploads >= 20`  — matches D-07 "Total upload ≤ 20 files"
     *   - `upload_max_filesize >= 10M` — matches D-07 "Per-file size limit ≤ 10 MB"
     *
     * Both checks degrade safely: a misconfigured host yields ONE structured
     * `Log::warning` line (greppable by ops) but NEVER a thrown boot-time
     * exception that would brick the plugin (T-04-01-01).
     */
    public function boot(): void
    {
        if (! App::runningInBackend()) {
            return;
        }

        $iMaxUploads = (int) ini_get('max_file_uploads');
        if ($iMaxUploads < 20) {
            Log::warning('GoodsReceived: max_file_uploads is below 20', [
                'current'     => $iMaxUploads,
                'recommended' => 20,
            ]);
        }

        $sUploadMaxSize = (string) ini_get('upload_max_filesize');
        $iUploadMaxBytes = self::parseIniSize($sUploadMaxSize);
        if ($iUploadMaxBytes < 10 * 1024 * 1024) {
            Log::warning('GoodsReceived: upload_max_filesize is below 10M', [
                'current'     => $sUploadMaxSize,
                'recommended' => '10M',
            ]);
        }
    }

    /**
     * Parse a php.ini-style size string ('10M', '512K', '2G', '1024') into bytes.
     *
     * Designed for boot-time self-checks: NEVER throws — returns 0 on empty
     * or malformed input so a misconfigured host can never abort plugin
     * registration via this helper (T-04-01-01 mitigation per D-35).
     *
     * @param  string  $sIni  raw ini value, e.g. result of `ini_get('upload_max_filesize')`
     * @return int            byte count, or 0 on empty/malformed input
     */
    private static function parseIniSize(string $sIni): int
    {
        $sIni = trim($sIni);
        if ($sIni === '') {
            return 0;
        }

        $sLast = strtoupper(substr($sIni, -1));
        $sNumeric = in_array($sLast, ['G', 'M', 'K'], true)
            ? substr($sIni, 0, -1)
            : $sIni;

        if (! is_numeric($sNumeric)) {
            return 0;
        }

        $iValue = (int) $sNumeric;

        return match ($sLast) {
            'G' => $iValue * 1024 * 1024 * 1024,
            'M' => $iValue * 1024 * 1024,
            'K' => $iValue * 1024,
            default => $iValue,
        };
    }

    /**
     * Register backend permissions for goods-received actions.
     *
     * Four split permissions enable fine-grained role assignment:
     * - upload_invoices: parse-only access (junior operator)
     * - apply_invoices:  commit stock writes (senior operator)
     * - override_invoices: re-apply duplicate invoices ADDITIVELY (per D12)
     * - run_initial_reset: trigger the one-shot baseline reset (admin / owner)
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function registerPermissions(): array
    {
        return [
            'logingrupa.goodsreceived.upload_invoices' => [
                'label'   => 'logingrupa.goodsreceivedshopaholic::lang.permission.upload_invoices',
                'tab'     => 'logingrupa.goodsreceivedshopaholic::lang.permission.tab',
                'comment' => 'logingrupa.goodsreceivedshopaholic::lang.permission.upload_invoices_comment',
                'order'   => 100,
            ],
            'logingrupa.goodsreceived.apply_invoices' => [
                'label'   => 'logingrupa.goodsreceivedshopaholic::lang.permission.apply_invoices',
                'tab'     => 'logingrupa.goodsreceivedshopaholic::lang.permission.tab',
                'comment' => 'logingrupa.goodsreceivedshopaholic::lang.permission.apply_invoices_comment',
                'order'   => 200,
            ],
            'logingrupa.goodsreceived.override_invoices' => [
                'label'   => 'logingrupa.goodsreceivedshopaholic::lang.permission.override_invoices',
                'tab'     => 'logingrupa.goodsreceivedshopaholic::lang.permission.tab',
                'comment' => 'logingrupa.goodsreceivedshopaholic::lang.permission.override_invoices_comment',
                'order'   => 300,
            ],
            'logingrupa.goodsreceived.run_initial_reset' => [
                'label'   => 'logingrupa.goodsreceivedshopaholic::lang.permission.run_initial_reset',
                'tab'     => 'logingrupa.goodsreceivedshopaholic::lang.permission.tab',
                'comment' => 'logingrupa.goodsreceivedshopaholic::lang.permission.run_initial_reset_comment',
                'order'   => 400,
            ],
        ];
    }

    /**
     * Register backend Settings menu under the Shopaholic settings group.
     *
     * Lands at: Backend -> Settings -> Goods Received (NOT main nav, per locked decision D6).
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    public function registerSettings(): array
    {
        return [
            'goodsreceived-settings' => [
                'label'       => 'logingrupa.goodsreceivedshopaholic::lang.settings.label',
                'description' => 'logingrupa.goodsreceivedshopaholic::lang.settings.description',
                'category'    => 'lovata.shopaholic::lang.tab.settings',
                'icon'        => 'icon-truck',
                'class'       => Settings::class,
                'order'       => 500,
            ],
        ];
    }
}
