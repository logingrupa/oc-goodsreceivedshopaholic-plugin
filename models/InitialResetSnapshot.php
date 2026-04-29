<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Models;

use October\Rain\Database\Model;
use October\Rain\Database\Traits\Validation;

/**
 * Class InitialResetSnapshot
 *
 * Captures the prior state of every offer/product BEFORE the one-shot
 * baseline reset writes zeroes/inactives. Snapshot rows are write-once —
 * the table has only `created_at` (no `updated_at`); `$timestamps = false`
 * disables October's automatic timestamp pair so writes set `created_at`
 * explicitly via service code (Phase 3 InitialResetService with injected
 * clock for determinism).
 *
 * Rollback story: re-applying the prior state walks these rows in order
 * and restores `offer.quantity` + `offer.active` + `product.active`.
 * Bare integer FKs to offer_id / prior_product_id (no `belongsTo` to
 * Lovata models) preserve audit history when offers/products are deleted.
 *
 * @package Logingrupa\GoodsReceivedShopaholic\Models
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $offer_id
 * @property int $prior_quantity
 * @property bool $prior_offer_active
 * @property int|null $prior_product_id
 * @property bool $prior_product_active
 * @property \Carbon\Carbon|null $created_at
 *
 * @property-read Invoice $invoice
 *
 * @method static \October\Rain\Database\Builder|InitialResetSnapshot newQuery()
 * @method static \October\Rain\Database\Builder|InitialResetSnapshot query()
 *
 * @mixin \Eloquent
 */
class InitialResetSnapshot extends Model
{
    use Validation;

    /** @var string */
    public $table = 'logingrupa_goods_received_initial_reset_snapshot';

    /**
     * Snapshot rows are write-once: only `created_at`, no `updated_at`.
     * October's default `$timestamps = true` adds both; we override.
     *
     * @var bool
     */
    public $timestamps = false;

    /** @var array<string, string> */
    public $rules = [
        'invoice_id' => 'required|integer',
        'offer_id' => 'required|integer',
        'prior_quantity' => 'required|integer|min:0',
    ];

    /** @var list<string> */
    protected $fillable = [
        'invoice_id',
        'offer_id',
        'prior_quantity',
        'prior_offer_active',
        'prior_product_id',
        'prior_product_active',
        'created_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'invoice_id' => 'integer',
        'offer_id' => 'integer',
        'prior_quantity' => 'integer',
        'prior_offer_active' => 'boolean',
        'prior_product_id' => 'integer',
        'prior_product_active' => 'boolean',
    ];

    /** @var list<string> */
    public $dates = ['created_at'];

    /** @var array<string, array<int|string, mixed>> */
    public $belongsTo = [
        'invoice' => [Invoice::class, 'key' => 'invoice_id'],
    ];
}
