<?php

/**
 * Pipelinq full data export job.
 *
 * Builds the "Export all data" archive in the background and tells the
 * administrator who asked for it, through a Nextcloud notification, where it
 * is (or that it could not be built).
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\FullExportServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs one full data export for one administrator.
 *
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
 */
class FullExportJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory      $time          The clock.
	 * @param FullExportServiceInterface $export        Builds and stores the archive.
	 * @param IManager          $notifications Nextcloud notifications.
	 * @param LoggerInterface   $logger        The logger.
	 *
	 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly FullExportServiceInterface $export,
		private readonly IManager $notifications,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Build the archive and notify the administrator.
	 *
	 * @param mixed $argument `{userId}`: the administrator who asked for it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
	 */
	protected function run(mixed $argument): void {
		$userId = trim((string) (((array) $argument)['userId'] ?? ''));
		if ($userId === '') {
			$this->logger->warning('FullExportJob: no administrator named; nothing exported');
			return;
		}

		try {
			$stored = $this->export->exportFor(userId: $userId);
		} catch (Throwable $e) {
			$this->logger->error('FullExportJob: the archive could not be built', ['exception' => $e->getMessage()]);
			$this->notify(userId: $userId, subject: 'full_export_failed', parameters: []);
			return;
		}

		$this->notify(
			userId: $userId,
			subject: 'full_export_ready',
			parameters: ['name' => $stored['name'], 'fileId' => (string) $stored['fileId']]
		);
	}//end run()

	/**
	 * Send the administrator a notification.
	 *
	 * @param string               $userId     The administrator.
	 * @param string               $subject    The subject.
	 * @param array<string, mixed> $parameters The subject parameters.
	 *
	 * @return void
	 */
	private function notify(string $userId, string $subject, array $parameters): void {
		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(AppIdentity::APP_ID)
				->setUser($userId)
				->setDateTime($this->time->getDateTime())
				->setObject('export', (string) ($parameters['fileId'] ?? 'full'))
				->setSubject($subject, $parameters);
			$this->notifications->notify($notification);
		} catch (Throwable $e) {
			$this->logger->warning('FullExportJob: the administrator could not be notified', ['exception' => $e->getMessage()]);
		}
	}//end notify()
}//end class
