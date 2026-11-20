<?php

/**
 * Contract of ScheduledTaskService
 *
 * The public surface of ScheduledTaskService that other components call, so they depend
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

use InvalidArgumentException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use RuntimeException;

/**
 * Contract of ScheduledTaskService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ScheduledTaskServiceInterface {

	/**
	 * Valid task types.
	 *
	 * @var array<string>
	 */
	public const VALID_TYPES = [
		'callbackRequest',
		'followUpTask',
		'informationRequest',
	];

	/**
	 * Forward the Accept-Language header to OpenRegister's LanguageService.
	 *
	 * Parses the highest-priority BCP-47 tag from the raw header value and
	 * sets it on OR's request-scoped LanguageService so that ObjectService
	 * calls return translated field values when the preferred language is
	 * available (ADR-025, task 9.3).
	 *
	 * No-ops silently when OR's LanguageService is unavailable (pre-ship).
	 *
	 * @param string $acceptLanguage The raw Accept-Language header value.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-14-pipelinq-manifest-i18n-tenant/tasks.md#task-9.3
	 */
	public function applyAcceptLanguage(string $acceptLanguage): void;

	/**
	 * Authorise a mutation on a task.
	 *
	 * Allowed if the user is the assignee, a member of the assigned group, or
	 * an administrator. Otherwise throws an OCSForbiddenException.
	 *
	 * @param array<string, mixed> $task The task object.
	 * @param string $userId The acting user ID.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When the user is not authorised.
	 *
	 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-1
	 */
	public function authorizeTaskMutation(array $task, string $userId): void;

	/**
	 * Create a new scheduled task.
	 *
	 * Required fields: `type`, `subject`, `deadline`. The `createdBy` field is
	 * always derived from the authenticated session and may not be overridden
	 * by the request body.
	 *
	 * @param array<string, mixed> $data The task data.
	 *
	 * @return array<string, mixed> The saved task object.
	 *
	 * @throws InvalidArgumentException On validation failure.
	 *
	 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-1
	 */
	public function createScheduledTask(array $data): array;

	/**
	 * Delete a scheduled task: only a task the configured register and schema
	 * hold, by the uuid that read found.
	 *
	 * @param string $id The task UUID.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the task is not found or cannot be deleted.
	 *
	 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-1
	 */
	public function deleteScheduledTask(string $id): void;

	/**
	 * Find open tasks with a deadline inside the next `$windowMinutes`.
	 *
	 * The window is capped at 1440 minutes (24 hours).
	 *
	 * @param int $windowMinutes The look-ahead window in minutes.
	 *
	 * @return array<int, array<string, mixed>> The matching tasks.
	 *
	 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-1
	 */
	public function getPendingTasks(int $windowMinutes = 60): array;

	/**
	 * Fetch a single scheduled task by ID.
	 *
	 * @param string $id The task UUID.
	 *
	 * @return array<string, mixed> The task object.
	 *
	 * @throws RuntimeException If the task is not found.
	 *
	 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-1
	 */
	public function getScheduledTask(string $id): array;

	/**
	 * List scheduled tasks with optional filters and pagination.
	 *
	 * Supported filters: `status`, `assigneeUserId`, `assigneeGroupId`,
	 * `from` (deadline ≥), `to` (deadline ≤). Pagination via `_page` and `_limit`.
	 *
	 * @param array<string, mixed> $params Filter and pagination parameters.
	 *
	 * @return array{items: array<int, mixed>, total: int, page: int, pages: int} List envelope.
	 *
	 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-1
	 */
	public function getScheduledTasks(array $params): array;

	/**
	 * Process all due scheduled tasks.
	 *
	 * Tasks with `deadline` ≤ now and `status = open` are transitioned:
	 * - `deadline` > 4 hours ago: notify assignee, set status `in_behandeling`, log attempt.
	 * - `deadline` ≤ 4 hours ago: set status `verlopen`, log expiry attempt.
	 *
	 * Tasks already `in_behandeling`, `afgerond`, or `verlopen` are skipped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-1
	 */
	public function processScheduledTasks(): void;

	/**
	 * Update an existing scheduled task.
	 *
	 * Merges `$data` into the existing task. The `createdBy` field is always
	 * stripped from incoming data and preserved from the existing record.
	 *
	 * @param string $id The task UUID.
	 * @param array<string, mixed> $data Partial update data.
	 *
	 * @return array<string, mixed> The updated task object.
	 *
	 * @spec openspec/changes/archive/2026-05-11-task-background-jobs/tasks.md#task-1
	 */
	public function updateScheduledTask(string $id, array $data): array;
}//end interface
