<?php

/**
 * OpenRegister BulkActionRegistrationEvent
 *
 * Declaration-only stub of BulkActionRegistrationEvent, for the unit suite and static analysis; the
 * real class wins whenever OpenRegister is installed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCP\EventDispatcher\Event;

/**
 * Dispatched when OpenRegister collects the apps' bulk actions.
 */
class BulkActionRegistrationEvent extends Event {

	/**
	 * The actions registered, in order.
	 *
	 * @var array<int, BulkActionInterface>
	 */
	private array $actions = [];

	/**
	 * Register one action.
	 *
	 * @param BulkActionInterface $action The action.
	 *
	 * @return void
	 */
	public function registerAction(BulkActionInterface $action): void {
		$this->actions[] = $action;
	}//end registerAction()

	/**
	 * The actions registered so far.
	 *
	 * @return array<int, BulkActionInterface> The actions.
	 */
	public function getActions(): array {
		return $this->actions;
	}//end getActions()
}//end class
