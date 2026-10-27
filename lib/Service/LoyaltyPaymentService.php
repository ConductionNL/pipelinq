<?php

/**
 * Pipelinq LoyaltyPaymentService.
 *
 * Spends loyalty points as one part of a payment at the cash register: the
 * points are debited against the sale and are worth the programme's point
 * value each.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-sale-board-look/specs/pos-transactions/spec.md#requirement-loyalty-points-can-pay-part-of-a-sale
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use RuntimeException;

/**
 * Loyalty points as a payment part.
 */
class LoyaltyPaymentService {
	/**
	 * Constructor.
	 *
	 * @param LoyaltyAccountService   $accountService   The account service.
	 * @param LoyaltyProgrammeService $programmeService The programme service.
	 * @param PointsLedgerService     $ledgerService    The ledger service.
	 */
	public function __construct(
		private LoyaltyAccountService $accountService,
		private LoyaltyProgrammeService $programmeService,
		private PointsLedgerService $ledgerService,
	) {
	}//end __construct()

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
	 * @spec openspec/changes/pos-sale-board-look/specs/pos-transactions/spec.md#requirement-loyalty-points-can-pay-part-of-a-sale
	 */
	public function payWithPoints(string $accountId, int $points, string $posTransactionId): array {
		if ($points <= 0 || $posTransactionId === '') {
			throw new RuntimeException('Give the points to spend and the sale they pay for.');
		}

		$account = $this->accountService->getAccount(accountId: $accountId);
		if ($account === null) {
			throw new RuntimeException('Account not found.');
		}

		$balance = (int)($account['currentBalance'] ?? 0);
		if ($points > $balance) {
			throw new RuntimeException('Insufficient balance.');
		}

		$programme  = $this->programmeService->getProgramme(programmeId: (string)($account['programmeId'] ?? ''));
		$pointValue = (float)($programme['pointValue'] ?? 0);
		if ($pointValue <= 0) {
			throw new RuntimeException('The programme gives points no value.');
		}

		$this->ledgerService->debitPoints(
			accountId: $accountId,
			amount: $points,
			redemptionId: $posTransactionId,
			sourceDocument: ['posTransactionId' => $posTransactionId, 'kind' => 'pos-payment'],
			processedBy: 'pos-payment'
		);

		return [
			'points'         => $points,
			'amount'         => round($points * $pointValue, 2),
			'currentBalance' => $balance - $points,
		];
	}//end payWithPoints()
}//end class
