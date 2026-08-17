<?php

/**
 * Unit tests for QueueService.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\QueueService;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for QueueService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class QueueServiceTest extends TestCase {

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
	 * The ticket resolver mock (unified `ticket` schema).
	 *
	 * @var TicketService&MockObject
	 */
	private TicketService $ticketService;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->ticketService = $this->createMock(originalClassName: TicketService::class);
	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * Queued items are request tickets, so the queue reads/writes resolve the
	 * unified `ticket` schema through TicketService.
	 *
	 * @return QueueService
	 */
	private function buildService(): QueueService {
		return new QueueService(
			appConfig: $this->appConfig,
			logger: $this->logger,
			registerResolver: new RegisterResolverService(appConfig: $this->appConfig),
			ticketService: $this->ticketService,
			objectService: $this->objectService,
		);
	}//end buildService()

	/**
	 * Configure the ticket resolver as provisioned (or not).
	 *
	 * @param string $ticketSchema The unified ticket schema ID ('' = unconfigured).
	 *
	 * @return void
	 */
	private function configureTicketService(string $ticketSchema = 'ticket-schema-id'): void {
		$this->ticketService->method('getRegisterId')->willReturn($ticketSchema === '' ? '' : 'reg-id');
		$this->ticketService->method('getSchemaId')->willReturn($ticketSchema);
		$this->ticketService->method('isConfigured')->willReturn($ticketSchema !== '');
	}//end configureTicketService()

	/**
	 * Configure app config (register + queue schema) and the ticket resolver.
	 *
	 * @param string $register The register ID.
	 * @param string $ticketSchema The unified ticket schema ID.
	 * @param string $queueSchema The queue schema ID.
	 *
	 * @return void
	 */
	private function configureAppConfig(
		string $register = 'reg-id',
		string $ticketSchema = 'ticket-schema-id',
		string $queueSchema = 'queue-schema-id',
	): void {
		$this->appConfig
			->method('getValueString')
			->willReturnMap(
				[
					[Application::APP_ID, 'register', '', $register],
					[Application::APP_ID, 'queue_schema', '', $queueSchema],
				]
			);

		$this->configureTicketService(ticketSchema: $ticketSchema);
	}//end configureAppConfig()

	/**
	 * The injected ObjectService double.
	 *
	 * Since ADR-084 the service takes ObjectServiceInterface as a constructor
	 * argument, so there is no container hop left to intercept: the double the
	 * test configures IS the one the service uses.
	 *
	 * @return ObjectServiceInterface&MockObject
	 */
	private function createObjectServiceMock(): MockObject {
		return $this->objectService;
	}//end createObjectServiceMock()

	/**
	 * Test getQueueDepth returns zero when register is not configured.
	 *
	 * @return void
	 */
	public function testGetQueueDepthReturnsZeroWhenNotConfigured(): void {
		$this->configureAppConfig(register: '', ticketSchema: '', queueSchema: '');

		$this->logger->expects($this->once())->method('warning');

		$result = $this->buildService()->getQueueDepth(queueId: 'some-queue-id');
		$this->assertSame(expected: 0, actual: $result);
	}//end testGetQueueDepthReturnsZeroWhenNotConfigured()

	/**
	 * Test getQueueDepth returns the true item count from ObjectService::count().
	 *
	 * Regression guard for issue #286: the previous implementation called
	 * findAll(limit: 1) then count()ed the PHP array, capping the reported
	 * depth at 1. The depth is now pushed down to OpenRegister's COUNT, so a
	 * queue holding more than one item reports its real depth.
	 *
	 * @return void
	 */
	public function testGetQueueDepthReturnsItemCount(): void {
		$this->configureAppConfig();

		$objectService = $this->createObjectServiceMock();
		$objectService->expects($this->once())
			->method('count')
			->with($this->callback(
					static function (array $config): bool {
						$filters = ($config['filters'] ?? []);
						return ($filters['queue'] ?? null) === 'queue-123'
						// Queued items are request tickets on the unified ticket
						// schema, narrowed by the ticketType discriminator.
						&& ($filters['schema'] ?? null) === 'ticket-schema-id'
						&& ($filters['ticketType'] ?? null) === TicketService::TYPE_REQUEST
						&& array_key_exists('register', $filters)
						&& array_key_exists('limit', $config) === false;
					}
				)
			)
			->willReturn(3);

		$result = $this->buildService()->getQueueDepth(queueId: 'queue-123');
		$this->assertSame(expected: 3, actual: $result);
	}//end testGetQueueDepthReturnsItemCount()

	/**
	 * Test getQueueDepth returns zero on exception.
	 *
	 * @return void
	 */
	public function testGetQueueDepthReturnsZeroOnException(): void {
		$this->configureAppConfig();

		$this->objectService->method('count')->willThrowException(new RuntimeException('service unavailable'));
		$this->logger->expects($this->once())->method('error');

		$result = $this->buildService()->getQueueDepth(queueId: 'queue-123');
		$this->assertSame(expected: 0, actual: $result);
	}//end testGetQueueDepthReturnsZeroOnException()

	/**
	 * Test isAtCapacity returns false when maxCapacity is null.
	 *
	 * @return void
	 */
	public function testIsAtCapacityReturnsFalseWhenNoLimit(): void {
		$queue = ['id' => 'q1', 'maxCapacity' => null];

		$result = $this->buildService()->isAtCapacity(queue: $queue, currentCount: 100);
		$this->assertFalse(condition: $result);
	}//end testIsAtCapacityReturnsFalseWhenNoLimit()

	/**
	 * Test isAtCapacity returns true when at capacity.
	 *
	 * @return void
	 */
	public function testIsAtCapacityReturnsTrueWhenAtLimit(): void {
		$queue = ['id' => 'q1', 'maxCapacity' => 50];

		$result = $this->buildService()->isAtCapacity(queue: $queue, currentCount: 50);
		$this->assertTrue(condition: $result);
	}//end testIsAtCapacityReturnsTrueWhenAtLimit()

	/**
	 * Test isAtCapacity returns true when over capacity.
	 *
	 * @return void
	 */
	public function testIsAtCapacityReturnsTrueWhenOverLimit(): void {
		$queue = ['id' => 'q1', 'maxCapacity' => 50];

		$result = $this->buildService()->isAtCapacity(queue: $queue, currentCount: 55);
		$this->assertTrue(condition: $result);
	}//end testIsAtCapacityReturnsTrueWhenOverLimit()

	/**
	 * Test isAtCapacity returns false when under capacity.
	 *
	 * @return void
	 */
	public function testIsAtCapacityReturnsFalseWhenUnderLimit(): void {
		$queue = ['id' => 'q1', 'maxCapacity' => 50];

		$result = $this->buildService()->isAtCapacity(queue: $queue, currentCount: 30);
		$this->assertFalse(condition: $result);
	}//end testIsAtCapacityReturnsFalseWhenUnderLimit()

	/**
	 * Test assignToQueue writes the queue field through the ticket resolver.
	 *
	 * The write no longer touches ObjectService::saveObject directly (the API
	 * mismatch of issue #286): it goes through TicketService::save(), which
	 * resolves the unified ticket schema and stamps the `ticketType`
	 * discriminator onto the payload.
	 *
	 * @return void
	 */
	public function testAssignToQueueUpdatesSaveObject(): void {
		$this->configureAppConfig();

		$this->ticketService
			->expects($this->once())
			->method('save')
			->with($this->equalTo(value: TicketService::TYPE_REQUEST),
				$this->equalTo(value: ['id' => 'request-123', 'queue' => 'queue-456']),
				$this->isNull(),
			)
			->willReturn(new \stdClass());

		$result = $this->buildService()->assignToQueue(requestId: 'request-123', queueId: 'queue-456');
		$this->assertTrue(condition: $result);
	}//end testAssignToQueueUpdatesSaveObject()

	// testRemoveFromQueueClearsQueueField was removed with
	// QueueService::removeFromQueue() — a one-statement wrapper with no caller.

	/**
	 * Test assignToQueue returns false when config is missing.
	 *
	 * @return void
	 */
	public function testAssignToQueueReturnsFalseWhenNotConfigured(): void {
		$this->configureAppConfig(register: '', ticketSchema: '', queueSchema: '');

		$result = $this->buildService()->assignToQueue(requestId: 'req-1', queueId: 'queue-1');
		$this->assertFalse(condition: $result);
	}//end testAssignToQueueReturnsFalseWhenNotConfigured()

	/**
	 * Test assignToQueue returns false on exception.
	 *
	 * @return void
	 */
	public function testAssignToQueueReturnsFalseOnException(): void {
		$this->configureAppConfig();

		// TicketService::save() throws when OpenRegister is unavailable.
		$this->ticketService->method('save')->willThrowException(new RuntimeException('fail'));
		$this->logger->expects($this->once())->method('error');

		$result = $this->buildService()->assignToQueue(requestId: 'req-1', queueId: 'queue-1');
		$this->assertFalse(condition: $result);
	}//end testAssignToQueueReturnsFalseOnException()

	/**
	 * Test processOverflow returns zero when not configured.
	 *
	 * @return void
	 */
	public function testProcessOverflowReturnsZeroWhenNotConfigured(): void {
		$this->configureAppConfig(register: '', ticketSchema: '', queueSchema: '');

		$this->logger->expects($this->once())->method('warning');

		$result = $this->buildService()->processOverflow();
		$this->assertSame(expected: 0, actual: $result);
	}//end testProcessOverflowReturnsZeroWhenNotConfigured()
}//end class
