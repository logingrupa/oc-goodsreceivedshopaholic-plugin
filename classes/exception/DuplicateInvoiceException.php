<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Exception;

/**
 * Thrown by Phase 3 `ParseAndPersistOrchestrator` on UNIQUE-index conflict
 * for `invoice_number`. Lang key `exception.duplicate_invoice`.
 */
final class DuplicateInvoiceException extends GoodsReceivedException
{
}
