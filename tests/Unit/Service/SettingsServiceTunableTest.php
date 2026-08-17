<?php

/**
 * Unit tests for SettingsService tenant-tunable admin-config getters (Phase 7).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\DefaultPipelineService;
use OCA\Pipelinq\Service\DefaultQueueService;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that Phase-7 tunable values default to the historical constant value
 * (behavior-preserving) and honour an admin-configured override.
 */
class SettingsServiceTunableTest extends TestCase {
	/**
	 * The app config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The service under test.
	 *
	 * @var SettingsService
	 */
	private SettingsService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->service = new SettingsService($this->appConfig,
			$this->createMock(IConfig::class),
			$this->createMock(SettingsLoadService::class),
			$this->createMock(DefaultPipelineService::class),
			$this->createMock(DefaultQueueService::class),
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * An unconfigured int value returns the supplied default (the historical constant).
	 *
	 * @return void
	 */
	public function testGetIntValueReturnsDefaultWhenUnconfigured(): void {
		// IAppConfig::getValueInt returns the default when no override is stored.
		$this->appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $default
		);

		$this->assertSame(
			900,
			$this->service->getIntValue('task_expiry.poll_interval_seconds', 900),
			'Default must preserve the historical 15-minute task-expiry interval'
		);
		$this->assertSame(
			8,
			$this->service->getIntValue('task.business_hour_start', 8),
			'Default must preserve the historical business-hour start'
		);
	}//end testGetIntValueReturnsDefaultWhenUnconfigured()

	/**
	 * An admin-configured int override replaces the default.
	 *
	 * @return void
	 */
	public function testGetIntValueReturnsConfiguredOverride(): void {
		$this->appConfig->method('getValueInt')->willReturnCallback(
			static function (string $app, string $key, int $default = 0): int {
				if ($key === 'task_expiry.poll_interval_seconds') {
					return 1800;
				}

				return $default;
			}
		);

		$this->assertSame(
			1800,
			$this->service->getIntValue('task_expiry.poll_interval_seconds', 900),
			'Configured override (1800) must win over the default'
		);
	}//end testGetIntValueReturnsConfiguredOverride()

	/**
	 * A string value defaults to the known third-party host and honours overrides.
	 *
	 * @return void
	 */
	public function testGetStringValueDefaultAndOverride(): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === 'kvk.api_base_url') {
					return 'https://api.kvk.example/api/v2';
				}

				return $default;
			}
		);

		$this->assertSame(
			'https://api.kvk.example/api/v2',
			$this->service->getStringValue('kvk.api_base_url', 'https://api.kvk.nl/api/v1'),
			'Configured KVK base URL must win over the default'
		);
		$this->assertSame(
			'https://api.opencorporates.com/v0.4',
			$this->service->getStringValue(
				'opencorporates.api_base_url',
				'https://api.opencorporates.com/v0.4'
			),
			'Unconfigured URL must fall back to the known default host'
		);
	}//end testGetStringValueDefaultAndOverride()

	/**
	 * getSettings() surfaces the tunable keys with their historical defaults.
	 *
	 * @return void
	 */
	public function testGetSettingsIncludesTunableDefaults(): void {
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$settings = $this->service->getSettings();

		$this->assertSame('300', $settings['queue_overflow.poll_interval_seconds']);
		$this->assertSame('17', $settings['task.business_hour_end']);
		$this->assertSame('https://api.kvk.nl/api/v1', $settings['kvk.api_base_url']);
	}//end testGetSettingsIncludesTunableDefaults()
}//end class
