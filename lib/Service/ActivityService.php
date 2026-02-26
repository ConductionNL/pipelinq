<?php

/**
 * Pipelinq ActivityService.
 *
 * Service for publishing Pipelinq activity events to the Nextcloud activity stream.
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
use OCP\Activity\IManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for publishing Pipelinq activity events.
 */
class ActivityService
{
    /**
     * Constructor.
     *
     * @param IManager        $activityManager The activity manager.
     * @param IUserSession    $userSession     The user session.
     * @param LoggerInterface $logger          The logger.
     */
    public function __construct(
        private IManager $activityManager,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Publish a created event for a lead or request.
     *
     * @param string  $entityType   The entity type.
     * @param string  $title        The entity title.
     * @param string  $objectId     The object ID.
     * @param ?string $affectedUser The affected user or null.
     *
     * @return void
     */
    public function publishCreated(
        string $entityType,
        string $title,
        string $objectId,
        ?string $affectedUser=null
    ): void {
        if ($entityType === 'request') {
            $type = 'request_created';
        } else {
            $type = 'lead_created';
        }

        $this->publish(
            subject: $type,
            type: 'pipelinq_assignment',
            parameters: [
                'title'      => $title,
                'entityType' => $entityType,
            ],
            objectType: $entityType,
            objectId: $objectId,
            affectedUser: $affectedUser
        );
    }//end publishCreated()

    /**
     * Publish an assignment event for a lead or request.
     *
     * @param string $entityType  The entity type.
     * @param string $title       The entity title.
     * @param string $newAssignee The newly assigned user.
     * @param string $objectId    The object ID.
     *
     * @return void
     */
    public function publishAssigned(
        string $entityType,
        string $title,
        string $newAssignee,
        string $objectId
    ): void {
        if ($entityType === 'request') {
            $type = 'request_assigned';
        } else {
            $type = 'lead_assigned';
        }

        $this->publish(
            subject: $type,
            type: 'pipelinq_assignment',
            parameters: [
                'title'      => $title,
                'entityType' => $entityType,
                'assignee'   => $newAssignee,
            ],
            objectType: $entityType,
            objectId: $objectId,
            affectedUser: $newAssignee
        );
    }//end publishAssigned()

    /**
     * Publish a stage change event for a lead.
     *
     * @param string  $title        The entity title.
     * @param string  $newStage     The new stage name.
     * @param string  $objectId     The object ID.
     * @param ?string $affectedUser The affected user or null.
     *
     * @return void
     */
    public function publishStageChanged(
        string $title,
        string $newStage,
        string $objectId,
        ?string $affectedUser=null
    ): void {
        $this->publish(
            subject: 'lead_stage_changed',
            type: 'pipelinq_stage_status',
            parameters: [
                'title' => $title,
                'stage' => $newStage,
            ],
            objectType: 'lead',
            objectId: $objectId,
            affectedUser: $affectedUser
        );
    }//end publishStageChanged()

    /**
     * Publish a status change event for a request.
     *
     * @param string  $title        The entity title.
     * @param string  $newStatus    The new status name.
     * @param string  $objectId     The object ID.
     * @param ?string $affectedUser The affected user or null.
     *
     * @return void
     */
    public function publishStatusChanged(
        string $title,
        string $newStatus,
        string $objectId,
        ?string $affectedUser=null
    ): void {
        $this->publish(
            subject: 'request_status_changed',
            type: 'pipelinq_stage_status',
            parameters: [
                'title'  => $title,
                'status' => $newStatus,
            ],
            objectType: 'request',
            objectId: $objectId,
            affectedUser: $affectedUser
        );
    }//end publishStatusChanged()

    /**
     * Publish a note added event.
     *
     * @param string  $entityType   The entity type.
     * @param string  $entityTitle  The entity title.
     * @param string  $objectId     The object ID.
     * @param ?string $affectedUser The affected user or null.
     *
     * @return void
     */
    public function publishNoteAdded(
        string $entityType,
        string $entityTitle,
        string $objectId,
        ?string $affectedUser=null
    ): void {
        $this->publish(
            subject: 'note_added',
            type: 'pipelinq_notes',
            parameters: [
                'title'      => $entityTitle,
                'entityType' => $entityType,
            ],
            objectType: $entityType,
            objectId: $objectId,
            affectedUser: $affectedUser
        );
    }//end publishNoteAdded()

    /**
     * Publish an activity event.
     *
     * @param string  $subject      The activity subject.
     * @param string  $type         The activity type.
     * @param array   $parameters   The activity parameters.
     * @param string  $objectType   The object type.
     * @param string  $objectId     The object ID.
     * @param ?string $affectedUser The affected user or null.
     *
     * @return void
     */
    private function publish(
        string $subject,
        string $type,
        array $parameters,
        string $objectType,
        string $objectId,
        ?string $affectedUser=null
    ): void {
        try {
            $currentUser = $this->userSession->getUser();
            if ($currentUser !== null) {
                $author = $currentUser->getUID();
            } else {
                $author = '';
            }

            $event = $this->activityManager->generateEvent();
            $event->setApp(Application::APP_ID)
                ->setType($type)
                ->setAuthor($author)
                ->setTimestamp(time())
                ->setSubject(subject: $subject, subjectParameters: $parameters)
                ->setObject(objectType: $objectType, objectId: (int) $objectId, objectName: $parameters['title'] ?? '');

            if ($affectedUser !== null && $affectedUser !== '') {
                $event->setAffectedUser($affectedUser);
            } else if ($author !== '') {
                $event->setAffectedUser($author);
            }

            $this->activityManager->publish($event);
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to publish Pipelinq activity',
                    [
                        'subject'   => $subject,
                        'type'      => $type,
                        'exception' => $e->getMessage(),
                    ]
                    );
        }//end try
    }//end publish()
}//end class
