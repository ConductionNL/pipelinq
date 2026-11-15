<?php

/**
 * Unit tests for the follow-up task CtiDispositionService creates on a
 * callback or escalated disposition: the payload written, how the task id is
 * read back, and what happens when the task schema is missing or the save fails.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Cti;

use OCA\Pipelinq\Service\Cti\EscalationQueues;
use OCA\Pipelinq\Service\CtiDispositionService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Follow-up task creation on disposition.
 *
 * @covers \OCA\Pipelinq\Service\CtiDispositionService
 */
final class DispositionTaskCreationTest extends TestCase {

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Captured saveObject() calls.
	 *
	 * @var list<array<int|string, mixed>>
	 */
	private array $saves = [];

	/**
	 * What saveObject() answers, or throws.
	 *
	 * @var mixed
	 */
	private mixed $answer = null;

	/**
	 * App config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'pipelinq', 'task_schema' => 'task'];

	/**
	 * Set up shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->saves = [];

	}//end setUp()

	/**
	 * Build the service.
	 *
	 * @return CtiDispositionService
	 */
	private function service(): CtiDispositionService {
		$objects = new class($this) {
			/**
			 * Constructor.
			 *
			 * @param DispositionTaskCreationTest $test The test receiving the calls
			 */
			public function __construct(private DispositionTaskCreationTest $test) {
			}

			/**
			 * Save an object.
			 *
			 * @param mixed ...$args The arguments
			 *
			 * @return mixed
			 */
			public function saveObject(mixed ...$args): mixed {
				return $this->test->recordSave(args: $args);
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($objects);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$tickets = $this->createMock(TicketService::class);
		$tickets->method('isConfigured')->willReturn(false);
		$queues = $this->createMock(EscalationQueues::class);
		$queues->method('find')->with('q-1')->willReturn(['id' => 'q-1', 'label' => 'Tweede lijn', 'groupId' => 'grp-2']);

		return new CtiDispositionService($container, $appConfig, $tickets, $queues, $this->logger);

	}//end service()

	/**
	 * Record a saveObject() call and answer it.
	 *
	 * @param array<int|string, mixed> $args The call arguments
	 *
	 * @return mixed
	 */
	public function recordSave(array $args): mixed {
		$this->saves[] = $args;
		if ($this->answer instanceof \Throwable) {
			throw $this->answer;
		}

		return $this->answer;

	}//end recordSave()

	/**
	 * A callback writes an open callbackRequest linked to the interaction and returns its id.
	 *
	 * @return void
	 */
	public function testCallbackCreatesTask(): void {
		$this->answer = ['id' => 'task-1'];

		$result = $this->service()->processDisposition('cm-1', 'Terugbellen', 'callback', 'Na 14:00');

		$this->assertSame(['outcome' => 'callback', 'interactionId' => 'cm-1', 'taskId' => 'task-1'], $result);
		$this->assertSame(
			[
				[
					['type' => 'callbackRequest', 'subject' => 'Terugbellen', 'description' => 'Na 14:00', 'status' => 'open', 'interaction' => 'cm-1'],
					[],
					'pipelinq',
					'task',
					null,
				],
			],
			$this->saves
		);

	}//end testCallbackCreatesTask()

	/**
	 * An escalation writes a followUpTask for the queue's group; empty notes are left out.
	 *
	 * @return void
	 */
	public function testEscalationAssignsTheQueueGroup(): void {
		$this->answer = ['uuid' => 'task-2'];

		$result = $this->service()->processDisposition('cm-1', 'Klacht', 'escalated', '', 'q-1');

		$this->assertSame('task-2', $result['taskId']);
		$this->assertSame(
			['type' => 'followUpTask', 'subject' => 'Klacht', 'status' => 'open', 'assigneeGroupId' => 'grp-2', 'interaction' => 'cm-1'],
			$this->saves[0][0]
		);

	}//end testEscalationAssignsTheQueueGroup()

	/**
	 * An entity answer gives its uuid; an entity without one, or an array without an id, gives no task id.
	 *
	 * @return void
	 */
	public function testTaskIdIsReadFromTheAnswer(): void {
		$this->answer = new class {
			/**
			 * The uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return 'task-3';
			}
		};
		$this->assertSame('task-3', $this->service()->processDisposition('cm-1', 'S', 'callback', 'n')['taskId']);

		$this->answer = new class {
			/**
			 * No uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return '';
			}
		};
		$this->assertNull($this->service()->processDisposition('cm-1', 'S', 'callback', 'n')['taskId']);

		$this->answer = new \stdClass();
		$this->assertNull($this->service()->processDisposition('cm-1', 'S', 'callback', 'n')['taskId']);

		$this->answer = ['title' => 'no id'];
		$this->assertNull($this->service()->processDisposition('cm-1', 'S', 'callback', 'n')['taskId']);

		$this->answer = 42;
		$this->assertNull($this->service()->processDisposition('cm-1', 'S', 'callback', 'n')['taskId']);

	}//end testTaskIdIsReadFromTheAnswer()

	/**
	 * Without a configured task schema no task is written and a warning says so.
	 *
	 * @return void
	 */
	public function testMissingTaskSchemaSkipsTheTask(): void {
		$this->config = ['register' => 'pipelinq'];
		$this->logger->expects($this->exactly(2))
			->method('warning')
			->willReturnCallback(
				function (string $message): void {
					$this->assertContains(
						$message,
						[
							'CTI disposition: ticket register/schema not configured.',
							'CTI disposition: task register/schema not configured -- skipping task creation.',
						]
					);
				}
			);

		$this->assertNull($this->service()->processDisposition('cm-1', 'S', 'callback', 'n')['taskId']);
		$this->assertSame([], $this->saves);

	}//end testMissingTaskSchemaSkipsTheTask()

	/**
	 * A failing save is logged as an error with the interaction and gives no task id.
	 *
	 * @return void
	 */
	public function testFailedSaveIsLogged(): void {
		$this->answer = new RuntimeException('validation failed');
		$this->logger->expects($this->once())
			->method('error')
			->with('CTI disposition: task save failed', ['exception' => 'validation failed', 'interactionId' => 'cm-1']);

		$this->assertNull($this->service()->processDisposition('cm-1', 'S', 'callback', 'n')['taskId']);

	}//end testFailedSaveIsLogged()

	/**
	 * Outcomes without a follow-up write no task.
	 *
	 * @return void
	 */
	public function testResolvedWritesNoTask(): void {
		$this->answer = ['id' => 'task-1'];

		$this->assertNull($this->service()->processDisposition('cm-1', 'S', 'resolved', 'n')['taskId']);
		$this->assertSame([], $this->saves);

	}//end testResolvedWritesNoTask()
}//end class
