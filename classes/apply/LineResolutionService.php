<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Apply;

use Logingrupa\GoodsReceivedShopaholic\Models\Invoice;
use Logingrupa\GoodsReceivedShopaholic\Models\InvoiceLine;
use Lovata\Shopaholic\Models\Offer;
use October\Rain\Exception\AjaxException;

/**
 * Operator resolution of `ambiguous` invoice lines (2026-09-08).
 *
 * The matcher chain emits `ambiguous` when an EAN belongs to 2+ live offers
 * and the active rule cannot pick one. This service owns the two things
 * the apply flow needs from there:
 *
 *   - `candidatesFor()`: the offers the operator may pick from. Nothing is
 *     stored; the list is a pure function of the EAN, recomputed at render
 *     time with ONE query for every ambiguous line of the invoice. A
 *     candidate is a live offer whose own code, or whose product's code,
 *     equals the line EAN.
 *   - `resolve()`: persist the operator's picks (`matched_offer_id` +
 *     `offer_code_operator`), then refuse apply while any line is still
 *     `ambiguous`. A pick outside the candidate set throws; the client
 *     `<select>` is not a security boundary.
 *
 * Throws AjaxException directly: the only callers are the Invoices
 * controller AJAX handlers, which relay the message to the operator.
 */
final class LineResolutionService
{
    /**
     * @param  list<InvoiceLine>  $arLines
     * @return array<int, list<array{offer_id: int, code: string, offer_name: string, product_code: string, product_name: string, active: bool, quantity: int}>>  keyed by line id
     */
    public function candidatesFor(array $arLines): array
    {
        if ($arLines === []) {
            return [];
        }

        $arEans = array_values(array_unique(array_map(
            static fn (InvoiceLine $obLine): string => (string) $obLine->ean,
            $arLines,
        )));
        $arRows = $this->loadCandidateRows($arEans);

        $arByLine = [];
        foreach ($arLines as $obLine) {
            $sEan = (string) $obLine->ean;
            $arByLine[(int) $obLine->id] = array_values(array_filter(
                $arRows,
                static fn (array $arRow): bool => $arRow['code'] === $sEan || $arRow['product_code'] === $sEan,
            ));
        }

        return $arByLine;
    }

    /**
     * Persist `offer_choice[<line_id>] => offer_id` picks, then block apply
     * while the invoice still has an unresolved `ambiguous` line.
     *
     * @throws AjaxException  on a pick outside the candidate set, or on an unresolved line
     */
    public function resolve(int $iInvoiceId, mixed $mChoices): void
    {
        $this->applyChoices($iInvoiceId, $mChoices);
        $this->assertResolved($iInvoiceId);
    }

    /**
     * @throws AjaxException
     */
    private function applyChoices(int $iInvoiceId, mixed $mChoices): void
    {
        if (! is_array($mChoices) || $mChoices === []) {
            return;
        }

        $arLines = $this->loadResolvableLines($iInvoiceId);
        $arCandidates = $this->candidatesFor(array_values($arLines));

        foreach ($mChoices as $mLineId => $mOfferId) {
            $iOfferId = is_scalar($mOfferId) ? (int) $mOfferId : 0;
            if ($iOfferId <= 0) {
                continue;
            }
            $obLine = $arLines[(int) $mLineId] ?? null;
            $this->assertCandidate($obLine, $iOfferId, $arCandidates[(int) $mLineId] ?? []);

            $obLine->matched_offer_id = $iOfferId;
            $obLine->match_strategy = InvoiceLine::MATCH_STRATEGY_OFFER_CODE_OPERATOR;
            $obLine->saveQuietly();
        }

        $this->recountInvoice($iInvoiceId);
    }

