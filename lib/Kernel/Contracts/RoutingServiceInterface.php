<?php

/**
 * Contract of RoutingService
 *
 * The public surface of RoutingService that other components call, so they depend
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
 * Contract of RoutingService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RoutingServiceInterface {

	/**
	 * Get suggested agents for a queued request or lead.
	 *
	 * A 'request' entity is a `ticket` with `ticketType: request`.
	 *
	 * @param string $entityType Either 'request' or 'lead'.
	 * @param string $entityId The entity UUID.
	 *
	 * @return array<string, mixed> Shape: { suggestions, atCapacity, noMatch }.
	 *
	 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-1.2
	 */
	public function getSuggestedAgents(string $entityType, string $entityId): array;
}//end interface
