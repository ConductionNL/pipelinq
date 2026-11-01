<?php

/**
 * Pipelinq SmsMessage.
 *
 * The outbound SMS that SmsAdapter::send() tries on each provider in turn.
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
 * One outbound SMS: who it is for, the number, the text and the audit context.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class SmsMessage {
	/**
	 * Constructor.
	 *
	 * @param string               $contactId Contact UUID.
	 * @param string               $toNumber  Recipient E.164 number.
	 * @param string               $body      Message body.
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
