<?php

/**
 * Pipelinq ConfiguredGroupGate.
 *
 * Admin-or-member checks against a group named in app config.
 *
 * @category Lifecycle
 * @package  OCA\Pipelinq\Lifecycle
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Decides whether a user is a Nextcloud administrator or a member of a group
 * named in app config. Fails closed: an empty user, or a setting that names no
 * group, never grants access beyond the explicit admin check.
 *
 * Built by the policy that owns the setting.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011
 */
final class ConfiguredGroupGate {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig    The app config.
	 * @param IGroupManager $groupManager The group manager.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether the user is an administrator or a member of the configured group.
	 *
	 * @param string          $userId The acting user UID.
	 * @param ConfiguredGroup $group  The setting naming the group.
	 *
	 * @return bool Whether the user is admitted.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011
	 */
	public function admits(string $userId, ConfiguredGroup $group): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		return $this->isMemberOf(userId: $userId, group: $group);
	}//end admits()

	/**
	 * Whether the user is a member of the configured group; false when it names none.
	 *
	 * @param string          $userId The acting user UID.
	 * @param ConfiguredGroup $group  The setting naming the group.
	 *
	 * @return bool Whether the user is a member.
	 *
	 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-011
	 */
	public function isMemberOf(string $userId, ConfiguredGroup $group): bool {
		$groupId = $this->appConfig->getValueString(AppIdentity::APP_ID, $group->key, $group->default);
		if ($groupId === '') {
			return false;
		}

		return $this->groupManager->isInGroup($userId, $groupId);
	}//end isMemberOf()
}//end class
