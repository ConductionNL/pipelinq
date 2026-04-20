<?php

/**
 * Pipelinq CalendarSyncService.
 *
 * Service for syncing calendar events with CRM entities.
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
 * @spec openspec/changes/2026-03-20-email-calendar-sync/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for creating and managing calendar events linked to CRM entities.
 */
class CalendarSyncService
{
    /**
     * Constructor.
     *
     * @param LoggerInterface $logger The logger.
     */
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a follow-up event for a CRM entity.
     *
     * Creates a calendar event linked to a CRM entity (client, contact, lead, or request).
     *
     * @param string               $entityType The type of entity (client, contact, lead, request)
     * @param string               $entityId   The UUID of the entity
     * @param array<string, mixed> $eventData  Event data including title, startDate, endDate, attendees
     *
     * @return array<string, mixed> The created event data
     *
     * @spec openspec/changes/2026-03-20-email-calendar-sync/tasks.md#task-2.2
     */
    public function createFollowUpEvent(string $entityType, string $entityId, array $eventData): array
    {
        // Validate entity type.
        $validTypes = ['client', 'contact', 'lead', 'request'];
        if (\in_array(needle: $entityType, haystack: $validTypes, strict: true) === false) {
            throw new \InvalidArgumentException("Invalid entity type: {$entityType}");
        }

        // Validate required event data.
        if (empty($eventData['title']) === true) {
            throw new \InvalidArgumentException('Event title is required');
        }

        // Generate event UID.
        $eventUid = \bin2hex(\random_bytes(16));

        $event = [
            'eventUid'         => $eventUid,
            'title'            => $eventData['title'],
            'startDate'        => $eventData['startDate'] ?? null,
            'endDate'          => $eventData['endDate'] ?? null,
            'attendees'        => $eventData['attendees'] ?? [],
            'linkedEntityType' => $entityType,
            'linkedEntityId'   => $entityId,
            'status'           => 'scheduled',
            'createdFrom'      => 'pipelinq',
        ];

        $this->logger->info(
                'Created follow-up event',
                [
                    'eventUid'   => $eventUid,
                    'entityType' => $entityType,
                    'entityId'   => $entityId,
                ]
                );

        return $event;
    }//end createFollowUpEvent()

    /**
     * Match calendar event attendees to CRM entities.
     *
     * Attempts to find CRM entities (contacts, clients) matching the event attendees.
     * In a complete implementation, would query the register for matching email addresses.
     *
     * @param array<string> $attendeeEmails Email addresses of event attendees
     *
     * @return array<array> Array of matched entities
     *
     * @spec openspec/changes/2026-03-20-email-calendar-sync/tasks.md#task-2.2
     */
    public function matchEventToEntities(array $attendeeEmails): array
    {
        $matches = [];

        foreach ($attendeeEmails as $email) {
            // Sanitize email address to prevent injection attacks
            $sanitizedEmail = \str_replace(["\r", "\n"], '', $email);
            $sanitizedEmail = \strtolower(\trim($sanitizedEmail));

            // Email matching would typically:
            // 1. Query the register for contacts/clients with matching email
            // 2. Check both primary and secondary email fields
            // 3. Return entity type and ID if found

            // This is a placeholder for actual register integration.
            // Developers should extend this method to implement register queries.
            $this->logger->debug(
                'Attempted to match attendee email to CRM entity',
                ['email' => $sanitizedEmail, 'result' => 'no_match']
            );
        }

        return $matches;
    }//end matchEventToEntities()
}//end class
