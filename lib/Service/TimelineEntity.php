<?php

/**
 * Pipelinq TimelineEntity.
 *
 * The entity an activity timeline or worklog belongs to.
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
 * An entity by its type (client|request|lead|contact) and its UUID.
 *
 * @spec openspec/specs/activity-timeline/spec.md
 */
final class TimelineEntity {
	/**
	 * Constructor.
	 *
	 * @param string $entityType The entity type.
	 * @param string $entityId   The entity UUID.
	 */
	public function __construct(
		public readonly string $entityType,
		public readonly string $entityId,
	) {
	}//end __construct()
}//end class
