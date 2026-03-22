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
use OCP\IConfig;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Service for sending Pipelinq notifications.
 */
class NotificationService
{
    /**
     * Map notification subjects to user setting keys.
     *
     * @var array<string, string>
     */
    private const SUBJECT_SETTING_MAP = [
        'lead_assigned'          => 'notify_assignments',
        'request_assigned'       => 'notify_assignments',
        'task_assigned'          => 'notify_assignments',
        'task_reassigned'        => 'notify_assignments',
        'lead_stage_changed'     => 'notify_stage_status',
        'request_status_changed' => 'notify_stage_status',
        'task_completed'         => 'notify_stage_status',
        'task_expired'           => 'notify_stage_status',
        'note_added'             => 'notify_notes',
    ];

    /**
     * Constructor.
     *
     * @param IManager        $notificationManager The notification manager.
     * @param IConfig         $config              The config service.
     * @param LoggerInterface $logger              The logger.
     */
    public function __construct(
        private IManager $notificationManager,
        private IConfig $config,
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

        $subject = 'lead_assigned';
        if ($entityType === 'request') {
            $subject = 'request_assigned';
        } elseif ($entityType === 'task') {
            $subject = 'task_assigned';
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
     * Notify a user about a task completion.
     *
     * @param string $title      The task subject.
     * @param string $resultText The completion result text.
     * @param string $userId     The user to notify (typically the creator).
     * @param string $objectId   The task object ID.
     * @param string $author     The user who completed the task.
     *
     * @return void
     */
    public function notifyTaskCompleted(
        string $title,
        string $resultText,
        string $userId,
        string $objectId,
        string $author
    ): void {
        if ($author === $userId) {
            return;
        }

        $this->send(
            subject: 'task_completed',
            parameters: [
                'title'      => $title,
                'resultText' => $resultText,
                'author'     => $author,
            ],
            userId: $userId,
            objectType: 'task',
            objectId: $objectId
        );
    }//end notifyTaskCompleted()

    /**
     * Notify a user about a task reassignment.
     *
     * @param string $title          The task subject.
     * @param string $assigneeUserId The new assignee user ID.
     * @param string $objectId       The task object ID.
     * @param string $author         The user who reassigned.
     * @param string $deadline       The task deadline.
     *
     * @return void
     */
    public function notifyTaskReassigned(
        string $title,
        string $assigneeUserId,
        string $objectId,
        string $author,
        string $deadline=''
    ): void {
        if ($author === $assigneeUserId) {
            return;
        }

        $this->send(
            subject: 'task_reassigned',
            parameters: [
                'title'    => $title,
                'author'   => $author,
                'deadline' => $deadline,
            ],
            userId: $assigneeUserId,
            objectType: 'task',
            objectId: $objectId
        );
    }//end notifyTaskReassigned()

    /**
     * Notify users about a task expiry or approaching deadline.
     *
     * @param string $title    The task subject.
     * @param string $userId   The user to notify.
     * @param string $objectId The task object ID.
     * @param string $deadline The task deadline.
     *
     * @return void
     */
    public function notifyTaskExpired(
        string $title,
        string $userId,
        string $objectId,
        string $deadline=''
    ): void {
        $this->send(
            subject: 'task_expired',
            parameters: [
                'title'    => $title,
                'deadline' => $deadline,
            ],
            userId: $userId,
            objectType: 'task',
            objectId: $objectId
        );
    }//end notifyTaskExpired()

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
        // Check user setting for this notification type.
        $settingKey = self::SUBJECT_SETTING_MAP[$subject] ?? null;
        if ($settingKey !== null) {
            $enabled = $this->config->getUserValue(
                userId: $userId,
                appName: Application::APP_ID,
                key: $settingKey,
                default: 'true'
            );
            if ($enabled !== 'true') {
                return;
            }
        }

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
