<?php

/**
 * Pipelinq NoticedObject.
 *
 * The Pipelinq object a notification is about.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/notifications-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * The object type and id a notification links to.
 *
 * @spec openspec/specs/notifications-activity/spec.md
 */
final class NoticedObject {
	/**
	 * Constructor.
	 *
	 * @param string $type The object type (lead, request, task, ...), or ''.
	 * @param string $id   The object id, or ''.
	 */
	public function __construct(
		public readonly string $type,
		public readonly string $id,
	) {
	}//end __construct()
}//end class
