<?php

/**
 * Unit tests for RecurringRevenueService.
 *
 * Covers interval normalization, MRR/ARR roll-up (one-off excluded, only
 * active+expiring counted), per-client recurring value, and per-period
 * renewal-rate + churned-MRR computation.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\RecurringRevenueService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Test suite for RecurringRevenueService.
 */
class RecurringRevenueServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var RecurringRevenueService
	 */
	private RecurringRevenueService $service;

	/**
	 * Build the service with stubbed dependencies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = new RecurringRevenueService(
			$this->createMock(IAppConfig::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * MRR normalizes intervals and excludes one-off; ARR is MRR × 12.
	 *
	 * @return void
	 */
	public function testMrrNormalizationAndArr(): void {
		$contracts = [
			['status' => 'active', 'billingInterval' => 'monthly', 'valuePerInterval' => 750],
			['status' => 'active', 'billingInterval' => 'quarterly', 'valuePerInterval' => 3000],
			['status' => 'active', 'billingInterval' => 'annual', 'valuePerInterval' => 12000],
			['status' => 'active', 'billingInterval' => 'one-off', 'valuePerInterval' => 5000],
		];

		$this->assertSame(2750.0, $this->service->computeMrr($contracts));
		$this->assertSame(33000.0, $this->service->computeArr($contracts));
	}//end testMrrNormalizationAndArr()

	/**
	 * Only active and expiring contracts contribute to MRR.
	 *
	 * @return void
	 */
	public function testOnlyActiveAndExpiringContribute(): void {
		$contracts = [
			['status' => 'active', 'billingInterval' => 'monthly', 'valuePerInterval' => 100],
			['status' => 'expiring', 'billingInterval' => 'monthly', 'valuePerInterval' => 50],
			['status' => 'draft', 'billingInterval' => 'monthly', 'valuePerInterval' => 999],
			['status' => 'churned', 'billingInterval' => 'monthly', 'valuePerInterval' => 999],
			['status' => 'renewed', 'billingInterval' => 'monthly', 'valuePerInterval' => 999],
		];

		$this->assertSame(150.0, $this->service->computeMrr($contracts));
	}//end testOnlyActiveAndExpiringContribute()

	/**
	 * Per-client recurring value sums only the client's active contracts.
	 *
	 * @return void
	 */
	public function testClientMrr(): void {
		$contracts = [
			['status' => 'active', 'clientRef' => 'c1', 'billingInterval' => 'monthly', 'valuePerInterval' => 750],
			['status' => 'active', 'clientRef' => 'c1', 'billingInterval' => 'annual', 'valuePerInterval' => 12000],
			['status' => 'active', 'clientRef' => 'c2', 'billingInterval' => 'monthly', 'valuePerInterval' => 500],
		];

		$this->assertSame(1750.0, $this->service->computeClientMrr($contracts, 'c1'));
		$this->assertSame(500.0, $this->service->computeClientMrr($contracts, 'c2'));
	}//end testClientMrr()

	/**
	 * Renewal rate = renewed / (renewed + churned) within the period;
	 * churned MRR is the normalized monthly value of churned contracts.
	 *
	 * @return void
	 */
	public function testRenewalMetricsPerPeriod(): void {
		$contracts = [
			['status' => 'renewed', 'endDate' => '2026-02-15', 'billingInterval' => 'monthly', 'valuePerInterval' => 100],
			['status' => 'renewed', 'endDate' => '2026-03-01', 'billingInterval' => 'monthly', 'valuePerInterval' => 100],
			['status' => 'renewed', 'endDate' => '2026-03-20', 'billingInterval' => 'monthly', 'valuePerInterval' => 100],
			['status' => 'renewed', 'endDate' => '2026-01-10', 'billingInterval' => 'monthly', 'valuePerInterval' => 100],
			['status' => 'churned', 'endDate' => '2026-02-28', 'billingInterval' => 'annual', 'valuePerInterval' => 1200],
			// Outside the period — ignored.
			['status' => 'churned', 'endDate' => '2026-09-01', 'billingInterval' => 'monthly', 'valuePerInterval' => 999],
		];

		$metrics = $this->service->computeRenewalMetrics($contracts, '2026-01-01', '2026-03-31');

		$this->assertSame(4, $metrics['renewed']);
		$this->assertSame(1, $metrics['churned']);
		$this->assertSame(80.0, $metrics['renewalRate']);
		// Annual 1200 -> 100/month churned MRR.
		$this->assertSame(100.0, $metrics['churnedMrr']);
	}//end testRenewalMetricsPerPeriod()

	/**
	 * Renewal rate is 0 when no contract closed in the period (no divide-by-zero).
	 *
	 * @return void
	 */
	public function testRenewalRateEmptyPeriod(): void {
		$metrics = $this->service->computeRenewalMetrics([], '2026-01-01', '2026-03-31');
		$this->assertSame(0.0, $metrics['renewalRate']);
		$this->assertSame(0.0, $metrics['churnedMrr']);
	}//end testRenewalRateEmptyPeriod()
}//end class
