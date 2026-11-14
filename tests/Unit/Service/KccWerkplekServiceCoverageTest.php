<?php

/**
 * Coverage tests for KccWerkplekService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Mcp\McpAnswer;
use OCA\Pipelinq\Service\KccWerkplekService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Exercises the workspace aggregation and availability toggle of
 * KccWerkplekService over OpenRegister and container doubles.
 *
 * @covers \OCA\Pipelinq\Service\KccWerkplekService
 * @uses   \OCA\Pipelinq\Service\TicketService
 * @uses   \OCA\Pipelinq\Mcp\McpAnswer
 */
class KccWerkplekServiceCoverageTest extends TestCase {
	/**
	 * App-config values the double answers, keyed by config key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Rows the object-service double holds, keyed by schema id.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $rows = [];

	/**
	 * Every findAll config the object-service double received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The object-service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The logger double handed to the subject.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The container double.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Set up a fully-configured instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = [
			'register' => 'reg',
			'ticket_schema' => 'sch-ticket',
			'task_schema' => 'sch-task',
			'agentProfile_schema' => 'sch-agent',
		];
		$this->rows = [];
		$this->queries = [];
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->container->method('get')->willReturn($this->objectService);
	}//end setUp()

	/**
	 * Route findAll on the object-service double to the per-schema rows.
	 *
	 * @return void
	 */
	private function serveRows(): void {
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$this->queries[] = $config;
				$schema = (string)($config['filters']['schema'] ?? '');

				return ($this->rows[$schema] ?? []);
			}
		);
	}//end serveRows()

	/**
	 * Build the subject over the doubles, with a real TicketService.
	 *
	 * @return KccWerkplekService The service under test.
	 */
	private function service(): KccWerkplekService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return ($this->config[$key] ?? $default);
			}
		);

		$ticketLogger = $this->createMock(originalClassName: LoggerInterface::class);
		$tickets = new TicketService(
			appConfig: $appConfig,
			logger: $ticketLogger,
			objectService: $this->objectService,
			mcp: new McpAnswer(logger: $ticketLogger),
		);

		return new KccWerkplekService(
			container: $this->container,
			appConfig: $appConfig,
			logger: $this->logger,
			ticketService: $tickets,
		);
	}//end service()

	/**
	 * The workspace merges tickets, tasks and the matching agent profile.
	 *
	 * @return void
	 */
	public function testWorkspaceStateAggregatesAllThreeSources(): void {
		$this->rows = [
			'sch-ticket' => [['id' => 'r-1', 'title' => 'Request']],
			'sch-task' => [['id' => 't-1']],
			'sch-agent' => [
				['userId' => 'bob', 'id' => 'p-bob'],
				[
					'userId' => 'alice',
					'@self' => ['id' => 'p-alice'],
					'isAvailable' => 1,
					'maxConcurrent' => '3',
					'skills' => 'dutch',
				],
			],
		];
		$this->serveRows();

		$state = $this->service()->getWorkspaceState('alice');

		$this->assertSame(
			expected: [
				'agentProfile' => [
					'id' => 'p-alice',
					'userId' => 'alice',
					'isAvailable' => true,
					'maxConcurrent' => 3,
					'skills' => ['dutch'],
				],
				'assignedRequests' => [['id' => 'r-1', 'title' => 'Request']],
				'openTasks' => [['id' => 't-1']],
			],
			actual: $state
		);
	}//end testWorkspaceStateAggregatesAllThreeSources()

	/**
	 * The inbox and task queries push the assignee and open statuses down.
	 *
	 * @return void
	 */
	public function testWorkspaceStatePushesFiltersDown(): void {
		$this->serveRows();

		$this->service()->getWorkspaceState('alice');

		$this->assertCount(expectedCount: 3, haystack: $this->queries);
		$this->assertSame(
			expected: [
				'register' => 'reg',
				'schema' => 'sch-ticket',
				'ticketType' => TicketService::TYPE_REQUEST,
				'assignee' => 'alice',
				'status' => ['new', 'in_progress'],
			],
			actual: $this->queries[0]['filters']
		);
		$this->assertSame(
			expected: [
				'filters' => [
					'register' => 'reg',
					'schema' => 'sch-task',
					'assigneeUserId' => 'alice',
					'status' => ['open', 'in_progress'],
				],
			],
			actual: $this->queries[1]
		);
		$this->assertSame(expected: ['filters' => ['register' => 'reg', 'schema' => 'sch-agent']], actual: $this->queries[2]);
	}//end testWorkspaceStatePushesFiltersDown()

	/**
	 * Without a matching profile the defaults are returned.
	 *
	 * @return void
	 */
	public function testWorkspaceStateDefaultsProfile(): void {
		$this->rows = ['sch-agent' => [['userId' => 'bob']]];
		$this->serveRows();

		$state = $this->service()->getWorkspaceState('alice');

		$this->assertSame(
			expected: ['userId' => 'alice', 'isAvailable' => false, 'maxConcurrent' => 0, 'skills' => []],
			actual: $state['agentProfile']
		);
		$this->assertSame(expected: [], actual: $state['assignedRequests']);
		$this->assertSame(expected: [], actual: $state['openTasks']);
	}//end testWorkspaceStateDefaultsProfile()

	/**
	 * A matched profile without an id or flags gets empty/false/zero values.
	 *
	 * @return void
	 */
	public function testWorkspaceStateMatchedProfileWithoutFields(): void {
		$this->rows = ['sch-agent' => [['userId' => 'alice']]];
		$this->serveRows();

		$profile = $this->service()->getWorkspaceState('alice')['agentProfile'];

		$this->assertSame(
			expected: ['id' => '', 'userId' => 'alice', 'isAvailable' => false, 'maxConcurrent' => 0, 'skills' => []],
			actual: $profile
		);
	}//end testWorkspaceStateMatchedProfileWithoutFields()

	/**
	 * Entities are normalised: jsonSerialize arrays, cast objects, scalars dropped to [].
	 *
	 * @return void
	 */
	public function testWorkspaceStateNormalisesEntities(): void {
		$serialisable = new class {
			/**
			 * Serialise.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'json'];
			}//end jsonSerialize()
		};
		$scalarSerialisable = new class {
			/**
			 * A public property kept by the array cast.
			 *
			 * @var string
			 */
			public string $id = 'cast-after-json';

			/**
			 * Serialise to a non-array.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'nope';
			}//end jsonSerialize()
		};
		$plain = new \stdClass();
		$plain->id = 'plain';

		$this->rows = ['sch-task' => [$serialisable, $scalarSerialisable, $plain, 'junk']];
		$this->serveRows();

		$tasks = $this->service()->getWorkspaceState('alice')['openTasks'];

		$this->assertSame(
			expected: [['id' => 'json'], ['id' => 'cast-after-json'], ['id' => 'plain'], []],
			actual: $tasks
		);
	}//end testWorkspaceStateNormalisesEntities()

	/**
	 * Ticket and agent entities go through the same normalisation.
	 *
	 * @return void
	 */
	public function testTicketsAndAgentsAreNormalised(): void {
		$ticket = new \stdClass();
		$ticket->id = 'r-obj';
		$agent = new \stdClass();
		$agent->userId = 'alice';
		$agent->id = 'p-obj';
		$this->rows = ['sch-ticket' => [$ticket], 'sch-agent' => [$agent]];
		$this->serveRows();

		$state = $this->service()->getWorkspaceState('alice');

		$this->assertSame(expected: [['id' => 'r-obj']], actual: $state['assignedRequests']);
		$this->assertSame(expected: 'p-obj', actual: $state['agentProfile']['id']);
	}//end testTicketsAndAgentsAreNormalised()

	/**
	 * With no register every read is refused and logged; nothing is queried.
	 *
	 * @return void
	 */
	public function testWorkspaceStateUnconfiguredRegisterQueriesNothing(): void {
		$this->config['register'] = '';
		$this->objectService->expects($this->never())->method('findAll');
		$this->container->expects($this->never())->method('get');
		$this->logger->expects($this->exactly(count: 2))
			->method('warning')
			->with(
				'Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped'
			);
		$infos = [];
		$this->logger->expects($this->exactly(count: 3))
			->method('info')
			->willReturnCallback(
				static function (string $message, array $context) use (&$infos): void {
					$infos[] = [$message, $context];
				}
			);

		$state = $this->service()->getWorkspaceState('alice');

		$this->assertSame(expected: [], actual: $state['assignedRequests']);
		$this->assertSame(expected: [], actual: $state['openTasks']);
		$this->assertFalse(condition: $state['agentProfile']['isAvailable']);
		$this->assertSame(
			expected: [
				[
					'[KccWerkplekService] register or ticket schema not configured',
					['ticketType' => TicketService::TYPE_REQUEST],
				],
				['[KccWerkplekService] register or schema not configured', ['schemaKey' => 'task_schema']],
				['[KccWerkplekService] register or schema not configured', ['schemaKey' => 'agentProfile_schema']],
			],
			actual: $infos
		);
	}//end testWorkspaceStateUnconfiguredRegisterQueriesNothing()

	/**
	 * A missing task schema skips only the task read.
	 *
	 * @return void
	 */
	public function testWorkspaceStateMissingTaskSchemaSkipsTasks(): void {
		unset($this->config['task_schema']);
		$this->rows = ['sch-ticket' => [['id' => 'r-1']]];
		$this->serveRows();

		$state = $this->service()->getWorkspaceState('alice');

		$this->assertSame(expected: [['id' => 'r-1']], actual: $state['assignedRequests']);
		$this->assertSame(expected: [], actual: $state['openTasks']);
		$this->assertCount(expectedCount: 2, haystack: $this->queries);
	}//end testWorkspaceStateMissingTaskSchemaSkipsTasks()

	/**
	 * OpenRegister outages are logged per read and yield an empty workspace.
	 *
	 * @return void
	 */
	public function testWorkspaceStateSwallowsOutages(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('or down'));
		$warnings = [];
		$this->logger->expects($this->exactly(count: 2))
			->method('warning')
			->willReturnCallback(
				static function (string $message, array $context = []) use (&$warnings): void {
					$warnings[] = [$message, $context];
				}
			);

		$state = $this->service()->getWorkspaceState('alice');

		$this->assertSame(expected: [], actual: $state['assignedRequests']);
		$this->assertSame(expected: [], actual: $state['openTasks']);
		$this->assertSame(
			expected: [
				[
					'[KccWerkplekService] filtered findAll failed',
					['schemaKey' => 'task_schema', 'error' => 'or down'],
				],
				['[KccWerkplekService] findAll failed', ['schemaKey' => 'agentProfile_schema', 'error' => 'or down']],
			],
			actual: $warnings
		);
	}//end testWorkspaceStateSwallowsOutages()

	/**
	 * A container failure is wrapped and, in the read paths, swallowed.
	 *
	 * @return void
	 */
	public function testWorkspaceStateContainerFailureDegrades(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no service'));
		$this->container = $container;
		$this->objectService->method('findAll')->willReturn([['id' => 'r-1']]);
		$this->logger->expects($this->exactly(count: 2))
			->method('warning')
			->with(
				$this->stringContains(string: 'findAll failed'),
				$this->callback(
					callback: static fn (array $context): bool => $context['error'] === 'OpenRegister ObjectService is not available'
				)
			);

		$state = $this->service()->getWorkspaceState('alice');

		$this->assertSame(expected: [['id' => 'r-1']], actual: $state['assignedRequests']);
		$this->assertSame(expected: [], actual: $state['openTasks']);
	}//end testWorkspaceStateContainerFailureDegrades()

	/**
	 * SetAvailability refuses when the agent schema is not configured.
	 *
	 * @return void
	 */
	public function testSetAvailabilityUnconfiguredSchemaThrows(): void {
		unset($this->config['agentProfile_schema']);
		$this->objectService->expects($this->never())->method('saveObject');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'agentProfile schema is not configured');

		$this->service()->setAvailability('alice', true);
	}//end testSetAvailabilityUnconfiguredSchemaThrows()

	/**
	 * SetAvailability refuses when the register is not configured.
	 *
	 * @return void
	 */
	public function testSetAvailabilityUnconfiguredRegisterThrows(): void {
		$this->config['register'] = '';
		$this->container->expects($this->never())->method('get');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'agentProfile schema is not configured');

		$this->service()->setAvailability('alice', false);
	}//end testSetAvailabilityUnconfiguredRegisterThrows()

	/**
	 * SetAvailability wraps a container failure.
	 *
	 * @return void
	 */
	public function testSetAvailabilityContainerFailureThrows(): void {
		$cause = new RuntimeException('no service');
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with('OCA\\OpenRegister\\Service\\ObjectService')
			->willThrowException($cause);
		$this->container = $container;

		try {
			$this->service()->setAvailability('alice', true);
			$this->fail(message: 'Expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'OpenRegister ObjectService is not available', actual: $e->getMessage());
			$this->assertSame(expected: $cause, actual: $e->getPrevious());
		}
	}//end testSetAvailabilityContainerFailureThrows()

	/**
	 * An existing profile is updated in place, keeping its other fields.
	 *
	 * @return void
	 */
	public function testSetAvailabilityUpdatesExistingProfile(): void {
		$this->rows = [
			'sch-agent' => [
				['userId' => 'bob', 'id' => 'p-bob'],
				[
					'userId' => 'alice',
					'id' => 'p-alice-legacy',
					'@self' => ['id' => 'p-alice'],
					'skills' => ['dutch'],
					'maxConcurrent' => 4,
					'isAvailable' => false,
				],
			],
		];
		$this->serveRows();
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(
				['userId' => 'alice', 'skills' => ['dutch'], 'maxConcurrent' => 4, 'isAvailable' => true],
				[],
				'reg',
				'sch-agent',
				'p-alice'
			);

		$result = $this->service()->setAvailability('alice', true);

		$this->assertSame(expected: ['userId' => 'alice', 'isAvailable' => true], actual: $result);
		$this->assertSame(expected: [['filters' => ['register' => 'reg', 'schema' => 'sch-agent']]], actual: $this->queries);
	}//end testSetAvailabilityUpdatesExistingProfile()

	/**
	 * An existing profile without @self falls back to its plain id.
	 *
	 * @return void
	 */
	public function testSetAvailabilityUsesPlainIdWithoutSelf(): void {
		$this->rows = ['sch-agent' => [['userId' => 'alice', 'id' => 'p-plain']]];
		$this->serveRows();
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(['userId' => 'alice', 'isAvailable' => false, 'maxConcurrent' => 1], [], 'reg', 'sch-agent', 'p-plain');

		$this->assertSame(expected: ['userId' => 'alice', 'isAvailable' => false], actual: $this->service()->setAvailability('alice', false));
	}//end testSetAvailabilityUsesPlainIdWithoutSelf()

	/**
	 * A matched profile with no id at all is saved as a new object.
	 *
	 * @return void
	 */
	public function testSetAvailabilityMatchedProfileWithoutIdCreates(): void {
		$this->rows = ['sch-agent' => [['userId' => 'alice', 'maxConcurrent' => 2]]];
		$this->serveRows();
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(['userId' => 'alice', 'maxConcurrent' => 2, 'isAvailable' => true], [], 'reg', 'sch-agent', null);

		$this->service()->setAvailability('alice', true);
	}//end testSetAvailabilityMatchedProfileWithoutIdCreates()

	/**
	 * Without a profile a new one is created with a default capacity of one.
	 *
	 * @return void
	 */
	public function testSetAvailabilityCreatesProfile(): void {
		$entity = new class {
			/**
			 * Serialise.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['userId' => 'bob', 'id' => 'p-bob'];
			}//end jsonSerialize()
		};
		$this->rows = ['sch-agent' => [$entity]];
		$this->serveRows();
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(['userId' => 'alice', 'isAvailable' => true, 'maxConcurrent' => 1], [], 'reg', 'sch-agent', null);

		$this->assertSame(expected: ['userId' => 'alice', 'isAvailable' => true], actual: $this->service()->setAvailability('alice', true));
	}//end testSetAvailabilityCreatesProfile()

	/**
	 * A failed lookup is logged and the profile is created rather than lost.
	 *
	 * @return void
	 */
	public function testSetAvailabilityLookupFailureStillSaves(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('lookup down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('[KccWerkplekService] setAvailability lookup failed', ['error' => 'lookup down']);
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(['userId' => 'alice', 'isAvailable' => false, 'maxConcurrent' => 1], [], 'reg', 'sch-agent', null);

		$this->assertSame(expected: ['userId' => 'alice', 'isAvailable' => false], actual: $this->service()->setAvailability('alice', false));
	}//end testSetAvailabilityLookupFailureStillSaves()

	/**
	 * A failed save is logged and rethrown wrapped.
	 *
	 * @return void
	 */
	public function testSetAvailabilitySaveFailureThrows(): void {
		$this->serveRows();
		$cause = new RuntimeException('save down');
		$this->objectService->method('saveObject')->willThrowException($cause);
		$this->logger->expects($this->once())
			->method('error')
			->with('[KccWerkplekService] saveObject failed', ['error' => 'save down']);

		try {
			$this->service()->setAvailability('alice', true);
			$this->fail(message: 'Expected a RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame(expected: 'Failed to update agent availability', actual: $e->getMessage());
			$this->assertSame(expected: $cause, actual: $e->getPrevious());
		}
	}//end testSetAvailabilitySaveFailureThrows()
}//end class
