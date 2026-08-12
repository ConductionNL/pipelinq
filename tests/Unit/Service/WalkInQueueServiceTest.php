<?php

/**
 * Unit tests for WalkInQueueService — ticket lifecycle + rebalance logic.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/appointment-booking-09-walkin-queue/specs/appointment-booking/spec.md#req-apt-012
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\AvailabilityService;
use OCA\Pipelinq\Service\WalkInQueueService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for WalkInQueueService.
 */
class WalkInQueueServiceTest extends TestCase {
	/**
	 * Build a WalkInQueueService with overridable mocks.
	 *
	 * @param ObjectService|null $objectService Optional pre-built ObjectService mock.
	 * @param AvailabilityService|null $availability Optional pre-built AvailabilityService mock.
	 *
	 * @return array{0: WalkInQueueService, 1: ObjectService, 2: AvailabilityService}
	 */
	private function buildService(
		?ObjectService $objectService = null,
		?AvailabilityService $availability = null,
	): array {
		$objectService = ($objectService ?? $this->createMock(originalClassName: ObjectService::class));
		$availability = ($availability ?? $this->createMock(originalClassName: AvailabilityService::class));

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			callback: static function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'pipelinq',
					'walkInTicket_schema' => 'walkInTicket',
					'service_schema' => 'service',
					'resource_schema' => 'resource',
				];
				return ($values[$key] ?? $default);
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		$service = new WalkInQueueService(
			container: $container,
			appConfig: $appConfig,
			availabilityService: $availability,
			logger: $logger,
		);

