<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Exception;

use RuntimeException;
use Throwable;

/**
 * Abstract base for typed plugin exceptions; carries forensic context array
 * for `Log::error()` import audit.
 *
 * Per CONTEXT.md D-07..D-10:
 *   - All eight typed exceptions in this namespace extend this class.
 *   - Phase 3 orchestrators and Phase 4 controllers catch the base type to
 *     handle every plugin-emitted exception polymorphically.
 *   - Subclasses inherit the constructor verbatim — they MUST NOT override it.
 *
 * Threat model (T-02-02-01..03):
 *   - `$arContext` is `public readonly` (PHP 8.4 enforces immutability).
 *   - `jsonContext()` is the log-injection guard: `json_encode` escapes
 *     control characters (newline / CR / tab) into `\nXX` literals so an
 *     attacker-controlled HTM cell cannot forge fake log lines. Falls back
 *     to `'{}'` on unencodable input (resources, recursive refs) so callers
 *     always receive a string.
 *
 * @property-read array<string, mixed> $arContext
 */
abstract class GoodsReceivedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $arContext
     */
    public function __construct(
        string $sMessage,
        public readonly array $arContext = [],
        ?Throwable $obPrevious = null,
    ) {
        parent::__construct($sMessage, 0, $obPrevious);
    }

    /**
     * Encode a context array as a single-line JSON string safe for
     * `Log::error()` sinks. `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`
     * keeps the payload readable; default `json_encode` behavior escapes
     * control characters (`\n`, `\r`, `\t`) into literal escape sequences,
     * blocking log-injection (T-02-02-02). Returns `'{}'` if `json_encode`
     * fails (T-02-02-03).
     *
     * @param  array<string, mixed>  $arContext
     */
    protected static function jsonContext(array $arContext): string
    {
        $sJson = json_encode($arContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $sJson !== false ? $sJson : '{}';
    }
}
