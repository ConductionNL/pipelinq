<?php

/**
 * Pipelinq TimeEntryApprovedEvent.
 *
 * Emitted when a time entry transitions to status `approved` so downstream
 * integrations (most notably the Shillinq WIP ledger) can dispatch a
 * one-way event without the approval path depending on those subsystems
 * directly.
 *
 * @category Event
 * @package  OCA\Pipelinq\Event
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Event;

use OCP\EventDispatcher\Event;

/**
 * Fired when a time entry is approved.
 *
 * The event carries the approved time entry data (already-persisted snapshot),
 * the approver's user id and the ISO 8601 UTC approval timestamp. Listeners
 * MUST treat the payload as read-only and MUST NOT throw — the approval write
 * has already completed by the time this event fires.
 *
 * @spec openspec/changes/pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-001
 */
class TimeEntryApprovedEvent extends Event
{
    /**
     * Constructor.
     *
     * @param string               $timeEntryUuid The UUID of the approved time entry.
     * @param array<string, mixed> $timeEntry     The approved time entry data snapshot.
     * @param string               $approvedBy    The approver's user id.
     * @param string               $approvedAt    ISO 8601 UTC approval timestamp.
     */
    public function __construct(
        private string $timeEntryUuid,
        private array $timeEntry,
        private string $approvedBy,
        private string $approvedAt,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * The approved time entry UUID.
     *
     * @return string The time entry UUID.
     */
    public function getTimeEntryUuid(): string
    {
        return $this->timeEntryUuid;
    }//end getTimeEntryUuid()

    /**
     * The approved time entry data snapshot.
     *
     * @return array<string, mixed> The time entry data.
     */
    public function getTimeEntry(): array
    {
        return $this->timeEntry;
    }//end getTimeEntry()

    /**
     * The approver's user id.
     *
     * @return string The approver user id.
     */
    public function getApprovedBy(): string
    {
        return $this->approvedBy;
    }//end getApprovedBy()

    /**
     * The ISO 8601 UTC approval timestamp.
     *
     * @return string The approval timestamp.
     */
    public function getApprovedAt(): string
    {
        return $this->approvedAt;
    }//end getApprovedAt()
}//end class
