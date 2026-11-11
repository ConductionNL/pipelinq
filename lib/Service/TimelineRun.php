<?php

/**
 * Pipelinq TimelineRun.
 *
 * One build of an activity timeline.
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
 * The entity and the filter one timeline build collects activities for.
 *
 * @spec openspec/specs/activity-timeline/spec.md
 */
final class TimelineRun {
	/**
	 * Constructor.
	 *
	 * @param TimelineEntity $entity The entity.
	 * @param TimelineFilter $filter The types and date bounds.
	 */
	public function __construct(
		public readonly TimelineEntity $entity,
		public readonly TimelineFilter $filter,
	) {
	}//end __construct()
}//end class
