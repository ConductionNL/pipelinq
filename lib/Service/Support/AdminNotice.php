<?php

/**
 * Pipelinq AdminNotice.
 *
 * A notification sent to every member of the admin group.
 *
 * @category Util
 * @package  OCA\Pipelinq\Service\Support
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Support;

use OCA\Pipelinq\Service\NoticedObject;

/**
 * Sends one notification per admin; without an admin group nothing is sent.
 * The host class provides IGroupManager `$groupManager` and
 * NotificationService `$notificationService` properties.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait AdminNotice {

	/**
	 * Notify every admin.
	 *
	 * @param string               $subject    The notification subject.
	 * @param array<string, mixed> $parameters The subject parameters.
	 * @param NoticedObject        $about      The object the notice is about.
	 *
	 * @return void
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function notifyAdmins(string $subject, array $parameters, NoticedObject $about): void {
		$this->noticeAdmins(notice: ['subject' => $subject, 'parameters' => $parameters, 'about' => $about]);
	}//end notifyAdmins()

	/**
	 * Send a notice to every member of the admin group, when it exists.
	 *
	 * @param array{subject: string, parameters: array<string, mixed>, about: NoticedObject} $notice The subject, its parameters and
	 *   the object the notice is about.
	 *
	 * @return void
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function noticeAdmins(array $notice): void {
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return;
		}

		$this->noticeEach(users: $adminGroup->getUsers(), notice: $notice);
	}//end noticeAdmins()

	/**
	 * Send one notification to each user.
	 *
	 * @param array<int, \OCP\IUser>                                                       $users  The users.
	 * @param array{subject: string, parameters: array<string, mixed>, about: NoticedObject} $notice The subject, its parameters and
	 *   the object the notice is about.
	 *
	 * @return void
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function noticeEach(array $users, array $notice): void {
		foreach ($users as $user) {
			$this->notificationService->sendNotification(
				userId: $user->getUID(),
				subject: $notice['subject'],
				parameters: $notice['parameters'],
				objectType: $notice['about']->objectType,
				objectId: $notice['about']->objectId
			);
		}
	}//end noticeEach()
}//end trait
