<?php

/**
 * Pipelinq PosAccessPolicy.
 *
 * Shared authorization predicate for the POS lifecycle guards. Centralises the
 * "cashier-owner OR POS-group member OR Nextcloud admin" rule used to guard the
 * cashier-level transitions (confirm/settle/park/resume) and the
 * "POS manager OR admin" rule used to gate refund / refund-completion. Fails
 * closed: an empty user, or an unconfigured group, never grants access by
 * default beyond the explicit ownership / admin checks.
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
 *
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IGroupManager;

/**
 * Authorization predicates shared by the POS lifecycle guards.
 *
 * The cashier-level predicate closes the IDOR: only the transaction's own
 * cashier, a member of the configured POS group, or a Nextcloud admin may drive
 * a cashier-level transition on a given object. The manager predicate gates
 * refund and refund completion to the configured manager group or admins.
 *
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.1
 */
class PosAccessPolicy {
	/**
	 * App-config key for the POS group (cashier-level access).
	 *
	 * @var string
	 */
	public const POS_GROUP_KEY = 'pos_group';

	/**
	 * Default POS group slug when none is configured.
	 *
	 * @var string
	 */
	public const POS_GROUP_DEFAULT = 'pos';

	/**
	 * App-config key for the POS manager group (refund-level access).
	 *
	 * @var string
	 */
	public const MANAGER_GROUP_KEY = 'pos_manager_group';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param IGroupManager $groupManager The group manager.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether a user may drive a cashier-level transition on a transaction.
	 *
	 * Grants access when the user is the transaction's own cashier, a member of
	 * the configured POS group (default `pos`), or a Nextcloud admin. Fails
	 * closed for an empty user.
	 *
	 * @param array<string, mixed> $object The posTransaction object payload.
	 * @param string $userId The acting user UID.
	 *
	 * @return bool Whether the user may transition this transaction.
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.1
	 */
	public function canAccessTransaction(array $object, string $userId): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		$cashier = (string)($object['cashier'] ?? '');
		if ($cashier !== '' && $cashier === $userId) {
			return true;
		}

		$posGroup = $this->appConfig->getValueString(
			Application::APP_ID,
			self::POS_GROUP_KEY,
			self::POS_GROUP_DEFAULT
		);
		if ($posGroup === '') {
			return false;
		}

		return $this->groupManager->isInGroup($userId, $posGroup);
	}//end canAccessTransaction()

	/**
	 * Whether a user is a POS operator (may use the shared POS surfaces).
	 *
	 * Grants access to a member of the configured POS group (default `pos`) or a
	 * Nextcloud admin. Used to gate the catalogue surfaces (barcode lookup /
	 * price resolution) that are not tied to a single owned object but are still
	 * a cashier capability rather than an any-authenticated-user one. Fails
	 * closed.
	 *
	 * @param string $userId The acting user UID.
	 *
	 * @return bool Whether the user may use the POS surfaces.
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#4.2
	 */
	public function isPosUser(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		$posGroup = $this->appConfig->getValueString(
			Application::APP_ID,
			self::POS_GROUP_KEY,
			self::POS_GROUP_DEFAULT
		);
		if ($posGroup === '') {
			return false;
		}

		return $this->groupManager->isInGroup($userId, $posGroup);
	}//end isPosUser()

	/**
	 * Whether a user is a POS manager (refund / refund-completion authority).
	 *
	 * A manager is a member of the configured manager group
	 * (`pos_manager_group`) or a Nextcloud administrator. Fails closed: when no
	 * group is configured, only NC admins qualify.
	 *
	 * @param string $userId The acting user UID.
	 *
	 * @return bool Whether the user is a POS manager.
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.1
	 */
	public function isManager(string $userId): bool {
		if ($userId === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		$managerGroup = $this->appConfig->getValueString(Application::APP_ID, self::MANAGER_GROUP_KEY, '');
		if ($managerGroup === '') {
			return false;
		}

		return $this->groupManager->isInGroup($userId, $managerGroup);
	}//end isManager()
}//end class
