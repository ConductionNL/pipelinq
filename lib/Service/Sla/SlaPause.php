<?php

/**
 * Pipelinq SlaPause.
 *
 * One paused stretch of an SLA timer being resumed: when it paused, when it
 * resumes, and the holiday calendar business time is counted against.
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
 * A pause that ends now.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
final class SlaPause {
	/**
	 * Constructor.
	 *
	 * @param DateTimeInterface $pausedAt        When the timer paused.
	 * @param DateTimeInterface $resumedAt       When the timer resumes.
	 * @param string            $holidayCalendar The policy's holiday calendar.
	 */
	public function __construct(
		public readonly DateTimeInterface $pausedAt,
		public readonly DateTimeInterface $resumedAt,
		public readonly string $holidayCalendar,
	) {
	}//end __construct()
}//end class
