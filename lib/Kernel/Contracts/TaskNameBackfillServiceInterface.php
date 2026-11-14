<?php

/**
 * Contract of TaskNameBackfillService
 *
 * The public surface of TaskNameBackfillService that other components call, so they depend
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
 * Contract of TaskNameBackfillService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TaskNameBackfillServiceInterface {

	/**
	 * Name every task that has no name yet, silently.
	 *
	 * `status` is `done` when the run completed, or the reason it wrote nothing
	 * (or stopped): `not-configured`, `no-silent-path`, `scope-inactive`,
	 * `read-failed`.
	 *
	 * @return array{status: string, named: int, skipped: int, failed: int} The outcome.
	 *
	 * @spec openspec/specs/repair-steps/spec.md#requirement-existing-tasks-are-named-after-their-subject-without-telling-anyone
	 */
	public function backfill(): array;
}//end interface
