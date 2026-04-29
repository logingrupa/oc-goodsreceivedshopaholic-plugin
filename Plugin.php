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
