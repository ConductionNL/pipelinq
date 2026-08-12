<?php

/**
 * Unit tests for ForecastService accuracy math.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ExchangeRateService;
use OCA\Pipelinq\Service\ForecastRollupService;
use OCA\Pipelinq\Service\ForecastService;
use OCA\Pipelinq\Service\QuotaService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for accuracy scoring and trailing-quarter averages.
 */
class ForecastServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var ForecastService
	 */
	private ForecastService $service;

	/**
	 * Set up fixtures with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $a, string $k, string $d = ''): string => $d
		);
		$container = $this->createMock(ContainerInterface::class);
		$logger = $this->createMock(LoggerInterface::class);
		$quota = $this->createMock(QuotaService::class);
		$exchange = new ExchangeRateService(appConfig: $appConfig);
		$rollup = new ForecastRollupService(exchangeRate: $exchange);

		$this->service = new ForecastService(
			container: $container,
			appConfig: $appConfig,
			quotaService: $quota,
			rollup: $rollup,
			logger: $logger
		);
	}//end setUp()

	/**
	 * Accuracy of a near-perfect commit lands in the green band.
	 *
	 * @return void
	 */
	public function testAccuracyGreen(): void {
		$score = $this->service->computeAccuracyScore(100000.0, 95000.0, true);
		$this->assertNotNull($score);
		$this->assertEqualsWithDelta(0.9474, $score, 0.0001);
		$this->assertSame('green', $this->service->accuracyBand($score));
	}//end testAccuracyGreen()

	/**
	 * A large miss lands in the red band.
	 *
	 * @return void
	 */
	public function testAccuracyRed(): void {
		$score = $this->service->computeAccuracyScore(100000.0, 60000.0, true);
		$this->assertNotNull($score);
		$this->assertEqualsWithDelta(0.3333, $score, 0.0001);
		$this->assertSame('red', $this->service->accuracyBand($score));
	}//end testAccuracyRed()

	/**
	 * Accuracy is null for an open period or missing actuals.
	 *
	 * @return void
	 */
	public function testAccuracyNullWhenNotClosedOrNoData(): void {
		$this->assertNull($this->service->computeAccuracyScore(100000.0, 95000.0, false));
		$this->assertNull($this->service->computeAccuracyScore(100000.0, 0.0, true));
		$this->assertNull($this->service->computeAccuracyScore(null, 95000.0, true));
	}//end testAccuracyNullWhenNotClosedOrNoData()

	/**
	 * Team accuracy is the average of rep accuracies.
	 *
	 * @return void
	 */
	public function testAverageAccuracy(): void {
		$this->assertEqualsWithDelta(0.90, $this->service->averageAccuracy([0.95, 0.85]), 0.0001);
		$this->assertNull($this->service->averageAccuracy([]));
	}//end testAverageAccuracy()

	/**
	 * Trailing-4Q average needs four quarters; otherwise 0.0.
	 *
	 * @return void
	 */
	public function testTrailingQuartersAverage(): void {
		$this->assertEqualsWithDelta(0.8875, $this->service->computeTrailingQuartersAccuracy([0.92, 0.88, 0.85, 0.90]), 0.0001);
		$this->assertSame(0.0, $this->service->computeTrailingQuartersAccuracy([0.92, 0.88, 0.85]));
	}//end testTrailingQuartersAverage()
}//end class
