<?php

/**
 * Pipelinq QuoteNumberSequence.
 *
 * Hands out a quote's number, "OFF-2026-0042": the quote's own yearly
 * counter, allocated race-safe the same way as the invoice numbers.
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
 * @spec openspec/specs/product-catalog-quoting/spec.md#requirement-quote-entity-enterprise
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Race-safe quote number allocator.
 *
 * @spec openspec/specs/product-catalog-quoting/spec.md#requirement-quote-entity-enterprise
 */
class QuoteNumberSequence extends InvoiceSequenceService {
	/**
	 * App-config key holding the last allocated quote counter value for the year.
	 *
	 * @var string
	 */
	protected const COUNTER_KEY = 'quote_number_counter';

	/**
	 * App-config key holding the year the quote counter belongs to.
	 *
	 * @var string
	 */
	protected const COUNTER_YEAR_KEY = 'quote_number_counter_year';

	/**
	 * Write a claimed counter value as a quote number.
	 *
	 * @param int $year   The calendar year.
	 * @param int $number The claimed counter value.
	 *
	 * @return string The quote number, e.g. "OFF-2026-0042".
	 *
	 * @spec openspec/specs/product-catalog-quoting/spec.md#requirement-quote-entity-enterprise
	 */
	protected function format(int $year, int $number): string {
		return sprintf('OFF-%04d-%04d', $year, $number);
	}//end format()
}//end class
