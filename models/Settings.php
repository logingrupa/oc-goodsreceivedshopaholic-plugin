<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Models;

use October\Rain\Database\Traits\Multisite;
use System\Models\SettingModel;

/**
 * Class Settings
 *
 * @package Logingrupa\GoodsReceivedShopaholic\Models
 *
 * Backend settings model for the Goods Received Notes plugin.
 * Per-site isolation provided by October Rain's `Multisite` trait — the same
 * mechanism Lovata Toolbox's shared settings base uses internally. Extends
 * `SettingModel` directly per locked decision D15, with the multisite trait
 * applied inline so per-site isolation behavior is functionally identical.
 *
 * @property bool $enabled
 * @property bool $auto_deactivate_on_zero
 * @property bool $auto_activate_on_stock
 * @property bool $allow_initial_reset
 *
 * @method static \October\Rain\Database\Builder|Settings newQuery()
 *
 * @mixin \System\Models\SettingModel
 * @mixin \Eloquent
 */
class Settings extends SettingModel
{
    use Multisite;

    public const SETTINGS_CODE = 'logingrupa_goodsreceivedshopaholic_settings';

    /** @var string */
    public $settingsCode = self::SETTINGS_CODE;

    /** @var string */
    public $settingsFields = 'fields.yaml';

    /**
     * Multisite trait requires `$propagatable` to be an array (initializeMultisite()
     * throws otherwise). Empty list = no attribute is auto-synced across sites;
     * each site's settings row is fully independent. This is what we want for
     * per-site toggles.
     *
     * @var array<int, string>
     */
    protected $propagatable = [];
}