		return [$service, $objectService, $availability];
	}//end buildService()

	/**
	 * The createTicket() call seats a walk-in with status=waiting + arrivedAt populated,
	 * and derives estimatedReadyAt from the earliest AvailabilityService gap.
	 *
	 * @return void
	 */
	public function testCreateTicketSeatsWaitingAndComputesEta(): void {
		$service = [
			'@self' => ['id' => 'svc-haircut'],
			'durationMinutes' => 30,
			'requiredResourceTypes' => ['staff'],
		];

		$resources = [
			['@self' => ['id' => 'res-a'], 'type' => 'staff', 'status' => 'active', 'bookable' => true],
			['@self' => ['id' => 'res-b'], 'type' => 'staff', 'status' => 'active', 'bookable' => true],
		];

		$object = $this->createMock(originalClassName: ObjectService::class);
		$object->method('find')->willReturn($service);
		$object->method('findAll')->willReturn($resources);

		$captured = null;
		$object->method('saveObject')->willReturnCallback(
			function (
				array|object $payload,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$captured): array {
				$captured = $payload;
				return ['@self' => ['id' => 't-1']];
			}
		);

		$availability = $this->createMock(originalClassName: AvailabilityService::class);
		$availability->method('computeAvailability')->willReturnCallback(
			callback: static function (string $resourceId, string $date, int $duration): array {
				if ($resourceId === 'res-a') {
					return [['startTime' => '11:00', 'endTime' => '11:30', 'durationMinutes' => 30]];
				}

				return [['startTime' => '09:30', 'endTime' => '10:00', 'durationMinutes' => 30]];
			}
		);

		[$walkIn] = $this->buildService(objectService: $object, availability: $availability);

		$uuid = $walkIn->createTicket(
			data: [
				'displayName' => 'Mr. Jansen',
				'serviceId' => 'svc-haircut',
			]
		);

		$this->assertSame(expected: 't-1', actual: $uuid);
		$this->assertIsArray(actual: $captured);
		$this->assertSame(expected: 'waiting', actual: $captured['status']);
		$this->assertSame(expected: 'Mr. Jansen', actual: $captured['displayName']);
		$this->assertSame(expected: 'svc-haircut', actual: $captured['serviceId']);
		$this->assertArrayHasKey(key: 'arrivedAt', array: $captured);
		$this->assertNotSame(expected: '', actual: $captured['arrivedAt']);
		// Earliest gap (res-b's 09:30) wins over res-a's 11:00.
		$this->assertArrayHasKey(key: 'estimatedReadyAt', array: $captured);
		$this->assertStringContainsString(needle: 'T09:30:00', haystack: $captured['estimatedReadyAt']);

	}//end testCreateTicketSeatsWaitingAndComputesEta()

	/**
	 * The createTicket() call without a serviceId is allowed (anonymous walk-in not yet
	 * triaged) and leaves estimatedReadyAt unset.
	 *
	 * @return void
	 */
	public function testCreateTicketWithoutServiceLeavesEtaUnset(): void {
		$object = $this->createMock(originalClassName: ObjectService::class);
		$captured = null;
		$object->method('saveObject')->willReturnCallback(
			function (
				array|object $payload,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$captured): array {
				$captured = $payload;
				return ['@self' => ['id' => 't-anon']];
			}
		);

		[$walkIn] = $this->buildService(objectService: $object);

		$uuid = $walkIn->createTicket(data: ['displayName' => 'Anoniem']);

		$this->assertSame(expected: 't-anon', actual: $uuid);
		$this->assertSame(expected: 'waiting', actual: $captured['status']);
		$this->assertArrayNotHasKey(key: 'estimatedReadyAt', array: $captured);
		$this->assertArrayNotHasKey(key: 'serviceId', array: $captured);

	}//end testCreateTicketWithoutServiceLeavesEtaUnset()

	/**
	 * The createTicket() call rejects an empty displayName (schema requires it).
	 *
	 * @return void
	 */
	public function testCreateTicketRejectsEmptyDisplayName(): void {
		$object = $this->createMock(originalClassName: ObjectService::class);
		$object->expects($this->never())->method('saveObject');

		[$walkIn] = $this->buildService(objectService: $object);

		$this->expectException(exception: InvalidArgumentException::class);
		$walkIn->createTicket(data: ['displayName' => '']);

	}//end testCreateTicketRejectsEmptyDisplayName()

	/**
	 * The callNext() call picks the oldest waiting ticket by arrivedAt and transitions
	 * it to `called`, stamping the supplied assignedResourceId.
	 *
	 * @return void
	 */
	public function testCallNextPicksOldestWaitingAndAssignsResource(): void {
		$rows = [
			['@self' => ['id' => 't-young'], 'status' => 'waiting', 'arrivedAt' => '2026-06-15T10:30:00+00:00'],
			['@self' => ['id' => 't-oldest'], 'status' => 'waiting', 'arrivedAt' => '2026-06-15T10:00:00+00:00'],
			['@self' => ['id' => 't-middle'], 'status' => 'waiting', 'arrivedAt' => '2026-06-15T10:15:00+00:00'],
		];

		$object = $this->createMock(originalClassName: ObjectService::class);
		$object->method('findAll')->willReturn($rows);

		$captured = null;
		$capturedUuid = null;
		$object->expects($this->once())->method('saveObject')->willReturnCallback(
			function (
				array|object $payload,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$captured, &$capturedUuid): array {
				$captured = $payload;
				$capturedUuid = $uuid;
				return ['@self' => ['id' => $uuid]];
			}
		);

		[$walkIn] = $this->buildService(objectService: $object);

		$next = $walkIn->callNext(assignedResourceId: 'res-sarah');

		$this->assertSame(expected: 't-oldest', actual: $next);
		$this->assertSame(expected: 't-oldest', actual: $capturedUuid);
		$this->assertSame(expected: 'called', actual: $captured['status']);
		$this->assertSame(expected: 'res-sarah', actual: $captured['assignedResourceId']);

	}//end testCallNextPicksOldestWaitingAndAssignsResource()

	/**
	 * The callNext() call returns '' when the queue holds no waiting tickets.
	 *
	 * @return void
	 */
	public function testCallNextReturnsEmptyWhenQueueIsEmpty(): void {
		$object = $this->createMock(originalClassName: ObjectService::class);
		$object->method('findAll')->willReturn([]);
		$object->expects($this->never())->method('saveObject');

		[$walkIn] = $this->buildService(objectService: $object);

		$this->assertSame(expected: '', actual: $walkIn->callNext());

	}//end testCallNextReturnsEmptyWhenQueueIsEmpty()

	/**
	 * The serveTicket() call transitions called -> served and stamps actualServedAt.
	 *
	 * @return void
	 */
	public function testServeTicketStampsActualServedAt(): void {
		$ticket = [
			'@self' => ['id' => 't-called'],
			'status' => 'called',
			'arrivedAt' => '2026-06-15T10:00:00+00:00',
			'displayName' => 'Mr. Jansen',
		];

		$object = $this->createMock(originalClassName: ObjectService::class);
		$object->method('find')->willReturn($ticket);

		$captured = null;
		$object->method('saveObject')->willReturnCallback(
			function (
				array|object $payload,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$captured): array {
				$captured = $payload;
				return ['@self' => ['id' => 't-called']];
			}
		);

		[$walkIn] = $this->buildService(objectService: $object);

		$walkIn->serveTicket(ticketId: 't-called');

		$this->assertSame(expected: 'served', actual: $captured['status']);
		$this->assertArrayHasKey(key: 'actualServedAt', array: $captured);
		$this->assertNotSame(expected: '', actual: $captured['actualServedAt']);

	}//end testServeTicketStampsActualServedAt()

	/**
	 * The abandonTicket() call transitions a waiting ticket -> abandoned (also legal
	 * from `called`).
	 *
	 * @return void
	 */
	public function testAbandonTicketTransitionsToAbandoned(): void {
		$ticket = [
			'@self' => ['id' => 't-gone'],
			'status' => 'waiting',
			'arrivedAt' => '2026-06-15T10:00:00+00:00',
		];

		$object = $this->createMock(originalClassName: ObjectService::class);
		$object->method('find')->willReturn($ticket);

		$captured = null;
		$object->method('saveObject')->willReturnCallback(
			function (
				array|object $payload,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$captured): array {
				$captured = $payload;
				return ['@self' => ['id' => 't-gone']];
			}
		);

		[$walkIn] = $this->buildService(objectService: $object);

		$walkIn->abandonTicket(ticketId: 't-gone');

		$this->assertSame(expected: 'abandoned', actual: $captured['status']);

	}//end testAbandonTicketTransitionsToAbandoned()

	/**
	 * Terminal statuses (served, abandoned) cannot transition further.
	 *
	 * @return void
	 */
	public function testTerminalTicketCannotTransition(): void {
		$ticket = [
			'@self' => ['id' => 't-done'],
			'status' => 'served',
			'arrivedAt' => '2026-06-15T10:00:00+00:00',
		];

		$object = $this->createMock(originalClassName: ObjectService::class);
		$object->method('find')->willReturn($ticket);
		$object->expects($this->never())->method('saveObject');

		[$walkIn] = $this->buildService(objectService: $object);

		$this->expectException(exception: InvalidArgumentException::class);
		$walkIn->serveTicket(ticketId: 't-done');

	}//end testTerminalTicketCannotTransition()

	/**
	 * The ticket state machine is sourced from the walkInTicket schema's
	 * x-openregister-lifecycle declaration (ADR-031), including terminal states
	 * as empty-array keys (so "unknown status" vs "invalid transition" still differ).
	 *
	 * @return void
	 */
	public function testAllowedTransitionsAreSourcedFromSchema(): void {
		$schemaGraph = (new \OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph(
			settingsDir: __DIR__ . '/../../../lib/Settings'
		))->fullAdjacencyFor(schemaSlug: 'walkInTicket');

		$this->assertSame(
			expected: WalkInQueueService::allowedTransitions(),
			actual: $schemaGraph
		);

		$this->assertSame(
			expected: [
				'waiting' => ['called', 'abandoned'],
				'called' => ['served', 'abandoned'],
				'served' => [],
				'abandoned' => [],
			],
			actual: WalkInQueueService::allowedTransitions()
		);
	}//end testAllowedTransitionsAreSourcedFromSchema()

	/**
	 * Rebalance recomputes estimatedReadyAt for every waiting ticket with a
	 * service link; tickets without a serviceId are skipped (no schedule).
	 *
	 * @return void
	 */
	public function testRebalanceRecomputesEtaForWaitingTickets(): void {
		$waiting = [
			[
				'@self' => ['id' => 't-1'],
				'status' => 'waiting',
				'serviceId' => 'svc-haircut',
				'arrivedAt' => '2026-06-15T10:00:00+00:00',
				'estimatedReadyAt' => '2026-06-15T11:00:00+00:00',
			],
			[
				'@self' => ['id' => 't-anon'],
				'status' => 'waiting',
				'arrivedAt' => '2026-06-15T10:05:00+00:00',
			],
		];

		$service = [
			'@self' => ['id' => 'svc-haircut'],
			'durationMinutes' => 30,
			'requiredResourceTypes' => ['staff'],
		];

		$resources = [
			['@self' => ['id' => 'res-a'], 'type' => 'staff', 'status' => 'active', 'bookable' => true],
		];

		$object = $this->createMock(originalClassName: ObjectService::class);
		$object->method('findAll')->willReturnCallback(
			callback: function (array $config = []) use ($waiting, $resources): array {
				$schema = (string)($config['schema'] ?? '');
				if ($schema === 'resource') {
					return $resources;
				}

				return $waiting;
			}
		);
		$object->method('find')->willReturn($service);

		$saved = [];
		$object->method('saveObject')->willReturnCallback(
			function (
				array|object $payload,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$saved): array {
				$saved[] = ['uuid' => $uuid, 'payload' => $payload];
				return ['@self' => ['id' => $uuid]];
			}
		);

		$availability = $this->createMock(originalClassName: AvailabilityService::class);
		$availability->method('computeAvailability')->willReturn(
			value: [
				['startTime' => '10:30', 'endTime' => '11:00', 'durationMinutes' => 30],
			]
		);

		[$walkIn] = $this->buildService(objectService: $object, availability: $availability);

		$touched = $walkIn->rebalance();

		// Only t-1 has a serviceId -> rebalance touches a single ticket.
		$this->assertSame(expected: 1, actual: $touched);
		$this->assertCount(expectedCount: 1, haystack: $saved);
		$this->assertSame(expected: 't-1', actual: $saved[0]['uuid']);
		$this->assertStringContainsString(
			needle: 'T10:30:00',
			haystack: $saved[0]['payload']['estimatedReadyAt']
		);

	}//end testRebalanceRecomputesEtaForWaitingTickets()

	/**
	 * Rebalance is a no-op when no waiting tickets are queued.
	 *
	 * @return void
	 */
	public function testRebalanceWithNoWaitingTickets(): void {
		$object = $this->createMock(originalClassName: ObjectService::class);
		$object->method('findAll')->willReturn([]);
		$object->expects($this->never())->method('saveObject');

		[$walkIn] = $this->buildService(objectService: $object);

		$this->assertSame(expected: 0, actual: $walkIn->rebalance());

	}//end testRebalanceWithNoWaitingTickets()

	/**
	 * Pins the SIZE of the rebalance fan-out, which is why it may not run on a
	 * request path.
	 *
	 * `rebalance()` reads every waiting ticket (capped at
	 * WalkInQueueService::QUEUE_PAGE_SIZE) and, per ticket, performs a service
	 * read, a resource read, an availability computation and a `saveObject()`.
	 * With a full queue that is QUEUE_PAGE_SIZE object writes and
	 * QUEUE_PAGE_SIZE availability computations in one call — the reason
	 * BookingService::completeBooking() schedules WalkInQueueRebalanceJob
	 * instead of calling this inline (openregister#2420 family).
	 *
	 * ⚠️ SCOPE OF THIS MEASUREMENT — READ BEFORE QUOTING THE NUMBER.
	 * The ObjectService double below resolves register/schema context from the
	 * TOP LEVEL of the findAll() config, which is how `listByStatus()` supplies
	 * it today. The real OpenRegister reads that context ONLY from
	 * `$config['filters']` (`ObjectService::prepareFindAllConfig`), so on a live
	 * instance the ticket read currently resolves no context and
	 * `MagicMapper::findAll()` returns an empty array.
	 *
	 * So this test measures the fan-out THE LOOP PERFORMS PER WAITING TICKET.
	 * It does NOT claim that 200 writes happen in production today: while the
	 * query stays mis-keyed the queue reads empty and the fan-out is LATENT.
	 * It becomes live the moment that separate defect is fixed — which is
	 * exactly why the rebalance must already be off the request path by then.
	 *
	 * @return void
	 */
	public function testRebalanceFansOutOneSaveObjectPerWaitingTicket(): void {
		$queueDepth = WalkInQueueService::QUEUE_PAGE_SIZE;

		$waiting = [];
		for ($i = 0; $i < $queueDepth; $i++) {
			$waiting[] = [
				'@self' => ['id' => 'ticket-' . $i],
				'status' => 'waiting',
				'serviceId' => 'svc-haircut',
				'displayName' => 'Walk-in ' . $i,
				'estimatedReadyAt' => '',
			];
		}

		$resources = [
			['@self' => ['id' => 'res-a'], 'type' => 'staff', 'status' => 'active', 'bookable' => true],
		];

		$object = $this->createMock(originalClassName: ObjectService::class);
		$object->method('find')->willReturn(
			[
				'@self' => ['id' => 'svc-haircut'],
				'durationMinutes' => 30,
				'requiredResourceTypes' => ['staff'],
			]
		);
		$object->method('findAll')->willReturnCallback(
			static function (array $config) use ($waiting, $resources): array {
				if (($config['schema'] ?? '') === 'walkInTicket') {
					return $waiting;
				}

				return $resources;
			}
		);

		$writes = 0;
		$object->method('saveObject')->willReturnCallback(
			function (
				array|object $payload,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$writes): array {
				$writes++;
				return ['@self' => ['id' => (string)$uuid]];
			}
		);

		$availabilityCalls = 0;
		$availability = $this->createMock(originalClassName: AvailabilityService::class);
		$availability->method('computeAvailability')->willReturnCallback(
			static function (string $resourceId, string $date, int $duration) use (&$availabilityCalls): array {
				$availabilityCalls++;
				return [['startTime' => '11:00', 'endTime' => '11:30', 'durationMinutes' => 30]];
			}
		);

		[$walkIn] = $this->buildService(objectService: $object, availability: $availability);

		$touched = $walkIn->rebalance();

		$this->assertSame(expected: $queueDepth, actual: $touched);
		$this->assertSame(expected: $queueDepth, actual: $writes);
		$this->assertSame(expected: $queueDepth, actual: $availabilityCalls);

	}//end testRebalanceFansOutOneSaveObjectPerWaitingTicket()
}//end class
