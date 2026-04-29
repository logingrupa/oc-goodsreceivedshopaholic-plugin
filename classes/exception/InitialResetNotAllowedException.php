<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Exception;

/**
 * Thrown by Phase 3 `InitialResetService` when the operator triggers a reset
 * but `Settings.allow_initial_reset` is off OR a prior reset has already been
 * recorded for the site. Lang key `exception.initial_reset_not_allowed`.
 */
final class InitialResetNotAllowedException extends GoodsReceivedException
{
}
