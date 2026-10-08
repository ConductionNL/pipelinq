<?php

/**
 * Pipelinq TaskCreatedByCreatingListener.
 *
 * Fills in who created a task. The task schema has a `createdBy` user field
 * ("Created by" on the task page), and no create path set it: the Tasks list
 * dialog, the Tasks tab of a deal and the API all left it empty
 * (round3-review-points, review point 2). Doing it on OpenRegister's pre-save
 * event covers every path in one place.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/round3-review-points/specs/user-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use OCP\IUserSession;

/**
 * Sets `createdBy` on a task that is being created without one.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/round3-review-points/specs/user-fields/spec.md
 */
class TaskCreatedByCreatingListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig   $appConfig   App config, source of the task schema id.
	 * @param IUserSession $userSession The session, source of the creating user.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Handle an object-creating event.
	 *
	 * Only tasks are touched, and only when nobody named a creator. Without a
	 * signed-in user (a background job, an occ command) nothing is set.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/round3-review-points/specs/user-fields/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false) {
			return;
		}

		$taskSchema = $this->appConfig->getValueString(Application::APP_ID, 'task_schema', '');
		$entity = $event->getObject();
		if ($taskSchema === '' || (string)$entity->getSchema() !== $taskSchema) {
			return;
		}

		$task = array_merge($entity->getObject(), $event->getModifiedData());
		if (is_string($task['createdBy'] ?? null) === true && $task['createdBy'] !== '') {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		// Merge, never replace: another listener may already have modified the data.
		$event->setModifiedData(array_merge($event->getModifiedData(), ['createdBy' => $user->getUID()]));
	}//end handle()
}//end class
