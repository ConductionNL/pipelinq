<?php

/**
 * Contract of CallbackService
 *
 * The public surface of CallbackService that other components call, so they depend
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
 * Contract of CallbackService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CallbackServiceInterface {

	/**
	 * Add a callback attempt to a task's attempts array.
	 *
	 * @param array<string, mixed> $taskData The task data array.
	 * @param string $result The attempt result.
	 * @param string $notes Optional attempt notes.
	 *
	 * @return array<string, mixed> The modified task data with the new attempt.
	 *
	 * @spec openspec/changes/archive/2026-05-31-callback-management/tasks.md#task-1.1
	 */
	public function addAttempt(array $taskData, string $result, string $notes = ''): array;

	/**
	 * Apply a claim to a task: set the current user as assignee and update status.
	 *
	 * @param array<string, mixed> $taskData The task data array.
	 *
	 * @return array<string, mixed> The modified task data.
	 *
	 * @spec openspec/changes/archive/2026-05-31-callback-management/tasks.md#task-1.1
	 */
	public function applyClaim(array $taskData): array;

	/**
	 * Apply completion to a task: set status, completedAt, and resultText.
	 *
	 * @param array<string, mixed> $taskData The task data array.
	 * @param string $resultText The completion result text.
	 *
	 * @return array<string, mixed> The modified task data.
	 *
	 * @spec openspec/changes/archive/2026-05-31-callback-management/tasks.md#task-1.1
	 */
	public function applyCompletion(array $taskData, string $resultText): array;

	/**
	 * Apply reassignment to a task.
	 *
	 * @param array<string, mixed> $taskData The task data array.
	 * @param string $assignee The new assignee ID.
	 * @param string $assigneeType The assignee type (user or group).
	 *
	 * @return array<string, mixed> The modified task data.
	 *
	 * @spec openspec/changes/archive/2026-05-31-callback-management/tasks.md#task-1.1
	 */
	public function applyReassignment(array $taskData, string $assignee, string $assigneeType): array;

	/**
	 * Check if the attempt threshold has been reached for a task.
	 *
	 * Counts only unsuccessful attempts (niet_bereikbaar, etc.).
	 *
	 * @param array<string, mixed> $taskData The task data array.
	 *
	 * @return bool True if the threshold has been reached.
	 *
	 * @spec openspec/changes/archive/2026-05-31-callback-management/tasks.md#task-1.1
	 */
	public function isAttemptThresholdReached(array $taskData): bool;

	/**
	 * Validate whether the current user can claim a group-assigned task.
	 *
	 * @param array<string, mixed> $taskData The task data array.
	 *
	 * @return array{eligible: bool, reason: string} Validation result.
	 *
	 * @spec openspec/changes/archive/2026-05-31-callback-management/tasks.md#task-1.1
	 */
	public function validateClaim(array $taskData): array;

	/**
	 * Validate whether a status transition is allowed.
	 *
	 * @param string $currentStatus The current task status.
	 * @param string $targetStatus The target task status.
	 *
	 * @return array{valid: bool, reason: string} Validation result.
	 *
	 * @spec openspec/changes/archive/2026-05-31-callback-management/tasks.md#task-1.1
	 */
	public function validateStatusTransition(string $currentStatus, string $targetStatus): array;
}//end interface
