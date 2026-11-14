<?php

/**
 * Contract of CronExpressionHelper
 *
 * The public surface of CronExpressionHelper that other components call, so they depend
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
 * Contract of CronExpressionHelper.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CronExpressionHelperInterface {

	/**
	 * Whether a cron expression matches a given minute.
	 *
	 * Evaluates all five fields against the supplied time (truncated to the
	 * minute). Day-of-month and day-of-week use cron's OR semantics only when
	 * both are restricted; otherwise the restricted field applies.
	 *
	 * @param string $expression The cron expression.
	 * @param \DateTimeInterface $when The instant to test.
	 *
	 * @return bool True when the schedule fires at this minute.
	 *
	 * @spec openspec/changes/archive/2026-06-14-bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-01
	 */
	public function isDue(string $expression, \DateTimeInterface $when): bool;

	/**
	 * The first minute after a moment at which a cron expression fires.
	 *
	 * Parses the expression once and steps a day at a time while the day does
	 * not match, an hour at a time while the hour does not, then by the minute.
	 *
	 * @param string $expression The cron expression.
	 * @param \DateTimeInterface $from The moment after which to look.
	 * @param int $horizonDays How far ahead to look.
	 *
	 * @return DateTimeImmutable|null The next firing minute, or null when the
	 *                                  expression is invalid or never fires in the horizon.
	 *
	 * @spec openspec/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
	 */
	public function nextRunAfter(string $expression, \DateTimeInterface $from, int $horizonDays=400): ?DateTimeImmutable;
}//end interface
