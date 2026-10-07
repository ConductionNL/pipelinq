<?php

/**
 * VAT rates per class come from the admin setting.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pipelinq-forms-review/specs/product-catalog/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ProductCatalogService;
use OCA\Pipelinq\Service\VatRates;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for VatRates and its use in ProductCatalogService.
 *
 * @covers \OCA\Pipelinq\Service\VatRates
 * @covers \OCA\Pipelinq\Service\ProductCatalogService
 */
class VatRatesTest extends TestCase {
	/**
	 * Nothing stored means the Dutch rates.
	 *
	 * @return void
	 */
	public function testNothingStoredGivesTheDefaults(): void {
		$this->assertSame(VatRates::DEFAULTS, VatRates::parse(''));
	}//end testNothingStoredGivesTheDefaults()

	/**
	 * A stored rate replaces its default; a broken one is ignored.
	 *
	 * @return void
	 */
	public function testAStoredRateWinsAndABrokenOneIsIgnored(): void {
		$rates = VatRates::parse('{"high": 20, "low": "abc", "zero": 150, "unknown": 5}');

		$this->assertSame(20.0, $rates['high']);
		$this->assertSame(9.0, $rates['low']);
		$this->assertSame(0.0, $rates['zero']);
		$this->assertArrayNotHasKey('unknown', $rates);
	}//end testAStoredRateWinsAndABrokenOneIsIgnored()

	/**
	 * The catalogue prices with the configured rate.
	 *
	 * @return void
	 */
	public function testTheCatalogueUsesTheConfiguredRate(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $key === 'vat_rates' ? '{"high": 19, "low": 7.5}' : $default
		);
		$service = new ProductCatalogService($appConfig, new NullLogger(), $this->createMock(ObjectServiceInterface::class));

		$this->assertSame(19, $service->btwClassToRate('high'));
		$this->assertSame(7.5, $service->btwClassToRate('low'));
		$this->assertSame(19, $service->btwClassToRate(null));
	}//end testTheCatalogueUsesTheConfiguredRate()
}//end class
