<?php

/**
 * Pipelinq RemainingMinutes.
 *
 * How much of an SLA duration is still to be placed, and from when.
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
 * The instant a deadline walk has reached and the business minutes it still has
 * to place from there.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
final class RemainingMinutes {
	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable $from    The instant reached.
	 * @param int               $minutes The minutes still to place.
	 */
	public function __construct(
		public readonly DateTimeImmutable $from,
		public readonly int $minutes,
	) {
	}//end __construct()
}//end class
