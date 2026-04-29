<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Exception;

/**
 * Thrown by `HtmInvoiceParser` (Phase 2 plan 02-05) when libxml emits a
 * fatal error OR the document yields zero extractable rows (D-17). Boundary
 * between "skip this row" (lenient) and "this whole file is unparseable"
 * (fail). Lang key `exception.malformed_htm`.
 */
final class MalformedHtmException extends GoodsReceivedException
{
}
