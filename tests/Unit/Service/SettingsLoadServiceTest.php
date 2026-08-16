<?php

/**
 * Unit tests for SettingsLoadService.
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

use OCA\OpenRegister\Service\ConfigurationService;
use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCA\Pipelinq\Service\SettingsMapBuilder;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SettingsLoadService.
 */
class SettingsLoadServiceTest extends TestCase {
	/**
	 * Test loadSettings calls configuration service.
	 *
	 * @return void
	 */
	public function testLoadSettingsCallsConfigurationService(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appManager = $this->createMock(IAppManager::class);
		$mapBuilder = new SettingsMapBuilder();
		$fileLoader = $this->createMock(ConfigFileLoaderService::class);

		$fileLoader->method('loadConfigurationFile')->willReturn(['key' => 'val']);
		$fileLoader->method('ensureSourceType')->willReturnArgument(0);
		$appManager->method('getAppVersion')->willReturn('1.0.0');

		// ConfigurationService is now a constructor-injected, concretely typed
		// dependency (ADR-083) rather than a container lookup, so the in-test
		// anonymous class this used to pass no longer satisfies the type-hint.
		$configService = $this->createMock(ConfigurationService::class);
		$configService->method('importFromApp')->willReturn(
			['registers' => [], 'schemas' => [], 'views' => []]
		);

		$appConfig->method('setValueString');

		$service = new SettingsLoadService(
			appConfig: $appConfig,
			appManager: $appManager,
			mapBuilder: $mapBuilder,
			fileLoader: $fileLoader,
			configurationService: $configService,
		);
		$result = $service->loadSettings();

		$this->assertIsArray($result);
		$this->assertArrayHasKey('registers', $result);
	}//end testLoadSettingsCallsConfigurationService()
}//end class
