<?php

/**
 * Pipelinq SlaRecipient.
 *
 * The customer an sms or whatsapp escalation goes to.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Sla
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
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Sla;

/**
 * The contact row, its UUID and the send context of a customer escalation.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
 */
class SlaRecipient {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $contact   Recipient contact or client row.
	 * @param string               $contactId Recipient UUID.
	 * @param array<string, mixed> $context   Send context (clientId, agent).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $contact,
		public readonly string $contactId,
		public readonly array $context,
	) {
	}//end __construct()

	/**
	 * Map an adapter outcome onto the notifiedActors marker vocabulary.
	 *
	 * @param string               $channel `sms` or `whatsapp`.
	 * @param array<string, mixed> $outcome Adapter outcome envelope.
	 *
	 * @return array<int, string> Notified actor identifiers / markers.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
	 */
	public function marker(string $channel, array $outcome): array {
		$status = (string)($outcome['status'] ?? '');
		if ($status === 'sent') {
			$actor = $this->contactId;
			if ($actor === '') {
				$actor = 'customer';
			}

			return [$actor];
		}

		if ($status === 'consentMissing') {
			return ['consent-missing:' . $channel];
		}

		if ($status === 'sessionWindowExpired') {
			return ['template-missing:' . $channel];
		}

		return ['failed:' . $channel . ':' . $status];
	}//end marker()
}//end class
