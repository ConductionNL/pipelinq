<?php

/**
 * Pipelinq ForecastRollupService.
 *
 * Pure forecast aggregation math: bucket deals by forecast category and sum
 * child snapshots into a parent level. No persistence; fully unit-testable.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004, REQ-FRC-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Stateless forecast aggregation engine.
 *
 * A "totals" array always has the shape:
 *   commit_amount, best_case_amount, pipeline_amount, closed_won_amount (floats).
 * The {@see self::EMPTY_TOTALS} constant provides the zeroed baseline.
 */
class ForecastRollupService
{
    /**
     * The four roll-up amount keys.
     *
     * @var array<int, string>
     */
    public const AMOUNT_KEYS = ['commit_amount', 'best_case_amount', 'pipeline_amount', 'closed_won_amount'];

    /**
     * Constructor.
     *
     * @param ExchangeRateService $exchangeRate The currency normalization service.
     */
    public function __construct(
        private ExchangeRateService $exchangeRate,
    ) {
    }//end __construct()

    /**
     * Bucket a rep's deals into the four forecast amounts.
     *
     * Commit/best_case/pipeline sum only their matching open category. Closed_won
     * deals feed closed_won_amount only. Omitted, closed_lost and any other
     * category contribute nothing. All values are normalized to the reporting
     * currency. The contributing deal ids are returned for the audit trail.
     *
     * @param array<int, array<string, mixed>> $deals The rep's deal objects.
     *
     * @return array{totals: array<string, float>, deal_ids: array<int, string>, currency: string}
     *
     * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-004-02, REQ-FRC-004-03
     */
    public function bucketDeals(array $deals): array
    {
        $totals   = $this->emptyTotals();
        $dealIds  = [];
        $currency = $this->exchangeRate->getReportingCurrency();

        foreach ($deals as $deal) {
            $category = (string) ($deal['forecast_category'] ?? '');
            $value    = $this->exchangeRate->toReportingCurrency(
                amount: (float) ($deal['value'] ?? 0),
                currency: ($deal['currency'] ?? null)
            );

            $key = $this->categoryToAmountKey(category: $category);
            if ($key === null) {
                continue;
            }

            $totals[$key] += $value;
            $dealIds[]     = $this->dealId(deal: $deal);
        }

        foreach (self::AMOUNT_KEYS as $amountKey) {
            $totals[$amountKey] = round($totals[$amountKey], 2);
        }

        return [
            'totals'   => $totals,
            'deal_ids' => array_values(array_filter($dealIds, static fn(string $id): bool => $id !== '')),
            'currency' => $currency,
        ];
    }//end bucketDeals()

    /**
     * Sum a set of child totals into a parent total.
     *
     * Each child contributes its four amounts. The same rule applies at every
     * level (rep->team->division->company).
     *
     * @param array<int, array<string, float>> $childTotals The child amount arrays.
     *
     * @return array<string, float> The summed parent totals.
     *
     * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-005-01, REQ-FRC-005-02, REQ-FRC-005-03
     */
    public function sumChildTotals(array $childTotals): array
    {
        $parent = $this->emptyTotals();
        foreach ($childTotals as $child) {
            foreach (self::AMOUNT_KEYS as $amountKey) {
                $parent[$amountKey] += (float) ($child[$amountKey] ?? 0);
            }
        }

        foreach (self::AMOUNT_KEYS as $amountKey) {
            $parent[$amountKey] = round($parent[$amountKey], 2);
        }

        return $parent;
    }//end sumChildTotals()

    /**
     * Extract the four roll-up amounts from a snapshot record.
     *
     * @param array<string, mixed> $snapshot The snapshot data.
     *
     * @return array<string, float> The four amounts.
     */
    public function totalsFromSnapshot(array $snapshot): array
    {
        $totals = $this->emptyTotals();
        foreach (self::AMOUNT_KEYS as $amountKey) {
            $totals[$amountKey] = (float) ($snapshot[$amountKey] ?? 0);
        }

        return $totals;
    }//end totalsFromSnapshot()

    /**
     * A zeroed totals baseline.
     *
     * @return array<string, float> The empty totals.
     */
    public function emptyTotals(): array
    {
        return [
            'commit_amount'     => 0.0,
            'best_case_amount'  => 0.0,
            'pipeline_amount'   => 0.0,
            'closed_won_amount' => 0.0,
        ];
    }//end emptyTotals()

    /**
     * Map a forecast category to its target amount key (or null when excluded).
     *
     * @param string $category The forecast category.
     *
     * @return string|null The amount key or null.
     */
    private function categoryToAmountKey(string $category): ?string
    {
        return match ($category) {
            'commit'     => 'commit_amount',
            'best_case'  => 'best_case_amount',
            'pipeline'   => 'pipeline_amount',
            'closed_won' => 'closed_won_amount',
            default      => null,
        };
    }//end categoryToAmountKey()

    /**
     * Resolve a deal's identifier for the audit trail.
     *
     * @param array<string, mixed> $deal The deal data.
     *
     * @return string The deal id/uuid/slug, or empty string.
     */
    private function dealId(array $deal): string
    {
        $self = $deal['@self'] ?? [];
        if (is_array($self) === true) {
            $candidate = $self['uuid'] ?? $self['id'] ?? $self['slug'] ?? null;
            if (is_string($candidate) === true && $candidate !== '') {
                return $candidate;
            }
        }

        $candidate = $deal['uuid'] ?? $deal['id'] ?? null;
        if (is_string($candidate) === true) {
            return $candidate;
        }

        return '';
    }//end dealId()
}//end class
