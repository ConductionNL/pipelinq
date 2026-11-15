<?php

/**
 * Pipelinq WhatsAppInbound.
 *
 * One inbound WhatsApp message that WhatsAppAdapter acts on.
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
 * Who sent an inbound WhatsApp message, what it said and which provider
 * row it came in on.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
final class WhatsAppInbound {
	/**
	 * Constructor.
	 *
	 * @param string               $contactId   Matched contact UUID, or empty for an unknown number.
	 * @param string               $from        Sender phone number.
	 * @param string               $body        Message body.
	 * @param array<string, mixed> $providerRow The provider row it came in on.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $contactId,
		public readonly string $from,
		public readonly string $body,
		public readonly array $providerRow,
	) {
	}//end __construct()
}//end class
