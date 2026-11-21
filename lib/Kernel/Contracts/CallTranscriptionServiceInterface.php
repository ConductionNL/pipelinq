<?php

/**
 * Contract of CallTranscriptionService
 *
 * The public surface of CallTranscriptionService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of CallTranscriptionService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CallTranscriptionServiceInterface {

	/**
	 * Transcribe one contact moment's recording and store the outcome on it.
	 *
	 * @param string                 $ticketId The contact moment ticket's id.
	 * @param DateTimeImmutable|null $now      The moment to judge the retention at.
	 *
	 * @return string The status written, or '' when the ticket could not be read.
	 *
	 * @spec openspec/changes/contact-moments-call-review/tasks.md#task-1.3
	 */
	public function transcribe(string $ticketId, ?DateTimeImmutable $now = null): string;
}//end interface
