<?php

/**
 * Unit tests for AvailabilityCacheRefreshJob's resource lookup: an unresolvable ObjectService and a lookup that answers with something else than a list.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\AvailabilityCacheRefreshJob;
use OCA\Pipelinq\Kernel\Contracts\AvailabilityServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the resource lookup of AvailabilityCacheRefreshJob.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\AvailabilityCacheRefreshJob
 */
class AvailabilityCacheRefreshJobLookupTest extends TestCase {

	/**
	 * Run the job once with a configured register and resource schema.
	 *
	 * @param ContainerInterface $container The container.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return AvailabilityServiceInterface&\PHPUnit\Framework\MockObject\MockObject The availability service, which must not be called.
	 */
	private function runWith(ContainerInterface $container, LoggerInterface $logger): AvailabilityServiceInterface {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => 'pipelinq', 'resource_schema' => 'resource'][$key] ?? $default
		);
		$availability = $this->createMock(AvailabilityServiceInterface::class);
		$availability->expects($this->never())->method('invalidateCache');

		$job = new AvailabilityCacheRefreshJob($this->createMock(ITimeFactory::class), $container, $config, $availability, $logger);
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);

		return $availability;
	}//end runWith()

	/**
	 * An ObjectService the container cannot build is logged as a failed lookup
	 * and no cache is invalidated.
	 *
	 * @return void
	 */
	public function testAnUnresolvableObjectServiceIsALoggedFailedLookup(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('boot failed'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Pipelinq: availability cache refresh resource lookup failed',
			['exception' => 'OpenRegister ObjectService is unavailable.']
		);
		$logger->expects($this->never())->method('info');

		$this->runWith($container, $logger);
	}//end testAnUnresolvableObjectServiceIsALoggedFailedLookup()

	/**
	 * A lookup that answers with something else than a list finds no resources.
	 *
	 * @return void
	 */
	public function testALookupThatIsNotAListFindsNoResources(): void {
		$objectService = new class {
			/**
			 * Not a list.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return string
			 */
			public function findAll(array $config = []): string {
				return 'unexpected';
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->never())->method('info');

		$this->runWith($container, $logger);
	}//end testALookupThatIsNotAListFindsNoResources()
}//end class
