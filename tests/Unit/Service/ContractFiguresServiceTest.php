<?php

/**
 * Unit tests for ContractFiguresService.
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ContractFiguresService;
use OCA\Pipelinq\Service\RecurringRevenueService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A contract's figures, as board PqContract's tiles read them.
 */
class ContractFiguresServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var ContractFiguresService
	 */
	private ContractFiguresService $figures;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->figures = new ContractFiguresService(
			revenueService: new RecurringRevenueService(
				appConfig: $this->createMock(IAppConfig::class),
				container: $this->createMock(ContainerInterface::class),
				logger: $this->createMock(LoggerInterface::class),
			),
		);
	}//end setUp()

	/**
	 * The figures PqContract's tiles read: monthly and annual value, the
	 * notice date and the days to the end, and what was invoiced this year.
	 *
	 * @return void
	 */
	public function testContractFigures(): void {
		$contract = [
			'billingInterval' => 'annual',
			'valuePerInterval' => 42000,
			'startDate' => '2026-01-01',
			'endDate' => '2026-12-31',
			'noticePeriodDays' => 60,
			'invoiceHistory' => [
				['reference' => 'inv-1', 'sentAt' => '2026-01-02T09:00:00+00:00', 'sentBy' => 'pieter', 'amount' => 42000],
				['reference' => 'inv-0', 'sentAt' => '2025-01-03T09:00:00+00:00', 'sentBy' => 'pieter', 'amount' => 40000],
			],
		];

		$figures = $this->figures->figures(contract: $contract, today: new \DateTimeImmutable('2026-10-07'));

		$this->assertSame(3500.0, $figures['monthlyValue']);
		$this->assertSame(42000.0, $figures['annualValue']);
		$this->assertSame('2026-11-01', $figures['noticeDate']);
		$this->assertSame(85, $figures['daysToEnd']);
		$this->assertSame(42000.0, $figures['invoicedThisYear']);
		$this->assertSame(1, $figures['invoicesSent']);
		$this->assertSame(1, $figures['invoicesDue']);
	}//end testContractFigures()

	/**
	 * A quarterly contract that starts in April is due three invoices this
	 * year; one sent without a history reads the last send.
	 *
	 * @return void
	 */
	public function testContractFiguresCountsThePeriodsInTheYear(): void {
		$contract = [
			'billingInterval' => 'quarterly',
			'valuePerInterval' => 3000,
			'startDate' => '2026-04-01',
			'endDate' => '2027-03-31',
			'invoicedAt' => '2026-04-02T09:00:00+00:00',
		];

		$figures = $this->figures->figures(contract: $contract, today: new \DateTimeImmutable('2026-10-07'));

		$this->assertSame(1000.0, $figures['monthlyValue']);
		$this->assertSame(12000.0, $figures['annualValue']);
		$this->assertSame(3, $figures['invoicesDue']);
		$this->assertSame(1, $figures['invoicesSent']);
		$this->assertSame(3000.0, $figures['invoicedThisYear']);
		$this->assertNull($figures['noticeDate']);
	}//end testContractFiguresCountsThePeriodsInTheYear()
}//end class
