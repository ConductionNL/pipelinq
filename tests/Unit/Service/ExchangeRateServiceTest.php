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

use DateTimeImmutable;
use OCA\Pipelinq\Service\EcbRateReader;
use OCA\Pipelinq\Service\ExchangeRateService;
use OCA\Pipelinq\Service\ShillinqFxRateReader;
use OCP\AppFramework\Utility\ITimeFactory;
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
	 * An administrator override wins over shillinq and the table.
	 *
	 * @return void
	 */
	public function testOverrideWinsOverEverySource(): void {
		$svc = $this->resolving(
			values: [
				'forecast_exchange_rates' => '{"USD":0.9}',
				'forecast_rate_overrides' => '{"USD":{"rate":0.92,"reason":"contract rate 2026","date":"2026-10-20"}}',
			],
			fleet: ['rate' => 0.95, 'date' => '2026-10-30', 'origin' => 'ecb']
		);

		$converted = $svc->convert(1000.0, 'USD');
		$this->assertSame(920.0, $converted['amount']);
		$this->assertSame('override', $converted['source']);
		$this->assertSame('contract rate 2026', $converted['reason']);
		$this->assertSame('2026-10-20', $converted['rateDate']);
	}//end testOverrideWinsOverEverySource()

	/**
	 * Shillinq's rate beats the hand-kept table and carries source and date.
	 *
	 * @return void
	 */
	public function testShillinqRateBeatsTheTable(): void {
		$svc = $this->resolving(
			values: ['forecast_exchange_rates' => '{"USD":0.9}'],
			fleet: ['rate' => 0.9132, 'date' => '2026-10-30', 'origin' => 'ecb']
		);

		$converted = $svc->convert(10000.0, 'USD');
		$this->assertSame(9132.0, $converted['amount']);
		$this->assertSame('shillinq', $converted['source']);
		$this->assertSame('ecb', $converted['origin']);
		$this->assertSame('2026-10-30', $converted['rateDate']);
		$this->assertFalse($converted['stale']);
	}//end testShillinqRateBeatsTheTable()

	/**
	 * Without shillinq the typed rate is used, with the date it was typed.
	 *
	 * @return void
	 */
	public function testTableUsedWithoutARateStore(): void {
		$svc = $this->resolving(
			values: [
				'forecast_exchange_rates' => '{"GBP":1.18}',
				'forecast_exchange_rate_dates' => '{"GBP":"2026-10-28"}',
			],
			fleet: null
		);

		$resolved = $svc->resolve('GBP');
		$this->assertSame(1.18, $resolved['rate']);
		$this->assertSame('manual', $resolved['source']);
		$this->assertSame('2026-10-28', $resolved['rateDate']);
	}//end testTableUsedWithoutARateStore()

	/**
	 * A currency without any rate converts 1:1 and says so.
	 *
	 * @return void
	 */
	public function testNoRateAnywhereSaysNone(): void {
		$resolved = $this->resolving(values: [], fleet: null)->resolve('JPY');
		$this->assertSame(1.0, $resolved['rate']);
		$this->assertSame('none', $resolved['source']);
		$this->assertNull($resolved['rateDate']);
		$this->assertFalse($resolved['stale']);
	}//end testNoRateAnywhereSaysNone()

	/**
	 * A rate exactly at the limit is fresh; one day older is stale.
	 *
	 * @return void
	 */
	public function testStaleAtTheBoundaryDay(): void {
		$values = ['forecast_exchange_rates' => '{"USD":0.9,"GBP":1.18}', 'forecast_exchange_rate_dates' => '{"USD":"2026-10-23","GBP":"2026-10-22"}'];
		$svc = $this->resolving(values: $values, fleet: null);

		$this->assertFalse($svc->resolve('USD')['stale']);
		$this->assertTrue($svc->resolve('GBP')['stale']);
	}//end testStaleAtTheBoundaryDay()

	/**
	 * The stale limit follows the app setting and defaults to 7 days.
	 *
	 * @return void
	 */
	public function testStaleLimitFollowsTheSetting(): void {
		$values = ['forecast_exchange_rates' => '{"USD":0.9}', 'forecast_exchange_rate_dates' => '{"USD":"2026-10-10"}'];
		$this->assertTrue($this->resolving(values: $values, fleet: null)->resolve('USD')['stale']);
		$this->assertFalse($this->resolving(values: $values, fleet: null, maxAge: 30)->resolve('USD')['stale']);
		$this->assertSame(7, $this->resolving(values: [], fleet: null, maxAge: 0)->getMaxRateAgeDays());
	}//end testStaleLimitFollowsTheSetting()

	/**
	 * The reader is asked for the pair from the deal currency to the reporting currency.
	 *
	 * @return void
	 */
	public function testShillinqAskedForThePairToTheReportingCurrency(): void {
		$reader = $this->createMock(ShillinqFxRateReader::class);
		$reader->expects($this->once())->method('rateFor')->with('USD', 'GBP')
			->willReturn(['rate' => 0.79, 'date' => '2026-10-30', 'origin' => 'ecb']);
		$svc = new ExchangeRateService(appConfig: $this->storeConfig(['currency' => 'GBP']), fleetRates: $reader, timeFactory: $this->clock());

		$this->assertSame(790.0, $svc->toReportingCurrency(1000.0, 'USD'));
	}//end testShillinqAskedForThePairToTheReportingCurrency()

	/**
	 * An override needs a positive rate and a reason; an unchanged one keeps its date.
	 *
	 * @return void
	 */
	public function testOverridesValidatedAndDated(): void {
		$svc = $this->resolving(
			values: ['forecast_rate_overrides' => '{"USD":{"rate":0.92,"reason":"contract","date":"2026-10-01"}}'],
			fleet: null
		);

		$saved = $svc->overrideStore()->normaliseOverrides(['usd' => ['rate' => '0.92', 'reason' => 'contract'], 'GBP' => ['rate' => 1.2, 'reason' => 'deal']]);
		$this->assertSame('2026-10-01', $saved['USD']['date']);
		$this->assertSame('2026-10-30', $saved['GBP']['date']);

		$this->expectException(\InvalidArgumentException::class);
		$svc->overrideStore()->normaliseOverrides(['USD' => ['rate' => 0.92, 'reason' => ' ']]);
	}//end testOverridesValidatedAndDated()

	/**
	 * A saved table keeps the date of an unchanged rate and stamps a changed one.
	 *
	 * @return void
	 */
	public function testRateDatesFollowChanges(): void {
		$svc = $this->resolving(
			values: ['forecast_exchange_rates' => '{"USD":0.9,"GBP":1.18}', 'forecast_exchange_rate_dates' => '{"USD":"2026-10-01","GBP":"2026-10-01"}'],
			fleet: null
		);

		$this->assertSame(['GBP' => '2026-10-01', 'USD' => '2026-10-30'], $svc->overrideStore()->rateDatesFor(['GBP' => 1.18, 'USD' => 0.91], $svc->getRates()));
	}//end testRateDatesFollowChanges()

	/**
	 * Every known currency is described; the reporting currency is left out.
	 *
	 * @return void
	 */
	public function testDescribeRatesListsKnownCurrencies(): void {
		$svc = $this->resolving(
			values: ['forecast_exchange_rates' => '{"USD":0.9,"EUR":1}', 'forecast_rate_overrides' => '{"CHF":{"rate":1.05,"reason":"x"}}'],
			fleet: null
		);

		$described = $svc->describeRates(['sek']);
		$this->assertSame(['CHF', 'SEK', 'USD'], array_keys($described));
		$this->assertSame('override', $described['CHF']['source']);
		$this->assertSame('none', $described['SEK']['source']);
	}//end testDescribeRatesListsKnownCurrencies()

	/**
	 * Without shillinq the ECB rate integriq keeps beats the hand-kept table.
	 *
	 * @return void
	 */
	public function testEcbRateUsedWithoutShillinq(): void {
		$svc = $this->resolving(
			values: ['forecast_exchange_rates' => '{"USD":0.9}'],
			fleet: null,
			ecb: ['rate' => 0.9132, 'date' => '2026-10-30']
		);

		$converted = $svc->convert(10000.0, 'USD');
		$this->assertSame(9132.0, $converted['amount']);
		$this->assertSame('ecb', $converted['source']);
		$this->assertSame('ecb', $converted['origin']);
		$this->assertSame('2026-10-30', $converted['rateDate']);
	}//end testEcbRateUsedWithoutShillinq()

	/**
	 * Shillinq's rate is asked before the ECB rate.
	 *
	 * @return void
	 */
	public function testShillinqRateBeatsTheEcbRate(): void {
		$svc = $this->resolving(
			values: [],
			fleet: ['rate' => 0.95, 'date' => '2026-10-30', 'origin' => 'manual'],
			ecb: ['rate' => 0.9132, 'date' => '2026-10-30']
		);

		$this->assertSame('shillinq', $svc->resolve('USD')['source']);
	}//end testShillinqRateBeatsTheEcbRate()

	/**
	 * The ECB reader is asked for the pair to the reporting currency on today.
	 *
	 * @return void
	 */
	public function testEcbAskedForThePairOnToday(): void {
		$appConfig = $this->storeConfig(['currency' => 'GBP']);
		$appConfig->method('getValueInt')->willReturn(7);
		$ecb = $this->createMock(EcbRateReader::class);
		$ecb->expects($this->once())->method('rateFor')->with('USD', 'GBP', '2026-10-30')
			->willReturn(['rate' => 0.79, 'date' => '2026-10-29']);
		$svc = new ExchangeRateService(appConfig: $appConfig, timeFactory: $this->clock(), ecbRates: $ecb);

		$this->assertSame(790.0, $svc->toReportingCurrency(1000.0, 'USD'));
	}//end testEcbAskedForThePairOnToday()

	/**
	 * A service over a store, optional shillinq and ECB answers and a clock on 30 Oct 2026.
	 *
	 * @param array<string, string> $values The stored values.
	 * @param array{rate: float, date: string, origin: string}|null $fleet What shillinq answers.
	 * @param int $maxAge The stale limit setting.
	 * @param array{rate: float, date: string}|null $ecb What the ECB reader answers.
	 *
	 * @return ExchangeRateService The service.
	 */
	private function resolving(array $values, ?array $fleet, int $maxAge = 7, ?array $ecb = null): ExchangeRateService {
		$appConfig = $this->storeConfig($values);
		$appConfig->method('getValueInt')->willReturn($maxAge);

		$reader = $this->createMock(ShillinqFxRateReader::class);
		$reader->method('rateFor')->willReturn($fleet);

		$ecbReader = $this->createMock(EcbRateReader::class);
		$ecbReader->method('rateFor')->willReturn($ecb);

		return new ExchangeRateService(appConfig: $appConfig, fleetRates: $reader, timeFactory: $this->clock(), ecbRates: $ecbReader);
	}//end resolving()

	/**
	 * A clock standing on 30 Oct 2026 at noon.
	 *
	 * @return ITimeFactory The clock.
	 */
	private function clock(): ITimeFactory {
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-10-30 12:00:00'));

		return $clock;
	}//end clock()

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
