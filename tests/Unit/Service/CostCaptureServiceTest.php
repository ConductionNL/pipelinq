<?php

/**
 * Unit tests for CostCaptureService.
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

use OCA\Pipelinq\Service\CostCaptureService;
use OCA\Pipelinq\Service\CostEstimationService;
use OCA\Pipelinq\Service\DeliveredMessage;
use OCA\Pipelinq\Service\MessageRoute;
use OCA\Pipelinq\Service\MessageTariff;
use OCA\Pipelinq\Service\MessagingExchangeRateService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Pins every cost source of a delivery webhook.
 */
class CostCaptureServiceTest extends TestCase {
	/** @var MessagingExchangeRateService&MockObject */
	private MessagingExchangeRateService $exchangeRate;

	/** @var CostEstimationService&MockObject */
	private CostEstimationService $estimator;

	private CostCaptureService $service;

	/**
	 * Build the service over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->exchangeRate = $this->createMock(MessagingExchangeRateService::class);
		$this->estimator = $this->createMock(CostEstimationService::class);
		$this->service = new CostCaptureService($this->exchangeRate, $this->estimator);
	}//end setUp()

	/**
	 * A vendor price in a foreign currency is converted and rounded to six decimals.
	 *
	 * @return void
	 */
	public function testConvertsTheVendorPriceToEur(): void {
		$this->exchangeRate->expects($this->once())->method('toEur')
			->with(0.0075, 'USD')->willReturn(0.00691234567);
		$this->estimator->expects($this->never())->method('estimate');

		$result = $this->service->capture(['Price' => '-0.0075', 'PriceUnit' => 'usd'], new DeliveredMessage(new MessageRoute('twilio', 'sms')));

		$this->assertSame(
			[
				'costEur' => 0.006912,
				'metadata' => [
					'costSource' => 'webhook',
					'costCurrency' => 'USD',
					'costSourceAmount' => 0.0075,
					'costCurrencyPending' => false,
					'costEstimated' => false,
				],
			],
			$result
		);
	}//end testConvertsTheVendorPriceToEur()

	/**
	 * Without a live rate the cost is zero and marked pending conversion.
	 *
	 * @return void
	 */
	public function testMarksThePriceAsPendingWithoutARate(): void {
		$this->exchangeRate->method('toEur')->willReturn(null);
		$this->estimator->expects($this->never())->method('estimate');

		$result = $this->service->capture(['Price' => '0.05', 'PriceUnit' => 'GBP', 'costEur' => 9], new DeliveredMessage(new MessageRoute('twilio', 'sms')));

		$this->assertSame(
			[
				'costEur' => 0.0,
				'metadata' => [
					'costSource' => 'webhook',
					'costCurrency' => 'GBP',
					'costSourceAmount' => 0.05,
					'costCurrencyPending' => true,
					'costEstimated' => false,
				],
			],
			$result
		);
	}//end testMarksThePriceAsPendingWithoutARate()

	/**
	 * A price without its unit falls through to the vendor's precomputed EUR cost.
	 *
	 * @return void
	 */
	public function testTakesThePrecomputedEurCost(): void {
		$this->exchangeRate->expects($this->never())->method('toEur');
		$this->estimator->expects($this->never())->method('estimate');

		$result = $this->service->capture(['Price' => '0.05', 'costEur' => '0.031'], new DeliveredMessage(new MessageRoute('bsp', 'whatsapp')));

		$this->assertSame(
			[
				'costEur' => 0.031,
				'metadata' => [
					'costSource' => 'webhook',
					'costCurrency' => 'EUR',
					'costEstimated' => false,
				],
			],
			$result
		);
	}//end testTakesThePrecomputedEurCost()

	/**
	 * A non-numeric precomputed cost falls back to the price table, with the given category and country.
	 *
	 * @return void
	 */
	public function testEstimatesFromThePriceTable(): void {
		$this->estimator->expects($this->once())->method('estimate')
			->with('meta', 'whatsapp', 'marketing', 'BE')->willReturn(0.12);

		$result = $this->service->capture(['costEur' => 'n/a'], new DeliveredMessage(new MessageRoute('meta', 'whatsapp'), new MessageTariff('marketing', 'BE')));

		$this->assertSame(
			[
				'costEur' => 0.12,
				'metadata' => [
					'costSource' => 'estimate',
					'costCurrency' => 'EUR',
					'costEstimated' => true,
				],
			],
			$result
		);
	}//end testEstimatesFromThePriceTable()

	/**
	 * The estimate defaults to a Dutch utility message.
	 *
	 * @return void
	 */
	public function testEstimatesAUtilityMessageInTheNetherlandsByDefault(): void {
		$this->estimator->expects($this->once())->method('estimate')
			->with('meta', 'sms', 'utility', 'NL')->willReturn(0.07);

		$result = $this->service->capture([], new DeliveredMessage(new MessageRoute('meta', 'sms')));

		$this->assertSame(0.07, $result['costEur']);
	}//end testEstimatesAUtilityMessageInTheNetherlandsByDefault()
}//end class