    /**
     * @throws AjaxException
     */
    private function assertResolved(int $iInvoiceId): void
    {
        $arOpen = InvoiceLine::ambiguousFor($iInvoiceId);
        if ($arOpen === []) {
            return;
        }

        $obInvoice = Invoice::find($iInvoiceId);
        $arRows = array_map(
            static fn (InvoiceLine $obLine): string => sprintf(
                '#%d %s %s',
                (int) $obLine->row_index,
                (string) $obLine->ean,
                (string) $obLine->product_name_raw,
            ),
            $arOpen,
        );

        throw new AjaxException([
            'message' => (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.apply.ambiguous_unresolved', [
                'number' => $obInvoice instanceof Invoice ? (string) $obInvoice->invoice_number : (string) $iInvoiceId,
                'count'  => count($arOpen),
                'rows'   => implode('; ', $arRows),
            ]),
        ]);
    }

    /**
     * @param  list<array{offer_id: int, code: string, offer_name: string, product_code: string, product_name: string, active: bool, quantity: int}>  $arCandidates
     *
     * @throws AjaxException
     *
     * @phpstan-assert InvoiceLine $obLine
     */
    private function assertCandidate(?InvoiceLine $obLine, int $iOfferId, array $arCandidates): void
    {
        $arCandidateIds = array_map(
            static fn (array $arCandidate): int => $arCandidate['offer_id'],
            $arCandidates,
        );
        if ($obLine instanceof InvoiceLine && in_array($iOfferId, $arCandidateIds, true)) {
            return;
        }

        throw new AjaxException([
            'message' => (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.apply.offer_choice_invalid', [
                'offer_id' => $iOfferId,
                'row'      => $obLine instanceof InvoiceLine ? (int) $obLine->row_index : 0,
                'ean'      => $obLine instanceof InvoiceLine ? (string) $obLine->ean : '',
            ]),
        ]);
    }

    /**
     * Lines an operator may (re)pick for: still ambiguous, or already picked
     * once. Keyed by line id.
     *
     * @return array<int, InvoiceLine>
     */
    private function loadResolvableLines(int $iInvoiceId): array
    {
        $arLines = [];
        foreach (InvoiceLine::where('invoice_id', $iInvoiceId)
            ->whereIn('match_strategy', [
                InvoiceLine::MATCH_STRATEGY_AMBIGUOUS,
                InvoiceLine::MATCH_STRATEGY_OFFER_CODE_OPERATOR,
            ])
            ->get() as $obLine) {
            if ($obLine instanceof InvoiceLine) {
                $arLines[(int) $obLine->id] = $obLine;
            }
        }

        return $arLines;
    }

    /**
     * One SELECT for the whole EAN list. Live offers only (Offer's soft
     * delete scope plus an explicit product `deleted_at` guard on the
     * join). Active offers first, then id, so the select lists the likely
     * pick at the top.
     *
     * @param  list<string>  $arEans
     * @return list<array{offer_id: int, code: string, offer_name: string, product_code: string, product_name: string, active: bool, quantity: int}>
     */
    private function loadCandidateRows(array $arEans): array
    {
        $obRows = Offer::query()
            ->leftJoin('lovata_shopaholic_products as gr_product', 'gr_product.id', '=', 'lovata_shopaholic_offers.product_id')
            ->whereNull('gr_product.deleted_at')
            ->where(static function ($obQuery) use ($arEans): void {
                $obQuery->whereIn('lovata_shopaholic_offers.code', $arEans)
                    ->orWhereIn('gr_product.code', $arEans);
            })
            ->orderByDesc('lovata_shopaholic_offers.active')
            ->orderBy('lovata_shopaholic_offers.id')
            ->get([
                'lovata_shopaholic_offers.id',
                'lovata_shopaholic_offers.code',
                'lovata_shopaholic_offers.name',
                'lovata_shopaholic_offers.active',
                'lovata_shopaholic_offers.quantity',
                'gr_product.code as product_code',
                'gr_product.name as product_name',
            ]);

        $arRows = [];
        foreach ($obRows as $obRow) {
            if (! $obRow instanceof Offer) {
                continue;
            }
            $arRows[] = [
                'offer_id'     => intval($obRow->id),
                'code'         => strval($obRow->code),
                'offer_name'   => strval($obRow->name),
                'product_code' => is_scalar($obRow->product_code) ? (string) $obRow->product_code : '',
                'product_name' => is_scalar($obRow->product_name) ? (string) $obRow->product_name : '',
                'active'       => boolval($obRow->active),
                'quantity'     => intval($obRow->quantity),
            ];
        }

        return $arRows;
    }

    /**
     * Header counters mirror ParseAndPersistOrchestrator::updateInvoiceCounters:
     * matched = lines with an offer id, unmatched = the rest (including
     * lines still ambiguous).
     */
    private function recountInvoice(int $iInvoiceId): void
    {
        $iTotal = InvoiceLine::where('invoice_id', $iInvoiceId)->count();
        $iMatched = InvoiceLine::where('invoice_id', $iInvoiceId)->whereNotNull('matched_offer_id')->count();

        Invoice::where('id', $iInvoiceId)->update([
            'matched_lines'   => $iMatched,
            'unmatched_lines' => $iTotal - $iMatched,
        ]);
    }
}
