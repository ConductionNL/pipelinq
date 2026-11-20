<?php

/**
 * Unit tests for CostEstimationService.
 *
 * The estimate is the per-message price a campaign is quoted at: it is read
 * from the vendor, channel, category and country rows of the price table,
 * falls back to the category and vendor defaults, and an operator's override
 * replaces a vendor without dropping the others.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\CostEstimationService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CostEstimationService::estimate.
 *
 * @covers \OCA\Pipelinq\Service\CostEstimationService
 */
class CostEstimationServiceTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Wire the logger.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the service with a price-table override (empty for the defaults).
	 *
	 * @param string $override The configured price_table_json.
	 *
	 * @return CostEstimationService
	 */
	private function service(string $override): CostEstimationService {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->with('pipelinq', 'messaging.price_table_json', '')->willReturn($override);

		return new CostEstimationService(appConfig: $config, logger: $this->logger);
	}//end service()

	/**
	 * Lookups in the default table.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: float}>
	 */
	public static function defaults(): array {
		return [
			'country price' => ['meta', 'whatsapp', 'marketing', 'DE', 0.070],
			'unknown country falls back to the category default' => ['meta', 'whatsapp', 'utility', 'FR', 0.020],
			'vendor with one price per category' => ['360dialog', 'whatsapp', 'authentication', 'NL', 0.025],
			'unknown vendor' => ['twilio', 'whatsapp', 'utility', 'NL', 0.0],
			'unknown channel' => ['meta', 'sms', 'utility', 'NL', 0.0],
			'unknown category without a default' => ['meta', 'whatsapp', 'service', 'NL', 0.0],
		];
	}//end defaults()

	/**
	 * The default table answers each lookup.
	 *
	 * @param string $vendor The vendor.
	 * @param string $channel The channel.
	 * @param string $category The category.
	 * @param string $country The country.
	 * @param float $expected The price per message.
	 *
	 * @return void
	 */
	#[DataProvider('defaults')]
	public function testEstimatesFromTheDefaultTable(string $vendor, string $channel, string $category, string $country, float $expected): void {
		self::assertSame(
			expected: $expected,
			actual: $this->service(override: '')->estimate(vendor: $vendor, channel: $channel, category: $category, country: $country)
		);
	}//end testEstimatesFromTheDefaultTable()

	/**
	 * Without a category or country the estimate is the Dutch utility price.
	 *
	 * @return void
	 */
	public function testDefaultsToADutchUtilityMessage(): void {
		self::assertSame(expected: 0.012, actual: $this->service(override: '')->estimate(vendor: 'meta', channel: 'whatsapp'));
	}//end testDefaultsToADutchUtilityMessage()

	/**
	 * An override replaces one vendor, keeps the others, and may use flat and channel-default prices.
	 *
	 * @return void
	 */
	public function testAnOverrideReplacesOnlyItsVendor(): void {
		$service = $this->service(
			override: (string)json_encode(
				[
					'meta' => ['whatsapp' => ['utility' => 0.5, 'default' => ['default' => 0.3]]],
					'bird' => ['sms' => ['marketing' => ['NL' => '0.07', 'default' => 'free']]],
					7 => ['ignored' => true],
				]
			)
		);

		self::assertSame(expected: 0.5, actual: $service->estimate(vendor: 'meta', channel: 'whatsapp', category: 'utility'));
		self::assertSame(expected: 0.3, actual: $service->estimate(vendor: 'meta', channel: 'whatsapp', category: 'marketing'));
		self::assertSame(expected: 0.07, actual: $service->estimate(vendor: 'bird', channel: 'sms', category: 'marketing'));
		self::assertSame(expected: 0.0, actual: $service->estimate(vendor: 'bird', channel: 'sms', category: 'marketing', country: 'BE'));
		self::assertSame(expected: 0.09, actual: $service->estimate(vendor: '360dialog', channel: 'whatsapp', category: 'marketing'));
	}//end testAnOverrideReplacesOnlyItsVendor()

	/**
	 * A broken override is logged and the defaults are used.
	 *
	 * @return void
	 */
	public function testABrokenOverrideFallsBackToTheDefaults(): void {
		$this->logger->expects(self::once())->method('warning')
			->with(self::stringContains(string: 'invalid price_table_json'), self::anything());

		self::assertSame(expected: 0.012, actual: $this->service(override: '{broken')->estimate(vendor: 'meta', channel: 'whatsapp'));
	}//end testABrokenOverrideFallsBackToTheDefaults()

	/**
	 * An override that is valid JSON but not an object is ignored.
	 *
	 * @return void
	 */
	public function testAScalarOverrideIsIgnored(): void {
		$this->logger->expects(self::never())->method('warning');

		self::assertSame(expected: 0.06, actual: $this->service(override: '42')->estimate(vendor: 'meta', channel: 'whatsapp', category: 'marketing'));
	}//end testAScalarOverrideIsIgnored()
}//end class
