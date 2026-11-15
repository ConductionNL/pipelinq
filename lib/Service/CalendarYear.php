<?php

/**
 * Pipelinq CalendarYear.
 *
 * One year of a (possibly composite) holiday calendar.
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
 * A calendar name (a slug, or a comma-separated composite) and a year.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
final class CalendarYear {
	/**
	 * Constructor.
	 *
	 * @param string $calendarName The calendar slug, or comma-separated composite.
	 * @param int    $year         The calendar year.
	 */
	public function __construct(
		public readonly string $calendarName,
		public readonly int $year,
	) {
	}//end __construct()

	/**
	 * The single calendar names of the (composite) calendar name, trimmed,
	 * empty parts left out.
	 *
	 * @return array<int, string> The names, keyed by their position.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function names(): array {
		return array_filter(array_map('trim', explode(',', $this->calendarName)));
	}//end names()
}//end class
