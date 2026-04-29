<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Exception;

/**
 * Thrown by `InvoiceNumberResolver` (Phase 2 plan 02-04) when both body and
 * filename resolution fail (D-20). Lang key `exception.invoice_number_missing`.
 */
final class InvoiceNumberMissingException extends GoodsReceivedException
{
}
