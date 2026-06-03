<?php

/**
 * Pipelinq ChannelProviderInterface.
 *
 * Vendor-agnostic contract every WhatsApp/SMS provider client implements.
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
 * Contract for vendor-specific messaging provider clients.
 *
 * Adding a new WhatsApp/SMS vendor requires only a new class implementing this
 * interface and a registration in {@see ProviderRegistry}; no changes to the
 * vendor-neutral WhatsAppService / SmsService are needed.
 *
 * All external HTTP is performed through OCP IClientService so it can be mocked
 * in unit tests — no live provider account is required to build or test.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-2.5
 */
interface ChannelProviderInterface
{
    /**
     * The vendor identifier this client handles (e.g. "meta", "twilio").
     *
     * @return string The lowercase vendor slug.
     */
    public function getVendor(): string;

    /**
     * Configure the client from a resolved provider config + secrets.
     *
     * Secrets are passed in transiently (resolved from app config/vault by the
     * caller) and MUST NOT be persisted on the provider object (ADR-005).
     *
     * @param array<string, mixed>  $config  The channelProvider object (no secrets).
     * @param array<string, string> $secrets The resolved secret material.
     *
     * @return void
     */
    public function configure(array $config, array $secrets): void;

    /**
     * Verify an inbound webhook is authentic before any processing.
     *
     * Implementations MUST perform a constant-time comparison and MUST NOT
     * trust the request until this returns true (ADR-005).
     *
     * @param string                $rawBody The exact raw request body bytes.
     * @param array<string, string> $headers Lower-cased request headers.
     * @param array<string, string> $query   Query parameters.
     * @param string                $secret  The configured webhook signing secret.
     *
     * @return bool True when the signature is valid.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, array $query, string $secret): bool;

    /**
     * Translate a vendor inbound webhook payload into normalised messages.
     *
     * A single webhook may carry zero or more messages (Meta batches).
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return InboundMessage[] The normalised inbound messages.
     */
    public function parseInboundMessages(array $payload): array;

    /**
     * Translate a vendor delivery/status webhook payload into normalised updates.
     *
     * @param array<string, mixed> $payload The decoded webhook body.
     *
     * @return DeliveryUpdate[] The normalised delivery updates.
     */
    public function parseDeliveryUpdates(array $payload): array;

    /**
     * Send an approved template (HSM) message.
     *
     * @param string             $toNumber     Recipient E.164 number.
     * @param string             $templateName The provider template identifier.
     * @param string             $language     BCP-47 language tag.
     * @param array<int, string> $parameters   Positional placeholder values.
     *
     * @return SendResult The send outcome.
     */
    public function sendTemplate(string $toNumber, string $templateName, string $language, array $parameters): SendResult;

    /**
     * Send a free-form (session) message.
     *
     * @param string             $toNumber Recipient E.164 number.
     * @param string             $body     The text body.
     * @param array<int, string> $mediaIds Optional provider media ids to attach.
     *
     * @return SendResult The send outcome.
     */
    public function sendFreeForm(string $toNumber, string $body, array $mediaIds=[]): SendResult;
}//end interface
