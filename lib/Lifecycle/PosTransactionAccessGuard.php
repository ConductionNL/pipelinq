<?php

/**
 * Pipelinq PosTransactionAccessGuard.
 *
 * OpenRegister lifecycle guard for the cashier-level posTransaction transitions
 * (settle, park, resume). Enforces that the caller is the transaction's own
 * cashier, a member of the configured POS group, or a Nextcloud admin — closing
 * the IDOR where any authenticated user could previously drive any transaction
 * by UUID. Referenced from the posTransaction schema's
 * x-openregister-lifecycle.transitions[*].requires in pipelinq_register.json.
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
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Guards the cashier-level posTransaction transitions (settle/park/resume).
 *
 * Allows only the transaction's own cashier, a POS-group member, or an admin.
 * Fails closed.
 *
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.2
 */
class PosTransactionAccessGuard implements LifecycleGuardInterface {
	/**
	 * Constructor.
	 *
	 * @param PosAccessPolicy $policy The shared POS access policy.
	 */
	public function __construct(
		private PosAccessPolicy $policy,
	) {
	}//end __construct()

	/**
	 * Authorise a cashier-level transition on a transaction.
	 *
	 * @param array<string, mixed> $object The posTransaction payload.
	 * @param string $action The transition action.
	 * @param string $userId The acting user UID.
	 *
	 * @return GuardResult Allow when the user owns the transaction, is a
	 *                     POS-group member, or is an admin; deny otherwise.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the
	 *  static allow()/deny() factories mandated by OpenRegister's contract.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action is part of the
	 *  LifecycleGuardInterface signature; this guard authorises every
	 *  cashier-level action identically.
	 *
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#2.2
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->policy->canAccessTransaction(object: $object, userId: $userId) === false) {
			return GuardResult::deny(
				'U mag deze transactie niet wijzigen. Alleen de eigen kassamedewerker, '
				. 'een lid van de POS-groep of een beheerder is gemachtigd.'
			);
		}

		return GuardResult::allow();
	}//end check()
}//end class
