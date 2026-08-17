<?php

/**
 * Pipelinq ObjectOwnerAccessPolicy.
 *
 * Per-object authorization for objects that carry an owner field: the caller
 * must be the owner, or belong to a privileged group (ADR-005, OWASP A01:2021).
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
 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Owner-or-privileged-group authorization for a loaded register object.
 *
 * This is the single implementation of the model pipelinq already expressed in
 * `ContractController::isAuthorized()`: the caller owns the object, or holds a
 * privileged group membership. It is deliberately narrow — it authorizes only
 * objects whose schema actually declares an owner field. At the time of writing
 * `contract` (`ownerId`) is the only pipelinq schema that does; every other
 * schema has no ownership concept, and guarding those requires a product
 * decision about what "owns" a ticket, a client or a loyalty account rather
 * than a new predicate here.
 *
 * Fails closed: an object with no owner value authorizes nobody but a
 * privileged-group member.
 *
 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
 */
class ObjectOwnerAccessPolicy {
	/**
	 * Groups whose members may act on any owned object.
	 *
	 * @var array<int, string>
	 */
	public const PRIVILEGED_GROUPS = ['admin', 'sales'];

	/**
	 * App config key naming an ADDITIONAL group whose members are privileged.
	 *
	 * PRIVILEGED_GROUPS above is a compile-time list, which is fine for the two
	 * groups this app has always assumed but cannot express "our CRM users live
	 * in a group called something else". Deployments that use a different name
	 * set this key; it is additive, so the built-in list keeps working and no
	 * existing caller changes behaviour.
	 *
	 * Empty by default, matching every other group key in this app
	 * (`pos_group`, `pos_manager_group`, `billing_handoff_manager_group`,
	 * `avg_handler_group` …): an unconfigured instance grants nothing extra
	 * rather than granting everything.
	 *
	 * @var string
	 */
	public const CRM_GROUP_KEY = 'crm_group';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager The NC group manager.
	 * @param IAppConfig    $appConfig    The app config.
	 */
	public function __construct(
		private IGroupManager $groupManager,
		private IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Whether the caller may read or mutate this object.
	 *
	 * @param string $uid The caller user ID.
	 * @param array<string,mixed> $object The loaded object.
	 * @param string $ownerField The property holding the owner UID.
	 *
	 * @return bool True when the caller owns the object or is privileged.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
	 */
	public function mayAccess(string $uid, array $object, string $ownerField = 'ownerId'): bool {
		if ($uid === '') {
			return false;
		}

		$owner = trim((string)($object[$ownerField] ?? ''));
		if ($owner !== '' && $owner === $uid) {
			return true;
		}

		return $this->isPrivileged(uid: $uid);
	}//end mayAccess()

	/**
	 * Whether the caller belongs to any privileged group.
	 *
	 * @param string $uid The caller user ID.
	 *
	 * @return bool True when the caller is privileged.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-lifecycle-management
	 */
	public function isPrivileged(string $uid): bool {
		if ($uid === '') {
			return false;
		}

		foreach (self::PRIVILEGED_GROUPS as $group) {
			if ($this->groupManager->isInGroup($uid, $group) === true) {
				return true;
			}
		}

		// Deployment-configured CRM group, additive to the built-in list.
		$configured = $this->appConfig->getValueString(Application::APP_ID, self::CRM_GROUP_KEY, '');
		if ($configured !== '' && $this->groupManager->isInGroup($uid, $configured) === true) {
			return true;
		}

		return false;
	}//end isPrivileged()
}//end class
