<?php

/**
 * Pipelinq NoteEventService.
 *
 * Service for triggering notifications and activity events when notes are added.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/entity-notes/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OC\Security\CSRF\CsrfTokenManager;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Service for triggering note-related events and notifications.
 *
 * @psalm-suppress UndefinedClass CsrfTokenManager is an internal Nextcloud class (no OCP interface yet); tracked as fleet debt.
 */
class NoteEventService
{
    private const TYPE_MAP = [
        'pipelinq_client'  => 'client',
        'pipelinq_contact' => 'contact',
        'pipelinq_lead'    => 'lead',
        'pipelinq_request' => 'request',
    ];

    /**
     * Constructor.
     *
     * @param NotificationService $notificationService The notification service.
     * @param ActivityService     $activityService     The activity service.
     * @param SettingsService     $settingsService     The settings service.
     * @param IUserSession        $userSession         The user session.
     * @param IURLGenerator       $urlGenerator        The URL generator.
     * @param IClientService      $clientService       The HTTP client service.
     * @param CsrfTokenManager    $csrfTokenManager    The CSRF token manager.
     * @param LoggerInterface     $logger              The logger.
     *
     * @psalm-suppress UndefinedClass CsrfTokenManager is an internal Nextcloud class (no OCP interface yet).
     */
    public function __construct(
        private NotificationService $notificationService,
        private ActivityService $activityService,
        private SettingsService $settingsService,
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
        private IClientService $clientService,
        private CsrfTokenManager $csrfTokenManager,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Trigger notification and activity events for a newly added note.
     *
     * @param string $objectType The object type.
     * @param string $objectId   The object ID.
     *
     * @return void
     *
     * @spec openspec/specs/entity-notes/spec.md
     */
    public function triggerNoteEvents(string $objectType, string $objectId): void
    {
        try {
            $entityType = self::TYPE_MAP[$objectType] ?? null;
            if ($entityType === null) {
                return;
            }

            if (in_array($entityType, ['lead', 'request', 'client', 'contact'], true) === false) {
                return;
            }

            $entityData = $this->fetchEntityData(
                entityType: $entityType,
                objectId: $objectId
            );

            if ($entityData === null) {
                return;
            }

            $this->publishNoteActivity(
                entityType: $entityType,
                entityData: $entityData,
                objectId: $objectId
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                    'Failed to trigger note events',
                    [
                        'objectType' => $objectType,
                        'objectId'   => $objectId,
                        'exception'  => $e->getMessage(),
                    ]
                    );
        }//end try
    }//end triggerNoteEvents()

    /**
     * Fetch entity data from OpenRegister for note event context.
     *
     * @param string $entityType The entity type.
     * @param string $objectId   The object ID.
     *
     * @return ?array The entity data with title and assignee, or null on failure.
     *
     * @psalm-suppress UnusedParam $objectId is used in URL interpolation
     * @psalm-suppress UndefinedClass CsrfTokenManager is an internal Nextcloud class (no OCP interface yet).
     */
    private function fetchEntityData(string $entityType, string $objectId): ?array
    {
        $settings  = $this->settingsService->getSettings();
        $register  = $settings['register'] ?? '';
        $schemaKey = $entityType.'_schema';
        $schema    = $settings[$schemaKey] ?? '';

        if ($register === '' || $schema === '') {
            return null;
        }

        $url = $this->urlGenerator->getAbsoluteURL(
            "/apps/openregister/api/objects/{$register}/{$schema}/{$objectId}"
        );

        $client   = $this->clientService->newClient();
        $response = $client->get(
                $url,
                [
                    'headers'   => [
                        'OCS-APIREQUEST' => 'true',
                        'requesttoken'   => $this->csrfTokenManager->getToken()->getEncryptedValue(),
                    ],
                    'nextcloud' => ['allow_local_address' => true],
                ]
                );

        return json_decode($response->getBody(), true);
    }//end fetchEntityData()

    /**
     * Publish activity and notification for a note addition.
     *
     * @param string $entityType The entity type.
     * @param array  $entityData The entity data from OpenRegister.
     * @param string $objectId   The object ID.
     *
     * @return void
     */
    private function publishNoteActivity(string $entityType, array $entityData, string $objectId): void
    {
        $entityTitle = $entityData['title'] ?? $entityType.' '.$objectId;
        $assignee    = $entityData['assignee'] ?? '';

        $currentUser = $this->userSession->getUser();
        $author      = '';
        if ($currentUser !== null) {
            $author = $currentUser->getUID();
        }

        $assigneeOrNull = null;
        if ($assignee !== '') {
            $assigneeOrNull = $assignee;
        }

        $this->activityService->publishNoteAdded(
            entityType: $entityType,
            entityTitle: $entityTitle,
            objectId: $objectId,
            affectedUser: $assigneeOrNull
        );

        if ($assignee !== '') {
            $this->notificationService->notifyNoteAdded(
                entityType: $entityType,
                entityTitle: $entityTitle,
                assigneeUserId: $assignee,
                objectId: $objectId,
                author: $author
            );
        }
    }//end publishNoteActivity()
}//end class
