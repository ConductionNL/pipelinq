<?php

/**
 * Contract of KccWerkplekService
 *
 * The public surface of KccWerkplekService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of KccWerkplekService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface KccWerkplekServiceInterface {

	/**
	 * Build the aggregated workspace state payload for one agent.
	 *
	 * Returns the agent profile (with sensible defaults if none exists),
	 * the assigned-and-open requests and the open tasks assigned to the user.
	 *
	 * @param string $userId Nextcloud user UID of the agent.
	 *
	 * @return array{agentProfile: array<string, mixed>, assignedRequests: array<int, array<string, mixed>>,
	 *               openTasks: array<int, array<string, mixed>>} Workspace state.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential guard clauses assembling one payload; extraction adds no clarity.
	 *
	 * @spec openspec/changes/archive/2026-06-14-kcc-werkplek/tasks.md#task-2
	 */
	public function getWorkspaceState(string $userId): array;

	/**
	 * Set the availability flag on the calling agent's profile.
	 *
	 * Idempotent: if no profile exists for `$userId` one is created with
	 * `userId` and `isAvailable` set. The caller's user ID is the only
	 * trusted identity — never accept a user ID from the request body.
	 *
	 * @param string $userId Nextcloud user UID of the agent.
	 * @param bool $available Desired availability flag.
	 *
	 * @return array{userId: string, isAvailable: bool} The updated profile shape.
	 *
	 * @throws RuntimeException When OR is unavailable or the save fails.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Idempotent find-merge-save with defensive guards; extraction adds no clarity.
	 *
	 * @spec openspec/changes/archive/2026-06-14-kcc-werkplek/tasks.md#task-2
	 */
	public function setAvailability(string $userId, bool $available): array;
}//end interface
