<?php

/**
 * Contract of TicketReferenceSequence
 *
 * The public surface of TicketReferenceSequence that other components call, so they depend
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
 * Contract of TicketReferenceSequence.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/ticket-leaf-closed-reference-assignee/specs/ticket-leaf/spec.md#requirement-a-ticket-carries-a-reference-number
 */
interface TicketReferenceSequenceInterface {

	/**
	 * Allocate the next ticket reference. Two concurrent callers never receive the same
	 * one, and the counter resets at the start of each calendar year.
	 *
	 * @return string The allocated ticket reference.
	 *
	 * @throws \RuntimeException If a unique ticket reference cannot be allocated after retries.
	 *
	 * @spec openspec/changes/ticket-leaf-closed-reference-assignee/specs/ticket-leaf/spec.md#requirement-a-ticket-carries-a-reference-number
	 */
	public function next(): string;
}//end interface
