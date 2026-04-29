<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Models;

use October\Rain\Database\Model;
use October\Rain\Database\Traits\Validation;

/**
 * Class Invoice
 *
 * Header model for distributor goods-received notes (GRN). One row per .HTM
 * delivery receipt. Idempotency is enforced at the DB layer via the UNIQUE
 * index on `invoice_number` — application logic resolves duplicates via the
 * override-and-reimport flow (D12: ADD-ON-TOP semantics, no diff/decrement).
 *
 * Self-referential override chain:
 *  - $belongsTo['overrideOf']  -> the prior invoice this one supersedes
 *  - $hasMany['overrides']     -> subsequent invoices that supersede this one
 *
 * Both relations key on `override_of_invoice_id`, declared with `nullOnDelete`
 * at the DB layer to preserve audit trail when prior invoices are deleted.
 *
 * @package Logingrupa\GoodsReceivedShopaholic\Models
 *
 * @property int $id
 * @property string $invoice_number
 * @property \Carbon\Carbon|null $invoice_date
 * @property string|null $country_code
 * @property string|null $source_filename
 * @property string|null $source_path
 * @property string $status
 * @property int $total_lines
 * @property int $matched_lines
 * @property int $unmatched_lines
 * @property int $stock_added_units
 * @property int|null $applied_by_user_id
 * @property \Carbon\Carbon|null $parsed_at
 * @property \Carbon\Carbon|null $applied_at
 * @property bool $initial_reset_applied
 * @property int|null $override_of_invoice_id
 * @property string|null $notes
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read \October\Rain\Database\Collection|InvoiceLine[] $lines
 * @property-read \October\Rain\Database\Collection|InitialResetSnapshot[] $snapshots
 * @property-read Invoice|null $overrideOf
 * @property-read \October\Rain\Database\Collection|Invoice[] $overrides
 *
 * @method static \October\Rain\Database\Builder|Invoice newQuery()
 * @method static \October\Rain\Database\Builder|Invoice query()
 *
 * @mixin \Eloquent
 */
class Invoice extends Model
{
    use Validation;

    public const STATUS_PARSED = 'parsed';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED_DUPLICATE = 'rejected_duplicate';

    /** @var string */
    public $table = 'logingrupa_goods_received_invoices';

    /** @var array<string, string> */
    public $rules = [
        'invoice_number' => 'required|string|max:64',
        'status' => 'required|string|max:32',
    ];

    /** @var list<string> */
    protected $fillable = [
        'invoice_number',
        'invoice_date',
        'country_code',
        'source_filename',
        'source_path',
        'status',
        'total_lines',
        'matched_lines',
        'unmatched_lines',
        'stock_added_units',
        'applied_by_user_id',
        'parsed_at',
        'applied_at',
        'initial_reset_applied',
        'override_of_invoice_id',
        'notes',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'total_lines' => 'integer',
        'matched_lines' => 'integer',
        'unmatched_lines' => 'integer',
        'stock_added_units' => 'integer',
        'applied_by_user_id' => 'integer',
        'override_of_invoice_id' => 'integer',
        'initial_reset_applied' => 'boolean',
    ];

    /** @var list<string> */
    public $dates = ['invoice_date', 'parsed_at', 'applied_at'];

    /** @var array<string, array<int|string, mixed>> */
    public $hasMany = [
        'lines' => [InvoiceLine::class, 'key' => 'invoice_id'],
        'snapshots' => [InitialResetSnapshot::class, 'key' => 'invoice_id'],
        'overrides' => [self::class, 'key' => 'override_of_invoice_id'],
    ];

    /** @var array<string, array<int|string, mixed>> */
    public $belongsTo = [
        'overrideOf' => [self::class, 'key' => 'override_of_invoice_id'],
    ];
}
