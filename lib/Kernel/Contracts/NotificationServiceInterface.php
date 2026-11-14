<?php

/**
 * Contract of NotificationService
 *
 * The public surface of NotificationService that other components call, so they depend
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
 * Contract of NotificationService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NotificationServiceInterface {

	/**
	 * Notify a user about a task completion.
	 *
	 * @param string $title The task subject.
	 * @param string $resultText The completion result text.
	 * @param string $userId The user to notify (typically the creator).
	 * @param string $objectId The task object ID.
	 * @param string $author The user who completed the task.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function notifyTaskCompleted(
		string $title,
		string $resultText,
		string $userId,
		string $objectId,
		string $author,
	): void;

	/**
	 * Notify a user about a task reassignment.
	 *
	 * @param string $title The task subject.
	 * @param string $assigneeUserId The new assignee user ID.
	 * @param string $objectId The task object ID.
	 * @param string $author The user who reassigned.
	 * @param string $deadline The task deadline.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function notifyTaskReassigned(
		string $title,
		string $assigneeUserId,
		string $objectId,
		string $author,
		string $deadline = '',
	): void;

	/**
	 * Send a generic notification to a user.
	 *
	 * Wrapper around the internal send() method for custom notification
	 * subjects that do not have a dedicated notifyXxx() helper.
	 *
	 * @param string $userId The target user ID.
	 * @param string $subject The notification subject.
	 * @param array $parameters The notification parameters.
	 * @param string $objectType The object type (optional).
	 * @param string $objectId The object ID (optional).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-activity-notify/tasks.md#task-3
	 */
	public function sendNotification(
		string $userId,
		string $subject,
		array $parameters,
		string $objectType = '',
		string $objectId = '',
	): void;
}//end interface
