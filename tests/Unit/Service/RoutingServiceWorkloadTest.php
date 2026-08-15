<?php

/**
 * Unit tests for RoutingService::getAgentWorkload query pushdown.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\RoutingService;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for RoutingService::getAgentWorkload.
 *
 * Since unify-ticket-supertype the open-requests leg reads the unified `ticket`
 * schema narrowed to `ticketType=request` via TicketService::findByType(); the
 * open-leads leg still counts the `lead` schema server-side.
 *
 * @spec openspec/changes/pipelinq-query-pushdown-batch-1/tasks.md#task-6
 */
class RoutingServiceWorkloadTest extends TestCase {

	/**
	 * The DI container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * The app config mock.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * The unified ticket resolver mock.
	 *
	 * @var TicketService&MockObject
	 */
	private TicketService $ticketService;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->ticketService = $this->createMock(TicketService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default): string {
				return match ($key) {
					'register' => 'pipelinq',
					'ticket_schema' => 'ticket',
					'lead_schema' => 'lead',
					default => $default,
				};
			}
		);

		$this->ticketService->method('getRegisterId')->willReturn('pipelinq');
		$this->ticketService->method('getSchemaId')->willReturn('ticket');
		$this->ticketService->method('isConfigured')->willReturn(true);
	}//end setUp()

	/**
	 * Wire TicketService::findByType to serve the ticket rows matching the
	 * requested subtype + the extra (assignee) filters.
	 *
	 * @param array<int, array<string, mixed>> $rows The full row set.
	 *
	 * @return void
	 */
	private function mockTicketRows(array $rows): void {
		$this->ticketService->method('findByType')->willReturnCallback(
			static function (string $ticketType, array $extraFilters = [], int $limit = 10000) use ($rows): array {
				$out = [];
				foreach ($rows as $row) {
					if (($row['schema'] ?? null) !== 'ticket' || ($row['ticketType'] ?? null) !== $ticketType) {
						continue;
					}

					foreach ($extraFilters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							continue 2;
						}
					}

					$out[] = $row;
				}

				return $out;
			}
		);
	}//end mockTicketRows()

	/**
	 * Build a fake OR ObjectService.
	 *
	 * findAll returns the in-memory rows matching the supplied filters
	 * (used by the open-requests leg, which counts non-terminal rows in PHP).
	 * count returns the number of rows matching the filters (used by the
	 * open-leads leg, which is fully pushed down).
	 *
	 * @param array<int, array<string, mixed>> $rows Object rows keyed by schema.
	 *
	 * @return object The fake ObjectService.
	 */
	private function fakeObjectService(array $rows): object {
		return new class($rows) {
			/**
			 * @param array<int, array<string, mixed>> $rows Rows.
			 */
			public function __construct(
				private array $rows,
			) {
			}//end __construct()

			/**
			 * @param array<string, mixed> $config Config with `filters`.
			 *
			 * @return array<int, array<string, mixed>> Matching rows.
			 */
			public function findAll(array $config = []): array {
				return $this->match(($config['filters'] ?? []));
			}//end findAll()

			/**
			 * @param array<string, mixed> $config Config with `filters`.
			 *
			 * @return int Count of matching rows.
			 */
			public function count(array $config = []): int {
				return count($this->match(($config['filters'] ?? [])));
			}//end count()

			/**
			 * @param array<string, mixed> $filters Filter map.
			 *
			 * @return array<int, array<string, mixed>> Matching rows.
			 */
			private function match(array $filters): array {
				unset($filters['register']);
				$out = [];
				foreach ($this->rows as $row) {
					foreach ($filters as $k => $v) {
						if (($row[$k] ?? null) !== $v) {
							continue 2;
						}
					}

					$out[] = $row;
				}

				return $out;
			}//end match()
		};
	}//end fakeObjectService()

	/**
	 * Open request tickets (non-terminal, counted in PHP) plus open leads
	 * (counted server-side via count()) are summed into the workload.
	 *
	 * @return void
	 */
	public function testGetAgentWorkloadSumsRequestsAndLeads(): void {
		$rows = [
			// Request tickets for user-1: 2 open (new, in_progress), 1 terminal (closed).
			['schema' => 'ticket', 'ticketType' => 'request', 'assignee' => 'user-1', 'status' => 'new'],
			['schema' => 'ticket', 'ticketType' => 'request', 'assignee' => 'user-1', 'status' => 'in_progress'],
			['schema' => 'ticket', 'ticketType' => 'request', 'assignee' => 'user-1', 'status' => 'closed'],
			// A contactmoment ticket for user-1 — another subtype, never counted.
			['schema' => 'ticket', 'ticketType' => 'interaction', 'assignee' => 'user-1', 'status' => 'new'],
			// Leads for user-1 with status open: 3.
			['schema' => 'lead', 'assignee' => 'user-1', 'status' => 'open'],
			['schema' => 'lead', 'assignee' => 'user-1', 'status' => 'open'],
			['schema' => 'lead', 'assignee' => 'user-1', 'status' => 'open'],
			// Lead that is not open — excluded by the server-side filter.
			['schema' => 'lead', 'assignee' => 'user-1', 'status' => 'won'],
			// Other user — excluded.
			['schema' => 'lead', 'assignee' => 'user-2', 'status' => 'open'],
		];

		$this->container->method('get')->willReturn($this->fakeObjectService($rows));
		$this->mockTicketRows($rows);
		$service = new RoutingService($this->appConfig, $this->container, $this->ticketService, $this->logger);

		// 2 open request tickets + 3 open leads = 5.
		$this->assertSame(5, $service->getAgentWorkload(userId: 'user-1'));
	}//end testGetAgentWorkloadSumsRequestsAndLeads()

	/**
	 * Empty user id short-circuits to zero.
	 *
	 * @return void
	 */
	public function testGetAgentWorkloadEmptyUserReturnsZero(): void {
		$this->container->method('get')->willReturn($this->fakeObjectService([]));
		$this->mockTicketRows([]);
		$service = new RoutingService($this->appConfig, $this->container, $this->ticketService, $this->logger);

		$this->assertSame(0, $service->getAgentWorkload(userId: ''));
	}//end testGetAgentWorkloadEmptyUserReturnsZero()
}//end class
