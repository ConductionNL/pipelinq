<?php

/**
 * Pipelinq ClientBillingPeriod.
 *
 * The client and inclusive date range a time-billing batch is selected for.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/time-approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A client and the inclusive ISO 8601 period its time entries are billed for.
 *
 * @spec openspec/specs/time-approval-workflow/spec.md
 */
final class ClientBillingPeriod {
	/**
	 * Constructor.
	 *
	 * @param string $clientId    The client UUID.
	 * @param string $periodStart The period start date (ISO 8601, inclusive).
	 * @param string $periodEnd   The period end date (ISO 8601, inclusive).
	 */
	public function __construct(
		public readonly string $clientId,
		public readonly string $periodStart,
		public readonly string $periodEnd,
	) {
	}//end __construct()

	/**
	 * Whether a date lies inside the period; an empty date never does.
	 *
	 * @param string $date The ISO 8601 date.
	 *
	 * @return bool True when the date is inside the period.
	 *
	 * @spec openspec/specs/time-approval-workflow/spec.md
	 */
	public function contains(string $date): bool {
		return ($date === '' || $date < $this->periodStart || $date > $this->periodEnd) === false;
	}//end contains()
}//end class
