<?php

/**
 * Unit tests for Seam 1, Batch 3 query pushdown (non-GDPR COUNT/facet + list).
 *
 * Pins the refactored, OpenRegister-backed KccWerkplekService::getWorkspaceState
 * to the SAME payload the prior fetch-all-then-PHP-filter/group path produced
 * over identical rows. The filtered list is driven by an inline fake
 * ObjectService that honours the `assignee` + `status IN` filters, so the
 * assertion is a true behaviour-preservation guard rather than a tautology
 * against a hand-rolled expectation.
 *
 * The grouped queue COUNT this batch also pushed down is gone with the queue
 * concept itself (retire-queue-concept); what remains is the inbox pushdown.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\KccWerkplekService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Behaviour-preservation tests for the Batch-3 KccWerkplek pushdown.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the service + the fake the pushdown exercises.
 */
class QueryPushdownBatch3Test extends TestCase {
	/**
	 * Request statuses considered "open" (mirrors the service constant).
	 *
	 * @var array<int, string>
	 */
	private const OPEN_REQUEST_STATUSES = ['new', 'in_progress'];

	/**
	 * Task statuses considered "open" (mirrors the service constant).
	 *
	 * @var array<int, string>
	 */
	private const OPEN_TASK_STATUSES = ['open', 'in_progress'];

	/**
	 * IAppConfig stub mapping every *_schema key to itself and register to a
	 * stable id.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === 'register') {
					return 'reg';
				}

				return $key;
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * Build a fake ObjectService whose findAll honours an `assignee` /
	 * `assigneeUserId` equality filter and a `status` IN filter over a per-schema
	 * row set keyed by the resolved schema slug.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $bySchema Rows per schema slug.
	 *
	 * @return ObjectServiceInterface The fake ObjectService.
	 */
	private function fakeObjectService(array $bySchema): ObjectServiceInterface {
		// Extends the autoloaded OR stub so KccWerkplekService::getObjectService()'s
		// `: \OCA\OpenRegister\Service\ObjectService` return type is satisfied.
		return new class($bySchema) extends \OCA\OpenRegister\Service\ObjectService {
			/**
			 * Construct the fake over a per-schema row set.
			 *
			 * @param array<string, array<int, array<string, mixed>>> $bySchema Rows per schema slug.
			 */
			public function __construct(
				private array $bySchema,
			) {
			}//end __construct()

			/**
			 * Return the rows of the requested schema that satisfy the filters.
			 *
			 * Signature mirrors the real OCA\OpenRegister\Service\ObjectService::findAll()
			 * (array $config, bool $_rbac, bool $_multitenancy) so this override stays
			 * declaration-compatible whether the OR stub (bare/host phpunit run) OR the
			 * real OpenRegister class (phpunit run inside an OR-loaded Nextcloud) wins
			 * autoloading. The $_rbac / $_multitenancy flags are irrelevant to the fake.
			 *
			 * @param array{filters?: array<string, mixed>} $config The find config.
			 * @param bool $_rbac Ignored (real-OR parity).
			 * @param bool $_multitenancy Ignored (real-OR parity).
			 *
			 * @return array<int, array<string, mixed>> The matching rows.
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$filters = (array)($config['filters'] ?? []);
				$schema = (string)($filters['schema'] ?? '');
				$rows = ($this->bySchema[$schema] ?? []);

				$out = [];
				foreach ($rows as $row) {
					if ($this->matches(row: $row, filters: $filters) === true) {
						$out[] = $row;
					}
				}

				return $out;
			}//end findAll()

			/**
			 * Whether a row satisfies the equality / IN filters (register + schema
			 * are injected keys and are ignored).
			 *
			 * @param array<string, mixed> $row The candidate row.
			 * @param array<string, mixed> $filters The field criteria.
			 *
			 * @return bool Whether the row matches.
			 */
			private function matches(array $row, array $filters): bool {
				foreach ($filters as $field => $criterion) {
					if ($field === 'register' || $field === 'schema') {
						continue;
					}

					$value = ($row[$field] ?? null);
					if (is_array($criterion) === true) {
						if (in_array((string)$value, array_map('strval', $criterion), true) === false) {
							return false;
						}

						continue;
					}

					if ((string)$value !== (string)$criterion) {
						return false;
					}
				}

				return true;
			}//end matches()
		};
	}//end fakeObjectService()

	/**
	 * Build a container that answers the ObjectService id with the given fake and
	 * the AggregationRunner id with the given runner.
	 *
	 * @param object $objectService The fake ObjectService.
	 * @param object $runner The fake AggregationRunner.
	 *
	 * @return ContainerInterface
	 */
	private function container(object $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}

