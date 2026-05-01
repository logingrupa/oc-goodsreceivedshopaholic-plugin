<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Classes\Match;

use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\MatchedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Dto\ParsedLine;
use Logingrupa\GoodsReceivedShopaholic\Classes\Exception\InvalidEanException;

/**
 * EAN match chain runner — Phase 6 / D-25-update.
 *
 * Orchestrates 3 MatchStrategy stages over ParsedLine residue:
 *   1. OfferCodeMatcher                — Offer.code direct match (1 query)
 *   2. ProductCodeSingleOfferMatcher   — Product.code with single-offer guard (1 query)
 *   3. VariationMatcher                — Offer.variation last-comma-segment with single-offer guard (1 query)
 *
 * Total query budget = 3 (one per stage, regardless of input size).
 * Counter-pinned by EanMatcherServiceTest "runs at most 3 queries" case.
 *
 * Public surface (post-refactor):
 *   matchLines(list<ParsedLine>): list<MatchedLine>
 *
 * The legacy 2-pass batch-and-build helpers (taking `list<string>` of EANs and
 * returning a map keyed by EAN, then a separate wrap step) are GONE — Phase 6
 * / D-25-update is a post-v1 internal refactor with a single caller
 * (`ParseAndPersistOrchestrator`). No back-compat shim per the locked spec.
 *
 * Defense-in-depth: every input EAN is regex-validated against /^\d{13}$/
 * BEFORE any DB query (T-02-06-01 mitigation; preserved verbatim from Phase 2
 * plan 02-06; pinned by EanMatcherServiceTest "InvalidEanException BEFORE any
 * DB query").
 *
 * Threat coverage (plan 06-05 register):
 *   - T-06-05-01 Tampering — defense-in-depth EAN regex preserved at chain
 *     runner boundary; assertAllEansValid runs BEFORE any chain stage SELECT.
 *   - T-06-05-02 Information disclosure — output order matches input order
 *     via reorderToInput; chain stages may match out of order but final
 *     order is input-canonical.
 *   - T-06-05-04 DoS via residue explosion — residue size monotonically
 *     decreases (each stage either matches or omits); bounded by parser's
 *     MAX_ROWS upstream guard.
 *
 * Hungarian notation throughout per Lovata Toolbox standard.
 */
final class EanMatcherService
{
    /**
     * Strict 13-digit EAN regex. Defense-in-depth check at the chain runner
     * boundary — the parser already filters non-13-digit rows (D-16 lenient
     * skip). If a bug ever lets a malformed EAN through, this assertion
     * raises `InvalidEanException` BEFORE any DB query.
     */
    private const string EAN_REGEX = '/^\d{13}$/';

    /**
     * Chain stages in deterministic execution order. Stage[i] receives only
     * the ParsedLine residue unmatched by stages [0..i-1]; stages return
     * lists of MatchedLine for the lines they could match. Lines unmatched
     * by ALL stages are wrapped as MatchedLine with match_strategy='none'.
     *
     * @var list<MatchStrategy>
     */
    private readonly array $arStages;

    public function __construct()
    {
        $this->arStages = [
            new OfferCodeMatcher(),
            new ProductCodeSingleOfferMatcher(),
            new VariationMatcher(),
        ];
    }

    /**
     * Run the EAN match chain over a list of ParsedLines.
     *
     * Each stage receives only the residue unmatched by prior stages. Lines
     * unmatched by ALL three stages emerge as MatchedLine with
     * match_strategy='none'. Output order matches input order.
     *
     * Total query budget = 3 (one SELECT per stage, regardless of input size).
     * Stages short-circuit when their residue is empty; an input where all
     * lines match in Pass 1 issues exactly 1 query, never 3.
     *
     * @param  list<ParsedLine>  $arLines
     * @return list<MatchedLine>
     *
     * @throws InvalidEanException  on any input line whose EAN is not exactly 13 digits
     */
    public function matchLines(array $arLines): array
    {
        if ($arLines === []) {
            return [];
        }

        $arEans = array_map(static fn (ParsedLine $obLine): string => $obLine->ean, $arLines);
        $this->assertAllEansValid($arEans);

        // Run the chain: each stage filters residue from prior stages.
        $arMatched = [];
        $arRemaining = $arLines;

        foreach ($this->arStages as $obStage) {
            if ($arRemaining === []) {
                break;
            }
            $arStageMatches = $obStage->match($arRemaining);
            if ($arStageMatches === []) {
                continue;
            }
            $arMatched = array_merge($arMatched, $arStageMatches);

            $arMatchedRowIndices = [];
            foreach ($arStageMatches as $obStageMatched) {
                $arMatchedRowIndices[$obStageMatched->line->row_index] = true;
            }
            $arRemaining = array_values(array_filter(
                $arRemaining,
                static fn (ParsedLine $obLine): bool => ! isset($arMatchedRowIndices[$obLine->row_index]),
            ));
        }

        // Wrap residue (lines unmatched by all stages) as 'none'.
        foreach ($arRemaining as $obLine) {
            $arMatched[] = new MatchedLine(
                line: $obLine,
                matched_offer_id: null,
                match_strategy: 'none',
            );
        }

        // Restore input order — chain stages may have produced matches out of
        // order; the contract for downstream `persistLines` (orchestrator) is
        // input-order preservation.
        return $this->reorderToInput($arLines, $arMatched);
    }

    /**
     * Defense-in-depth EAN regex guard. Throws BEFORE any DB query so an
     * invalid EAN cannot reach an IN-clause builder (T-02-06-01).
     *
     * @param  list<string>  $arEans
     *
     * @throws InvalidEanException
     */
    private function assertAllEansValid(array $arEans): void
    {
        foreach ($arEans as $sEan) {
            if (preg_match(self::EAN_REGEX, $sEan) === 1) {
                continue;
            }

            throw new InvalidEanException(
                (string) \Lang::get('logingrupa.goodsreceivedshopaholic::lang.exception.invalid_ean'),
                ['raw' => $sEan, 'expected_format' => '13 digits'],
            );
        }
    }

    /**
     * Reorder MatchedLine results to match the order of input ParsedLines
     * (keyed by row_index). Chain stages may match out of order; the
     * orchestrator's `persistLines` requires input-order preservation so
     * `invoice_lines.row_index` mirrors the upload's row sequence.
     *
     * @param  list<ParsedLine>   $arInput
     * @param  list<MatchedLine>  $arUnordered
     * @return list<MatchedLine>
     */
    private function reorderToInput(array $arInput, array $arUnordered): array
    {
        $arByRowIndex = [];
        foreach ($arUnordered as $obMatched) {
            $arByRowIndex[$obMatched->line->row_index] = $obMatched;
        }

        $arResult = [];
        foreach ($arInput as $obLine) {
            if (isset($arByRowIndex[$obLine->row_index])) {
                $arResult[] = $arByRowIndex[$obLine->row_index];
            }
        }

        return $arResult;
    }
}
