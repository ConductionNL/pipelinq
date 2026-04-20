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

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Service\SettingsLoadService;
use OCA\Pipelinq\Service\SettingsMapBuilder;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for SettingsLoadService.
 */
class SettingsLoadServiceTest extends TestCase
{
    /**
     * Test loadSettings calls configuration service.
     *
     * @return void
     */
    public function testLoadSettingsCallsConfigurationService(): void
    {
        $appConfig  = $this->createMock(IAppConfig::class);
        $appManager = $this->createMock(IAppManager::class);
        $container  = $this->createMock(ContainerInterface::class);
        $mapBuilder = new SettingsMapBuilder();
        $fileLoader = $this->createMock(ConfigFileLoaderService::class);

        $fileLoader->method('loadConfigurationFile')->willReturn(['key' => 'val']);
        $fileLoader->method('ensureSourceType')->willReturnArgument(0);
        $appManager->method('getAppVersion')->willReturn('1.0.0');

        $configService = new class {
            public function importFromApp(string $appId, array $data, string $version, bool $force): array
            {
                return ['registers' => [], 'schemas' => [], 'views' => []];
            }
        };

        $container->method('get')->willReturn($configService);
        $appConfig->method('setValueString');

        $service = new SettingsLoadService($appConfig, $appManager, $container, $mapBuilder, $fileLoader);
        $result  = $service->loadSettings();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('registers', $result);
    }//end testLoadSettingsCallsConfigurationService()
}//end class
