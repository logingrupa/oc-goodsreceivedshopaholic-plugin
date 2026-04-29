<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Models;

use October\Rain\Database\Model;
use October\Rain\Database\Traits\Validation;

/**
 * Class InvoiceLine
 *
 * One row per parsed `<TR class="R20|R21">` data row in a `.HTM` invoice.
 * Each line carries the EAN/qty/unit_price extracted from the distributor
 * receipt, plus the match resolution outcome (`match_strategy`,
 * `matched_offer_id`, `matched_product_id`).
 *
 * Cross-plugin coupling is intentionally absent: `matched_offer_id` and
 * `matched_product_id` are bare integer FKs, NOT `belongsTo` relations to
 * `Lovata\Shopaholic\Models\{Offer,Product}`. Phase 3 services resolve via
 * `Offer::find($iId)` directly when needed; this preserves audit history
 * if upstream rows are deleted.
 *
 * @package Logingrupa\GoodsReceivedShopaholic\Models
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $row_index
 * @property string $ean
 * @property string $product_name_raw
 * @property int $qty
 * @property string|null $unit_price
 * @property int|null $matched_offer_id
 * @property int|null $matched_product_id
 * @property string $match_strategy
 * @property bool $applied
 * @property int|null $override_qty
 * @property string|null $override_reason
 * @property \Carbon\Carbon|null $applied_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Invoice $invoice
 */
class InvoiceLine extends Model
{
    use Validation;

    public const MATCH_STRATEGY_OFFER_CODE = 'offer_code';

    public const MATCH_STRATEGY_PRODUCT_CODE_SINGLE_OFFER = 'product_code_single_offer';

    public const MATCH_STRATEGY_NONE = 'none';

    /** @var string */
    public $table = 'logingrupa_goods_received_invoice_lines';

    /** @var array<string, string> */
    public $rules = [
        'invoice_id' => 'required|integer',
        'ean' => 'required|string|max:13',
        'qty' => 'required|integer|min:0',
        'match_strategy' => 'required|string|max:32',
    ];

    /** @var list<string> */
    protected $fillable = [
        'invoice_id',
        'row_index',
        'ean',
        'product_name_raw',
        'qty',
        'unit_price',
        'matched_offer_id',
        'matched_product_id',
        'match_strategy',
        'applied',
        'override_qty',
        'override_reason',
        'applied_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'invoice_id' => 'integer',
        'row_index' => 'integer',
        'qty' => 'integer',
        'unit_price' => 'decimal:4',
        'matched_offer_id' => 'integer',
        'matched_product_id' => 'integer',
        'applied' => 'boolean',
        'override_qty' => 'integer',
    ];

    /** @var list<string> */
    public $dates = ['applied_at'];

    /** @var array<string, array<int|string, mixed>> */
    public $belongsTo = [
        'invoice' => [Invoice::class, 'key' => 'invoice_id'],
    ];
}
