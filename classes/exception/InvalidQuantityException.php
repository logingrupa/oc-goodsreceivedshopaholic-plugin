<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Exception;

/**
 * Thrown by `QuantityNormalizer::parseQuantity` (Phase 2 plan 02-03, D-21)
 * when the input is non-integer (decimal-comma, decimal-period, zero or
 * negative). Guards against silent Eloquent int-clamp on `offer.quantity`.
 * Lang key `exception.invalid_quantity`.
 */
final class InvalidQuantityException extends GoodsReceivedException
{
}
