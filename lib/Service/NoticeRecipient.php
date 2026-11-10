<?php

/**
 * Pipelinq NoticeRecipient.
 *
 * The user a Pipelinq notification goes to, and the object it is about.
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
 * The user a notification goes to and the object it is about.
 *
 * @spec openspec/specs/notifications-activity/spec.md
 */
final class NoticeRecipient {
	/**
	 * Constructor.
	 *
	 * @param string        $userId The user to notify.
	 * @param NoticedObject $object The object the notification is about.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly NoticedObject $object,
	) {
	}//end __construct()
}//end class
