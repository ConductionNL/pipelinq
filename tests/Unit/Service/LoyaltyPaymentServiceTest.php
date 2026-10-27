<?php

/**
 * Tests for LoyaltyPaymentService: loyalty points spent as part of a payment
 * at the cash register.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\LoyaltyPaymentService;
use OCA\Pipelinq\Service\LoyaltyProgrammeService;
use OCA\Pipelinq\Service\PointsLedgerService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Loyalty points as a payment part.
 */
class LoyaltyPaymentServiceTest extends TestCase {

	/**
	 * The account service mock.
	 *
	 * @var LoyaltyAccountService&MockObject
	 */
	private LoyaltyAccountService&MockObject $accounts;

	/**
	 * The programme service mock.
	 *
	 * @var LoyaltyProgrammeService&MockObject
	 */
	private LoyaltyProgrammeService&MockObject $programmes;

	/**
	 * The ledger service mock.
	 *
	 * @var PointsLedgerService&MockObject
	 */
	private PointsLedgerService&MockObject $ledger;

	/**
	 * The service under test.
	 *
	 * @var LoyaltyPaymentService
	 */
	private LoyaltyPaymentService $service;

	/**
	 * Wire the service with an account of 340 points in a programme where a
	 * point is worth 5 cents.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->accounts   = $this->createMock(originalClassName: LoyaltyAccountService::class);
		$this->programmes = $this->createMock(originalClassName: LoyaltyProgrammeService::class);
		$this->ledger     = $this->createMock(originalClassName: PointsLedgerService::class);
		$this->accounts->method('getAccount')->willReturn(['programmeId' => 'prog-1', 'currentBalance' => 340, 'status' => 'active']);
		$this->programmes->method('getProgramme')->willReturn(['pointValue' => 0.05, 'currency' => 'EUR']);
		$this->service = new LoyaltyPaymentService(
			accountService: $this->accounts,
			programmeService: $this->programmes,
			ledgerService: $this->ledger
		);

	}//end setUp()

	/**
	 * Spending 200 points pays 10 euro and debits the points against the sale.
	 *
	 * @return void
	 */
	public function testPayWithPointsDebitsAndReturnsTheAmount(): void {
		$this->ledger->expects($this->once())
			->method('debitPoints')
			->with(
				'acc-1',
				200,
				'tx-1',
				['posTransactionId' => 'tx-1', 'kind' => 'pos-payment'],
				'pos-payment'
			)
			->willReturn([]);

		$result = $this->service->payWithPoints(accountId: 'acc-1', points: 200, posTransactionId: 'tx-1');

		$this->assertSame(expected: 200, actual: $result['points']);
		$this->assertSame(expected: 10.0, actual: $result['amount']);
		$this->assertSame(expected: 140, actual: $result['currentBalance']);

	}//end testPayWithPointsDebitsAndReturnsTheAmount()

	/**
	 * More points than the account holds are refused before anything is debited.
	 *
	 * @return void
	 */
	public function testPayWithPointsRefusesMoreThanTheBalance(): void {
		$this->ledger->expects($this->never())->method('debitPoints');
		$this->expectException(exception: RuntimeException::class);

		$this->service->payWithPoints(accountId: 'acc-1', points: 400, posTransactionId: 'tx-1');

	}//end testPayWithPointsRefusesMoreThanTheBalance()

	/**
	 * A programme without a point value cannot pay.
	 *
	 * @return void
	 */
	public function testPayWithPointsNeedsAPointValue(): void {
		$programmes = $this->createMock(originalClassName: LoyaltyProgrammeService::class);
		$programmes->method('getProgramme')->willReturn(['pointValue' => 0]);
		$service = new LoyaltyPaymentService(
			accountService: $this->accounts,
			programmeService: $programmes,
			ledgerService: $this->ledger
		);
		$this->ledger->expects($this->never())->method('debitPoints');
		$this->expectException(exception: RuntimeException::class);

		$service->payWithPoints(accountId: 'acc-1', points: 20, posTransactionId: 'tx-1');

	}//end testPayWithPointsNeedsAPointValue()
}//end class
