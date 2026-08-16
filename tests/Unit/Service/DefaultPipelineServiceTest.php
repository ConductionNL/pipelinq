<?php

/**
 * Unit tests for DefaultPipelineService.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\DefaultPipelineService;
use OCA\Pipelinq\Service\PipelineStageData;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for DefaultPipelineService.
 */
class DefaultPipelineServiceTest extends TestCase {
	/**
	 * Test createDefaultPipelines skips when register not configured.
	 *
	 * @return void
	 */
	public function testSkipsWhenNotConfigured(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$stageData = new PipelineStageData();
		$logger = $this->createMock(LoggerInterface::class);

		$logger->expects($this->once())->method('warning');

		$service = new DefaultPipelineService($appConfig, $stageData, $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
		$service->createDefaultPipelines();
	}//end testSkipsWhenNotConfigured()

	/**
	 * Test createDefaultPipelines catches exceptions.
	 *
	 * The failure is raised by the OpenRegister call itself. It used to be
	 * raised by the container lookup, but the service takes an injected
	 * ObjectServiceInterface now (ADR-083/084), so a container that refuses to
	 * resolve never reaches this method — the throw moved to the only place
	 * that can still fail at runtime.
	 *
	 * @return void
	 */
	public function testCatchesExceptions(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('1');

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willThrowException(new \RuntimeException('OpenRegister unreachable'));

		$stageData = new PipelineStageData();
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$service = new DefaultPipelineService($appConfig, $stageData, $logger,
			objectService: $objectService,
		);

		// The point of the test: the throw is swallowed, not propagated.
		$service->createDefaultPipelines();
	}//end testCatchesExceptions()
}//end class