				throw new \RuntimeException('unexpected service ' . $id);
			}
		);

		return $container;
	}//end container()

	/**
	 * The prior fetch-all-then-PHP oracle: build the workspace payload the old
	 * code would have produced from the full row sets.
	 *
	 * `$tickets` is the full `ticket` row set (every subtype); the oracle narrows
	 * it to `ticketType=request` exactly as the discriminator filter now does.
	 *
	 * @param string $userId The agent UID.
	 * @param array<int, array<string, mixed>> $tickets All ticket rows (any subtype).
	 * @param array<int, array<string, mixed>> $tasks All task rows.
	 * @param array<int, array<string, mixed>> $agents All agent rows.
	 *
	 * @return array<string, mixed> The oracle payload.
	 */
	private function oracle(string $userId, array $tickets, array $tasks, array $agents): array {
		$requests = array_values(
			array_filter($tickets,
				static fn (array $ticket): bool => (string)($ticket['ticketType'] ?? '') === TicketService::TYPE_REQUEST
			)
		);

		$assignedRequests = [];
		foreach ($requests as $request) {
			$assignee = (string)($request['assignee'] ?? '');
			$status = (string)($request['status'] ?? '');
			if ($assignee === $userId && in_array($status, self::OPEN_REQUEST_STATUSES, true) === true) {
				$assignedRequests[] = $request;
			}
		}

		$openTasks = [];
		foreach ($tasks as $task) {
			$assignee = (string)($task['assigneeUserId'] ?? '');
			$status = (string)($task['status'] ?? '');
			if ($assignee === $userId && in_array($status, self::OPEN_TASK_STATUSES, true) === true) {
				$openTasks[] = $task;
			}
		}

		return [
			'assignedRequests' => $assignedRequests,
			'openTasks' => $openTasks,
		];
	}//end oracle()

	/**
	 * The pushed-down getWorkspaceState returns the SAME assigned-requests and
	 * open-tasks payload the prior fetch-all PHP path produced — including the
	 * missing-assignee exclusion — reading the unified `ticket` schema narrowed
	 * by `ticketType=request` (unify-ticket-supertype), so sibling subtypes never
	 * leak into the inbox.
	 *
	 * @return void
	 */
	public function testWorkspaceStateMatchesPriorPhpReduce(): void {
		$userId = 'alice';

		// The ticket row set carries every subtype. c1 (a complaint) is assigned to
		// alice and open: it must be excluded from the inbox purely by the
		// `ticketType` discriminator. r4 is closed and r5 has no assignee.
		$tickets = [
			['id' => 'r1', 'ticketType' => 'request', 'assignee' => 'alice', 'status' => 'new'],
			['id' => 'r2', 'ticketType' => 'request', 'assignee' => 'alice', 'status' => 'in_progress'],
			['id' => 'r3', 'ticketType' => 'request', 'assignee' => 'bob', 'status' => 'new'],
			['id' => 'r4', 'ticketType' => 'request', 'assignee' => 'alice', 'status' => 'closed'],
			['id' => 'r5', 'ticketType' => 'request', 'status' => 'new'],
			['id' => 'c1', 'ticketType' => 'complaint', 'assignee' => 'alice', 'status' => 'new'],
		];

		$tasks = [
			['id' => 't1', 'assigneeUserId' => 'alice', 'status' => 'open'],
			['id' => 't2', 'assigneeUserId' => 'alice', 'status' => 'done'],
			['id' => 't3', 'assigneeUserId' => 'bob', 'status' => 'in_progress'],
			['id' => 't4', 'assigneeUserId' => 'alice', 'status' => 'in_progress'],
		];

		$agents = [
			['@self' => ['id' => 'a1'], 'userId' => 'alice', 'isAvailable' => true, 'maxConcurrent' => 3, 'skills' => ['nl']],
		];

		// The appConfig stub maps every *_schema key to itself, so the ticket schema
		// resolves to the literal `ticket_schema`.
		$bySchema = [
			'ticket_schema' => $tickets,
			'task_schema' => $tasks,
			'agentProfile_schema' => $agents,
		];

		$objectService = $this->fakeObjectService(bySchema: $bySchema);
		$container = $this->container(objectService: $objectService);
		$logger = $this->createMock(LoggerInterface::class);
		$appConfig = $this->appConfig();

		$ticketService = new TicketService(
			appConfig: $appConfig,
			logger: $logger,
			objectService: $objectService,
		);

		$service = new KccWerkplekService(
			container: $container,
			appConfig: $appConfig,
			logger: $logger,
			ticketService: $ticketService
		);

		$state = $service->getWorkspaceState(userId: $userId);
		$oracle = $this->oracle(userId: $userId, tickets: $tickets, tasks: $tasks, agents: $agents);

		$this->assertSame($oracle['assignedRequests'], $state['assignedRequests']);
		$this->assertSame($oracle['openTasks'], $state['openTasks']);

		// Spell out the expected facts so the oracle itself can't silently drift.
		$this->assertCount(2, $state['assignedRequests']);
		$this->assertCount(2, $state['openTasks']);

		// The inbox holds only request tickets — alice's open complaint is not one.
		$this->assertSame(['r1', 'r2'], array_column($state['assignedRequests'], 'id'));

		// Agent profile rides through unchanged.
		$this->assertSame('alice', $state['agentProfile']['userId']);
		$this->assertTrue($state['agentProfile']['isAvailable']);

		// The payload no longer carries a queue plane at all.
		$this->assertArrayNotHasKey('queueCounts', $state);
		$this->assertArrayNotHasKey('queues', $state);
	}//end testWorkspaceStateMatchesPriorPhpReduce()
}//end class
