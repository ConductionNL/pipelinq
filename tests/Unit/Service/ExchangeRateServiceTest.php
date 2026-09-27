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
	/**
	 * The reporting currency is the one the setup wizard stores (`currency`),
	 * the same value manifest dashboards format with. The forecast used to
	 * read its own `forecast_reporting_currency`, so an instance set up in USD
	 * still converted its forecast to EUR (pipelinq#2040).
	 *
	 * @return void
	 */
	public function testReportingCurrencyIsTheSetupWizardSetting(): void {
		$svc = new ExchangeRateService(appConfig: $this->storeConfig(['currency' => 'usd']));
		$this->assertSame('USD', $svc->getReportingCurrency());
		$this->assertSame(1000.0, $svc->toReportingCurrency(1000.0, 'USD'));
	}//end testReportingCurrencyIsTheSetupWizardSetting()

	/**
	 * An instance that only ever set the old forecast key keeps its value.
	 *
	 * @return void
	 */
	public function testOldForecastCurrencyKeyStillReadWhenTheWizardSetNone(): void {
		$svc = new ExchangeRateService(appConfig: $this->storeConfig(['forecast_reporting_currency' => 'GBP']));
		$this->assertSame('GBP', $svc->getReportingCurrency());
	}//end testOldForecastCurrencyKeyStillReadWhenTheWizardSetNone()

	/**
	 * The rate table reads back as currency => rate, upper-cased.
	 *
	 * @return void
	 */
	public function testRatesReadBack(): void {
		$svc = new ExchangeRateService(appConfig: $this->storeConfig(['forecast_exchange_rates' => '{"USD":0.9,"gbp":1.18}']));
		$this->assertSame(['GBP' => 1.18, 'USD' => 0.9], $svc->getRates());
	}//end testRatesReadBack()

	/**
	 * A rate table with a non-ISO code or a rate that is not positive is refused.
	 *
	 * @return void
	 */
	public function testInvalidRatesRefused(): void {
		$svc = new ExchangeRateService(appConfig: $this->storeConfig([]));
		$this->assertSame(['USD' => 0.9], $svc->normaliseRates(['usd' => '0.9']));

		$this->expectException(\InvalidArgumentException::class);
		$svc->normaliseRates(['DOLLAR' => 0.9]);
	}//end testInvalidRatesRefused()

	/**
	 * A zero rate is refused rather than zeroing every deal in that currency.
	 *
	 * @return void
	 */
	public function testZeroRateRefused(): void {
		$svc = new ExchangeRateService(appConfig: $this->storeConfig([]));
		$this->expectException(\InvalidArgumentException::class);
		$svc->normaliseRates(['USD' => 0]);
	}//end testZeroRateRefused()

	/**
	 * An app config double that answers from a key/value map.
	 *
	 * @param array<string, string> $values The stored values.
	 *
	 * @return IAppConfig The double.
	 */
	private function storeConfig(array $values): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);

		return $appConfig;
	}//end storeConfig()
}//end class
