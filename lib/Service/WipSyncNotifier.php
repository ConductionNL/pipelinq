<?php

/**
 * Pipelinq WipSyncNotifier.
 *
 * Notifies Nextcloud administrators when a Shillinq WIP dispatch permanently
 * fails, so they can trigger a manual re-dispatch from the time entry detail
 * view.
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
 * @spec openspec/changes/pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IGroupManager;

/**
 * Sends admin notifications on WIP sync failure.
 *
 * Extracted from the time-entry listener so it does not couple directly to
 * both the group manager and the notification service (keeps listener coupling
 * within the project's PHPMD threshold).
 *
 * @spec openspec/changes/pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-003
 */
class WipSyncNotifier
{
    /**
     * Constructor.
     *
     * @param NotificationService $notificationService The notification service.
     * @param IGroupManager       $groupManager        The group manager (admin resolution).
     */
    public function __construct(
        private NotificationService $notificationService,
        private IGroupManager $groupManager,
    ) {
    }//end __construct()

    /**
     * Notify every admin user that a WIP dispatch permanently failed.
     *
     * @param string $title The time entry title (used in the notification text).
     * @param string $uuid  The time entry UUID (for the detail-view reference).
     *
     * @return void
     *
     * @spec openspec/changes/pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-003
     */
    public function notifyFailure(string $title, string $uuid): void
    {
        $adminGroup = $this->groupManager->get('admin');
        if ($adminGroup === null) {
            return;
        }

        foreach ($adminGroup->getUsers() as $user) {
            $this->notificationService->sendNotification(
                userId: $user->getUID(),
                subject: 'wip_sync_failed',
                parameters: [
                    'title' => $title,
                ],
                objectType: 'timeEntry',
                objectId: $uuid
            );
        }
    }//end notifyFailure()
}//end class
