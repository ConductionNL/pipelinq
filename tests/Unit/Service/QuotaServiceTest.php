<?php

/**
 * Unit tests for QuotaService attainment math.
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

use OCA\Pipelinq\Service\QuotaService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for projected attainment and the at-risk rule.
 */
class QuotaServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var QuotaService
	 */
	private QuotaService $service;

	/**
	 * Set up fixtures with default thresholds (90% / 30 days).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static function (string $a, string $k, int $d = 0): int {
				return $d;
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->service = new QuotaService(container: $container, appConfig: $appConfig, logger: $logger);
	}//end setUp()

	/**
	 * Projected attainment weights best_case at 0.5.
	 *
	 * @return void
	 */
	public function testProjectedAttainment(): void {
		// 50 + 75 + 0.5*25 = 137.5
		$this->assertSame(137500.0, $this->service->projectedAttainment(50000.0, 75000.0, 25000.0));
	}//end testProjectedAttainment()

	/**
	 * At risk when below 90% and fewer than 30 days remain.
	 *
	 * @return void
	 */
	public function testAtRiskTriggers(): void {
		// 130k / 150k = 86.7% (< 90%) with 20 days (< 30).
		$this->assertTrue($this->service->isAtRisk(130000.0, 150000.0, 20));
	}//end testAtRiskTriggers()

	/**
	 * No warning when the quota is exceeded.
	 *
	 * @return void
	 */
	public function testNoWarningWhenQuotaExceeded(): void {
		$this->assertFalse($this->service->isAtRisk(160000.0, 150000.0, 20));
	}//end testNoWarningWhenQuotaExceeded()

	/**
	 * No warning when more than 30 days remain.
	 *
	 * @return void
	 */
	public function testNoWarningWhenAmpleTime(): void {
		$this->assertFalse($this->service->isAtRisk(130000.0, 150000.0, 35));
	}//end testNoWarningWhenAmpleTime()

	/**
	 * No warning when the quota is unset/zero.
	 *
	 * @return void
	 */
	public function testNoWarningWhenNoQuota(): void {
		$this->assertFalse($this->service->isAtRisk(130000.0, 0.0, 10));
	}//end testNoWarningWhenNoQuota()
}//end class
