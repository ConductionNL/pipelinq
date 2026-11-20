<?php

/**
 * Contract of ReportingService
 *
 * The public surface of ReportingService that other components call, so they depend
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
 * Contract of ReportingService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportingServiceInterface {

	/**
	 * Generate CSV content from data.
	 *
	 * Uses semicolon separators and UTF-8 BOM for Excel compatibility.
	 *
	 * @param array<string> $headers The CSV header row.
	 * @param array<array<string>> $rows The data rows.
	 *
	 * @return string The CSV content.
	 *
	 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-export-and-bi-integration
	 */
	public function generateCsv(array $headers, array $rows): string;

	/**
	 * Get per-agent performance metrics for the given date range.
	 *
	 * @param string $from ISO 8601 start date.
	 * @param string $to ISO 8601 end date.
	 *
	 * @return array<string, array{count: int, fcrRate: float, avgHandlingTime: string}> Per-agent metrics.
	 *
	 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getAgentPerformance(string $from, string $to): array;

	/**
	 * Get all SLA configuration.
	 *
	 * @return array<string, array<string, mixed>> SLA targets per channel.
	 *
	 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-sla-configuration
	 */
	public function getAllSlaTargets(): array;

	/**
	 * Get channel distribution for the given date range.
	 *
	 * @param string $from ISO 8601 start date.
	 * @param string $to ISO 8601 end date.
	 *
	 * @return array<string, int> Contact counts keyed by channel.
	 *
	 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getChannelDistribution(string $from, string $to): array;

	/**
	 * Get channel trend data for the given date range.
	 *
	 * Returns daily or weekly contact counts per channel as time-series data.
	 *
	 * @param string $from ISO 8601 start date.
	 * @param string $to ISO 8601 end date.
	 * @param string $granularity 'daily' or 'weekly'.
	 *
	 * @return array<string, array<string, int>> Time-series data: channel => [date => count].
	 *
	 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getChannelTrend(string $from, string $to, string $granularity = 'daily'): array;

	/**
	 * Get KPI data for the given date range.
	 *
	 * Reads contactmoment objects via ObjectService where available; returns
	 * zero-value KPIs when no data is present rather than throwing.
	 *
	 * @param string $from ISO 8601 start date (inclusive).
	 * @param string $to ISO 8601 end date (inclusive).
	 *
	 * @return array{total: int, fcrRate: float, avgHandlingTime: string, slaCompliance: float} KPI data.
	 *
	 * @spec openspec/changes/archive/2026-06-14-contactmomenten-rapportage/tasks.md#task-1
	 */
	public function getKpis(string $from, string $to): array;

	/**
	 * Update SLA target for a channel.
	 *
	 * Validates that channel and metric are in the DEFAULT_SLA_TARGETS allowlist
	 * before constructing the appconfig key, preventing arbitrary key injection
	 * into oc_appconfig (issue #606).
	 *
	 * @param string $channel The channel type.
	 * @param string $metric The metric name.
	 * @param string $value The target value.
	 *
	 * @return bool False when channel or metric is not in the allowlist.
	 *
	 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-sla-configuration
	 */
	public function setSlaTarget(string $channel, string $metric, string $value): bool;
}//end interface
