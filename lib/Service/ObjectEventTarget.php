<?php

/**
 * Pipelinq ObjectEventTarget.
 *
 * The object an activity and notification event is about.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The title, id and assignee of the object an event is about, and its type
 * when the event names one.
 *
 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-3
 */
final class ObjectEventTarget {
	/**
	 * Constructor.
	 *
	 * @param string $title      The object's title.
	 * @param string $objectId   The object's id.
	 * @param string $assignee   The assignee user id; empty for none.
	 * @param string $entityType The entity type; empty when the event names none.
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $objectId,
		public readonly string $assignee,
		public readonly string $entityType = '',
	) {
	}//end __construct()

	/**
	 * The user the activity is for: the assignee, or null when there is none.
	 *
	 * @return string|null The assignee, or null.
	 *
	 * @spec openspec/changes/archive/2026-06-14-reverse-2026-05-26-be-events/tasks.md#task-3
	 */
	public function affectedUser(): ?string {
		if ($this->assignee !== '') {
			return $this->assignee;
		}

		return null;
	}//end affectedUser()
}//end class
