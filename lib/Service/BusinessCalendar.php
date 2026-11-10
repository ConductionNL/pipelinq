<?php

/**
 * Pipelinq BusinessCalendar.
 *
 * The calendar an SLA clock runs on.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;


/**
 * A calendar mode (24x7, business-hours, extended-business-hours) and the
 * holiday calendar it skips.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
final class BusinessCalendar {
	/**
	 * Constructor.
	 *
	 * @param string $type            The calendar mode.
	 * @param string $holidayCalendar The holiday calendar slug.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $holidayCalendar,
	) {
	}//end __construct()
}//end class
