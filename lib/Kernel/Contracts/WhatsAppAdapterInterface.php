<?php

/**
 * Contract of WhatsAppAdapter
 *
 * The public surface of WhatsAppAdapter that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of WhatsAppAdapter.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WhatsAppAdapterInterface {

	/**
	 * Handle a Meta `messages` inbound webhook.
	 *
	 * Validates the X-Hub-Signature-256, persists the inbound message,
	 * advances a known contact's conversation `lastInboundAt`, and fires
	 * automatic STOP / opt-in detection. A number that matches no contact
	 * gets no placeholder contact: a STOP is recorded on the number itself,
	 * anything else is logged as a contact moment for a person to pick up.
	 *
	 * @param string $rawBody Raw request body.
	 * @param string $signature Signature header value.
	 * @param string $providerId channelProvider UUID.
	 *
	 * @return array{
	 *     status: string,
	 *     messageId?: string,
	 *     conversationId?: string,
	 *     unknownSender?: bool,
	 *     contactMomentId?: string,
	 *     optOutRecorded?: bool,
	 *     error?: string
	 * } Outcome envelope.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 */
	public function handleInboundWebhook(string $rawBody, string $signature, string $providerId): array;
}//end interface
