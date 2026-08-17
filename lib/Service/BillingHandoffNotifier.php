<?php

/**
 * Pipelinq BillingHandoffNotifier.
 *
 * Notifies Nextcloud administrators when a shillinq time-intake billing
 * handoff batch permanently fails (transport/5xx), so they can trigger a
 * manual re-send from the client's billing section — the deterministic
 * batchId makes that re-send idempotent (time-billing-handoff-emit).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/specs/time-approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IGroupManager;

/**
 * Sends admin notifications on billing-handoff batch failure.
 *
 * Mirrors {@see WipSyncNotifier}: extracted from the handoff service so it
 * does not couple directly to both the group manager and the notification
 * service.
 *
 * @spec openspec/specs/time-approval-workflow/spec.md
 */
class BillingHandoffNotifier {
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
	 * Notify every admin user that a billing-handoff batch permanently failed.
	 *
	 * @param string $clientName The client's display name (used in the notification text).
	 * @param string $clientId The client UUID (notification object reference).
	 * @param string $batchId The deterministic batch id that failed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/time-approval-workflow/spec.md
	 */
	public function notifyFailure(string $clientName, string $clientId, string $batchId): void {
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return;
		}

		foreach ($adminGroup->getUsers() as $user) {
			$this->notificationService->sendNotification(
				userId: $user->getUID(),
				subject: 'billing_handoff_failed',
				parameters: [
					'title' => $clientName,
					'batchId' => $batchId,
				],
				objectType: 'client',
				objectId: $clientId
			);
		}
	}//end notifyFailure()
}//end class
