<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Models;

use Illuminate\Support\Facades\DB;
use Lovata\Shopaholic\Models\Offer;
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
 * Cross-plugin coupling stays soft: `matched_offer_id` and
 * `matched_product_id` are bare integer FKs at the schema level (no FK
 * constraint, no cascade — preserves audit history if upstream rows are
 * deleted). A `matched_offer` belongsTo relation is exposed as a
 * READ-ONLY view affordance so backend list columns can eager-load the
 * current Offer.quantity via October's `relation:`/`select:` column type
 * without N+1. Service layer (Phase 3) still resolves via
 * `Offer::find($iId)` directly.
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

    /** The EAN belongs to 2+ live offers and the active rule could not pick one; the operator must. */
    final public const string MATCH_STRATEGY_AMBIGUOUS = 'ambiguous';

    /** Duplicate code, exactly one active offer in the group, picked by rule. */
    final public const string MATCH_STRATEGY_OFFER_CODE_ACTIVE = 'offer_code_active';

    /** Duplicate code resolved by an operator choice in the apply modal. */
    final public const string MATCH_STRATEGY_OFFER_CODE_OPERATOR = 'offer_code_operator';

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
        'invoice'        => [Invoice::class, 'key' => 'invoice_id'],
        'matched_offer'  => [Offer::class, 'key' => 'matched_offer_id'],
    ];

    /**
     * Lines of one invoice whose EAN resolved to no offer, in document
     * order. Sole definition of "unmatched" for the upload apply modal, the
     * apply confirm modal and the invoice summary block.
     *
     * @return list<self>
     */
    public static function unmatchedFor(int $iInvoiceId): array
    {
        return self::linesWithStrategy($iInvoiceId, self::MATCH_STRATEGY_NONE);
    }

    /**
     * Lines of one invoice whose EAN belongs to 2+ live offers and still
     * waits for an operator choice, in document order. Apply is blocked
     * while this list is non-empty (LineResolutionService::resolve).
     *
     * @return list<self>
     */
    public static function ambiguousFor(int $iInvoiceId): array
    {
        return self::linesWithStrategy($iInvoiceId, self::MATCH_STRATEGY_AMBIGUOUS);
    }

    /**
     * The instanceof loop narrows October's untyped Builder rows for PHPStan
     * L10 (same pattern as ApplyOrchestrator::loadMatchedLines).
     *
     * @return list<self>
     */
    private static function linesWithStrategy(int $iInvoiceId, string $sStrategy): array
    {
        $arLines = [];
        foreach (self::where('invoice_id', $iInvoiceId)
            ->where('match_strategy', $sStrategy)
            ->orderBy('row_index')
            ->get() as $obLine) {
            if ($obLine instanceof self) {
                $arLines[] = $obLine;
            }
        }

        return $arLines;
    }

    /**
     * Units apply will actually add for one invoice: `COALESCE(override_qty,
     * qty)` over matched lines only, the same per-line formula
     * StockApplyService uses. Shared by both apply modals so they quote the
     * same number.
     */
    public static function unitsToApplyFor(int $iInvoiceId): int
    {
        return (int) self::where('invoice_id', $iInvoiceId)
            ->whereNotNull('matched_offer_id')
            ->sum(DB::raw('COALESCE(override_qty, qty)'));
    }
}
