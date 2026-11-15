<?php

/**
 * Pipelinq PosCartAccess.
 *
 * Whether the signed-in user may still change a sale at the till.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\PosCart
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/archive/2026-11-12-till-server-priced-lines/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\PosCart;

use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IUserSession;

/**
 * Refuses a user who may not change a sale, and a sale that is no longer open.
 *
 * The user rule is the lifecycle guard's (PosAccessPolicy::canAccessTransaction).
 *
 * @spec openspec/changes/archive/2026-11-12-till-server-priced-lines/tasks.md#task-2
 */
class PosCartAccess {
	/**
	 * The statuses of a sale still being rung up.
	 */
	private const OPEN = ['draft', 'parked'];

	/**
	 * Constructor.
	 *
	 * @param PosAccessPolicy $policy      Who may change a sale.
	 * @param IUserSession    $userSession The signed-in user.
	 *
	 * @spec openspec/changes/archive/2026-11-12-till-server-priced-lines/tasks.md#task-2
	 */
	public function __construct(
		private readonly PosAccessPolicy $policy,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Refuse a user who may not change the sale, and a sale that is no longer open.
	 *
	 * @param array<string, mixed> $sale The sale.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException  When the user may not change the sale.
	 * @throws OCSBadRequestException When the sale is no longer open.
	 *
	 * @spec openspec/changes/archive/2026-11-12-till-server-priced-lines/tasks.md#task-2
	 */
	public function assertMayChange(array $sale): void {
		$userId = (string)($this->userSession->getUser()?->getUID() ?? '');
		if ($this->policy->canAccessTransaction(object: $sale, userId: $userId) === false) {
			throw new OCSForbiddenException('No access to this sale');
		}

		if (in_array((string)($sale['status'] ?? ''), self::OPEN, true) === false) {
			throw new OCSBadRequestException('The sale is no longer open');
		}
	}//end assertMayChange()
}//end class
