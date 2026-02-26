<?php

/**
 * Pipelinq NotificationService.
 *
 * Service for sending Pipelinq notifications to users.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use DateTime;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Service for sending Pipelinq notifications.
 */
class NotificationService
{
    /**
     * Constructor.
     *
     * @param IManager        $notificationManager The notification manager.
     * @param LoggerInterface $logger              The logger.
     */
    public function __construct(
        private IManager $notificationManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Notify a user about a lead or request assignment.
     *
     * @param string $entityType     The entity type.
     * @param string $title          The entity title.
     * @param string $assigneeUserId The assignee user ID.
     * @param string $objectId       The object ID.
     * @param string $author         The author user ID.
     *
     * @return void
     */
    public function notifyAssignment(
        string $entityType,
        string $title,
        string $assigneeUserId,
        string $objectId,
        string $author
    ): void {
        if ($author === $assigneeUserId) {
            return;
        }

        if ($entityType === 'request') {
            $subject = 'request_assigned';
        } else {
            $subject = 'lead_assigned';
        }

        $this->send(
            subject: $subject,
            parameters: [
                'title'      => $title,
                'entityType' => $entityType,
                'author'     => $author,
            ],
            userId: $assigneeUserId,
            objectType: $entityType,
            objectId: $objectId
        );
    }//end notifyAssignment()

    /**
     * Notify a user about a lead stage change.
     *
     * @param string $title          The entity title.
     * @param string $newStage       The new stage name.
     * @param string $assigneeUserId The assignee user ID.
     * @param string $objectId       The object ID.
     * @param string $author         The author user ID.
     *
     * @return void
     */
    public function notifyStageChange(
        string $title,
        string $newStage,
        string $assigneeUserId,
        string $objectId,
        string $author
    ): void {
        if ($author === $assigneeUserId) {
            return;
        }

        $this->send(
            subject: 'lead_stage_changed',
            parameters: [
                'title'  => $title,
                'stage'  => $newStage,
                'author' => $author,
            ],
            userId: $assigneeUserId,
            objectType: 'lead',
            objectId: $objectId
        );
    }//end notifyStageChange()

    /**
     * Notify a user about a request status change.
     *
     * @param string $title          The entity title.
     * @param string $newStatus      The new status name.
     * @param string $assigneeUserId The assignee user ID.
     * @param string $objectId       The object ID.
     * @param string $author         The author user ID.
     *
     * @return void
     */
    public function notifyStatusChange(
        string $title,
        string $newStatus,
        string $assigneeUserId,
        string $objectId,
        string $author
    ): void {
        if ($author === $assigneeUserId) {
            return;
        }

        $this->send(
            subject: 'request_status_changed',
            parameters: [
                'title'  => $title,
                'status' => $newStatus,
                'author' => $author,
            ],
            userId: $assigneeUserId,
            objectType: 'request',
            objectId: $objectId
        );
    }//end notifyStatusChange()

    /**
     * Notify a user about a new note on an entity.
     *
     * @param string $entityType     The entity type.
     * @param string $entityTitle    The entity title.
     * @param string $assigneeUserId The assignee user ID.
     * @param string $objectId       The object ID.
     * @param string $author         The author user ID.
     *
     * @return void
     */
    public function notifyNoteAdded(
        string $entityType,
        string $entityTitle,
        string $assigneeUserId,
        string $objectId,
        string $author
    ): void {
        if ($author === $assigneeUserId) {
            return;
        }

        $this->send(
            subject: 'note_added',
            parameters: [
                'title'      => $entityTitle,
                'entityType' => $entityType,
                'author'     => $author,
            ],
            userId: $assigneeUserId,
            objectType: $entityType,
            objectId: $objectId
        );
    }//end notifyNoteAdded()

    /**
     * Send a notification to a user.
     *
     * @param string $subject    The notification subject.
     * @param array  $parameters The notification parameters.
     * @param string $userId     The target user ID.
     * @param string $objectType The object type.
     * @param string $objectId   The object ID.
     *
     * @return void
     */
    private function send(
        string $subject,
        array $parameters,
        string $userId,
        string $objectType,
        string $objectId
    ): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($userId)
                ->setDateTime(new DateTime())
                ->setObject(type: $objectType, id: $objectId)
                ->setSubject(subject: $subject, parameters: $parameters);

            $this->notificationManager->notify($notification);
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to send Pipelinq notification',
                    [
                        'subject'   => $subject,
                        'userId'    => $userId,
                        'exception' => $e->getMessage(),
                    ]
                    );
        }
    }//end send()
}//end class
