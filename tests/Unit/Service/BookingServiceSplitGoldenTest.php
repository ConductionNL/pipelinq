<?php

/**
 * Characterisation tests for BookingService creation, rescheduling, slot
 * search, candidate filtering and availability invalidation.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\AvailabilityService;
use OCA\Pipelinq\Service\BookingService;
use OCA\Pipelinq\Service\EligibilityService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins the exact payloads, refusals and side effects of the BookingService
 * units that are split into named steps.
 */
class BookingServiceSplitGoldenTest extends TestCase {

	/**
	 * A service offered online, without deposit.
	 *
	 * @var array<string, mixed>
	 */
	private const SERVICE = [
		'@self' => ['id' => 'svc-1'],
		'durationMinutes' => 30,
		'requiredResourceTypes' => ['staff'],
		'requiresDeposit' => false,
		'depositAmount' => -5,
		'bookableOnline' => true,
	];

	/**
	 * The OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The availability service mock.
	 *
	 * @var AvailabilityService&MockObject
	 */
	private AvailabilityService&MockObject $availability;

	/**
	 * The eligibility service mock.
	 *
	 * @var EligibilityService&MockObject
	 */
	private EligibilityService&MockObject $eligibility;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Saved payloads with their update uuid, in call order.
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: ?string}>
	 */
	private array $saved = [];

	/**
	 * Invalidated resource|date pairs, in call order.
	 *
	 * @var array<int, string>
	 */
	private array $invalidated = [];

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->availability = $this->createMock(AvailabilityService::class);
		$this->eligibility = $this->createMock(EligibilityService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->availability->method('invalidateCache')->willReturnCallback(
			function (string $resourceId, string $date): void {
				$this->invalidated[] = $resourceId . '|' . $date;
			}
		);
	}//end setUp()

	/**
	 * Build the service.
	 *
	 * @return BookingService
	 */
	private function service(): BookingService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = ['register' => 'pipelinq', 'booking_schema' => 'booking', 'service_schema' => 'service'];
				return ($values[$key] ?? $default);
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new BookingService(
			appConfig: $appConfig,
			userSession: $session,
			availabilityService: $this->availability,
			eligibilityService: $this->eligibility,
			logger: $this->logger,
			jobList: $this->createMock(IJobList::class),
			objectService: $this->objects,
		);
	}//end service()

	/**
	 * A row as the entity OpenRegister returns.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param string               $id  The entity uuid.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(array $row, string $id): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($id);
		$entity->setObject($row);

		return $entity;
	}//end entity()

	/**
	 * Record saves; a new booking gets the given id.
	 *
	 * @param string $newId The id answered for a new booking.
	 *
	 * @return void
	 */
	private function recordSaves(string $newId): void {
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null) use ($newId): ObjectEntityInterface {
				$this->saved[] = [$object, $uuid];
				return self::entity([], $uuid ?? $newId);
			}
		);
	}//end recordSaves()

	/**
	 * Replace the clock-dependent changedAt of every history entry.
	 *
	 * @param array<string, mixed> $payload A booking payload.
	 *
	 * @return array<string, mixed>
	 */
	private static function withoutClock(array $payload): array {
		foreach ($payload['statusHistory'] as $i => $entry) {
			if (str_starts_with($entry['changedAt'], '2026-06-01') === false) {
				$payload['statusHistory'][$i]['changedAt'] = 'NOW';
			}
		}

		return $payload;
	}//end withoutClock()

	/**
	 * A valid create request.
	 *
	 * @return array<string, mixed>
	 */
	private static function createRequest(): array {
		return [
			'customerId' => ' cust-1 ',
			'serviceId' => 'svc-1',
			'startAt' => '2026-06-15T10:00:00+02:00',
			'endAt' => '2026-06-15T10:30:00+02:00',
			'resourceAssignments' => [
				['stepIndex' => 0, 'resourceId' => 'res-a', 'startAt' => '2026-06-15T10:00:00+02:00', 'endAt' => '2026-06-15T10:30:00+02:00'],
			],
			'notes' => 'n',
			'internalNotes' => 'i',
			'source' => 'ignored',
		];
	}//end createRequest()

	/**
	 * Every create refusal and its message; nothing is read or saved.
	 *
	 * @return void
	 */
	public function testCreateRefusals(): void {
		$this->objects->expects($this->never())->method('find');
		$this->objects->expects($this->never())->method('saveObject');
		$service = $this->service();
		$cases = [
			'customerId, serviceId, startAt and endAt are required' => ['customerId' => '  '],
			'startAt and endAt must be ISO-8601 timestamps' => ['endAt' => 'not a date'],
			'startAt must be earlier than endAt' => ['endAt' => '2026-06-15T10:00:00+02:00'],
		];
		foreach ($cases as $message => $override) {
			try {
				$service->createBooking(array_merge(self::createRequest(), $override), 'portal');
				$this->fail('Expected a refusal: ' . $message);
			} catch (InvalidArgumentException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}

		$request = self::createRequest();
		unset($request['serviceId']);
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('customerId, serviceId, startAt and endAt are required');
		$service->createBooking($request, 'portal');
	}//end testCreateRefusals()

	/**
	 * The exact new booking payload, invalidation and returned id.
	 *
	 * @return void
	 */
	public function testCreatePayloadGolden(): void {
		$this->objects->method('find')->willReturn(self::entity(self::SERVICE, 'svc-1'));
		$this->recordSaves('b-new');

		$uuid = $this->service()->createBooking(self::createRequest(), ' KIOSK ');

		$this->assertSame('b-new', $uuid);
		$this->assertCount(1, $this->saved);
		$this->assertNull($this->saved[0][1]);
		$this->assertSame(
			[
				'customerId' => 'cust-1',
				'serviceId' => 'svc-1',
				'resourceAssignments' => [
					['stepIndex' => 0, 'resourceId' => 'res-a', 'startAt' => '2026-06-15T10:00:00+02:00', 'endAt' => '2026-06-15T10:30:00+02:00'],
				],
				'startAt' => '2026-06-15T10:00:00+02:00',
				'endAt' => '2026-06-15T10:30:00+02:00',
				'status' => 'confirmed',
				'statusHistory' => [
					['status' => 'confirmed', 'changedAt' => 'NOW', 'changedBy' => 'admin', 'reason' => 'Booking created via portal'],
				],
				'notes' => 'n',
				'internalNotes' => 'i',
				'source' => 'portal',
				'depositAmount' => 0.0,
			],
			self::withoutClock($this->saved[0][0])
		);
		$this->assertSame(['res-a|2026-06-15'], $this->invalidated);
	}//end testCreatePayloadGolden()

	/**
	 * A deposit service starts pending; a save without id is refused before
	 * the cache is touched.
	 *
	 * @return void
	 */
	public function testCreatePendingDepositAndMissingId(): void {
		$deposit = array_merge(self::SERVICE, ['requiresDeposit' => true, 'depositAmount' => 15]);
		$this->objects->method('find')->willReturn(self::entity($deposit, 'svc-1'));
		$this->objects->method('saveObject')->willReturnOnConsecutiveCalls(
			self::entity([], 'b-dep'),
			self::entity([], '')
		);
		$service = $this->service();

		$this->assertSame('b-dep', $service->createBooking(self::createRequest(), 'admin'));
		$this->assertSame(['res-a|2026-06-15'], $this->invalidated);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Booking save returned no id');
		try {
			$service->createBooking(self::createRequest(), 'admin');
		} finally {
			$this->assertCount(1, $this->invalidated);
		}
	}//end testCreatePendingDepositAndMissingId()

	/**
	 * The stored original booking.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private static function original(array $overrides = []): array {
		return array_merge(
			[
				'customerId' => 'cust-1',
				'serviceId' => 'svc-1',
				'startAt' => '2026-06-15T10:00:00+02:00',
				'endAt' => '2026-06-15T10:30:00+02:00',
				'status' => 'confirmed',
				'statusHistory' => [
					['status' => 'confirmed', 'changedAt' => '2026-06-01T09:00:00+02:00', 'changedBy' => 'portal', 'reason' => 'r'],
				],
				'resourceAssignments' => [
					['stepIndex' => '1', 'resourceId' => 'res-a', 'startAt' => '2026-06-15T10:00:00+02:00', 'endAt' => '2026-06-15T10:30:00+02:00'],
					'junk',
					['resourceId' => 'res-b'],
				],
				'notes' => 'n',
				'depositAmount' => '7.5',
			],
			$overrides
		);
	}//end original()

	/**
	 * Every reschedule refusal.
	 *
	 * @return void
	 */
	public function testRescheduleRefusals(): void {
		$this->objects->expects($this->never())->method('saveObject');
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			self::entity(self::original(['endAt' => '']), 'b-orig'),
			self::entity(self::original(), 'b-orig'),
			self::entity(self::original(['status' => 'completed']), 'b-orig')
		);
		$service = $this->service();
		$cases = [
			['nope', 'newStartAt must be an ISO-8601 timestamp'],
			['2026-06-15T12:00:00+02:00', 'original booking is missing startAt/endAt'],
			['2026-06-15T08:00:00Z', 'newStartAt must differ from current startAt'],
		];
		foreach ($cases as [$start, $message]) {
			try {
				$service->rescheduleBooking('b-orig', $start);
				$this->fail('Expected a refusal: ' . $message);
			} catch (InvalidArgumentException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}

		$this->expectException(InvalidArgumentException::class);
		$service->rescheduleBooking('b-orig', '2026-06-15T12:00:00+02:00');
	}//end testRescheduleRefusals()

	/**
	 * The exact new and updated payloads, and both invalidations.
	 *
	 * @return void
	 */
	public function testReschedulePayloadGolden(): void {
		$this->objects->method('find')->willReturn(self::entity(self::original(), 'b-orig'));
		$this->recordSaves('b-new');

		$this->assertSame('b-new', $this->service()->rescheduleBooking('b-orig', '2026-06-15T12:00:00+02:00'));

		$this->assertCount(2, $this->saved);
		$this->assertNull($this->saved[0][1]);
		$this->assertSame(
			[
				'customerId' => 'cust-1',
				'serviceId' => 'svc-1',
				'resourceAssignments' => [
					['stepIndex' => 1, 'resourceId' => 'res-a', 'startAt' => '2026-06-15T10:00:00+00:00', 'endAt' => '2026-06-15T10:30:00+00:00'],
					['stepIndex' => 0, 'resourceId' => 'res-b', 'startAt' => '', 'endAt' => ''],
				],
				'startAt' => '2026-06-15T12:00:00+02:00',
				'endAt' => '2026-06-15T10:30:00+00:00',
				'status' => 'confirmed',
				'statusHistory' => [
					['status' => 'confirmed', 'changedAt' => 'NOW', 'changedBy' => 'admin', 'reason' => 'Rescheduled from b-orig'],
				],
				'notes' => 'n',
				'internalNotes' => '',
				'source' => 'portal',
				'depositAmount' => 7.5,
				'previousBookingId' => 'b-orig',
			],
			self::withoutClock($this->saved[0][0])
		);
		$this->assertSame('b-orig', $this->saved[1][1]);
		$update = self::withoutClock($this->saved[1][0]);
		$this->assertSame('rescheduled', $update['status']);
		$this->assertSame(
			[
				['status' => 'confirmed', 'changedAt' => '2026-06-01T09:00:00+02:00', 'changedBy' => 'portal', 'reason' => 'r'],
				['status' => 'rescheduled', 'changedAt' => 'NOW', 'changedBy' => 'admin', 'reason' => 'Rescheduled to booking b-new'],
			],
			$update['statusHistory']
		);
		$this->assertArrayNotHasKey('@self', $update);
		$this->assertSame(['res-a|2026-06-15', 'res-a|2026-06-15'], $this->invalidated);
	}//end testReschedulePayloadGolden()

	/**
	 * A reschedule whose save answers no id is refused before the original
	 * is touched.
	 *
	 * @return void
	 */
	public function testRescheduleMissingIdLeavesOriginal(): void {
		$this->objects->method('find')->willReturn(self::entity(self::original(), 'b-orig'));
		$this->objects->expects($this->once())->method('saveObject')->willReturn(self::entity([], ''));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Reschedule save returned no id');
		$this->service()->rescheduleBooking('b-orig', '2026-06-15T12:00:00+02:00');
	}//end testRescheduleMissingIdLeavesOriginal()

	/**
	 * Slot search: refusals, the failing lookup, and the merged slots.
	 *
	 * @return void
	 */
	public function testAvailableSlots(): void {
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('down')),
			self::entity(array_merge(self::SERVICE, ['durationMinutes' => 0]), 'svc-1'),
			self::entity(self::SERVICE, 'svc-1')
		);
		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);
		$this->eligibility->method('getEligibleResources')->willReturn(
			[['@self' => ['id' => 'res-a'], 'type' => 'staff'], ['@self' => ['id' => 'res-b'], 'type' => 'staff']]
		);
		$this->availability->method('computeAvailability')->willReturnCallback(
			static function (string $resourceId, string $date, int $minutes): array {
				return [
					['startTime' => $resourceId . '-1', 'endTime' => 'e', 'durationMinutes' => $minutes, 'extra' => 1],
					['startTime' => $resourceId . '-2', 'endTime' => 'e', 'durationMinutes' => $minutes],
				];
			}
		);
		$service = $this->service();

		$this->assertSame([], $service->getAvailableSlots('', '2026-06-15'));
		$this->assertSame([], $service->getAvailableSlots('svc-1', '15-06-2026'));
		$this->assertSame([], $service->getAvailableSlots('svc-1', '2026-06-15'));
		$this->assertSame([], $service->getAvailableSlots('svc-1', '2026-06-15'));
		$this->assertSame(
			[
				['startTime' => 'res-a-1', 'endTime' => 'e', 'durationMinutes' => 30, 'resourceId' => 'res-a'],
				['startTime' => 'res-a-2', 'endTime' => 'e', 'durationMinutes' => 30, 'resourceId' => 'res-a'],
				['startTime' => 'res-b-1', 'endTime' => 'e', 'durationMinutes' => 30, 'resourceId' => 'res-b'],
				['startTime' => 'res-b-2', 'endTime' => 'e', 'durationMinutes' => 30, 'resourceId' => 'res-b'],
			],
			$service->getAvailableSlots('svc-1', '2026-06-15')
		);
		$this->assertSame(
			[
				['Pipelinq: service load failed', ['service' => 'svc-1']],
				['Pipelinq: service lookup failed in getAvailableSlots', ['service' => 'svc-1', 'date' => '2026-06-15']],
			],
			$warnings
		);
	}//end testAvailableSlots()

	/**
	 * Candidate filtering: bookable, status, type, id; and the type-less service.
	 *
	 * @return void
	 */
	public function testCandidateFiltering(): void {
		$resources = [
			['@self' => ['id' => 'ok'], 'type' => 'staff'],
			['@self' => ['id' => 'unbookable'], 'type' => 'staff', 'bookable' => false],
			['@self' => ['id' => 'inactive'], 'type' => 'staff', 'status' => 'archived'],
			['@self' => ['id' => 'room'], 'type' => 'room'],
			['type' => 'staff'],
			['@self' => ['id' => 'active'], 'status' => 'active', 'type' => 'staff', 'bookable' => true],
		];
		$this->eligibility->method('getEligibleResources')->willReturn($resources);
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			self::entity(self::SERVICE, 'svc-1'),
			self::entity(array_merge(self::SERVICE, ['requiredResourceTypes' => 'staff']), 'svc-1'),
			self::entity(array_merge(self::SERVICE, ['requiredResourceTypes' => []]), 'svc-1')
		);
		$asked = [];
		$this->availability->method('computeAvailability')->willReturnCallback(
			static function (string $resourceId) use (&$asked): array {
				$asked[] = $resourceId;
				return [];
			}
		);
		$service = $this->service();

		$service->getAvailableSlots('svc-1', '2026-06-15');
		$this->assertSame(['ok', 'active'], $asked);
		$asked = [];
		$service->getAvailableSlots('svc-1', '2026-06-15');
		$this->assertSame(['ok', 'room', 'active'], $asked);
		$asked = [];
		$service->getAvailableSlots('svc-1', '2026-06-15');
		$this->assertSame(['ok', 'room', 'active'], $asked);
	}//end testCandidateFiltering()

	/**
	 * A failing eligibility lookup answers no slots and logs.
	 *
	 * @return void
	 */
	public function testCandidateLookupFailure(): void {
		$this->objects->method('find')->willReturn(self::entity(self::SERVICE, 'svc-1'));
		$this->eligibility->method('getEligibleResources')->willThrowException(new RuntimeException('down'));
		$this->availability->expects($this->never())->method('computeAvailability');
		$this->logger->expects($this->once())->method('warning')
			->with('Pipelinq: eligibility lookup failed', ['service' => 'svc-1']);

		$this->assertSame([], $this->service()->getAvailableSlots('svc-1', '2026-06-15'));
	}//end testCandidateLookupFailure()

	/**
	 * Invalidation dedups per resource and day, skips incomplete rows and
	 * survives a failing cache.
	 *
	 * @return void
	 */
	public function testInvalidationDedupAndFailure(): void {
		$availability = $this->createMock(AvailabilityService::class);
		$calls = [];
		$availability->method('invalidateCache')->willReturnCallback(
			static function (string $resourceId, string $date) use (&$calls): void {
				$calls[] = $resourceId . '|' . $date;
				if ($resourceId === 'res-x') {
					throw new RuntimeException('cache down');
				}
			}
		);
		$this->availability = $availability;
		$this->logger->expects($this->once())->method('warning')
			->with('Pipelinq: availability invalidation failed', ['resource' => 'res-x', 'date' => '2026-06-15']);
		$this->objects->method('find')->willReturn(self::entity(self::SERVICE, 'svc-1'));
		$this->objects->method('saveObject')->willReturn(self::entity([], 'b-1'));
		$request = self::createRequest();
		$request['resourceAssignments'] = [
			['resourceId' => 'res-x', 'startAt' => '2026-06-15T10:00:00+02:00'],
			['resourceId' => 'res-a', 'startAt' => '2026-06-15T10:00:00+02:00'],
			['resourceId' => 'res-a', 'startAt' => '2026-06-15T11:00:00+02:00'],
			['resourceId' => 'res-a', 'startAt' => '2026-06-16T11:00:00+02:00'],
			['resourceId' => '', 'startAt' => '2026-06-16T11:00:00+02:00'],
			['resourceId' => 'res-c', 'startAt' => ''],
		];

		$this->service()->createBooking($request, 'portal');

		$this->assertSame(['res-x|2026-06-15', 'res-a|2026-06-15', 'res-a|2026-06-16'], $calls);
	}//end testInvalidationDedupAndFailure()
}//end class
