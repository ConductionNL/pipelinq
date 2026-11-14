<?php

/**
 * Contract of RecurringRevenueService
 *
 * The public surface of RecurringRevenueService that other components call, so they depend
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
 * Contract of RecurringRevenueService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecurringRevenueServiceInterface {

	/**
	 * Compute the renewal rate and churned MRR for contracts whose renewal
	 * window closed within the given period.
	 *
	 * Renewal rate = renewed / (renewed + churned). Churned MRR = sum of the
	 * normalized monthly value of the churned contracts.
	 *
	 * @param array<int, array<string,mixed>> $contracts All contracts.
	 * @param string $periodFrom ISO date (inclusive lower bound on endDate).
	 * @param string $periodTo ISO date (inclusive upper bound on endDate).
	 *
	 * @return array{renewed:int, churned:int, renewalRate:float, churnedMrr:float}
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
	 */
	public function computeRenewalMetrics(array $contracts, string $periodFrom, string $periodTo): array;

	/**
	 * Build the full recurring-revenue summary for the dashboard.
	 *
	 * @return array{mrr:float, arr:float, activeCount:int, expiringCount:int}
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-recurring-revenue-roll-up
	 */
	public function getSummary(): array;
}//end interface
