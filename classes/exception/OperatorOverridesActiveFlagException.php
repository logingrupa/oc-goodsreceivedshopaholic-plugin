<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Exception;

/**
 * Thrown by Phase 3 `ActiveFlagService` (informational skip) when an offer
 * carries `active_managed_by=operator` and is therefore exempt from
 * automatic active-flag toggling. Defining the type now keeps the typed-
 * exception catalog complete per PARSE-02. Lang key
 * `exception.operator_overrides_active_flag`.
 */
final class OperatorOverridesActiveFlagException extends GoodsReceivedException
{
}
