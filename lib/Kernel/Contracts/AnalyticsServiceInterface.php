<?php

/**
 * Contract of AnalyticsService
 *
 * The public surface of AnalyticsService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of AnalyticsService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AnalyticsServiceInterface {

	/**
	 * Default period when caller supplies none.
	 *
	 * @var string
	 */
	public const DEFAULT_PERIOD = 'month';

	/**
	 * Commercial KPI overview for the Commercial dashboard.
	 *
	 * Computes, for the trailing window of $period:
	 *   - revenue:           settled-POS turnover + won-deal value closed in window
	 *   - wonValue:          sum of won-lead value closed in window
	 *   - winRate:           won / (won + lost) leads closed in window (0-100, null if none)
	 *   - avgDealSize:       mean won-lead value in window (null if none)
	 *   - weightedForecast:  sum over OPEN leads of value * win chance (qualification score / 100)
	 *   - openPipelineValue: sum of value over OPEN leads (forward-looking)
	 *   - wonCount/lostCount: deals won and lost in window
	 *   - openCount/scoredOpenCount: OPEN leads, and those with a qualification score
	 *   - wonShareOfOpen:    won value as a whole percentage of the open value (null with nothing open)
	 *   - targetRemaining/pipelineCover: target less won value, open value over it (null without a target)
	 *   - previousPeriod:    windowed figures for the preceding equal-length window
	 *
	 * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
	 * @param array<string, mixed> $leadFilter Field filters the open-pipeline figures follow, as the leads list sends them.
	 *
	 * @return array{
	 *   revenue: float,
	 *   wonValue: float,
	 *   winRate: float|null,
	 *   avgDealSize: float|null,
	 *   wonCount: int,
	 *   lostCount: int,
	 *   weightedForecast: float,
	 *   openPipelineValue: float,
	 *   openCount: int,
	 *   scoredOpenCount: int,
	 *   wonShareOfOpen: int|null,
	 *   targetRemaining: float|null,
	 *   pipelineCover: float|null,
	 *   period: string,
	 *   previousPeriod: array<string, float|int|null>
	 * }
	 *
	 * @throws InvalidArgumentException When the period is not recognised.
	 * @throws RuntimeException When OpenRegister is unreachable.
	 *
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	public function getCommercialOverview(string $period = self::DEFAULT_PERIOD, array $leadFilter = []): array;

	/**
	 * Lead-to-close and request-to-resolved funnel data.
	 *
	 * Returns:
	 *   - leadFunnel:    { open, won, lost, conversionRate }
	 *   - requestFunnel: { new, in_progress, completed, rejected, resolutionRate }
	 *
	 * @return array{
	 *   leadFunnel: array<string, int|float|null>,
	 *   requestFunnel: array<string, int|float|null>
	 * }
	 *
	 * @throws RuntimeException When OpenRegister is unreachable.
	 *
	 * @spec openspec/changes/archive/2026-06-14-dashboard/tasks.md#task-2.2
	 */
	public function getFunnels(): array;

	/**
	 * Cross-module KPI overview for the unified analytics widget.
	 *
	 * Computes:
	 *   - leadConversionRate:        won-leads / total-leads within the period (0-100)
	 *   - avgRequestResolutionTime:  mean hours between the request ticket's occurredAt
	 *                                and its resolvedAt for requests resolved within the
	 *                                period (null if none)
	 *   - contactMomentVolume:       count of contactmoment tickets within the period
	 *   - customerSatisfactionScore: mean surveyResponse.score within the period (null if none)
	 *   - period:                    echo of the period key
	 *   - previousPeriod:            same metric block for the prior equal-length window
	 *
	 * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
	 *
	 * @return array{
	 *   leadConversionRate: float|null,
	 *   avgRequestResolutionTime: float|null,
	 *   contactMomentVolume: int,
	 *   customerSatisfactionScore: float|null,
	 *   period: string,
	 *   previousPeriod: array<string, mixed>
	 * }
	 *
	 * @throws InvalidArgumentException When the period is not recognised.
	 * @throws RuntimeException When OpenRegister is unreachable.
	 *
	 * @spec openspec/changes/archive/2026-06-14-dashboard/tasks.md#task-2.2
	 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#REQ-RPD-001
	 */
	public function getOverview(string $period = self::DEFAULT_PERIOD): array;

	/**
	 * Time-series trend data for the unified analytics charts.
	 *
	 * Supported metrics:
	 *   - `leads`                — count of leads bucketed by createdAt
	 *   - `pipeline-value`       — sum of value over leads bucketed by createdAt
	 *   - `requests-by-category` — count of requests grouped by category
	 *                              (returns one series entry per category)
	 *
	 * @param string $metric One of ALLOWED_TREND_METRICS.
	 * @param string $period One of ALLOWED_OVERVIEW_PERIODS.
	 *
	 * @return array{metric: string, period: string, series: array<int, array<string, mixed>>}
	 *
	 * @throws InvalidArgumentException When metric or period unsupported.
	 * @throws RuntimeException When OpenRegister is unreachable.
	 *
	 * @spec openspec/changes/archive/2026-06-14-dashboard/tasks.md#task-2.2
	 */
	public function getTrends(string $metric, string $period = self::DEFAULT_PERIOD): array;
}//end interface
