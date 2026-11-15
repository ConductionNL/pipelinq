<?php

/**
 * Pipelinq WhatsAppMessage.
 *
 * One outbound WhatsApp message: who it goes to and what it says.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Provider
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Provider;

/**
 * The recipient and body of one WhatsApp send.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class WhatsAppMessage {
	/**
	 * Constructor.
	 *
	 * @param string               $contactId Contact UUID.
	 * @param string               $toNumber  Recipient phone number.
	 * @param string               $body      Free-form body.
	 * @param array<string, mixed> $context   Send context for the outbound audit (`clientId`, `agent`).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $contactId,
		public readonly string $toNumber,
		public readonly string $body,
		public readonly array $context,
	) {
	}//end __construct()
}//end class
