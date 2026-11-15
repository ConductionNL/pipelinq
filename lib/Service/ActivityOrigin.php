<?php

/**
 * Pipelinq ActivityOrigin.
 *
 * The entity a timeline activity item refers back to.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/specs/activity-timeline/spec.md#requirement-activity-events-must-cover-all-entity-types
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The entity type and id of the record a timeline was read for.
 *
 * @spec openspec/specs/activity-timeline/spec.md#requirement-activity-events-must-cover-all-entity-types
 */
final class ActivityOrigin {
	/**
	 * Constructor.
	 *
	 * @param string $entityType The originating entityType.
	 * @param string $entityId   The originating entityId.
	 */
	public function __construct(
		public readonly string $entityType,
		public readonly string $entityId,
	) {
	}//end __construct()
}//end class
