<?php

/**
 * Pipelinq TimelineFilter.
 *
 * What an activity timeline is narrowed to.
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
 * @spec openspec/specs/activity-timeline/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The activity types a timeline shows (null for all) and its date bounds.
 *
 * @spec openspec/specs/activity-timeline/spec.md
 */
final class TimelineFilter {
	/**
	 * Constructor.
	 *
	 * @param array<int,string>|null $types  The accepted activity types, or null for all.
	 * @param DateBounds             $bounds The date bounds.
	 */
	public function __construct(
		public readonly ?array $types,
		public readonly DateBounds $bounds,
	) {
	}//end __construct()
}//end class
