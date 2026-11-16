<?php

/**
 * Contract of ClientHistorySummaryService
 *
 * The public surface of ClientHistorySummaryService that other components call, so they depend
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
 * Contract of ClientHistorySummaryService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ClientHistorySummaryServiceInterface {

	/**
	 * Whether hermiq is there to write a summary.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.1
	 */
	public function available(): bool;

	/**
	 * A summary of the client's last 90 days, as far as the viewer may read them.
	 *
	 * `summary` is null when hermiq is absent, when there was nothing to read,
	 * or when hermiq gave no answer; `recordsRead` says how many records went in.
	 *
	 * @param string                 $clientId The client's id.
	 * @param string                 $viewer   The user the summary is written for.
	 * @param DateTimeImmutable|null $now      The moment to judge at; now when null.
	 *
	 * @return array{available:bool, summary:string|null, recordsRead:int, generatedAt:string|null}
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.1
	 */
	public function summarise(string $clientId, string $viewer, ?DateTimeImmutable $now = null): array;
}//end interface
