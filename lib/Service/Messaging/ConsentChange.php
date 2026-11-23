<?php

/**
 * Pipelinq ConsentChange.
 *
 * A messaging consent opt-in or opt-out as the send surface records it.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Messaging
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
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Messaging;

/**
 * The contact, channel, action, source, evidence and legal basis of one consent change.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
 */
final class ConsentChange {
	/**
	 * Constructor.
	 *
	 * @param string $contactId  Contact UUID.
	 * @param string $channel    `sms` or `whatsapp`.
	 * @param string $action     `opt-in` or `opt-out`.
	 * @param string $source     Source enum (manual / webform / ...).
	 * @param string $evidence   Audit-trail evidence.
	 * @param string $legalBasis GDPR legal basis.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $contactId,
		public readonly string $channel,
		public readonly string $action,
		public readonly string $source,
		public readonly string $evidence,
		public readonly string $legalBasis,
	) {
	}//end __construct()

	/**
	 * The same change with its evidence attributed to the recording user.
	 *
	 * @param string $userId The acting user id.
	 *
	 * @return self The attributed change.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 */
	public function attributedTo(string $userId): self {
		return new self(
			contactId: $this->contactId,
			channel: $this->channel,
			action: $this->action,
			source: $this->source,
			evidence: sprintf('%s (recorded by %s)', $this->evidence, $userId),
			legalBasis: $this->legalBasis,
		);
	}//end attributedTo()
}//end class
