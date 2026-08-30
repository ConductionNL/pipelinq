<?php

/**
 * Unit tests for DefaultQueueService.
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
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\DefaultQueueService;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for DefaultQueueService.
 */
class DefaultQueueServiceTest extends TestCase {
	/**
	 * The app config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The injected OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return DefaultQueueService
	 */
	private function buildService(): DefaultQueueService {
		return new DefaultQueueService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			registerResolver: new RegisterResolverService(appConfig: $this->appConfig),
			objectService: $this->objectService,
		);
	}//end buildService()

	/**
	 * Test that createDefaultQueues logs a warning and skips when register is not configured.
	 *
	 * @return void
	 */
	public function testCreateDefaultQueuesSkipsWhenRegisterNotConfigured(): void {
		$this->appConfig
			->method('getValueString')
			->willReturnMap([
				[Application::APP_ID, 'register', '', ''],
				[Application::APP_ID, 'queue_schema', '', ''],
			]);

		$this->logger->expects($this->once())->method('warning');
		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('saveObject');

		$this->buildService()->createDefaultQueues();
	}//end testCreateDefaultQueuesSkipsWhenRegisterNotConfigured()

	/**
	 * Test that createDefaultQueues skips when queue_schema is not configured.
	 *
	 * @return void
	 */
	public function testCreateDefaultQueuesSkipsWhenSchemaNotConfigured(): void {
		$this->appConfig
			->method('getValueString')
			->willReturnMap([
				[Application::APP_ID, 'register', '', 'some-register'],
				[Application::APP_ID, 'queue_schema', '', ''],
			]);

		$this->logger->expects($this->once())->method('warning');

		$this->buildService()->createDefaultQueues();
	}//end testCreateDefaultQueuesSkipsWhenSchemaNotConfigured()

	/**
	 * Test that createDefaultQueues skips when queues already exist.
	 *
	 * @return void
	 */
	public function testCreateDefaultQueuesSkipsWhenQueuesAlreadyExist(): void {
		$this->appConfig
			->method('getValueString')
			->willReturnMap([
				[Application::APP_ID, 'register', '', 'reg-id'],
				[Application::APP_ID, 'queue_schema', '', 'schema-id'],
			]);

		$this->objectService
			->method('findAll')
			->willReturn([['id' => 'existing-queue']]);
		$this->objectService
			->expects($this->never())
			->method('saveObject');

		$this->logger->expects($this->once())->method('info');

		$this->buildService()->createDefaultQueues();
	}//end testCreateDefaultQueuesSkipsWhenQueuesAlreadyExist()

	/**
	 * Test that createDefaultQueues creates all 3 default queues when none exist.
	 *
	 * @return void
	 */
	public function testCreateDefaultQueuesCreatesDefaultQueues(): void {
		$this->appConfig
			->method('getValueString')
			->willReturnMap([
				[Application::APP_ID, 'register', '', 'reg-id'],
				[Application::APP_ID, 'queue_schema', '', 'schema-id'],
			]);

		$this->objectService
			->method('findAll')
			->willReturn([]);
		// 3 default queues defined in DEFAULT_QUEUES constant.
		$this->objectService
			->expects($this->exactly(3))
			->method('saveObject');

		$this->buildService()->createDefaultQueues();
	}//end testCreateDefaultQueuesCreatesDefaultQueues()

	/**
	 * Test that createDefaultSkills skips when skill_schema is not configured.
	 *
	 * @return void
	 */
	public function testCreateDefaultSkillsSkipsWhenSchemaNotConfigured(): void {
		$this->appConfig
			->method('getValueString')
			->willReturnMap([
				[Application::APP_ID, 'register', '', 'reg-id'],
				[Application::APP_ID, 'skill_schema', '', ''],
			]);

		$this->logger->expects($this->once())->method('warning');

		$this->buildService()->createDefaultSkills();
	}//end testCreateDefaultSkillsSkipsWhenSchemaNotConfigured()

	/**
	 * Test that createDefaultSkills creates all 5 default skills when none exist.
	 *
	 * @return void
	 */
	public function testCreateDefaultSkillsCreatesDefaultSkills(): void {
		$this->appConfig
			->method('getValueString')
			->willReturnMap([
				[Application::APP_ID, 'register', '', 'reg-id'],
				[Application::APP_ID, 'skill_schema', '', 'skill-schema-id'],
			]);

		$this->objectService->method('findAll')->willReturn([]);
		// 5 default skills defined in DEFAULT_SKILLS constant.
		$this->objectService->expects($this->exactly(5))->method('saveObject');

		$this->buildService()->createDefaultSkills();
	}//end testCreateDefaultSkillsCreatesDefaultSkills()

	/**
	 * Test that createDefaultQueues logs an error on exception.
	 *
	 * @return void
	 */
	public function testCreateDefaultQueuesLogsErrorOnException(): void {
		$this->appConfig
			->method('getValueString')
			->willReturnMap([
				[Application::APP_ID, 'register', '', 'reg-id'],
				[Application::APP_ID, 'queue_schema', '', 'schema-id'],
			]);

		$this->objectService->method('findAll')->willThrowException(new \RuntimeException('object service error'));
		$this->logger->expects($this->once())->method('error');

		$this->buildService()->createDefaultQueues();
	}//end testCreateDefaultQueuesLogsErrorOnException()
}//end class
