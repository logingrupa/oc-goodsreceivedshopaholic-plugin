<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic;

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
     * Boot method, called right before the request route
     */
    public function boot(): void
    {
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
