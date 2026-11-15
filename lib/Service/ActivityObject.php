<?php

/**
 * Pipelinq ActivityObject.
 *
 * The Pipelinq object an activity is about, and who it is addressed to.
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
 * The object type and id an activity links to, and its affected user.
 *
 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-capture-all-interaction-types
 */
final class ActivityObject {
	/**
	 * Constructor.
	 *
	 * @param string      $objectType   The object type.
	 * @param string      $objectId     The object ID.
	 * @param string|null $affectedUser The affected user or null.
	 */
	public function __construct(
		public readonly string $objectType,
		public readonly string $objectId,
		public readonly ?string $affectedUser = null,
	) {
	}//end __construct()

	/**
	 * Who the activity is addressed to: the affected user, else the author; '' for nobody.
	 *
	 * @param string $author The author's UID, or an empty string.
	 *
	 * @return string The recipient UID, or an empty string.
	 *
	 * @spec openspec/specs/activity-timeline/spec.md#requirement-timeline-must-capture-all-interaction-types
	 */
	public function recipient(string $author): string {
		if ($this->affectedUser !== null && $this->affectedUser !== '') {
			return $this->affectedUser;
		}

		return $author;
	}//end recipient()
}//end class
