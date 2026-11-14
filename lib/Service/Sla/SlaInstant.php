<?php

/**
 * Pipelinq SlaInstant.
 *
 * The moment an SLA evaluation runs at, and the holiday calendar it counts
 * business time against.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Sla
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Sla;

use DateTimeInterface;

/**
 * An evaluation instant under a policy's holiday calendar.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
final class SlaInstant {
	/**
	 * Constructor.
	 *
	 * @param string            $holidayCalendar The policy's holiday calendar.
	 * @param DateTimeInterface $now             The evaluation instant.
	 */
	public function __construct(
		public readonly string $holidayCalendar,
		public readonly DateTimeInterface $now,
	) {
	}//end __construct()
}//end class
