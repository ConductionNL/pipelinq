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
		$this->service = new RecurringRevenueService($this->createMock(IAppConfig::class),
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
	/**
	 * The service over the given config and container answer.
	 *
	 * @param array<string, string> $config  App config key => value.
	 * @param mixed                 $service The object service, or a Throwable.
	 * @param array<int, mixed>     $calls   The container and findAll calls, filled in.
	 * @param LoggerInterface|null  $logger  The logger.
	 *
	 * @return RecurringRevenueService
	 */
	private function loading(array $config, mixed $service, array &$calls, ?LoggerInterface $logger = null): RecurringRevenueService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($config, &$calls): string {
				$calls[] = ['config', $app, $key];
				return ($config[$key] ?? $default);
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($service, &$calls) {
				$calls[] = ['get', $id];
				if ($service instanceof \Throwable) {
					throw $service;
				}

				return $service;
			}
		);

		return new RecurringRevenueService($appConfig, $container, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end loading()

	/**
	 * Every way the contracts load: a missing register or schema reads
	 * nothing (both config values still read); entity rows serialise, array
	 * rows stay, others drop, in order; a failure is logged and reads as
	 * none; the summary counts active and expiring from the same load.
	 *
	 * @return void
	 */
	public function testEveryWayContractsLoad(): void {
		$calls = [];
		$this->assertSame([], $this->loading(['register' => 'r'], null, $calls)->loadContracts());
		$this->assertSame([['config', 'pipelinq', 'register'], ['config', 'pipelinq', 'contract_schema']], $calls);

		$entity = new class implements \JsonSerializable {
			/**
			 * The serialised row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['status' => 'expiring', 'billingInterval' => 'annual', 'valuePerInterval' => 1200];
			}
		};
		$objects = new class ($entity, $calls) {
			/**
			 * Constructor.
			 *
			 * @param object            $entity An entity row.
			 * @param array<int, mixed> $calls  The calls.
			 */
			public function __construct(private object $entity, public array &$calls) {
			}

			/**
			 * The rows.
			 *
			 * @param array<string, mixed> $config The config.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$this->calls[] = ['findAll', $config];
				return [['status' => 'active', 'billingInterval' => 'monthly', 'valuePerInterval' => 50], 'junk', $this->entity, ['status' => 'churned']];
			}
		};
		$calls = [];
		$objects->calls = &$calls;
		$service = $this->loading(['register' => 'r', 'contract_schema' => 's'], $objects, $calls);
		$contracts = $service->loadContracts();
		$this->assertSame(
			[['status' => 'active', 'billingInterval' => 'monthly', 'valuePerInterval' => 50], ['status' => 'expiring', 'billingInterval' => 'annual', 'valuePerInterval' => 1200], ['status' => 'churned']],
			$contracts
		);
		$this->assertSame(
			[['config', 'pipelinq', 'register'], ['config', 'pipelinq', 'contract_schema'], ['get', 'OCA\OpenRegister\Service\ObjectService'], ['findAll', ['filters' => ['register' => 'r', 'schema' => 's'], 'limit' => 10000]]],
			$calls
		);
		$this->assertSame(['mrr' => 150.0, 'arr' => 1800.0, 'activeCount' => 1, 'expiringCount' => 1], $service->getSummary());

		$calls = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('RecurringRevenueService: failed to load contracts', ['error' => 'gone']);
		$this->assertSame([], $this->loading(['register' => 'r', 'contract_schema' => 's'], new \RuntimeException('gone'), $calls, $logger)->loadContracts());
	}//end testEveryWayContractsLoad()

	/**
	 * Every renewal rule: the period bounds are inclusive, a contract without
	 * an end date or outside the period is not counted, only renewed and
	 * churned count, the churned MRR is the monthly value, and the rate is
	 * rounded to one decimal.
	 *
	 * @return void
	 */
	public function testEveryRenewalRule(): void {
		$contracts = [
			['status' => 'renewed', 'endDate' => '2026-01-01'],
			['status' => 'renewed', 'endDate' => '2026-03-31'],
			['status' => 'churned', 'endDate' => '2026-02-15', 'billingInterval' => 'quarterly', 'valuePerInterval' => 100],
			['status' => 'active', 'endDate' => '2026-02-01'],
			['status' => 'churned', 'endDate' => '2025-12-31', 'billingInterval' => 'monthly', 'valuePerInterval' => 999],
			['status' => 'renewed'],
			['status' => 'renewed', 'endDate' => '2026-04-01'],
		];

		$this->assertSame(
			['renewed' => 2, 'churned' => 1, 'renewalRate' => 66.7, 'churnedMrr' => 33.33],
			$this->service->computeRenewalMetrics($contracts, '2026-01-01', '2026-03-31')
		);
	}//end testEveryRenewalRule()
}//end class
