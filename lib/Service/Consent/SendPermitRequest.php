<?php

/**
 * Pipelinq SendPermitRequest.
 *
 * One question whether a send to a contact is permitted.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Consent
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
 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Consent;

/**
 * The contact, channel, intent and optional list a send permission is
 * asked for.
 *
 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
 */
final class SendPermitRequest {
	/**
	 * Constructor.
	 *
	 * @param string      $contactId Contact UUID or slug.
	 * @param string      $channel   "email" or "sms", as the caller gave it.
	 * @param string      $intent    `promotional` or `service`.
	 * @param string|null $listId    The mailing list, when the send is list-scoped.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $contactId,
		public readonly string $channel,
		public readonly string $intent,
		public readonly ?string $listId,
	) {
	}//end __construct()

	/**
	 * Whether the send is scoped to one mailing list.
	 *
	 * @return bool True when a non-empty list id was given.
	 *
	 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
	 */
	public function isListScoped(): bool {
		return $this->listId !== null && $this->listId !== '';
	}//end isListScoped()
}//end class
