<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Exception;

/**
 * Thrown by Phase 3 `ApplyOrchestrator` when `Invoice.status='applied'`.
 * Apply is idempotent and refuses to run twice; the override flow is a
 * separate code path. Lang key `exception.apply_already_done`.
 */
final class ApplyAlreadyDoneException extends GoodsReceivedException
{
}
