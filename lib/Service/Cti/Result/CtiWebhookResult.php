<?php

/**
 * Pipelinq CtiWebhookResult.
 *
 * Value object returned by adapter::handleInboundWebhook describing the
 * normalised event extracted from a platform-specific webhook payload.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti\Result
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Result;

/**
 * Normalised inbound CTI webhook event.
 *
 * Adapters translate vendor-specific webhook payloads into this shape so the
 * CtiService can act uniformly regardless of platform.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */
final class CtiWebhookResult
{
    /**
     * Constructor.
     *
     * @param string              $eventType          One of ringing|answered|ended|abandoned|transferred|presence|recording.
     * @param string              $externalCallId     Platform's call UUID; correlates with contactmoment.
     * @param string|null         $direction          inbound|outbound|null when unknown.
     * @param string|null         $fromNumber         Caller phone number (raw, as supplied by platform).
     * @param string|null         $toNumber           Callee phone number (raw, as supplied by platform).
     * @param string|null         $extension          Agent extension that handled the call.
     * @param string|null         $userId             Resolved NC user UID when the platform identifies one.
     * @param int|null            $durationSeconds    Talk duration on `ended` events.
     * @param string|null         $recordingUrl       Recording URL on `ended`/`recording` events.
     * @param string|null         $recordingExpiresAt ISO 8601 retention expiry of the recording.
     * @param string|null         $presenceState      Presence value on `presence` events.
     * @param string|null         $queueName          Inbound queue/campaign name.
     * @param string|null         $agentSkill         Skill tag.
     * @param array<string,mixed> $raw                Raw payload (stored verbatim in cti_event_log).
     *
     * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Immutable normalised webhook DTO; each field is a distinct CTI event attribute.
     */
    public function __construct(
        public readonly string $eventType,
        public readonly string $externalCallId,
        public readonly ?string $direction=null,
        public readonly ?string $fromNumber=null,
        public readonly ?string $toNumber=null,
        public readonly ?string $extension=null,
        public readonly ?string $userId=null,
        public readonly ?int $durationSeconds=null,
        public readonly ?string $recordingUrl=null,
        public readonly ?string $recordingExpiresAt=null,
        public readonly ?string $presenceState=null,
        public readonly ?string $queueName=null,
        public readonly ?string $agentSkill=null,
        public readonly array $raw=[],
    ) {
    }//end __construct()
}//end class
