<?php

/**
 * Unit tests for ExchangeRateService.
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
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for currency normalization.
 */
class ExchangeRateServiceTest extends TestCase {
	/**
	 * Build a service with the given reporting currency and rate table.
	 *
	 * @param string $reporting The reporting currency.
	 * @param string $ratesJson The JSON rate table.
	 *
	 * @return ExchangeRateService The configured service.
	 */
	private function service(string $reporting, string $ratesJson): ExchangeRateService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($reporting, $ratesJson): string {
				if ($key === ExchangeRateService::REPORTING_CURRENCY_KEY) {
					return $reporting;
				}

				if ($key === ExchangeRateService::RATES_KEY) {
					return $ratesJson;
				}

				return $default;
			}
		);

		return new ExchangeRateService(appConfig: $appConfig);
	}//end service()

	/**
	 * The reporting currency passes through unchanged.
	 *
	 * @return void
	 */
	public function testReportingCurrencyUnchanged(): void {
		$svc = $this->service('EUR', '');
		$this->assertSame(1000.0, $svc->toReportingCurrency(1000.0, 'EUR'));
		$this->assertSame(1000.0, $svc->toReportingCurrency(1000.0, null));
	}//end testReportingCurrencyUnchanged()

	/**
	 * A known source currency converts at the configured rate.
	 *
	 * @return void
	 */
	public function testConvertsAtConfiguredRate(): void {
		$svc = $this->service('EUR', '{"GBP":1.18,"USD":0.92}');
		$this->assertSame(1180.0, $svc->toReportingCurrency(1000.0, 'GBP'));
		$this->assertSame(920.0, $svc->toReportingCurrency(1000.0, 'usd'));
	}//end testConvertsAtConfiguredRate()

	/**
	 * An unknown currency falls back to 1:1 (never drops the value).
	 *
	 * @return void
	 */
	public function testUnknownCurrencyFallsBackToParity(): void {
		$svc = $this->service('EUR', '{"GBP":1.18}');
		$this->assertSame(1000.0, $svc->toReportingCurrency(1000.0, 'JPY'));
	}//end testUnknownCurrencyFallsBackToParity()
}//end class
