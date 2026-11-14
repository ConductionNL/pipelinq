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
 * @spec openspec/specs/pos-transactions/spec.md#requirement-loyalty-points-can-pay-part-of-a-sale
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Kernel\Contracts\LoyaltyPaymentServiceInterface;
use RuntimeException;

/**
 * Loyalty points as a payment part.
 *
 * @spec openspec/specs/pos-transactions/spec.md#requirement-loyalty-points-can-pay-part-of-a-sale
 */
class LoyaltyPaymentService implements LoyaltyPaymentServiceInterface {
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
	 * @spec openspec/specs/pos-transactions/spec.md#requirement-loyalty-points-can-pay-part-of-a-sale
	 */
	public function payWithPoints(string $accountId, int $points, string $posTransactionId): array {
		if ($points <= 0 || $posTransactionId === '') {
			throw new RuntimeException('Give the points to spend and the sale they pay for.');
		}

		$balance = $this->spendableBalance(accountId: $accountId, points: $points);
		$pointValue = $this->pointValueOf(programmeId: $balance['programmeId']);

		$source = ['posTransactionId' => $posTransactionId, 'kind' => 'pos-payment'];
		$this->ledgerService->forOperation(operationId: 'pos-payment:'.$posTransactionId)
			->debitPoints(accountId: $accountId, amount: $points, redemptionId: $posTransactionId, sourceDocument: $source, processedBy: 'pos-payment');

		return [
			'points'         => $points,
			'amount'         => round($points * $pointValue, 2),
			'currentBalance' => $balance['balance'] - $points,
		];
	}//end payWithPoints()

	/**
	 * The account's balance and programme, when it holds at least the points.
	 *
	 * @param string $accountId The loyalty account.
	 * @param int    $points    The points to spend.
	 *
	 * @return array{balance: int, programmeId: string}
	 *
	 * @throws RuntimeException When the account is not found or holds fewer points.
	 */
	private function spendableBalance(string $accountId, int $points): array {
		$account = $this->accountService->getAccount(accountId: $accountId);
		if ($account === null) {
			throw new RuntimeException('Account not found.');
		}

		$balance = (int)($account['currentBalance'] ?? 0);
		if ($points > $balance) {
			throw new RuntimeException('Insufficient balance.');
		}

		return ['balance' => $balance, 'programmeId' => (string)($account['programmeId'] ?? '')];
	}//end spendableBalance()

	/**
	 * The programme's value of one point.
	 *
	 * @param string $programmeId The programme.
	 *
	 * @return float
	 *
	 * @throws RuntimeException When the programme gives points no value.
	 */
	private function pointValueOf(string $programmeId): float {
		$programme = $this->programmeService->getProgramme(programmeId: $programmeId);
		$pointValue = (float)($programme['pointValue'] ?? 0);
		if ($pointValue <= 0) {
			throw new RuntimeException('The programme gives points no value.');
		}

		return $pointValue;
	}//end pointValueOf()
}//end class
