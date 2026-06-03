<?php

/**
 * Pipelinq InboundMessage.
 *
 * Immutable value object describing a normalised inbound WhatsApp/SMS message.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Messaging
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Messaging;

/**
 * Normalised, vendor-agnostic representation of an inbound message event.
 *
 * Provider clients translate vendor-specific webhook payloads into this shape
 * so the vendor-neutral WhatsAppService / SmsService can act without knowing
 * the vendor (REQ-003).
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) — flat immutable DTO of webhook fields
 * @spec                                           openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.5
 */
class InboundMessage
{
    /**
     * Constructor.
     *
     * @param string                           $channel           The channel ('whatsapp'|'sms').
     * @param string                           $fromNumber        Sender phone number (raw).
     * @param string                           $toNumber          Recipient (the tenant) number (raw).
     * @param string                           $body              The text body (empty for media-only).
     * @param string|null                      $externalMessageId The provider's message id.
     * @param array<int, array<string, mixed>> $media             Media descriptors (id/mime/filename).
     * @param string|null                      $timestamp         ISO 8601 send timestamp, if present.
     */
    public function __construct(
        public readonly string $channel,
        public readonly string $fromNumber,
        public readonly string $toNumber='',
        public readonly string $body='',
        public readonly ?string $externalMessageId=null,
        public readonly array $media=[],
        public readonly ?string $timestamp=null,
    ) {
    }//end __construct()

    /**
     * Whether this inbound carries any media attachments.
     *
     * @return bool True when at least one media descriptor is present.
     */
    public function hasMedia(): bool
    {
        return $this->media !== [];
    }//end hasMedia()
}//end class
