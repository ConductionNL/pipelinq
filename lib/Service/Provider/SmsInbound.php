<?php

/**
 * Pipelinq SmsInbound.
 *
 * One inbound SMS that SmsAdapter stores and acts on.
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
 * The provider an inbound SMS came in on, the contact it was matched to,
 * the sender and what it said.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
final class SmsInbound {
	/**
	 * Constructor.
	 *
	 * @param string $providerId channelProvider UUID.
	 * @param string $contactId  Matched contact UUID, or empty for an unknown number.
	 * @param string $from       Sender in E.164.
	 * @param string $body       Message text.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $providerId,
		public readonly string $contactId,
		public readonly string $from,
		public readonly string $body,
	) {
	}//end __construct()
}//end class
