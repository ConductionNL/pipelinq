<?php

/**
 * Unit tests for the BlastSendJob cycle: the logged run, skipped rows, the
 * OpenRegister-unavailable path and the webhook-queue edge cases.
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
use OCA\Pipelinq\Kernel\Contracts\BlastServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\WebhookProcessorServiceInterface;
use OCA\Pipelinq\Service\SystemServiceAccount;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the BlastSendJob cycle.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#blastsendjob
 *
 * @covers \OCA\Pipelinq\BackgroundJob\BlastSendJob
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 * @uses   \OCA\Pipelinq\Util\ContainerObjectServiceLookup
 * @uses   \OCA\Pipelinq\Service\SystemServiceAccount
 */
class BlastSendJobCycleTest extends TestCase {

	/**
	 * In-memory app config.
	 *
	 * @var array<string, string>
	 */
	private array $store = [];

	/**
	 * Log calls as [level, message, context].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * The session's active user; null while nobody is signed in (cron).
	 *
	 * @var IUser|null
	 */
	private ?IUser $active = null;

	/**
	 * The pipelinq system account the job switches to.
	 *
	 * @var IUser|null
	 */
	private ?IUser $system = null;

	/**
	 * Build the job over the in-memory config, a container and the two services.
	 *
	 * @param ContainerInterface|null $container The container; one without OpenRegister when null.
	 * @param BlastServiceInterface|null $blasts The blast service.
	 * @param WebhookProcessorServiceInterface|null $webhooks The webhook processor.
	 * @param bool $deleteFails Whether deleteKey throws.
	 *
	 * @return BlastSendJob
	 */
	private function job(
		?ContainerInterface $container = null,
		?BlastServiceInterface $blasts = null,
		?WebhookProcessorServiceInterface $webhooks = null,
		bool $deleteFails = false,
	): BlastSendJob {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->store[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
		$config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key) use ($deleteFails): void {
				if ($deleteFails === true) {
					throw new RuntimeException('config is read-only');
				}

				unset($this->store[$key]);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		if ($container === null) {
			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willThrowException(new RuntimeException('OpenRegister is not installed'));
		}

		return new BlastSendJob(
			$this->createMock(ITimeFactory::class),
			$config,
			$container,
			($blasts ?? $this->createMock(BlastServiceInterface::class)),
			($webhooks ?? $this->createMock(WebhookProcessorServiceInterface::class)),
			$logger,
			$this->systemAccount(),
		);
	}//end job()

	/**
	 * A system account over a session whose active user this test tracks.
	 *
	 * @return SystemServiceAccount The account.
	 */
	private function systemAccount(): SystemServiceAccount {
		$this->system = $this->createMock(originalClassName: IUser::class);

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->active);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->active = $user;
			}
		);

		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('get')->willReturn($this->system);

		return new SystemServiceAccount(userManager: $users, userSession: $session, random: $this->createMock(originalClassName: ISecureRandom::class));
	}//end systemAccount()

	/**
	 * A container whose ObjectService returns the given sending rows.
	 *
	 * @param array<int, mixed> $rows The rows findAll() returns.
	 *
	 * @return ContainerInterface
	 */
	private function containerWith(array $rows): ContainerInterface {
		$objectService = new class($rows) {
			/**
			 * @param array<int, mixed> $rows The rows.
			 */
			public function __construct(
				private array $rows,
			) {
			}

			/**
			 * The seeded rows.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config = []): array {
				return $this->rows;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($objectService);
		return $container;
	}//end containerWith()

	/**
	 * The messages logged at one level.
	 *
	 * @param string $level The level.
	 *
	 * @return list<string>
	 */
	private function messages(string $level): array {
		return array_values(array_map(static fn (array $log): string => $log[1], array_filter($this->logs, static fn (array $log): bool => $log[0] === $level)));
	}//end messages()

	/**
	 * A run dispatches, drains and logs the counts of both.
	 *
	 * @return void
	 */
	public function testARunLogsTheStartAndTheCountsOfACompletedCycle(): void {
		$this->store[BlastSendJob::WEBHOOK_QUEUE_KEY] = json_encode([['provider' => 'mailgun', 'event' => ['id' => 'e1']]]);
		$blasts = $this->createMock(BlastServiceInterface::class);
		$blasts->method('dispatchBlastDeliveries')->with('b-1')->willReturn(3);
		$job = $this->job(container: $this->containerWith([['uuid' => 'b-1']]), blasts: $blasts);

		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);

		$this->assertSame(['BlastSendJob: starting cycle', 'BlastSendJob: cycle complete'], $this->messages('info'));
		$this->assertSame(['dispatched' => 3, 'webhookEventsDrained' => 1], $this->logs[1][2]);
		$this->assertArrayNotHasKey(BlastSendJob::WEBHOOK_QUEUE_KEY, $this->store);
	}//end testARunLogsTheStartAndTheCountsOfACompletedCycle()

	/**
	 * Under cron nobody is signed in: the cycle reads and dispatches the blasts
	 * as the pipelinq system account and leaves the session as it found it
	 * (found live: an anonymous read of a blast is refused, so no mailing was
	 * ever sent). A signed-in caller keeps its own identity.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-mail-templates/tasks.md#task-2.7
	 */
	public function testUnderCronTheCycleRunsAsTheSystemAccount(): void {
		$actors = [];
		$blasts = $this->createMock(originalClassName: BlastServiceInterface::class);
		$blasts->method('dispatchBlastDeliveries')->willReturnCallback(
			function (string $blastId) use (&$actors): int {
				$actors[] = $this->active;
				return 1;
			}
		);
		$job = $this->job(container: $this->containerWith(rows: [['uuid' => 'b-1']]), blasts: $blasts);

		(new \ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame(expected: [$this->system], actual: $actors);
		$this->assertNull(actual: $this->active);

		$caller       = $this->createMock(originalClassName: IUser::class);
		$this->active = $caller;
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame(expected: [$this->system, $caller], actual: $actors);
		$this->assertSame(expected: $caller, actual: $this->active);
	}//end testUnderCronTheCycleRunsAsTheSystemAccount()

	/**
	 * Without OpenRegister there is nothing to dispatch, and that is said at debug level.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsDispatched(): void {
		$blasts = $this->createMock(BlastServiceInterface::class);
		$blasts->expects($this->never())->method('dispatchBlastDeliveries');

		$this->assertSame(0, $this->job(blasts: $blasts)->dispatchSendingBlasts());
		$this->assertSame(['BlastSendJob.getObjectService: OpenRegister unavailable'], $this->messages('warning'));
		$this->assertSame(['BlastSendJob: no sending blasts'], $this->messages('debug'));
	}//end testWithoutOpenRegisterNothingIsDispatched()

	/**
	 * A blast without uuid, id or slug is skipped; the id is read from `@self`
	 * when the top level has none, and `id` / `slug` count when `uuid` is empty.
	 *
	 * @return void
	 */
	public function testABlastWithoutAnIdIsSkippedAndTheIdIsFoundInSelfIdOrSlug(): void {
		$dispatched = [];
		$blasts = $this->createMock(BlastServiceInterface::class);
		$blasts->method('dispatchBlastDeliveries')->willReturnCallback(
			function (string $blastId) use (&$dispatched): int {
				$dispatched[] = $blastId;
				return 1;
			}
		);
		$rows = [
			['title' => 'no id'],
			['@self' => ['id' => 'from-self']],
			['uuid' => '', 'slug' => 'by-slug'],
			['id' => 42],
			['@self' => 'not-an-array'],
		];

		$total = $this->job(container: $this->containerWith($rows), blasts: $blasts)->dispatchSendingBlasts();

		$this->assertSame(['from-self', 'by-slug', '42'], $dispatched);
		$this->assertSame(3, $total);
	}//end testABlastWithoutAnIdIsSkippedAndTheIdIsFoundInSelfIdOrSlug()

	/**
	 * A queued envelope whose event is not an array is dropped unprocessed, and
	 * a failing event is logged and not counted; the batch leaves the queue.
	 *
	 * @return void
	 */
	public function testAMalformedOrFailingWebhookEventIsNotCountedAndLeavesTheQueue(): void {
		$this->store[BlastSendJob::WEBHOOK_QUEUE_KEY] = json_encode([
			['provider' => 'mailgun', 'event' => 'broken'],
			['provider' => 'sendgrid', 'event' => ['id' => 'boom']],
			['event' => ['id' => 'ok']],
		]);
		$seen = [];
		$webhooks = $this->createMock(WebhookProcessorServiceInterface::class);
		$webhooks->method('processEvent')->willReturnCallback(
			function (array $event, string $provider) use (&$seen): bool {
				$seen[] = $provider . ':' . $event['id'];
				if ($event['id'] === 'boom') {
					throw new RuntimeException('provider rejected');
				}

				return true;
			}
		);

		$processed = $this->job(webhooks: $webhooks)->drainWebhookQueue();

		$this->assertSame(1, $processed);
		$this->assertSame(['sendgrid:boom', 'unknown:ok'], $seen);
		$this->assertSame(['BlastSendJob: webhook event processing failed'], $this->messages('warning'));
		$this->assertSame(['provider' => 'sendgrid', 'exception' => 'provider rejected'], $this->logs[0][2]);
		$this->assertArrayNotHasKey(BlastSendJob::WEBHOOK_QUEUE_KEY, $this->store);
	}//end testAMalformedOrFailingWebhookEventIsNotCountedAndLeavesTheQueue()

	/**
	 * A stored queue that is not a JSON array reads as empty.
	 *
	 * @return void
	 */
	public function testAQueueThatIsNotJsonReadsAsEmpty(): void {
		$this->store[BlastSendJob::WEBHOOK_QUEUE_KEY] = 'not json';
		$webhooks = $this->createMock(WebhookProcessorServiceInterface::class);
		$webhooks->expects($this->never())->method('processEvent');

		$this->assertSame(0, $this->job(webhooks: $webhooks)->drainWebhookQueue());
		$this->assertSame('not json', $this->store[BlastSendJob::WEBHOOK_QUEUE_KEY]);
	}//end testAQueueThatIsNotJsonReadsAsEmpty()

	/**
	 * When the emptied queue cannot be deleted it is stored as `[]` instead.
	 *
	 * @return void
	 */
	public function testAnEmptiedQueueThatCannotBeDeletedIsStoredAsAnEmptyArray(): void {
		$this->store[BlastSendJob::WEBHOOK_QUEUE_KEY] = json_encode([['provider' => 'mailgun', 'event' => ['id' => 'e1']]]);

		$this->assertSame(1, $this->job(deleteFails: true)->drainWebhookQueue());
		$this->assertSame('[]', $this->store[BlastSendJob::WEBHOOK_QUEUE_KEY]);
		$this->assertSame(['BlastSendJob.writeQueue: deleteKey failed — falling back to empty array'], $this->messages('info'));
	}//end testAnEmptiedQueueThatCannotBeDeletedIsStoredAsAnEmptyArray()

	/**
	 * An event that cannot be encoded leaves the stored queue as it was.
	 *
	 * @return void
	 */
	public function testAnEventThatCannotBeEncodedLeavesTheQueueAlone(): void {
		$this->store[BlastSendJob::WEBHOOK_QUEUE_KEY] = json_encode([['provider' => 'mailgun', 'event' => ['id' => 'e1']]]);

		$this->job()->enqueueWebhookEvent(provider: 'mailgun', event: ['body' => "\xB1\x31"]);

		$this->assertSame(json_encode([['provider' => 'mailgun', 'event' => ['id' => 'e1']]]), $this->store[BlastSendJob::WEBHOOK_QUEUE_KEY]);
	}//end testAnEventThatCannotBeEncodedLeavesTheQueueAlone()
}//end class
