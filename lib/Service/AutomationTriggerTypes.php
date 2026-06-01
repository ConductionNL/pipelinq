<?php

/**
 * Pipelinq AutomationTriggerTypes.
 *
 * Enumeration of valid automation trigger type values.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Constants for automation trigger types.
 *
 * V1 triggers are currently active. V2 triggers are registered here
 * to anchor spec traceability but MUST NOT be processed by the
 * automation engine until the crm-workflow-automation spec implementation
 * is complete and these triggers are explicitly enabled.
 *
 * @spec openspec/changes/email-calendar-sync/tasks.md#task-4.1
 */
class AutomationTriggerTypes
{
    // V1 trigger types (active).

    /** Triggered when a new lead is created. */
    public const LEAD_CREATED = 'lead.created';

    /** Triggered when a lead stage changes. */
    public const LEAD_STAGE_CHANGED = 'lead.stage_changed';

    /** Triggered when a request is created. */
    public const REQUEST_CREATED = 'request.created';

    /** Triggered when a request status changes. */
    public const REQUEST_STATUS_CHANGED = 'request.status_changed';

    /** Triggered when a contact is created. */
    public const CONTACT_CREATED = 'contact.created';

    // V2 trigger types (registered but NOT active — awaiting crm-workflow-automation spec).
    // DO NOT process these triggers until the automation engine change is merged.

    /**
     * Triggered when the EmailSyncJob links an inbound email to a CRM entity.
     *
     * V2 — NOT enabled. Registered here for spec traceability only.
     *
     * @see openspec/changes/email-calendar-sync/tasks.md#task-4.1
     */
    public const EMAIL_RECEIVED = 'email.received';

    /**
     * Triggered when a calendar event linked to a CRM entity starts.
     *
     * V2 — NOT enabled. Registered here for spec traceability only.
     *
     * @see openspec/changes/email-calendar-sync/tasks.md#task-4.1
     */
    public const CALENDAR_EVENT_START = 'calendar.event.start';

    /**
     * Return all valid V1 trigger type values.
     *
     * @return array<string> The active trigger types.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-4.1
     */
    public static function getV1Types(): array
    {
        return [
            self::LEAD_CREATED,
            self::LEAD_STAGE_CHANGED,
            self::REQUEST_CREATED,
            self::REQUEST_STATUS_CHANGED,
            self::CONTACT_CREATED,
        ];
    }//end getV1Types()

    /**
     * Return all registered trigger type values, including V2 (inactive).
     *
     * @return array<string> All registered trigger types.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-4.1
     */
    public static function getAllTypes(): array
    {
        return array_merge(
            self::getV1Types(),
            [
                self::EMAIL_RECEIVED,
                self::CALENDAR_EVENT_START,
            ]
        );
    }//end getAllTypes()

    /**
     * Check whether a trigger type value is a registered V1 trigger.
     *
     * @param string $type The trigger type to check.
     *
     * @return bool True if the trigger type is a valid V1 trigger.
     *
     * @spec openspec/changes/email-calendar-sync/tasks.md#task-4.1
     */
    public static function isV1Type(string $type): bool
    {
        return in_array($type, self::getV1Types(), strict: true);
    }//end isV1Type()
}//end class
