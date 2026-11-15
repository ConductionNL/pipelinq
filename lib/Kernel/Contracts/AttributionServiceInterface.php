<?php

/**
 * Contract of AttributionService
 *
 * The public surface of AttributionService that other components call, so they depend
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
 * Contract of AttributionService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AttributionServiceInterface {

	/**
	 * Return the attribution summary for a Blast — the number of distinct
	 * attributed deals and the summed EUR `attributedValue`. Used by the
	 * Performance Dashboard Attribution tab via
	 * `GET /api/blasts/{id}/attribution` (member 08).
	 *
	 * @param string $blastId Blast UUID or slug.
	 *
	 * @return array{blastId: string, dealCount: int, attributedValue: float, currency: string}
	 *
	 * @spec openspec/changes/archive/2026-06-14-marketing-segmentation-and-blast-08-performance-dashboard/tasks.md#performancedashboard-vue-task-3-4-of-giant
	 * @spec openspec/specs/commercial-dashboard/spec.md
	 */
	public function getBlastAttributionSummary(string $blastId): array;
}//end interface
