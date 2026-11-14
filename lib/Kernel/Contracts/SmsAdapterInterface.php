<?php

/**
 * Contract of SmsAdapter
 *
 * The public surface of SmsAdapter that other components call, so they depend
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
 * Contract of SmsAdapter.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SmsAdapterInterface {

	/**
	 * Handle an inbound SMS webhook.
	 *
	 * Verifies the signature using the channelProvider.webhookSecret,
	 * persists the inbound message, auto-detects STOP keywords, and
	 * fires placeholder-contact creation when the number is unknown.
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
	 * A number that matches no contact gets no placeholder contact: a STOP is
	 * recorded on the number itself, anything else is logged as a contact
	 * moment for a person to pick up.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 */
	public function handleInboundWebhook(string $rawBody, string $signature, string $providerId): array;
}//end interface
