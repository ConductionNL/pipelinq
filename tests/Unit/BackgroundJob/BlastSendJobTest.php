<?php

/**
 * Unit tests for BlastSendJob.
 *
 * Covers the spec scenarios:
 * - dispatches each sending blast (calls BlastService::dispatchBlastDeliveries)
 * - one-blast failure does not abort the loop
 * - webhook queue drain hands events to WebhookProcessorService
 * - empty queue is a no-op
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
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#blastsendjob
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\BlastSendJob;
use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\WebhookProcessorService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BlastSendJob.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#blastsendjob
 */
class BlastSendJobTest extends TestCase {
	private ITimeFactory $timeFactory;
	private IAppConfig $appConfig;
	private ContainerInterface $container;
	private BlastService $blastService;
	private WebhookProcessorService $webhookProcessor;
	private LoggerInterface $logger;
	private object $objectService;

	/**
	 * In-memory app-config store for the mock.
	 *
	 * @var array<string, string>
	 */
	private array $appConfigStore = [];

	/**
	 * Set up — mock collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->blastService = $this->createMock(BlastService::class);
		$this->webhookProcessor = $this->createMock(WebhookProcessorService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->timeFactory->method('getTime')->willReturn(time());

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return $this->appConfigStore[$key] ?? $default;
			}
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->appConfigStore[$key] = $value;
				return true;
			}
		);
		$this->appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): bool {
				unset($this->appConfigStore[$key]);
				return true;
			}
		);

		$this->objectService = new class {
			/** @var array<int, array<string, mixed>> */
			public array $sendingBlasts = [];

			/**
			 * Mock findAll() — returns the seeded sending blasts.
			 *
			 * Mirrors OR's real ObjectService::findAll(array $config): the
			 * data-property filters travel inside $config['filters'].
			 *
			 * @param array<string, mixed> $config Config with a `filters` map.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$filters = $config['filters'] ?? [];
				if (($filters['status'] ?? null) === 'sending') {
					return $this->sendingBlasts;
				}

				return [];
			}
		};

		$this->container->method('get')->willReturnCallback(
			function (string $class): object {
				if ($class === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}

				throw new \RuntimeException('Service not available: ' . $class);
			}
		);
	}//end setUp()

	/**
	 * Build the job under test.
	 *
	 * @return BlastSendJob
	 */
	private function buildJob(): BlastSendJob {
		return new BlastSendJob($this->timeFactory,
			$this->appConfig,
			$this->container,
			$this->blastService,
			$this->webhookProcessor,
			$this->logger,
		);
	}//end buildJob()

	/**
	 * Test job instantiation.
	 *
	 * @return void
	 */
	public function testJobCanBeInstantiated(): void {
		$this->assertInstanceOf(BlastSendJob::class, $this->buildJob());
	}//end testJobCanBeInstantiated()

	/**
	 * Test that the job dispatches every sending blast.
	 *
	 * @return void
	 */
	public function testDispatchSendingBlastsCallsServicePerBlast(): void {
		$this->objectService->sendingBlasts = [
			['uuid' => 'blast-1', 'status' => 'sending'],
			['uuid' => 'blast-2', 'status' => 'sending'],
		];

		$this->blastService->expects($this->exactly(2))
			->method('dispatchBlastDeliveries')
			->willReturn(7);

		$this->blastService->expects($this->exactly(2))
			->method('updateBlastTotals');

		$total = $this->buildJob()->dispatchSendingBlasts();
		$this->assertSame(14, $total);
	}//end testDispatchSendingBlastsCallsServicePerBlast()

	/**
	 * Test that a failure on one blast does not abort the loop — the second
	 * blast is still dispatched.
	 *
	 * @return void
	 */
	public function testDispatchContinuesOnPerBlastFailure(): void {
		$this->objectService->sendingBlasts = [
			['uuid' => 'blast-bad', 'status' => 'sending'],
			['uuid' => 'blast-good', 'status' => 'sending'],
		];

		$callCount = 0;
		$this->blastService->method('dispatchBlastDeliveries')->willReturnCallback(
			function (string $blastId) use (&$callCount): int {
				$callCount++;
				if ($blastId === 'blast-bad') {
					throw new \RuntimeException('upstream provider down');
				}

				return 4;
			}
		);

		// Only the good blast should reach updateBlastTotals (the bad blast's
		// catch block continues without it).
		$this->blastService->expects($this->once())
			->method('updateBlastTotals')
			->with($this->equalTo('blast-good'));

		$total = $this->buildJob()->dispatchSendingBlasts();
		$this->assertSame(4, $total);
		$this->assertSame(2, $callCount, 'dispatch must be called for both blasts');
	}//end testDispatchContinuesOnPerBlastFailure()

