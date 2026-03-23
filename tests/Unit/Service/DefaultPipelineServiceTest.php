<?php

/**
 * Unit tests for DefaultPipelineService.
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

use OCA\Pipelinq\Service\DefaultPipelineService;
use OCA\Pipelinq\Service\PipelineStageData;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DefaultPipelineService.
 */
class DefaultPipelineServiceTest extends TestCase
{
    /**
     * Test createDefaultPipelines skips when register not configured.
     *
     * @return void
     */
    public function testSkipsWhenNotConfigured(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');

        $container = $this->createMock(ContainerInterface::class);
        $stageData = new PipelineStageData();
        $logger    = $this->createMock(LoggerInterface::class);

        $logger->expects($this->once())->method('warning');

        $service = new DefaultPipelineService($appConfig, $container, $stageData, $logger);
        $service->createDefaultPipelines();
    }//end testSkipsWhenNotConfigured()

    /**
     * Test createDefaultPipelines catches exceptions.
     *
     * @return void
     */
    public function testCatchesExceptions(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('1');

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new \RuntimeException('Not found'));

        $stageData = new PipelineStageData();
        $logger    = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $service = new DefaultPipelineService($appConfig, $container, $stageData, $logger);
        $service->createDefaultPipelines();
    }//end testCatchesExceptions()
}//end class
