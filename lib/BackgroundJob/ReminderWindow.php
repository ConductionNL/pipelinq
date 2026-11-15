<?php

/**
 * Pipelinq ReminderWindow.
 *
 * The start-time window a reminder cycle sends 24-hour reminders for.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

/**
 * The ISO 8601 lower and upper bound of the booking start times a reminder
 * cycle covers.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
final class ReminderWindow {
	/**
	 * Constructor.
	 *
	 * @param string $startIso The lower bound.
	 * @param string $endIso   The upper bound.
	 */
	public function __construct(
		public readonly string $startIso,
		public readonly string $endIso,
	) {
	}//end __construct()
}//end class
