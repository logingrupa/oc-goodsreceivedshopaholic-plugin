<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Exception;

/**
 * Thrown by `EanMatcherService` (Phase 2 plan 02-06) as defense-in-depth at
 * the matcher boundary (security threat model). The HTM parser itself does
 * NOT throw this — D-16 dictates lenient parser behavior (log + skip).
 * Lang key `exception.invalid_ean`.
 */
final class InvalidEanException extends GoodsReceivedException
{
}
