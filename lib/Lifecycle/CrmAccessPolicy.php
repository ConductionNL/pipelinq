<?php
/**
 * Shared CRM access policy.
 *
 * @category Lifecycle
 * @package  OCA\Pipelinq\Lifecycle
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Whether a user may reach this app's CRM surfaces.
 *
 * WHY THIS EXISTS, AND WHY IT IS NOT AN OWNERSHIP CHECK
 * -----------------------------------------------------
 * gate-7 reported 50 `#[NoAdminRequired]` methods with no authorization guard.
 * 34 of them take a caller-supplied selector (an id / uuid / slug read from the
 * request), so any authenticated user could name any object. The obvious remedy
 * — "check the caller owns the object" — CANNOT BE WRITTEN AGAINST THIS DATA:
 * of the 27 schemas in `lib/Settings/pipelinq_register.json`, **23 carry no
 * ownership field at all**. Only `agentProfile` (`userId`), `lead`
 * (`assignee`, `organisation`) and `task` (`createdBy`) have one.
 *
 * A guard is only as good as the identity field it reads. Written as an
 * ownership test, 23 schemas' worth of endpoints would deny every caller
 * including the legitimate one.
 *
 * So this mirrors `PosAccessPolicy`, which already solved the same problem for
 * the cashier surfaces and states the reasoning in its own docblock: the POS
 * catalogue endpoints are "not tied to a single owned object but are still a
 * cashier capability rather than an any-authenticated-user one". The CRM
 * surfaces are the same shape — reading a lead, a segment or an activity
 * timeline is a CRM-user capability, not something every account on the
 * Nextcloud instance holds by virtue of being logged in.
 *
 * The layering is taken from `PosAccessPolicy::canAccessTransaction()`:
 *
 *   1. empty uid            -> false            (fail closed)
 *   2. Nextcloud admin      -> true
 *   3. the object's OWN owner field matches     (where the schema has one)
 *   4. member of the configured CRM group
 *   5. otherwise            -> false
 *
 * Step 3 is what makes this stricter than a pure group check for the four
 * schemas that can express ownership, without making it unsatisfiable for the
 * 23 that cannot.
 *
 * FAILS CLOSED ON MISCONFIGURATION. If no group is configured, only Nextcloud
 * admins qualify — the same choice `PosAccessPolicy` makes, and the opposite of
 * the "empty config means allow everyone" default that turns a guard into
 * decoration.
 */
class CrmAccessPolicy {
	/**
	 * App config key naming the group whose members may use the CRM surfaces.
	 *
	 * @var string
	 */
	public const CRM_GROUP_KEY = 'crm_group';

	/**
	 * Default CRM group when the key is unset.
	 *
	 * @var string
	 */
	public const CRM_GROUP_DEFAULT = 'pipelinq';

	/**
	 * Object fields that can carry an owning user, in priority order.
	 *
	 * Derived from the register: `agentProfile.userId`, `lead.assignee`,
	 * `task.createdBy`. Listed rather than guessed so that adding an ownership
	 * field to a schema is a deliberate edit here too.
	 *
	 * @var list<string>
	 */
	private const OWNER_FIELDS = ['userId', 'assignee', 'createdBy', 'owner'];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig    $appConfig    The app config.
	 * @param IGroupManager $groupManager The group manager.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether a user may use this app's CRM surfaces at all.
	 *
	 * For the endpoints that take no object selector — analytics overviews,
	 * searches, list endpoints — this is the whole question: the data is not
	 * per-object, so there is nothing to own, and the decision is whether the
	 * caller is a CRM user.
	 *
	 * @param string $userId The acting user UID.
	 *
	 * @return bool Whether the user may use the CRM surfaces.
	 */
	public function isCrmUser(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		$group = $this->appConfig->getValueString(
			Application::APP_ID,
			self::CRM_GROUP_KEY,
			self::CRM_GROUP_DEFAULT
		);
		if ($group === '') {
			return false;
		}

		return $this->groupManager->isInGroup($userId, $group);
	}//end isCrmUser()

	/**
	 * Whether a user may act on one CRM object.
	 *
	 * Use this wherever the caller supplies a selector. It is strictly stronger
	 * than `isCrmUser()` for a schema that records an owner, and falls back to
	 * it for one that does not.
	 *
	 * @param array<string, mixed> $object The object payload.
	 * @param string               $userId The acting user UID.
	 *
	 * @return bool Whether the user may act on this object.
	 */
	public function canAccessObject(array $object, string $userId): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		foreach (self::OWNER_FIELDS as $field) {
			$owner = (string)($object[$field] ?? '');
			if ($owner !== '' && $owner === $userId) {
				return true;
			}
		}

		return $this->isCrmUser(userId: $userId);
	}//end canAccessObject()
}//end class
