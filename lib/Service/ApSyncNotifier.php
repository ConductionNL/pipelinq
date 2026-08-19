<?php

/**
 * Pipelinq ApSyncNotifier.
 *
 * Notifies Nextcloud administrators when a Shillinq AP voucher dispatch
 * permanently fails, so they can trigger a manual re-dispatch from the
 * expense detail view (REQ-AP-003 Scenarios 10 + 11).
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
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCP\IGroupManager;

/**
 * Sends admin notifications on Shillinq AP sync failure.
 *
 * Extracted from the expense listeners so they do not couple directly to both
 * the group manager and the notification service (keeps listener coupling
 * within the project's PHPMD threshold).
 *
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 */
class ApSyncNotifier
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
     * Notify every admin user that an AP dispatch permanently failed.
     *
     * @param string $expenseTitle The expense title (for the notification body).
     * @param string $uuid         The expense UUID (for the detail-view reference).
     *
     * @return void
     *
     * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
     */
    public function notifyFailure(string $expenseTitle, string $uuid): void
    {
        $adminGroup = $this->groupManager->get('admin');
        if ($adminGroup === null) {
            return;
        }

        foreach ($adminGroup->getUsers() as $user) {
            $this->notificationService->sendNotification(
                userId: $user->getUID(),
                subject: 'ap_sync_failed',
                parameters: [
                    'expenseTitle' => $expenseTitle,
                    'id'           => $uuid,
                ],
                objectType: 'expense',
                objectId: $uuid
            );
        }
    }//end notifyFailure()
}//end class
