<?php

/**
 * Pipelinq TicketReferenceSequence.
 *
 * Hands out a ticket's reference number, "PQ-2026-0402": the ticket's own
 * yearly counter, allocated race-safe the same way as the invoice numbers.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/ticket-leaf-closed-reference-assignee/specs/ticket-leaf/spec.md#requirement-a-ticket-carries-a-reference-number
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Race-safe ticket reference allocator.
 *
 * @spec openspec/changes/ticket-leaf-closed-reference-assignee/specs/ticket-leaf/spec.md#requirement-a-ticket-carries-a-reference-number
 */
class TicketReferenceSequence extends InvoiceSequenceService {
	/**
	 * App-config key holding the last allocated ticket counter value for the year.
	 *
	 * @var string
	 */
	protected const COUNTER_KEY = 'ticket_reference_counter';

	/**
	 * App-config key holding the year the ticket counter belongs to.
	 *
	 * @var string
	 */
	protected const COUNTER_YEAR_KEY = 'ticket_reference_counter_year';

	/**
	 * Write a claimed counter value as a ticket reference.
	 *
	 * @param int $year The calendar year.
	 * @param int $number The claimed counter value.
	 *
	 * @return string The reference, e.g. "PQ-2026-0402".
	 */
	protected function format(int $year, int $number): string {
		return sprintf('PQ-%04d-%04d', $year, $number);
	}//end format()
}//end class
