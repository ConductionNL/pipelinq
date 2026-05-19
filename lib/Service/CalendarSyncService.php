<?php

/**
 * Pipelinq CalendarSyncService.
 *
 * Service for calendar event synchronization with CRM entities.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for calendar synchronization with CRM entities.
 *
 * Creates calendar events from Pipelinq follow-ups and links
 * Nextcloud Calendar events to CRM entities by attendee matching.
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
     * Build a CalendarLink data array for OpenRegister storage.
     *
     * @param string $eventUid         The calendar event UID.
     * @param string $title            The event title.
     * @param string $startDate        ISO 8601 start date.
     * @param string $endDate          ISO 8601 end date.
     * @param string $linkedEntityType The entity type.
     * @param string $linkedEntityId   The entity UUID.
     * @param string $createdFrom      Where the event was created.
     * @param array  $attendees        Attendee email addresses.
     *
     * @return array<string, mixed> The CalendarLink data.
     */
    public function buildCalendarLinkData(
        string $eventUid,
        string $title,
        string $startDate,
        string $endDate,
        string $linkedEntityType,
        string $linkedEntityId,
        string $createdFrom='pipelinq',
        array $attendees=[],
    ): array {
        return [
            'eventUid'         => $eventUid,
            'title'            => $title,
            'startDate'        => $startDate,
            'endDate'          => $endDate,
            'attendees'        => $attendees,
            'linkedEntityType' => $linkedEntityType,
            'linkedEntityId'   => $linkedEntityId,
            'status'           => 'scheduled',
            'createdFrom'      => $createdFrom,
        ];
    }//end buildCalendarLinkData()

    /**
     * Generate iCalendar VEVENT content for creating a calendar event.
     *
     * @param string $title       Event title.
     * @param string $startDate   ISO 8601 start date.
     * @param string $endDate     ISO 8601 end date.
     * @param string $description Event description.
     * @param string $entityUrl   URL back to the Pipelinq entity.
     * @param array  $attendees   Attendee email addresses.
     *
     * @return string The iCalendar VEVENT string.
     */
    public function generateVEvent(
        string $title,
        string $startDate,
        string $endDate,
        string $description='',
        string $entityUrl='',
        array $attendees=[],
    ): string {
        $uid   = bin2hex(random_bytes(16)).'@pipelinq';
        $now   = gmdate('Ymd\THis\Z');
        $start = gmdate('Ymd\THis\Z', strtotime($startDate));
        $end   = gmdate('Ymd\THis\Z', strtotime($endDate));

        $vcal  = "BEGIN:VCALENDAR\r\n";
        $vcal .= "VERSION:2.0\r\n";
        $vcal .= "PRODID:-//Pipelinq//CRM//NL\r\n";
        $vcal .= "BEGIN:VEVENT\r\n";
        $vcal .= "UID:{$uid}\r\n";
        $vcal .= "DTSTAMP:{$now}\r\n";
        $vcal .= "DTSTART:{$start}\r\n";
        $vcal .= "DTEND:{$end}\r\n";
        $vcal .= "SUMMARY:{$title}\r\n";

        if ($description !== '' || $entityUrl !== '') {
            $desc = $description;
            if ($entityUrl !== '') {
                if ($desc !== '') {
                    $desc .= '\n\n';
                }

                $desc .= 'Pipelinq: '.$entityUrl;
            }

            $vcal .= "DESCRIPTION:{$desc}\r\n";
        }

        foreach ($attendees as $attendee) {
            $vcal .= "ATTENDEE;RSVP=TRUE:mailto:{$attendee}\r\n";
        }

        $vcal .= "END:VEVENT\r\n";
        $vcal .= "END:VCALENDAR\r\n";

        return $vcal;
    }//end generateVEvent()

    /**
     * Determine if a calendar event has passed its end date.
     *
     * @param string $endDate ISO 8601 end date.
     *
     * @return bool True if the event end date has passed.
     */
    public function isEventPassed(string $endDate): bool
    {
        return new \DateTime($endDate) < new \DateTime();
    }//end isEventPassed()
}//end class
