<?php

/**
 * Pipelinq LedgerSyncNotifier.
 *
 * Notifies Nextcloud administrators when a Shillinq project-ledger dispatch
 * permanently fails, so they can trigger a manual re-dispatch from the project
 * detail view.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-003-02
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IGroupManager;

/**
 * Sends admin notifications on ledger sync failure.
 *
 * Extracted from the project listeners so they do not couple directly to both
 * the group manager and the notification service (keeps listener coupling within
 * the project's PHPMD threshold).
 *
 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-003-02
 */
class LedgerSyncNotifier {
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
	 * Notify every admin user that a ledger dispatch permanently failed.
	 *
	 * @param string $projectName The project name.
	 * @param string $eventType The event type (creation or status change).
	 * @param string $uuid The project UUID (for the detail-view reference).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-003-02
	 */
	public function notifyFailure(string $projectName, string $eventType, string $uuid): void {
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return;
		}

		foreach ($adminGroup->getUsers() as $user) {
			$this->notificationService->sendNotification(
				userId: $user->getUID(),
				subject: 'ledger_sync_failed',
				parameters: [
					'projectName' => $projectName,
					'eventType' => $eventType,
				],
				objectType: 'project',
				objectId: $uuid
			);
		}
	}//end notifyFailure()
}//end class