	/**
	 * Test that the webhook queue drain hands events to the processor.
	 *
	 * @return void
	 */
	public function testDrainWebhookQueueProcessesEvents(): void {
		$queue = [
			['provider' => 'sendgrid', 'event' => ['eventType' => 'delivered']],
			['provider' => 'sendgrid', 'event' => ['eventType' => 'open']],
		];
		$this->appConfigStore[BlastSendJob::WEBHOOK_QUEUE_KEY] = json_encode($queue);

		$this->webhookProcessor->expects($this->exactly(2))
			->method('processEvent')
			->willReturn(true);

		$drained = $this->buildJob()->drainWebhookQueue();
		$this->assertSame(2, $drained);

		// After a successful full-drain the key is removed (empty queue).
		$this->assertArrayNotHasKey(
			BlastSendJob::WEBHOOK_QUEUE_KEY,
			$this->appConfigStore,
			'Drained queue should clear the app-config key',
		);
	}//end testDrainWebhookQueueProcessesEvents()

	/**
	 * Test that an empty webhook queue is a no-op.
	 *
	 * @return void
	 */
	public function testDrainIsNoOpWhenQueueEmpty(): void {
		$this->webhookProcessor->expects($this->never())->method('processEvent');
		$this->assertSame(0, $this->buildJob()->drainWebhookQueue());
	}//end testDrainIsNoOpWhenQueueEmpty()

	/**
	 * Test that enqueueWebhookEvent appends to the persisted queue.
	 *
	 * @return void
	 */
	public function testEnqueueWebhookEventAppendsToQueue(): void {
		$job = $this->buildJob();
		$job->enqueueWebhookEvent(provider: 'sendgrid', event: ['eventType' => 'open']);
		$job->enqueueWebhookEvent(provider: 'twilio', event: ['eventType' => 'bounce']);

		$stored = json_decode($this->appConfigStore[BlastSendJob::WEBHOOK_QUEUE_KEY], true);
		$this->assertCount(2, $stored);
		$this->assertSame('sendgrid', $stored[0]['provider']);
		$this->assertSame('twilio', $stored[1]['provider']);
	}//end testEnqueueWebhookEventAppendsToQueue()

	/**
	 * Golden: the identity a blast row is known by — its own uuid, id or slug first, then its `@self` one.
	 *
	 * @return void
	 */
	public function testBlastIdentityIsReadInThisOrder(): void {
		$extract = new \ReflectionMethod(BlastSendJob::class, 'extractBlastId');
		$job = $this->buildJob();
		$cases = [
			[['uuid' => 'u-1', 'id' => 'i-1', 'slug' => 's-1'], 'u-1'],
			[['uuid' => '', 'id' => 7, 'slug' => 's-1'], '7'],
			[['uuid' => ['x'], 'slug' => 's-1'], 's-1'],
			[['@self' => ['id' => 'self-1', 'uuid' => 'self-u']], 'self-u'],
			[['uuid' => '', '@self' => ['slug' => 'self-s']], 'self-s'],
			[['@self' => 'not-a-map'], ''],
			[['@self' => ['uuid' => false, 'id' => '']], ''],
			[[], ''],
		];
		foreach ($cases as [$row, $expected]) {
			$this->assertSame($expected, $extract->invoke($job, $row), (string)json_encode($row));
		}
	}//end testBlastIdentityIsReadInThisOrder()

	/**
	 * Golden: the sending-blast read — its filters, the rows it keeps, and a failing read.
	 *
	 * @return void
	 */
	public function testSendingBlastsAreReadExactly(): void {
		$load = new \ReflectionMethod(BlastSendJob::class, 'loadSendingBlasts');
		$this->objectService->sendingBlasts = [['uuid' => 'b-1', 'status' => 'sending'], [], ['uuid' => 'b-2']];
		$this->assertSame([['uuid' => 'b-1', 'status' => 'sending'], ['uuid' => 'b-2']], $load->invoke($this->buildJob()));

		$this->appConfigStore['blast_schema'] = '';
		$this->assertSame([], $load->invoke($this->buildJob()));
		unset($this->appConfigStore['blast_schema']);

		$failing = new class {
			/**
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed> Never.
			 */
			public function findAll(array $config = []): array {
				throw new \RuntimeException('register offline');
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($failing);
		$this->logger->expects($this->once())->method('warning')
			->with('BlastSendJob.loadSendingBlasts: findAll failed', ['exception' => 'register offline']);
		$job = new BlastSendJob($this->timeFactory, $this->appConfig, $container, $this->blastService, $this->webhookProcessor, $this->logger);
		$this->assertSame([], $load->invoke($job));
	}//end testSendingBlastsAreReadExactly()
}//end class
