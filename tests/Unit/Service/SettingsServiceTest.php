<?php

/**
 * Unit tests for SettingsService.
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
use OCA\Pipelinq\Service\DefaultSkillService;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCA\Pipelinq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SettingsService.
 */
class SettingsServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var SettingsService
	 */
	private SettingsService $service;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Mock config.
	 *
	 * @var IConfig
	 */
	private IConfig $config;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->config = $this->createMock(originalClassName: IConfig::class);
		$settingsLoadService = $this->createMock(originalClassName: SettingsLoadService::class);
		$pipelineService = $this->createMock(originalClassName: DefaultPipelineService::class);
		$skillService = $this->createMock(originalClassName: DefaultSkillService::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->service = new SettingsService(
			appConfig: $this->appConfig,
			config: $this->config,
			settingsLoadService: $settingsLoadService,
			pipelineService: $pipelineService,
			skillService: $skillService,
			logger: $logger,
		);
	}//end setUp()

	/**
	 * Test getSettings returns config keys.
	 *
	 * @return void
	 */
	public function testGetSettingsReturnsConfigKeys(): void {
		$this->appConfig->method('getValueString')->willReturn('val');

		$result = $this->service->getSettings();

		$this->assertArrayHasKey(key: 'register', array: $result);
		$this->assertArrayHasKey(key: 'client_schema', array: $result);
		$this->assertArrayHasKey(key: 'lead_schema', array: $result);
	}//end testGetSettingsReturnsConfigKeys()

	/**
	 * Test updateSettings only updates known keys.
	 *
	 * @return void
	 */
	public function testUpdateSettingsOnlyUpdatesKnownKeys(): void {
		$this->appConfig->expects($this->atLeastOnce())
			->method('setValueString');
		$this->appConfig->method('getValueString')->willReturn('');

		$result = $this->service->updateSettings(['register' => '5', 'unknown_key' => 'x']);

		$this->assertArrayHasKey(key: 'register', array: $result);
	}//end testUpdateSettingsOnlyUpdatesKnownKeys()

	/**
	 * Test getUserSettings returns boolean settings.
	 *
	 * @return void
	 */
	public function testGetUserSettingsReturnsBooleans(): void {
		$this->config->method('getUserValue')->willReturn('true');

		$result = $this->service->getUserSettings('admin');

		$this->assertTrue(condition: $result['notify_assignments']);
		$this->assertTrue(condition: $result['notify_stage_status']);
		$this->assertTrue(condition: $result['notify_notes']);
	}//end testGetUserSettingsReturnsBooleans()

	/**
	 * Test updateUserSettings stores boolean values.
	 *
	 * @return void
	 */
	public function testUpdateUserSettings(): void {
		$this->config->expects($this->atLeastOnce())
			->method('setUserValue');
		$this->config->method('getUserValue')->willReturn('false');

		$result = $this->service->updateUserSettings('admin', [
			'notify_assignments' => false,
		]);

		$this->assertFalse(condition: $result['notify_assignments']);
	}//end testUpdateUserSettings()

	/**
	 * Test getConfigValue delegates to appConfig.
	 *
	 * @return void
	 */
	public function testGetConfigValueDelegates(): void {
		$this->appConfig->method('getValueString')->willReturn('myval');

		$this->assertSame(expected: 'myval', actual: $this->service->getConfigValue(key: 'register'));
	}//end testGetConfigValueDelegates()
}//end class
