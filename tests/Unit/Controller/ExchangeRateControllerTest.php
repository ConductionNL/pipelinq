<?php

/**
 * Unit tests for ExchangeRateController.
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

use OCA\Pipelinq\Controller\ExchangeRateController;
use OCA\Pipelinq\Service\ExchangeRateService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The LeadDetail conversion endpoint.
 */
class ExchangeRateControllerTest extends TestCase {
	/**
	 * Build the controller over a fixed store.
	 *
	 * @return ExchangeRateController The controller.
	 */
	private function controller(): ExchangeRateController {
		$values = [
			'forecast_exchange_rates' => '{"USD":0.9}',
			'forecast_exchange_rate_dates' => '{"USD":"2026-10-06"}',
			'forecast_rate_overrides' => '{"GBP":{"rate":1.2,"reason":"contract","date":"2026-10-06"}}',
		];
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);

		return new ExchangeRateController($this->createMock(IRequest::class), new ExchangeRateService($config));
	}//end controller()

	/**
	 * A manual rate converts and names its source, date and the stale limit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-a-stale-rate-is-shown-as-stale-req-dxr-002
	 */
	public function testConvertsWithMetadata(): void {
		$data = $this->controller()->convert('usd', '131400')->getData();

		$this->assertSame(118260.0, $data['amount']);
		$this->assertSame('manual', $data['source']);
		$this->assertSame('2026-10-06', $data['rateDate']);
		$this->assertSame('EUR', $data['reportingCurrency']);
		$this->assertSame(7, $data['maxRateAgeDays']);
	}//end testConvertsWithMetadata()

	/**
	 * The override reason stays on the admin page.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-a-stale-rate-is-shown-as-stale-req-dxr-002
	 */
	public function testOverrideReasonNotExposed(): void {
		$data = $this->controller()->convert('GBP', '100')->getData();

		$this->assertSame('override', $data['source']);
		$this->assertArrayNotHasKey('reason', $data);
	}//end testOverrideReasonNotExposed()

	/**
	 * A malformed currency or amount is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-a-stale-rate-is-shown-as-stale-req-dxr-002
	 */
	public function testMalformedInputRefused(): void {
		$this->assertSame(400, $this->controller()->convert('dollars', '1')->getStatus());
		$this->assertSame(400, $this->controller()->convert('USD', 'lots')->getStatus());
	}//end testMalformedInputRefused()
}//end class
