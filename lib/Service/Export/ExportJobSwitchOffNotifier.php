<?php

/**
 * Pipelinq ExportJobSwitchOffNotifier.
 *
 * Tells every Nextcloud administrator, once, that an export job was switched
 * off after repeated failed runs: the job, the number of failures and the last
 * error (exports-switch-off-after-repeated-failure).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Export
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-administrators-are-told-once-when-a-job-is-switched-off-req-efs-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Export;

use OCA\Pipelinq\Service\NotificationService;
use OCP\IGroupManager;

/**
 * Sends the admin notification for an export job that was switched off.
 *
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-administrators-are-told-once-when-a-job-is-switched-off-req-efs-002
 */
class ExportJobSwitchOffNotifier {
	/**
	 * The notification subject.
	 *
	 * @var string
	 */
	public const SUBJECT = 'export_job_switched_off';

	/**
	 * Constructor.
	 *
	 * @param NotificationService $notificationService The notification service.
	 * @param IGroupManager $groupManager The group manager (admin resolution).
	 */
	public function __construct(
		private NotificationService $notificationService,
		private IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Notify every administrator that a job was switched off.
	 *
	 * @param array<string, mixed> $job The switched-off job.
	 * @param int $failures The failed runs in a row that switched it off.
	 * @param string $lastError The last run's error message.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-administrators-are-told-once-when-a-job-is-switched-off-req-efs-002
	 */
	public function notifySwitchedOff(array $job, int $failures, string $lastError): void {
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return;
		}

		$jobId = (string)($job['id'] ?? $job['uuid'] ?? '');
		foreach ($adminGroup->getUsers() as $user) {
			$this->notificationService->sendNotification(
				userId: $user->getUID(),
				subject: self::SUBJECT,
				parameters: [
					'title' => (string)($job['name'] ?? ''),
					'failures' => $failures,
					'error' => $lastError,
				],
				objectType: 'exportJob',
				objectId: $jobId
			);
		}
	}//end notifySwitchedOff()
}//end class
