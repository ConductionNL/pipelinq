<?php

/**
 * Contract of QuoteNumberSequence
 *
 * The public surface of QuoteNumberSequence that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of QuoteNumberSequence.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/product-catalog-quoting/spec.md#requirement-quote-entity-enterprise
 */
interface QuoteNumberSequenceInterface {

	/**
	 * Allocate the next quote number. Two concurrent callers never receive the same
	 * one, and the counter resets at the start of each calendar year.
	 *
	 * @return string The allocated quote number.
	 *
	 * @throws \RuntimeException If a unique quote number cannot be allocated after retries.
	 *
	 * @spec openspec/specs/product-catalog-quoting/spec.md#requirement-quote-entity-enterprise
	 */
	public function next(): string;
}//end interface
