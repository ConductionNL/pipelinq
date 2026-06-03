<?php

/**
 * Pipelinq CtiWebhookResult.
 *
 * Immutable value object describing the normalised outcome of parsing an
 * inbound telephony webhook payload.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;

/**
 * Normalised, platform-agnostic representation of an inbound webhook event.
 *
 * Adapters translate vendor-specific payloads into this shape so the
 * platform-neutral CtiService can act on it without knowing the vendor.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) — flat immutable DTO of webhook fields
 * @spec                                           openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */
class CtiWebhookResult
{
    /**
     * Constructor.
     *
     * @param string      $eventType          Normalised event type
     *                                        (ringing|answered|ended|abandoned|transferred|presence_changed|recording_ready).
     * @param string|null $externalCallId     Platform call identifier, if present.
     * @param string|null $fromNumber         Calling-party number (raw), if present.
     * @param string|null $toNumber           Called-party number (raw), if present.
     * @param string|null $extension          Agent extension, if present.
     * @param int|null    $durationSeconds    Call duration in seconds, if present.
     * @param string|null $recordingUrl       Recording deep link, if present.
     * @param string|null $recordingExpiresAt Recording retention expiry (ISO 8601), if present.
     * @param string|null $presenceState      New presence state for presence_changed events.
     * @param string|null $userId             Agent user UID for presence_changed events.
     */
    public function __construct(
        public readonly string $eventType,
        public readonly ?string $externalCallId=null,
        public readonly ?string $fromNumber=null,
        public readonly ?string $toNumber=null,
        public readonly ?string $extension=null,
        public readonly ?int $durationSeconds=null,
        public readonly ?string $recordingUrl=null,
        public readonly ?string $recordingExpiresAt=null,
        public readonly ?string $presenceState=null,
        public readonly ?string $userId=null,
    ) {
    }//end __construct()
}//end class
