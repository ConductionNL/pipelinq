<?php

/**
 * Contract of ForecastExportService
 *
 * The public surface of ForecastExportService that other components call, so they depend
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
 * Contract of ForecastExportService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ForecastExportServiceInterface {

	/**
	 * Build the paginated snapshot export payload.
	 *
	 * @param string $periodId The fiscal period id.
	 * @param string $level The hierarchy level.
	 * @param string|null $ownerId Optional owner filter.
	 * @param int $limit The page size.
	 * @param int $offset The page offset.
	 *
	 * @return array{snapshots: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010-01, REQ-FRC-010-05
	 */
	public function exportSnapshots(string $periodId, string $level, ?string $ownerId, int $limit, int $offset): array;

	/**
	 * Render snapshots as injection-safe CSV.
	 *
	 * @param array<int, array<string, mixed>> $snapshots The presented snapshots.
	 *
	 * @return string The CSV body.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-010-02
	 */
	public function toCsv(array $snapshots): string;
}//end interface
