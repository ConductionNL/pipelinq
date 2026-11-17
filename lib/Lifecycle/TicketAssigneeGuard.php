<?php

/**
 * Pipelinq TicketAssigneeGuard.
 *
 * OpenRegister lifecycle guard for the ticket `start` and `reject`
 * transitions: the ticket's own assignee, a member of the sales group or a
 * Nextcloud admin may run them; everyone else is refused. Referenced from the
 * ticket schema's x-openregister-lifecycle.transitions[*].requires in
 * lib/Settings/register.d/99-unify-ticket-supertype.json.
 *
 * @category Lifecycle
 * @package  OCA\Pipelinq\Lifecycle
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/request-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCP\IGroupManager;

/**
 * Assignee-or-staff authorization for picking up and rejecting a ticket.
 *
 * A group-only `authorization` list on the transition could not express "the
 * assignee", so an assignee outside the sales group (a CRM-group handler) was
 * refused when picking up their own ticket. This guard admits the assignee as
 * well. Fails closed: an empty caller, or a ticket without an assignee,
 * admits only a sales or admin member.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) GuardResult exposes only the static
 *  allow()/deny() factories mandated by OpenRegister's contract.
 *
 * @spec openspec/specs/request-management/spec.md
 */
class TicketAssigneeGuard implements LifecycleGuardInterface {
	/**
	 * The group whose members may start or reject any ticket, as admins may.
	 *
	 * @var string
	 */
	public const STAFF_GROUP = 'sales';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager The NC group manager.
	 */
	public function __construct(
		private IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Allow the transition for the ticket's assignee or a staff member.
	 *
	 * @param array<string, mixed> $object The ticket payload.
	 * @param string $action The transition action ('start'|'reject').
	 * @param string $userId The acting user UID.
	 *
	 * @return GuardResult Allow for the assignee or a staff member; deny otherwise.
	 *
	 * @spec openspec/specs/request-management/spec.md
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->mayAct(object: $object, userId: $userId) === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny(
			'Alleen de behandelaar van dit ticket of een lid van de verkoopgroep mag deze stap uitvoeren.'
		);
	}//end check()

	/**
	 * Whether the caller is the ticket's assignee or a staff member.
	 *
	 * @param array<string, mixed> $object The ticket payload.
	 * @param string $userId The acting user UID.
	 *
	 * @return bool True when the caller may act.
	 *
	 * @spec openspec/specs/request-management/spec.md
	 */
	private function mayAct(array $object, string $userId): bool {
		if ($userId === '') {
			return false;
		}

		$assignee = $object['assignee'] ?? '';
		if (is_string($assignee) === true && trim($assignee) !== '' && trim($assignee) === $userId) {
			return true;
		}

		if ($this->groupManager->isAdmin($userId) === true) {
			return true;
		}

		return $this->groupManager->isInGroup($userId, self::STAFF_GROUP) === true;
	}//end mayAct()
}//end class
