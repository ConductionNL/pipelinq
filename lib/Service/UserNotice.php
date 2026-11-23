<?php

/**
 * Pipelinq UserNotice.
 *
 * The subject and parameters of one Pipelinq notification.
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
 * The subject and parameters of one notification a user receives.
 *
 * @spec openspec/specs/notifications-activity/spec.md
 */
final class UserNotice {
	/**
	 * Constructor.
	 *
	 * @param string               $subject    The notification subject.
	 * @param array<string, mixed> $parameters The notification parameters.
	 */
	public function __construct(
		public readonly string $subject,
		public readonly array $parameters,
	) {
	}//end __construct()

	/**
	 * Whether the given user is the notice's author (its `author` parameter).
	 *
	 * @param string $userId The user.
	 *
	 * @return bool True when the user caused the notice.
	 *
	 * @spec openspec/specs/notifications-activity/spec.md
	 */
	public function isBy(string $userId): bool {
		return ($this->parameters['author'] ?? null) === $userId;
	}//end isBy()
}//end class
