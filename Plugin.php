<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic;

use Backend;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
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
     * Backend-gated: only registers the Catalog-side-menu item when the
     * request is dispatched to the backend so frontend page-loads incur
     * zero overhead.
     */
    public function boot(): void
    {
        if (! App::runningInBackend()) {
            return;
        }

        // Inject "Goods Received" as a side-menu item under the Catalog
        // main menu (Lovata.Shopaholic / shopaholic-menu-main, defined in
        // plugins/lovata/shopaholic/plugin.yaml). Avoids a noisy top-level
        // entry — operators reach the GRN feature from inside the catalog
        // workflow context.
        //
        // Use the modern Event::listen('backend.menu.extendItems') hook —
        // the deprecated BackendMenu::registerCallback() fires too early
        // (before NavigationManager::loadItems initializes $items) and
        // throws "Unable to add navigation items before they are loaded".
        // The extendItems event fires AFTER plugin nav arrays have been
        // collected, so addSideMenuItems can safely target Lovata.Shopaholic.
        Event::listen('backend.menu.extendItems', static function (\Backend\Classes\NavigationManager $obManager): void {
            $obManager->addSideMenuItems('Lovata.Shopaholic', 'shopaholic-menu-main', [
                'goodsreceived' => [
                    'label'       => 'logingrupa.goodsreceivedshopaholic::lang.menu.goods_received',
                    'icon'        => 'icon-truck',
                    'url'         => Backend::url('logingrupa/goodsreceivedshopaholic/invoices'),
                    'permissions' => ['logingrupa.goodsreceived.upload_invoices'],
                    'order'       => 400,
                ],
            ]);
        });
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
     * Register backend Settings menu entry — single canonical surface.
     *
     * Operators reach the Goods Received feature via:
     *   1. Settings → Goods Received  -> 4 per-site toggles (this entry).
     *   2. Main nav → Shopaholic → Goods Received → Invoices  -> upload + audit
     *      history (registered via `registerNavigation` below; Lovata.Shopaholic
     *      group is already in main nav so we extend it instead of adding new
     *      top-level clutter per D6).
     *
     * The Settings form view (partials/_settings_field_manage_invoices.htm)
     * also surfaces a "Manage Invoices" link to the controller URL for
     * operators who land on Settings first.
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

    /**
     * Register backend main-nav extension under Lovata.Shopaholic group.
     *
     * Adds the Invoices controller as a side-menu item beneath Shopaholic so
     * operators can upload, preview, and apply HTM delivery receipts without
     * leaving the main backend navigation flow.
     *
     * Settings stays under Settings menu (registerSettings above) — the two
     * surfaces are separate because:
     *   - Invoices CRUD is a workflow tool (frequent operator action)
     *   - Settings toggles are configuration (rare, admin-only)
     *
     * @return array<string, array<string, mixed>>
     */
    public function registerNavigation(): array
    {
        // Top-level "Goods Received" entry intentionally omitted — the
        // sideMenu callback in boot() registers the feature beneath
        // Catalog (Lovata.Shopaholic / shopaholic-menu-main) instead so
        // operators reach it from inside the existing catalog workflow.
        // Keeping the method as an explicit empty return documents the
        // decision and prevents October from inferring auto-navigation.
        return [];
    }
}
