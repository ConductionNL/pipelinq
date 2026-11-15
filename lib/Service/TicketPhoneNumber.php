<?php

/**
 * Pipelinq TicketPhoneNumber.
 *
 * Which phone number a ticket is about, and that number in E.164.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\Contracts\TicketPhoneNumberInterface;

/**
 * Reads the number a ticket is about from whichever field its writer filled,
 * and normalises it the way the screen pop does (design D2).
 *
 * The other party is the one the number names: the caller on an inbound call,
 * the callee on an outbound one. A ticket that is not a call falls back to the
 * number its reporter gave (a web enquiry's `reporterPhone`) and then to the
 * sender of a message from an unknown number (`channelMetadata.from`).
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.2
 */
class TicketPhoneNumber implements TicketPhoneNumberInterface {

	/**
	 * Constructor.
	 *
	 * @param PhoneNormaliser $phoneNormaliser Normalises a typed number to E.164.
	 */
	public function __construct(
		private readonly PhoneNormaliser $phoneNormaliser,
	) {
	}//end __construct()

	/**
	 * The number the ticket is about, as stored; null when it names none.
	 *
	 * @param array<string,mixed> $ticket The ticket fields.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.2
	 */
	public function rawNumber(array $ticket): ?string {
		$fields = ['from_number', 'to_number'];
		if (($ticket['direction'] ?? null) === 'outbound') {
			$fields = ['to_number', 'from_number'];
		}

		$fields[] = 'reporterPhone';
		foreach ($fields as $field) {
			$value = $this->text(value: ($ticket[$field] ?? null));
			if ($value !== null) {
				return $value;
			}
		}

		$metadata = ($ticket['channelMetadata'] ?? null);
		if (is_array($metadata) === true) {
			return $this->text(value: ($metadata['from'] ?? null));
		}

		return null;
	}//end rawNumber()

	/**
	 * The number the ticket is about in E.164; null when it names none or the
	 * number does not normalise.
	 *
	 * @param array<string,mixed> $ticket The ticket fields.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.2
	 */
	public function e164(array $ticket): ?string {
		$raw = $this->rawNumber(ticket: $ticket);
		if ($raw === null) {
			return null;
		}

		return $this->phoneNormaliser->normaliseForOrg($raw)['e164'];
	}//end e164()

	/**
	 * A non-blank string, trimmed; null otherwise.
	 *
	 * @param mixed $value The field value.
	 *
	 * @return string|null
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end text()
}//end class
