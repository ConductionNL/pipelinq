<?php

/**
 * The receipt address follows the setup wizard's organisation step.
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
 * @spec openspec/changes/pipelinq-setup-wizard-review/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ReceiptService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReceiptService::companyAddress().
 */
class ReceiptCompanyAddressTest extends TestCase {
	/**
	 * Build the service over a fixed app config.
	 *
	 * @param array<string,string> $config The stored app config values.
	 *
	 * @return ReceiptService
	 */
	private function service(array $config): ReceiptService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new ReceiptService($appConfig, $this->createMock(IL10N::class));
	}//end service()

	/**
	 * The organisation step's fields compose the address.
	 *
	 * @return void
	 */
	public function testTheOrganisationStepFieldsComposeTheAddress(): void {
		$service = $this->service(
			[
				'receipt_company_street' => 'Lauriergracht 14h',
				'receipt_company_postcode' => '1016 RL',
				'receipt_company_city' => 'Amsterdam',
				'receipt_company_country' => 'Netherlands',
			]
		);

		$this->assertSame('Lauriergracht 14h, 1016 RL Amsterdam, Netherlands', $service->companyDetails()['address']);
	}//end testTheOrganisationStepFieldsComposeTheAddress()

	/**
	 * An existing one-line address keeps working and wins.
	 *
	 * @return void
	 */
	public function testAnExistingOneLineAddressWins(): void {
		$service = $this->service(
			[
				'receipt_company_address' => 'Kerkstraat 1, Utrecht',
				'receipt_company_city' => 'Amsterdam',
			]
		);

		$this->assertSame('Kerkstraat 1, Utrecht', $service->companyDetails()['address']);
	}//end testAnExistingOneLineAddressWins()

	/**
	 * Nothing set means no address, not a stray comma.
	 *
	 * @return void
	 */
	public function testNothingSetGivesNoAddress(): void {
		$this->assertSame('', $this->service([])->companyDetails()['address']);
	}//end testNothingSetGivesNoAddress()
}//end class
