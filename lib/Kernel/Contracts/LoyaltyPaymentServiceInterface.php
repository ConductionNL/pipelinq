<?php

/**
 * Contract of LoyaltyPaymentService
 *
 * The public surface of LoyaltyPaymentService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of LoyaltyPaymentService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LoyaltyPaymentServiceInterface {

	/**
	 * Debit points from an account to pay part of a sale.
	 *
	 * @param string $accountId        The loyalty account UUID.
	 * @param int    $points           The points to spend.
	 * @param string $posTransactionId The sale the points pay for.
	 *
	 * @return array{points: int, amount: float, currentBalance: int} What was spent and what is left.
	 *
	 * @throws RuntimeException When the account, the point value or the balance does not allow it.
	 *
	 * @spec openspec/specs/pos-transactions/spec.md#requirement-loyalty-points-can-pay-part-of-a-sale
	 */
	public function payWithPoints(string $accountId, int $points, string $posTransactionId): array;
}//end interface
