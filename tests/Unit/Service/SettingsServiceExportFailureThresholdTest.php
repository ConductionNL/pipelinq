<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://pipelinq.app
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\DefaultPipelineService;
use OCA\Pipelinq\Service\DefaultSkillService;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * The admin sets after how many failed runs in a row an export job switches
 * itself off: a whole number from 1 to 10, 3 by default.
 *
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-an-administrator-sets-the-threshold-req-efs-004
 */
class SettingsServiceExportFailureThresholdTest extends TestCase {
	/**
	 * In-memory app config.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Service under test.
	 *
	 * @var SettingsService
	 */
	private SettingsService $service;

	/**
	 * Build the service over an in-memory app config.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		$this->service = new SettingsService(
			$appConfig,
			$this->createMock(IConfig::class),
			$this->createMock(SettingsLoadService::class),
			$this->createMock(DefaultPipelineService::class),
			$this->createMock(DefaultSkillService::class),
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * The threshold reads as 3 until an administrator sets it.
	 *
	 * @return void
	 */
	public function testTheThresholdDefaultsToThree(): void {
		$this->assertSame('3', $this->service->getSettings()['export_failure_threshold']);
	}//end testTheThresholdDefaultsToThree()

	/**
	 * A stricter threshold of 1 is stored.
	 *
	 * @return void
	 */
	public function testAThresholdOfOneIsStored(): void {
		$config = $this->service->updateSettings(['export_failure_threshold' => '1']);

		$this->assertSame('1', $config['export_failure_threshold']);
	}//end testAThresholdOfOneIsStored()

	/**
	 * Zero is refused with the message the admin sees, and the stored
	 * threshold stays as it was.
	 *
	 * @return void
	 */
	public function testZeroIsRefusedAndTheThresholdStays(): void {
		$this->store['export_failure_threshold'] = '5';

		foreach (['0', '11', '2.5', ''] as $value) {
			try {
				$this->service->updateSettings(['export_failure_threshold' => $value]);
				$this->fail('a threshold of ' . var_export($value, true) . ' must be refused');
			} catch (UnexpectedValueException $e) {
				$this->assertSame('Enter a number from 1 to 10.', $e->getMessage());
			}
		}

		$this->assertSame('5', $this->store['export_failure_threshold']);
	}//end testZeroIsRefusedAndTheThresholdStays()
}//end class
