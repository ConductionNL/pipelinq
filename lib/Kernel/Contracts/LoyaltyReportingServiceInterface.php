<?php

/**
 * Contract of LoyaltyReportingService
 *
 * The public surface of LoyaltyReportingService that other components call, so they depend
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
 * Contract of LoyaltyReportingService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LoyaltyReportingServiceInterface {

	/**
	 * Expiry forecast: how many points are scheduled to expire in the next $days days.
	 *
	 * @param string $programmeId The programme UUID.
	 * @param int $days Window size in days (default 30).
	 *
	 * @return array{points: int, accounts: int, until: string}
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function getExpiryForecast(string $programmeId, int $days = 30): array;

	/**
	 * Compute the headline KPIs for a programme over a date window.
	 *
	 * @param string $programmeId The programme UUID.
	 * @param ?string $from ISO date lower bound (inclusive).
	 * @param ?string $to ISO date upper bound (inclusive).
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-008-01
	 */
	public function getKpis(string $programmeId, ?string $from = null, ?string $to = null): array;

	/**
	 * Liability snapshot per IFRS 15 / RJ 270.
	 *
	 * @param string $programmeId The programme UUID.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-009-01
	 */
	public function getLiabilitySnapshot(string $programmeId): array;

	/**
	 * Tier distribution with each tier's account count.
	 *
	 * @param string $programmeId The programme UUID.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AggregationQuery::create() is the
	 *  library's documented value-object factory; there is no instance to call
	 *  it on.
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function getTierReport(string $programmeId): array;

	/**
	 * The KPIs as CSV: one metric and value line per scalar KPI.
	 *
	 * @param array<string, mixed> $kpis The KPIs from {@see getKpis()}.
	 *
	 * @return string The CSV text.
	 *
	 * @spec exclude This app has no canonical loyalty spec; see
	 *  LoyaltyReportingController::tierDistribution().
	 */
	public function kpisToCsv(array $kpis): string;

	/**
	 * The window of the last `$days` days, ending today (UTC), as ISO dates.
	 *
	 * @param int $days The number of days, at least one.
	 *
	 * @return array{from: string, to: string}
	 *
	 * @spec exclude This app has no canonical loyalty spec; see
	 *  LoyaltyReportingController::tierDistribution().
	 */
	public function lastDaysWindow(int $days): array;

	/**
	 * The tier report as CSV: one tier and account count line per tier.
	 *
	 * @param array<int, array<string, mixed>> $tiers The rows from {@see getTierReport()}.
	 *
	 * @return string The CSV text.
	 *
	 * @spec exclude This app has no canonical loyalty spec; see
	 *  LoyaltyReportingController::tierDistribution().
	 */
	public function tiersToCsv(array $tiers): string;
}//end interface
