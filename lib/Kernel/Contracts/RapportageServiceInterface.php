<?php

/**
 * Contract of RapportageService
 *
 * The public surface of RapportageService that other components call, so they depend
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

/**
 * Contract of RapportageService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RapportageServiceInterface {

	/**
	 * Aging buckets — distributes open leads across the 4 fixed buckets.
	 *
	 * Buckets: 0-7d / 8-14d / 15-30d / >30d, keyed by `_dateModified`.
	 *
	 * @return array<int, array{bucket: string, count: int, totalValue: float}>
	 *
	 * @spec openspec/specs/lead-management/spec.md
	 */
	public function getAgingBuckets(): array;

	/**
	 * Source performance: total / won / conversion / avg-won-value per source.
	 *
	 * @param string|null $dateFrom Optional ISO 8601 lower bound (lead created date).
	 * @param string|null $dateTo Optional ISO 8601 upper bound (lead created date).
	 *
	 * @return array<int, array{source: string, total: int, won: int, conversionRate: float, avgWonValue: float}>
	 *
	 * @spec openspec/specs/lead-management/spec.md
	 */
	public function getSourcePerformance(?string $dateFrom = null, ?string $dateTo = null): array;

	/**
	 * Pipeline value per stage (count, total value, weighted by win chance).
	 *
	 * @param string|null $pipelineId Optional pipeline filter (matches lead.pipeline).
	 *
	 * @return array<int, array{stage: string, count: int, totalValue: float, weightedValue: float}>
	 *
	 * @spec openspec/specs/lead-management/spec.md
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	public function getStageValues(?string $pipelineId = null): array;

	/**
	 * Win/loss summary for closed leads within the optional date range.
	 *
	 * @param string|null $dateFrom Optional ISO 8601 lower bound.
	 * @param string|null $dateTo Optional ISO 8601 upper bound.
	 *
	 * @return array{wonCount:int, lostCount:int, winRate:float, avgWonValue:float, avgLostValue:float, avgDaysToClose:float}
	 *
	 * @spec openspec/specs/lead-management/spec.md
	 */
	public function getWinLossAnalysis(?string $dateFrom = null, ?string $dateTo = null): array;
}//end interface
