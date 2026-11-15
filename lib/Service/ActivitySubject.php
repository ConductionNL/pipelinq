<?php

/**
 * Pipelinq ActivitySubject.
 *
 * What a Pipelinq activity says: its subject, type and parameters.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-capture-all-interaction-types
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The subject, activity type and subject parameters of one activity event.
 *
 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-capture-all-interaction-types
 */
final class ActivitySubject {
	/**
	 * Constructor.
	 *
	 * @param string               $subject    The activity subject.
	 * @param string               $type       The activity type.
	 * @param array<string, mixed> $parameters The subject parameters.
	 */
	public function __construct(
		public readonly string $subject,
		public readonly string $type,
		public readonly array $parameters,
	) {
	}//end __construct()
}//end class
