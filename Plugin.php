<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic;

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
}
