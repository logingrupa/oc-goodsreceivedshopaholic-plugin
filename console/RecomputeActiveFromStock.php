<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Console;

use Illuminate\Console\Command;
use Logingrupa\GoodsReceivedShopaholic\Classes\Apply\ActiveFlagService;
use Throwable;

/**
 * Operator-driven recovery CLI: reconcile every offer's `active` flag from
 * its current `quantity` while honoring the per-row `active_managed_by`
 * provenance gate (UI-11 / D-29 / D-30 / D-31 / D-32 / D-33).
 *
 * Use cases:
 *   - Settings toggles flipped after stock data drifted (e.g., legacy 1C
 *     XML import previously owned `quantity`; operator just disabled that
 *     and turned on `auto_deactivate_on_zero` — the table needs one
 *     reconcile pass before the next inbound apply).
 *   - Post-restore dry-run after disaster recovery.
 *
 * Contract:
 *   - Exit 0 on success (also when both auto-flag toggles are OFF — that
 *     case correctly reconciles zero offers; ActiveFlagService::reconcileAll
 *     short-circuits to `return 0`).
 *   - Exit 1 on uncaught exception; failure printed via `$this->error()`.
 *   - Operator-managed offers (`active_managed_by='operator'`) are excluded
 *     AT THE QUERY LEVEL inside `ActiveFlagService::reconcileAll` (D-03-04-03);
 *     this command inherits that contract.
 *   - `--chunk` is sanitized: non-positive values coerce to default 500
 *     (T-04-02-01 mitigation — silent no-op / infinite loop avoided).
 *
 * Threat coverage (plan 04-02 register):
 *   - T-04-02-01 DoS via --chunk=0/-1: mitigated by the coerce-to-500 guard.
 *   - T-04-02-04 Repudiation on failure: mitigated by Throwable catch +
 *     `$this->error('Recompute failed: ...')` + exit code 1.
 */
final class RecomputeActiveFromStock extends Command
{
    /** @var string */
    protected $signature = 'goodsreceived:recompute_active_from_stock {--chunk=500}';

    /** @var string */
    protected $description = 'Reconcile offer.active from current offer.quantity per Settings; skips operator-managed offers.';

    public function handle(): int
    {
        $iChunk = (int) $this->option('chunk');
        if ($iChunk <= 0) {
            $iChunk = 500;
        }

        try {
            // Larastan narrows app(ClassString) to the typed instance; the
            // explicit instanceof guard the plan suggested is dead code at
            // L10 (instanceof.alwaysTrue). The IoC binding can still throw
            // BindingResolutionException — that is what the Throwable catch
            // below covers. (Deviation D-04-02-02: instanceof guard dropped.)
            $obService = app(ActiveFlagService::class);
            $iReconciled = $obService->reconcileAll($iChunk);
            $this->info(sprintf('Reconciled %d offers (chunk=%d).', $iReconciled, $iChunk));

            return 0;
        } catch (Throwable $obException) {
            $this->error('Recompute failed: '.$obException->getMessage());

            return 1;
        }
    }
}
