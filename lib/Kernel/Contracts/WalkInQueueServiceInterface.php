<?php

/**
 * Contract of WalkInQueueService
 *
 * The public surface of WalkInQueueService that other components call, so they depend
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
 * Contract of WalkInQueueService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WalkInQueueServiceInterface {

	/**
	 * Rebalance estimatedReadyAt for all waiting tickets.
	 *
	 * Recomputed from the current schedule (AvailabilityService) so customers
	 * see live ETAs as scheduled appointments complete. Errors per ticket are
	 * logged and swallowed — the rebalance is best-effort.
	 *
	 * @return int The number of tickets touched.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function rebalance(): int;
}//end interface
