<?php

/**
 * Pipelinq SocialSpendBudget.
 *
 * Whether a charged social network call fits the tenant's spend budget.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

use OCA\Pipelinq\Service\Social\SocialNetworkAdapter;

/**
 * A free call always fits; a charged one asks the spend budget for the
 * tenant and the adapter's network. The host class provides a BudgetService
 * `$budget` property and a getTenantId() step.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait SocialSpendBudget {

	/**
	 * Whether the call may go within the spend budget.
	 *
	 * @param SocialNetworkAdapter $adapter The adapter.
	 * @param float                $cost    What one call costs.
	 *
	 * @return bool
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function withinBudget(SocialNetworkAdapter $adapter, float $cost): bool {
		if ($cost <= 0.0) {
			return true;
		}

		$allowed = $this->budget->canSend(
			tenantId: $this->getTenantId(),
			providerId: $adapter->network(),
			estimatedCostEur: $cost,
		);

		return $allowed === true;
	}//end withinBudget()
}//end trait
