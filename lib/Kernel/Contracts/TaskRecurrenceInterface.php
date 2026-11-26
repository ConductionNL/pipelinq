<?php

/**
 * Contract of TaskRecurrence
 *
 * The public surface of TaskRecurrence that other components call, so they depend
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
 * Contract of TaskRecurrence.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 */
interface TaskRecurrenceInterface {

	/**
	 * Make the next occurrence of every repeating task that has come due.
	 *
	 * @param DateTimeImmutable|null $now The reference instant (default now).
	 *
	 * @return int How many occurrences were made.
	 *
	 * @spec openspec/changes/specify-capability-rows/specs/task-background-jobs/spec.md#requirement-a-task-with-a-repeat-schedule-comes-back-on-that-schedule
	 */
	public function run(?DateTimeImmutable $now=null): int;
}//end interface
