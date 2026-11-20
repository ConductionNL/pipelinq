<?php

/**
 * Pipelinq DaySpan.
 *
 * The part of one day an elapsed-time count looks at.
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

use DateTimeImmutable;

/**
 * From an instant on one day up to a moment (as a Unix timestamp) that may lie on a
 * later day: the part of that day an elapsed-time count charges.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
final class DaySpan {
	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $from       The instant the span starts at.
	 * @param int               $untilStamp The Unix timestamp the count ends at.
	 */
	public function __construct(
		public readonly DateTimeImmutable $from,
		public readonly int $untilStamp,
	) {
	}//end __construct()
}//end class
