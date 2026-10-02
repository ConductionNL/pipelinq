<?php

/**
 * Forecast settings expose the rate table and write the one reporting currency.
 *
 * pipelinq#2040: the rate table had no screen (occ only), and the forecast kept
 * its own reporting currency beside the setup wizard's `currency`.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use ArrayObject;
use OCA\Pipelinq\Controller\ForecastSettingsController;
use OCA\Pipelinq\Service\ExchangeRateService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Round trip of the rate table and the reporting currency.
 */
class ForecastSettingsControllerTest extends TestCase {
	/**
	 * The stored app config.
	 *
	 * @var ArrayObject<string, string>
	 */
	private ArrayObject $store;

	/**
	 * Build the controller over an in-memory app config and a request.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return ForecastSettingsController The controller.
	 */
	private function controller(array $params = []): ForecastSettingsController {
		$store = $this->store;
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (string)($store[$key] ?? $default)
		);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => (int)($store[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use ($store): bool {
				$store[$key] = $value;
				return true;
			}
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new ForecastSettingsController($request, $config, new ExchangeRateService($config));
	}//end controller()

	/**
	 * Set up an empty store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new ArrayObject();
	}//end setUp()

	/**
	 * A saved rate table is stored where the conversion reads it and reads back.
	 *
	 * @return void
	 */
	public function testRateTableRoundTrips(): void {
		$response = $this->controller(['exchange_rates' => ['usd' => '0.9', 'GBP' => 1.18]])->update();
		$this->assertSame(200, $response->getStatus());
		$this->assertSame('{"GBP":1.18,"USD":0.9}', $this->store[ExchangeRateService::RATES_KEY]);

		$data = $this->controller()->index()->getData();
		$this->assertEquals((object)['GBP' => 1.18, 'USD' => 0.9], $data['exchange_rates']);
		$this->assertSame(900.0, (new ExchangeRateService($this->configFromStore()))->toReportingCurrency(1000.0, 'USD'));
	}//end testRateTableRoundTrips()

	/**
	 * A rate that is not a positive number is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testInvalidRateRefused(): void {
		$response = $this->controller(['exchange_rates' => ['USD' => '-1']])->update();
		$this->assertSame(400, $response->getStatus());
		$this->assertArrayNotHasKey(ExchangeRateService::RATES_KEY, $this->store->getArrayCopy());
	}//end testInvalidRateRefused()

	/**
	 * The reporting currency is written to the setup wizard's `currency`
	 * key, so the forecast and the dashboards agree on one currency.
	 *
	 * @return void
	 */
	public function testReportingCurrencyIsTheOneAppSetting(): void {
		$this->controller(['reporting_currency' => 'usd'])->update();
		$this->assertSame('USD', $this->store['currency']);
		$this->assertArrayNotHasKey('forecast_reporting_currency', $this->store->getArrayCopy());
		$this->assertSame('USD', $this->controller()->index()->getData()['reporting_currency']);
	}//end testReportingCurrencyIsTheOneAppSetting()

	/**
	 * An app config double reading the shared store.
	 *
	 * @return IAppConfig The double.
	 */
	private function configFromStore(): IAppConfig {
		$store = $this->store;
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => (string)($store[$key] ?? $default)
		);

		return $config;
	}//end configFromStore()
}//end class
