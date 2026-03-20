<?php

/**
 * Unit tests for ConfigFileLoaderService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ConfigFileLoaderService.
 */
class ConfigFileLoaderServiceTest extends TestCase
{
    /**
     * Test that ensureSourceType adds sourceType when missing.
     *
     * @return void
     */
    public function testEnsureSourceTypeAddsWhenMissing(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $service    = new ConfigFileLoaderService($appManager);

        $data   = ['openapi' => '3.0.0'];
        $result = $service->ensureSourceType($data);

        $this->assertArrayHasKey('x-openregister', $result);
        $this->assertEquals('local', $result['x-openregister']['sourceType']);
    }//end testEnsureSourceTypeAddsWhenMissing()

    /**
     * Test that ensureSourceType preserves existing sourceType.
     *
     * @return void
     */
    public function testEnsureSourceTypePreservesExisting(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $service    = new ConfigFileLoaderService($appManager);

        $data   = ['x-openregister' => ['sourceType' => 'remote']];
        $result = $service->ensureSourceType($data);

        $this->assertEquals('remote', $result['x-openregister']['sourceType']);
    }//end testEnsureSourceTypePreservesExisting()

    /**
     * Test that ensureSourceType handles empty x-openregister block.
     *
     * @return void
     */
    public function testEnsureSourceTypeWithEmptyBlock(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $service    = new ConfigFileLoaderService($appManager);

        $data   = ['x-openregister' => []];
        $result = $service->ensureSourceType($data);

        $this->assertEquals('local', $result['x-openregister']['sourceType']);
    }//end testEnsureSourceTypeWithEmptyBlock()

    /**
     * Test that loadConfigurationFile throws when file not found.
     *
     * @return void
     */
    public function testLoadConfigurationFileThrowsWhenNotFound(): void
    {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('getAppPath')->willReturn('/nonexistent/path');

        $service = new ConfigFileLoaderService($appManager);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuration file not found');

        $service->loadConfigurationFile();
    }//end testLoadConfigurationFileThrowsWhenNotFound()
}//end class
