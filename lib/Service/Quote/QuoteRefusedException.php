<?php

/**
 * Pipelinq QuoteRefusedException.
 *
 * Why a quote could not be sent, with the HTTP status the endpoint answers.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Quote
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Quote;

use RuntimeException;
use Throwable;

/**
 * A quote that was not sent, and why.
 *
 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */
class QuoteRefusedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string               $message  What the user is told.
	 * @param int                  $status   The HTTP status the endpoint answers.
	 * @param array<string, mixed> $details  Extra fields for the answer, such as where the PDF was filed.
	 * @param Throwable|null       $previous The cause.
	 */
	public function __construct(
		string $message,
		private readonly int $status,
		private readonly array $details = [],
		?Throwable $previous = null,
	) {
		parent::__construct(message: $message, code: 0, previous: $previous);
	}//end __construct()

	/**
	 * The HTTP status the endpoint answers.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()

	/**
	 * Extra fields for the answer.
	 *
	 * @return array<string, mixed> The details.
	 *
	 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
	 */
	public function getDetails(): array {
		return $this->details;
	}//end getDetails()
}//end class
