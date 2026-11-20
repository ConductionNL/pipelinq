<?php

/**
 * Contract of ClientRiskService
 *
 * The public surface of ClientRiskService that other components call, so they depend
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
 * Contract of ClientRiskService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ClientRiskServiceInterface {

	/**
	 * Assess every client, in batches, writing only those whose level or
	 * reasons changed.
	 *
	 * @param DateTimeImmutable|null $now The moment to judge at; now when null.
	 *
	 * @return array{written:int, unchanged:int}
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.3
	 */
	public function recomputeAll(?DateTimeImmutable $now = null): array;

	/**
	 * Assess one client and store the result when its level or reasons changed.
	 *
	 * @param string                 $clientId The client's id.
	 * @param DateTimeImmutable|null $now      The moment to judge at; now when null.
	 *
	 * @return bool Whether the client was written.
	 *
	 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.3
	 */
	public function recompute(string $clientId, ?DateTimeImmutable $now = null): bool;
}//end interface
