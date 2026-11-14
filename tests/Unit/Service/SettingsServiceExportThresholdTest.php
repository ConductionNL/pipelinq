<?php

/**
 * SettingsService: the export failure threshold.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-an-administrator-sets-the-threshold-req-efs-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\DefaultPipelineService;
use OCA\Pipelinq\Service\DefaultSkillService;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * The threshold reads 3 by default, takes a whole number from 1 to 10 and
 * refuses anything else without changing the stored value.
 *
 * @covers \OCA\Pipelinq\Service\SettingsService
 * @uses   \OCA\Pipelinq\Service\Export\ExportJobService
 */
class SettingsServiceExportThresholdTest extends TestCase {

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
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
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
	 * Without a stored value the threshold reads 3.
	 *
	 * @return void
	 */
	public function testTheThresholdDefaultsToThree(): void {
		$this->assertSame('3', $this->service->getSettings()['export_failure_threshold']);
	}//end testTheThresholdDefaultsToThree()

	/**
	 * A value from 1 to 10 is stored and read back.
	 *
	 * @return void
	 */
	public function testAValueInRangeIsStored(): void {
		$config = $this->service->updateSettings(['export_failure_threshold' => '1']);

		$this->assertSame('1', $config['export_failure_threshold']);
		$this->assertSame('1', $this->store['export_failure_threshold']);
	}//end testAValueInRangeIsStored()

	/**
	 * A value outside 1 to 10 is refused and the threshold stays as it was,
	 * as does every other setting in the same save.
	 *
	 * @param string $value The entered value.
	 *
	 * @return void
	 */
	#[DataProvider('outOfRange')]
	public function testAValueOutOfRangeIsRefused(string $value): void {
		$this->store['export_failure_threshold'] = '5';

		try {
			$this->service->updateSettings(['export_failure_threshold' => $value, 'lead_stale_threshold_days' => '20']);
			$this->fail('an out-of-range threshold must be refused');
		} catch (UnexpectedValueException $e) {
			$this->assertSame('Enter a number from 1 to 10', $e->getMessage());
		}

		$this->assertSame('5', $this->store['export_failure_threshold']);
		$this->assertArrayNotHasKey('lead_stale_threshold_days', $this->store);
	}//end testAValueOutOfRangeIsRefused()

	/**
	 * Values the threshold refuses.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function outOfRange(): array {
		return [
			'zero' => ['0'],
			'eleven' => ['11'],
			'text' => ['three'],
			'decimal' => ['2.5'],
			'empty' => [''],
		];
	}//end outOfRange()

	/**
	 * A save without the threshold does not touch it.
	 *
	 * @return void
	 */
	public function testASaveWithoutTheThresholdLeavesIt(): void {
		$config = $this->service->updateSettings(['lead_stale_threshold_days' => '20']);

		$this->assertSame('20', $config['lead_stale_threshold_days']);
		$this->assertArrayNotHasKey('export_failure_threshold', $this->store);
	}//end testASaveWithoutTheThresholdLeavesIt()
}//end class
