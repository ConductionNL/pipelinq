<?php

/**
 * Unit tests for BookingService — booking lifecycle, status state machine,
 * cancellation policy enforcement, no-show counter, AvailabilityCache
 * invalidation, reschedule audit-trail.
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
 * @spec openspec/changes/appointment-booking-04-booking-service/specs/appointment-booking/spec.md#req-apt-008
 * @spec openspec/changes/appointment-booking-04-booking-service/specs/appointment-booking/spec.md#req-apt-009
 * @spec openspec/changes/appointment-booking-04-booking-service/specs/appointment-booking/spec.md#req-apt-011
 * @spec openspec/changes/appointment-booking-04-booking-service/specs/appointment-booking/spec.md#req-apt-013
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\BackgroundJob\WalkInQueueRebalanceJob;
use OCA\Pipelinq\Service\AvailabilityService;
use OCA\Pipelinq\Service\BookingService;
use OCA\Pipelinq\Service\EligibilityService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for BookingService.
 *
 * Mocks ObjectService, AvailabilityService, IUserSession, and the optional
 * payment / email seams. No live Nextcloud server is required.
 */
class BookingServiceTest extends TestCase {

	/**
	 * Service fixture: no deposit, free cancellation, 30-min duration.
	 *
	 * @var array<string, mixed>
	 */
	private const SERVICE_FREE_HAIRCUT = [
		'@self' => ['id' => 'svc-haircut'],
		'name' => 'Knipbeurt',
		'durationMinutes' => 30,
		'bufferBeforeMinutes' => 0,
		'bufferAfterMinutes' => 5,
		'price' => 27.5,
		'requiredResourceTypes' => ['staff'],
		'requiresDeposit' => false,
		'depositAmount' => 0,
		'noShowFee' => 10.0,
		'cancellationPolicy' => 'free',
		'cancellationHoursBefore' => 24,
		'status' => 'active',
		// The portal only ever offers services carrying this flag. It was
		// absent here, which is part of why getAvailableSlots() could go so
		// long without checking it: no fixture distinguished a service the
		// public may see from one it may not.
		'bookableOnline' => true,
	];

	/**
	 * Service fixture: requires a deposit + always-charge cancellation.
	 *
	 * @var array<string, mixed>
	 */
	private const SERVICE_DEPOSIT_REQUIRED = [
		'@self' => ['id' => 'svc-color'],
		'name' => 'Kleur & Knippen',
		'durationMinutes' => 120,
		'requiredResourceTypes' => ['staff'],
		'requiresDeposit' => true,
		'depositAmount' => 20.0,
		'noShowFee' => 25.0,
		'cancellationPolicy' => 'always-charge',
		'cancellationHoursBefore' => 48,
		'price' => 95.0,
		'status' => 'active',
	];

	/**
	 * Build a BookingService with overridable mocks.
	 *
	 * @param ObjectServiceInterface|null $objectService Optional pre-built ObjectService mock.
	 * @param AvailabilityService|null $availability Optional pre-built AvailabilityService mock.
	 * @param EligibilityService|null $eligibility Optional pre-built EligibilityService mock.
	 * @param IUserSession|null $userSession Optional pre-built user session mock.
	 * @param IJobList|null $jobList Optional pre-built background-job list mock.
	 *
	 * @return array{0: BookingService, 1: ObjectServiceInterface, 2: AvailabilityService, 3: EligibilityService, 4: IJobList}
	 */
	private function buildService(
		?ObjectServiceInterface $objectService = null,
		?AvailabilityService $availability = null,
		?EligibilityService $eligibility = null,
		?IUserSession $userSession = null,
		?IJobList $jobList = null,
	): array {
		$objectService = ($objectService ?? $this->createMock(originalClassName: ObjectServiceInterface::class));
		$availability = ($availability ?? $this->createMock(originalClassName: AvailabilityService::class));
		$eligibility = ($eligibility ?? $this->createMock(originalClassName: EligibilityService::class));

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			callback: static function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'pipelinq',
					'booking_schema' => 'appointmentBooking',
					'service_schema' => 'service',
					'resource_schema' => 'appointmentResource',
					'contact_schema' => 'contact',
				];
				return ($values[$key] ?? $default);
			}
		);

		if ($userSession === null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn('admin');

			$userSession = $this->createMock(originalClassName: IUserSession::class);
			$userSession->method('getUser')->willReturn($user);
		}

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$jobList = ($jobList ?? $this->createMock(originalClassName: IJobList::class));

		$service = new BookingService(
			appConfig: $appConfig,
			userSession: $userSession,
			availabilityService: $availability,
			eligibilityService: $eligibility,
			logger: $logger,
			jobList: $jobList,
			objectService: $objectService,
		);

		return [$service, $objectService, $availability, $eligibility, $jobList];
	}//end buildService()

	/**
	 * Wrap a fixture row as the ObjectEntity OpenRegister actually returns.
	 *
	 * Since ADR-084 `find()` / `saveObject()` are declared to return
	 * `ObjectEntityInterface`, not the bare array the fixtures are written as.
	 * The UUID is taken from the fixture's own `@self.id` so the entity's
	 * `jsonSerialize()` envelope — which OVERWRITES `@self` — reproduces the id
	 * the assertions expect rather than a blank one.
	 *
	 * @param array<string, mixed> $row The fixture row.
	 *
	 * @return ObjectEntity The row as an entity.
	 */
	private static function entity(array $row): ObjectEntity {
		$self = ($row['@self'] ?? []);
		$id = '';
		if (is_array($self) === true && isset($self['id']) === true) {
			$id = (string)$self['id'];
		} elseif (isset($row['id']) === true) {
			$id = (string)$row['id'];
		}

		$entity = new ObjectEntity();
		$entity->setUuid($id);
		$entity->setObject($row);

		return $entity;
	}//end entity()

	/**
	 * Confirms createBooking returns a `confirmed` booking when no deposit is
	 * required and stamps a single statusHistory entry signed by the user UID.
	 *
	 * @return void
	 */
	public function testCreateBookingCreatesConfirmedWhenNoDeposit(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity(self::SERVICE_FREE_HAIRCUT));

		$captured = null;
		$object->expects($this->once())->method('saveObject')->willReturnCallback(
			function (
				array|object $bookingObject,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$captured): ObjectEntityInterface {
				$captured = $bookingObject;
				return self::entity(['@self' => ['id' => 'b-new']]);
			}
		);

		[$service] = $this->buildService(objectService: $object);

		$uuid = $service->createBooking(
			data: [
				'customerId' => 'cust-1',
				'serviceId' => 'svc-haircut',
				'startAt' => '2026-06-15T10:00:00+02:00',
				'endAt' => '2026-06-15T10:30:00+02:00',
				'resourceAssignments' => [
					[
						'stepIndex' => 0,
						'resourceId' => 'res-sarah',
						'startAt' => '2026-06-15T10:00:00+02:00',
						'endAt' => '2026-06-15T10:30:00+02:00',
					],
				],
			],
			source: 'portal'
		);

		$this->assertSame(expected: 'b-new', actual: $uuid);
		$this->assertIsArray(actual: $captured);
		$this->assertSame(expected: 'confirmed', actual: $captured['status']);
		$this->assertCount(expectedCount: 1, haystack: $captured['statusHistory']);
		$this->assertSame(expected: 'confirmed', actual: $captured['statusHistory'][0]['status']);
		$this->assertSame(expected: 'admin', actual: $captured['statusHistory'][0]['changedBy']);
		$this->assertSame(expected: 'portal', actual: $captured['source']);
		$this->assertSame(expected: 0.0, actual: $captured['depositAmount']);

	}//end testCreateBookingCreatesConfirmedWhenNoDeposit()

	/**
	 * Confirms a deposit-requiring service starts the booking in pending-deposit
	 * and snapshots the Service.depositAmount onto the Booking row.
	 *
	 * @return void
	 */
	public function testCreateBookingCreatesPendingDepositWhenDepositRequired(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity(self::SERVICE_DEPOSIT_REQUIRED));

		$captured = null;
		$object->method('saveObject')->willReturnCallback(
			function (
				array|object $bookingObject,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$captured): ObjectEntityInterface {
				$captured = $bookingObject;
				return self::entity(['@self' => ['id' => 'b-pending']]);
			}
		);

		[$service] = $this->buildService(objectService: $object);

		$uuid = $service->createBooking(
			data: [
				'customerId' => 'cust-1',
				'serviceId' => 'svc-color',
				'startAt' => '2026-06-20T13:00:00+02:00',
				'endAt' => '2026-06-20T15:00:00+02:00',
			],
			source: 'portal'
		);

		$this->assertSame(expected: 'b-pending', actual: $uuid);
		$this->assertSame(expected: 'pending-deposit', actual: $captured['status']);
		$this->assertSame(expected: 20.0, actual: $captured['depositAmount']);
		$this->assertSame(expected: 'pending-deposit', actual: $captured['statusHistory'][0]['status']);

	}//end testCreateBookingCreatesPendingDepositWhenDepositRequired()

	/**
	 * Reschedule transitions the original to `rescheduled` (preserving the
	 * audit trail), creates a new Booking with the original's customer +
	 * service + shifted assignments, and stamps previousBookingId.
	 *
	 * @return void
	 */
	public function testRescheduleBookingPreservesOriginalAndCreatesNew(): void {
		$original = [
			'@self' => ['id' => 'b-orig'],
			'customerId' => 'cust-1',
			'serviceId' => 'svc-haircut',
			'startAt' => '2026-06-15T10:00:00+02:00',
			'endAt' => '2026-06-15T10:30:00+02:00',
			'status' => 'confirmed',
			'statusHistory' => [
				[
					'status' => 'confirmed',
					'changedAt' => '2026-06-01T09:00:00+02:00',
					'changedBy' => 'portal',
					'reason' => 'Booking created via portal',
				],
			],
			'source' => 'portal',
			'depositAmount' => 0.0,
			'resourceAssignments' => [
				[
					'stepIndex' => 0,
					'resourceId' => 'res-sarah',
					'startAt' => '2026-06-15T10:00:00+02:00',
					'endAt' => '2026-06-15T10:30:00+02:00',
				],
			],
			'notes' => 'Graag iets korter',
		];

		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity($original));

		$saved = [];
		$object->method('saveObject')->willReturnCallback(
			function (
				array|object $payload,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$saved): ObjectEntityInterface {
				// First call = new booking (uuid null); second = update of original.
				if ($uuid === null) {
					$saved['new'] = $payload;
					return self::entity(['@self' => ['id' => 'b-new']]);
				}

				$saved['update'] = ['payload' => $payload, 'uuid' => $uuid];
				return self::entity(['@self' => ['id' => $uuid]]);
			}
		);

		[$service] = $this->buildService(objectService: $object);

		$newUuid = $service->rescheduleBooking(
			bookingId: 'b-orig',
			newStartAt: '2026-06-15T14:00:00+02:00'
		);

		$this->assertSame(expected: 'b-new', actual: $newUuid);
		$this->assertArrayHasKey(key: 'new', array: $saved);
		$this->assertArrayHasKey(key: 'update', array: $saved);

		// New booking inherits customer + service, shifts times, stamps previousBookingId.
		$this->assertSame(expected: 'cust-1', actual: $saved['new']['customerId']);
		$this->assertSame(expected: 'svc-haircut', actual: $saved['new']['serviceId']);
		$this->assertSame(expected: '2026-06-15T14:00:00+02:00', actual: $saved['new']['startAt']);
		$this->assertSame(expected: 'b-orig', actual: $saved['new']['previousBookingId']);
		$this->assertSame(expected: 'confirmed', actual: $saved['new']['status']);

		// Original transitioned to `rescheduled` (preserved, not deleted).
		$this->assertSame(expected: 'b-orig', actual: $saved['update']['uuid']);
		$this->assertSame(expected: 'rescheduled', actual: $saved['update']['payload']['status']);
		$this->assertCount(expectedCount: 2, haystack: $saved['update']['payload']['statusHistory']);

	}//end testRescheduleBookingPreservesOriginalAndCreatesNew()

	/**
	 * Free-policy cancellation within the window: customer cancels, no
	 * payment charge queued (regardless of window).
	 *
	 * @return void
	 */
	public function testCancelBookingFreePolicyDoesNotChargeWithinWindow(): void {
		$bookingStart = (new DateTimeImmutable('+48 hours', new DateTimeZone('UTC')))
			->format('Y-m-d\TH:i:sP');
		$booking = [
			'@self' => ['id' => 'b-free'],
			'customerId' => 'cust-1',
			'serviceId' => 'svc-haircut',
			'startAt' => $bookingStart,
			'endAt' => $bookingStart,
			'status' => 'confirmed',
			'statusHistory' => [
				[
					'status' => 'confirmed',
					'changedAt' => '2026-01-01T00:00:00+00:00',
					'changedBy' => 'portal',
					'reason' => 'Booking created',
				],
			],
			'depositAmount' => 0.0,
		];

		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturnCallback(
			callback: function (string|int $id) use ($booking): ?ObjectEntityInterface {
				if ($id === 'b-free') {
					return self::entity($booking);
				}

				if ($id === 'svc-haircut') {
					return self::entity(self::SERVICE_FREE_HAIRCUT);
				}

				return null;
			}
		);

		$saved = null;
		$object->method('saveObject')->willReturnCallback(
			function (
				array|object $payload,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$saved): ObjectEntityInterface {
				$saved = $payload;
				return self::entity(['@self' => ['id' => 'b-free']]);
			}
		);

		$payment = new class {

			/**
			 * Charges captured for assertion.
			 *
			 * @var array<int, array{0: string, 1: float, 2: string}>
			 */
			public array $charges = [];

			/**
			 * Capture a cancellation fee request.
			 *
			 * @param string $bookingId Booking UUID.
			 * @param float $amount Amount.
			 *
			 * @return void
			 */
			public function chargeCancellationFee(string $bookingId, float $amount): void {
				$this->charges[] = [$bookingId, $amount, 'cancel'];
			}//end chargeCancellationFee()
		};

		[$service] = $this->buildService(objectService: $object);
		$service->setPaymentProvider(provider: $payment);

		$service->cancelBooking(
			bookingId: 'b-free',
			reason: 'Andere afspraak',
			cancelledBy: 'cust-1'
		);

		$this->assertSame(expected: 'cancelled-by-customer', actual: $saved['status']);
		$this->assertSame(expected: [], actual: $payment->charges);

	}//end testCancelBookingFreePolicyDoesNotChargeWithinWindow()

	/**
	 * Late cancellation under an always-charge service triggers a charge via
	 * the payment seam (REQ-APT-009 scenario 2).
	 *
	 * @return void
	 */
	public function testCancelBookingTriggersChargeWhenInsidePolicyWindow(): void {
		$bookingStart = (new DateTimeImmutable('+18 hours', new DateTimeZone('UTC')))
			->format('Y-m-d\TH:i:sP');

		$alwaysChargeService = [
			'@self' => ['id' => 'svc-strict'],
			'price' => 50.0,
			'durationMinutes' => 30,
			'cancellationPolicy' => 'always-charge',
			'cancellationHoursBefore' => 24,
			'depositAmount' => 0.0,
			'requiresDeposit' => false,
			'noShowFee' => 0.0,
			'status' => 'active',
			'requiredResourceTypes' => ['staff'],
		];

		$booking = [
			'@self' => ['id' => 'b-late'],
			'customerId' => 'cust-1',
			'serviceId' => 'svc-strict',
			'startAt' => $bookingStart,
			'endAt' => $bookingStart,
			'status' => 'confirmed',
			'statusHistory' => [
				[
					'status' => 'confirmed',
					'changedAt' => '2026-01-01T00:00:00+00:00',
					'changedBy' => 'portal',
					'reason' => 'Booking created',
				],
			],
			'depositAmount' => 0.0,
		];

		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturnCallback(
			callback: function (string|int $id) use ($booking, $alwaysChargeService): ?ObjectEntityInterface {
				if ($id === 'b-late') {
					return self::entity($booking);
				}

				if ($id === 'svc-strict') {
					return self::entity($alwaysChargeService);
				}

				return null;
			}
		);
		$object->method('saveObject')->willReturn(self::entity(['@self' => ['id' => 'b-late']]));

		$payment = new class {

			/**
			 * Charges captured for assertion.
			 *
			 * @var array<int, array{bookingId: string, amount: float}>
			 */
			public array $charges = [];

			/**
			 * Capture a cancellation fee request.
			 *
			 * @param string $bookingId Booking UUID.
			 * @param float $amount Amount.
			 *
			 * @return void
			 */
			public function chargeCancellationFee(string $bookingId, float $amount): void {
				$this->charges[] = ['bookingId' => $bookingId, 'amount' => $amount];
			}//end chargeCancellationFee()
		};

		[$service] = $this->buildService(objectService: $object);
		$service->setPaymentProvider(provider: $payment);

		$service->cancelBooking(
			bookingId: 'b-late',
			reason: 'Te laat',
			cancelledBy: 'cust-1'
		);

		$this->assertCount(expectedCount: 1, haystack: $payment->charges);
		$this->assertSame(expected: 'b-late', actual: $payment->charges[0]['bookingId']);
		$this->assertSame(expected: 50.0, actual: $payment->charges[0]['amount']);

	}//end testCancelBookingTriggersChargeWhenInsidePolicyWindow()

	/**
	 * Staff cancellations transition to cancelled-by-business AND never charge,
	 * regardless of policy (REQ-APT-009 scenario 3).
	 *
	 * @return void
	 */
	public function testStaffCancellationSkipsChargeRegardlessOfPolicy(): void {
		$bookingStart = (new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')))
			->format('Y-m-d\TH:i:sP');

		$alwaysChargeService = self::SERVICE_DEPOSIT_REQUIRED;

		$booking = [
			'@self' => ['id' => 'b-staff'],
			'customerId' => 'cust-1',
			'serviceId' => 'svc-color',
			'startAt' => $bookingStart,
			'endAt' => $bookingStart,
			'status' => 'confirmed',
			'statusHistory' => [
				[
					'status' => 'confirmed',
					'changedAt' => '2026-01-01T00:00:00+00:00',
					'changedBy' => 'portal',
					'reason' => 'Created',
				],
			],
			'depositAmount' => 20.0,
		];

		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturnCallback(
			callback: function (string|int $id) use ($booking, $alwaysChargeService): ?ObjectEntityInterface {
				if ($id === 'b-staff') {
					return self::entity($booking);
				}

				if ($id === 'svc-color') {
					return self::entity($alwaysChargeService);
				}

				return null;
			}
		);

		$saved = null;
		$object->method('saveObject')->willReturnCallback(
			function (
				array|object $payload,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$saved): ObjectEntityInterface {
				$saved = $payload;
				return self::entity(['@self' => ['id' => 'b-staff']]);
			}
		);

		$payment = new class {

			/**
			 * Charges captured for assertion.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $charges = [];

			/**
			 * Capture a cancellation fee request.
			 *
			 * @param string $bookingId Booking UUID.
			 * @param float $amount Amount.
			 *
			 * @return void
			 */
			public function chargeCancellationFee(string $bookingId, float $amount): void {
				$this->charges[] = ['bookingId' => $bookingId, 'amount' => $amount];
			}//end chargeCancellationFee()
		};

		[$service] = $this->buildService(objectService: $object);
		$service->setPaymentProvider(provider: $payment);

		$service->cancelBooking(
			bookingId: 'b-staff',
			reason: 'Resource onbeschikbaar',
			cancelledBy: 'admin'
		);

		$this->assertSame(expected: 'cancelled-by-business', actual: $saved['status']);
		$this->assertSame(expected: [], actual: $payment->charges);

	}//end testStaffCancellationSkipsChargeRegardlessOfPolicy()

	/**
	 * Mark no-show: transitions to `no-show` and increments the customer's
	 * lifetime noShowCount. The no-show is recorded even when the customer
	 * lookup fails (REQ-APT-011 scenario 2).
	 *
	 * @return void
	 */
	public function testMarkNoShowIncrementsCustomerNoShowCount(): void {
		$booking = [
			'@self' => ['id' => 'b-shown'],
			'customerId' => 'cust-99',
			'serviceId' => 'svc-haircut',
			'startAt' => '2026-05-01T10:00:00+02:00',
			'endAt' => '2026-05-01T10:30:00+02:00',
			'status' => 'confirmed',
			'statusHistory' => [
				[
					'status' => 'confirmed',
					'changedAt' => '2026-01-01T00:00:00+00:00',
					'changedBy' => 'portal',
					'reason' => 'Created',
				],
			],
			'depositAmount' => 0.0,
		];

		$customer = ['@self' => ['id' => 'cust-99'], 'noShowCount' => 2];

		$saves = [];
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturnCallback(
			callback: function (string|int $id) use ($booking, $customer): ?ObjectEntityInterface {
				if ($id === 'b-shown') {
					return self::entity($booking);
				}

				if ($id === 'svc-haircut') {
					return self::entity(self::SERVICE_FREE_HAIRCUT);
				}

				if ($id === 'cust-99') {
					return self::entity($customer);
				}

				return null;
			}
		);
		$object->method('saveObject')->willReturnCallback(
			function (
				array|object $payload,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$saves): ObjectEntityInterface {
				$saves[] = ['payload' => $payload, 'schema' => $schema, 'uuid' => $uuid];
				return self::entity(['@self' => ['id' => ($uuid ?? 'new')]]);
			}
		);

		[$service] = $this->buildService(objectService: $object);

		$service->markNoShow(bookingId: 'b-shown', staffUserId: 'admin');

		$this->assertNotEmpty(actual: $saves);
		$bookingSave = $saves[0]['payload'];
		$this->assertSame(expected: 'no-show', actual: $bookingSave['status']);
		$this->assertSame(expected: 'admin', actual: end($bookingSave['statusHistory'])['changedBy']);

		$customerSave = null;
		foreach ($saves as $entry) {
			if ($entry['schema'] === 'contact') {
				$customerSave = $entry['payload'];
				break;
			}
		}

		$this->assertNotNull(actual: $customerSave, message: 'expected a customer no-show counter update');
		$this->assertSame(expected: 3, actual: $customerSave['noShowCount']);

	}//end testMarkNoShowIncrementsCustomerNoShowCount()

	/**
	 * The booking state machine is now sourced from the Booking schema's
	 * `x-openregister-lifecycle` (ADR-031). allowedTransitions() must equal the
	 * prior hardcoded map so every legal/illegal edge is preserved exactly.
	 *
	 * @return void
	 */
	public function testAllowedTransitionsSourcedFromSchema(): void {
		$this->assertSame(
			expected: [
				'pending-deposit' => [
					'confirmed',
					'cancelled-by-customer',
					'cancelled-by-business',
					'rescheduled',
				],
				'confirmed' => [
					'completed',
					'no-show',
					'cancelled-by-customer',
					'cancelled-by-business',
					'rescheduled',
				],
				'completed' => [],
				'no-show' => [],
				'cancelled-by-customer' => [],
				'cancelled-by-business' => [],
				'rescheduled' => [],
			],
			actual: BookingService::allowedTransitions()
		);
	}//end testAllowedTransitionsSourcedFromSchema()

	/**
	 * Legal edges declared in the schema are accepted, and a transition out of a
	 * terminal state is rejected with the "Unknown source status" message (the
	 * terminal state is a key with an empty target list).
	 *
	 * @return void
	 */
	public function testLegalAndTerminalBookingTransitions(): void {
		[$service] = $this->buildService();

		// Legal edges (no exception).
		$service->assertTransitionAllowed(from: 'pending-deposit', to: 'confirmed');
		$service->assertTransitionAllowed(from: 'confirmed', to: 'completed');
		$service->assertTransitionAllowed(from: 'confirmed', to: 'cancelled-by-business');
		$this->addToAssertionCount(count: 3);

		// Out of a terminal state: completed has an empty target list, so any
		// target is an invalid transition.
		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Invalid status transition: completed -> confirmed');
		$service->assertTransitionAllowed(from: 'completed', to: 'confirmed');
	}//end testLegalAndTerminalBookingTransitions()

	/**
	 * Status machine: confirmed -> pending-deposit is an invalid transition.
	 * Reject it with InvalidArgumentException (REQ-APT-013 scenario 1).
	 *
	 * @return void
	 */
	public function testInvalidStatusTransitionIsRejected(): void {
		$booking = [
			'@self' => ['id' => 'b-invalid'],
			'customerId' => 'cust-1',
			'serviceId' => 'svc-haircut',
			'startAt' => '2026-06-01T10:00:00+02:00',
			'endAt' => '2026-06-01T10:30:00+02:00',
			'status' => 'confirmed',
			'statusHistory' => [],
			'depositAmount' => 0.0,
		];

		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity($booking));
		$object->expects($this->never())->method('saveObject');

		[$service] = $this->buildService(objectService: $object);

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Invalid status transition: confirmed -> pending-deposit');

		$service->assertTransitionAllowed(from: 'confirmed', to: 'pending-deposit');

	}//end testInvalidStatusTransitionIsRejected()

	/**
	 * Confirms the pending-deposit -> confirmed transition stamps
	 * confirmationSentAt and dispatches via the email seam.
	 *
	 * @return void
	 */
	public function testConfirmBookingTransitionsAndFiresEmailSeam(): void {
		$booking = [
			'@self' => ['id' => 'b-pending'],
			'customerId' => 'cust-1',
			'serviceId' => 'svc-color',
			'startAt' => '2026-07-01T10:00:00+02:00',
			'endAt' => '2026-07-01T12:00:00+02:00',
			'status' => 'pending-deposit',
			'statusHistory' => [
				[
					'status' => 'pending-deposit',
					'changedAt' => '2026-06-01T00:00:00+00:00',
					'changedBy' => 'portal',
					'reason' => 'Created',
				],
			],
			'depositAmount' => 20.0,
		];

		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity($booking));

		$captured = null;
		$object->method('saveObject')->willReturnCallback(
			function (
				array|object $payload,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$captured): ObjectEntityInterface {
				$captured = $payload;
				return self::entity(['@self' => ['id' => 'b-pending']]);
			}
		);

		$email = new class {

			/**
			 * Tracks confirmation dispatch calls.
			 *
			 * @var array<int, string>
			 */
			public array $sent = [];

			/**
			 * Capture sendConfirmation.
			 *
			 * @param string $bookingId Booking UUID.
			 *
			 * @return void
			 */
			public function sendConfirmation(string $bookingId): void {
				$this->sent[] = $bookingId;
			}//end sendConfirmation()
		};

		[$service] = $this->buildService(objectService: $object);
		$service->setEmailProvider(provider: $email);

		$service->confirmBooking(bookingId: 'b-pending', reason: 'Deposit cleared');

		$this->assertSame(expected: 'confirmed', actual: $captured['status']);
		$this->assertNotSame(expected: '', actual: $captured['confirmationSentAt']);
		$this->assertCount(expectedCount: 2, haystack: $captured['statusHistory']);
		$this->assertSame(expected: ['b-pending'], actual: $email->sent);

	}//end testConfirmBookingTransitionsAndFiresEmailSeam()

	/**
	 * AvailabilityCache is invalidated for every (resource, date) pair the
	 * booking touches when a Booking is created.
	 *
	 * @return void
	 */
	public function testCreateBookingInvalidatesAvailabilityCache(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity(self::SERVICE_FREE_HAIRCUT));
		$object->method('saveObject')->willReturn(self::entity(['@self' => ['id' => 'b-new']]));

		$availability = $this->createMock(originalClassName: AvailabilityService::class);
		$availability->expects($this->exactly(count: 2))
			->method('invalidateCache')
			->willReturnCallback(
				callback: static function (string $resourceId, string $date): void {
					// Argument capture; no return.
				}
			);

		[$service] = $this->buildService(objectService: $object, availability: $availability);

		$service->createBooking(
			data: [
				'customerId' => 'cust-1',
				'serviceId' => 'svc-haircut',
				'startAt' => '2026-06-15T10:00:00+02:00',
				'endAt' => '2026-06-15T11:00:00+02:00',
				'resourceAssignments' => [
					[
						'stepIndex' => 0,
						'resourceId' => 'res-a',
						'startAt' => '2026-06-15T10:00:00+02:00',
						'endAt' => '2026-06-15T10:30:00+02:00',
					],
					[
						'stepIndex' => 1,
						'resourceId' => 'res-b',
						'startAt' => '2026-06-15T10:30:00+02:00',
						'endAt' => '2026-06-15T11:00:00+02:00',
					],
				],
			],
			source: 'portal'
		);

	}//end testCreateBookingInvalidatesAvailabilityCache()

	/**
	 * Confirms getAvailableSlots delegates per-resource computation to the
	 * AvailabilityService and tags each slot with its resourceId.
	 *
	 * @return void
	 */
	public function testGetAvailableSlotsDelegatesToAvailabilityService(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity(self::SERVICE_FREE_HAIRCUT));

		$eligibility = $this->createMock(originalClassName: EligibilityService::class);
		$eligibility->method('getEligibleResources')->willReturn(
			value: [
				['@self' => ['id' => 'res-a'], 'type' => 'staff', 'status' => 'active', 'bookable' => true],
				['@self' => ['id' => 'res-b'], 'type' => 'staff', 'status' => 'active', 'bookable' => true],
				['@self' => ['id' => 'res-c'], 'type' => 'room',  'status' => 'active', 'bookable' => true],
			]
		);

		$availability = $this->createMock(originalClassName: AvailabilityService::class);
		$availability->method('computeAvailability')->willReturnCallback(
			callback: static function (string $resourceId, string $date, int $duration): array {
				return [
					['startTime' => '09:00', 'endTime' => '09:30', 'durationMinutes' => 30],
				];
			}
		);

		[$service] = $this->buildService(
			objectService: $object,
			availability: $availability,
			eligibility: $eligibility
		);

		$slots = $service->getAvailableSlots(serviceId: 'svc-haircut', date: '2026-06-15');

		// Two staff resources qualify (room filtered out by requiredResourceTypes).
		$this->assertCount(expectedCount: 2, haystack: $slots);
		$resourceIds = array_column($slots, 'resourceId');
		$this->assertContains(needle: 'res-a', haystack: $resourceIds);
		$this->assertContains(needle: 'res-b', haystack: $resourceIds);
		$this->assertNotContains(needle: 'res-c', haystack: $resourceIds);

	}//end testGetAvailableSlotsDelegatesToAvailabilityService()

	/**
	 * A service that is not offered online has no public availability.
	 *
	 * `GET /portal/availability` is `@PublicPage` and takes `serviceId`
	 * straight from the caller, so without this check an anonymous request
	 * naming any service id could read that service's free/busy pattern —
	 * including services the portal's own `services()` list, which filters on
	 * `bookableOnline: true`, deliberately never shows. The booking path
	 * already refused such a service; this path did not.
	 *
	 * The assertion is that the resources are never even consulted: a refusal
	 * that still computed availability and then discarded it would leak the
	 * same information through timing, and would pass a count-only test.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function testGetAvailableSlotsRefusesAServiceNotBookableOnline(): void {
		$offline = self::SERVICE_FREE_HAIRCUT;
		$offline['bookableOnline'] = false;

		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity($offline));

		$eligibility = $this->createMock(originalClassName: EligibilityService::class);
		$eligibility->expects($this->never())->method('getEligibleResources');

		$availability = $this->createMock(originalClassName: AvailabilityService::class);
		$availability->expects($this->never())->method('computeAvailability');

		[$service] = $this->buildService(
			objectService: $object,
			availability: $availability,
			eligibility: $eligibility
		);

		$this->assertSame(
			expected: [],
			actual: $service->getAvailableSlots(serviceId: 'svc-haircut', date: '2026-06-15')
		);

	}//end testGetAvailableSlotsRefusesAServiceNotBookableOnline()

	/**
	 * A confirmed-from-creation Booking pushes a VEVENT through the calendar
	 * seam (member 10). Pending-deposit bookings MUST NOT push — the calendar
	 * mirror only mirrors actually-confirmed appointments.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/appointment-booking-10-calendar-sync/specs/appointment-booking/spec.md#req-apt-018
	 */
	public function testCreateBookingFiresCalendarPushSeamWhenConfirmed(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity(self::SERVICE_FREE_HAIRCUT));
		$object->method('saveObject')->willReturn(self::entity(['@self' => ['id' => 'b-new']]));

		[$service] = $this->buildService(objectService: $object);

		$calendar = new class {

			/**
			 * Tracks the booking ids the seam was asked to push.
			 *
			 * @var array<int, string>
			 */
			public array $pushed = [];

			/**
			 * Capture pushBookingEvent invocations.
			 *
			 * @param string $bookingId Booking UUID.
			 *
			 * @return void
			 */
			public function pushBookingEvent(string $bookingId): void {
				$this->pushed[] = $bookingId;
			}//end pushBookingEvent()
		};

		$service->setCalendarProvider(provider: $calendar);

		$service->createBooking(
			data: [
				'customerId' => 'cust-1',
				'serviceId' => 'svc-haircut',
				'startAt' => '2026-06-15T10:00:00+02:00',
				'endAt' => '2026-06-15T10:30:00+02:00',
				'resourceAssignments' => [
					[
						'stepIndex' => 0,
						'resourceId' => 'res-sarah',
						'startAt' => '2026-06-15T10:00:00+02:00',
						'endAt' => '2026-06-15T10:30:00+02:00',
					],
				],
			],
			source: 'portal'
		);

		$this->assertSame(expected: ['b-new'], actual: $calendar->pushed);

	}//end testCreateBookingFiresCalendarPushSeamWhenConfirmed()

	/**
	 * A pending-deposit booking MUST NOT fire the calendar seam — calendar
	 * mirroring is reserved for confirmed bookings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/appointment-booking-10-calendar-sync/specs/appointment-booking/spec.md#req-apt-018
	 */
	public function testPendingDepositBookingSkipsCalendarPushSeam(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity(self::SERVICE_DEPOSIT_REQUIRED));
		$object->method('saveObject')->willReturn(self::entity(['@self' => ['id' => 'b-pending']]));

		[$service] = $this->buildService(objectService: $object);

		$calendar = new class {

			/**
			 * Tracks pushes.
			 *
			 * @var array<int, string>
			 */
			public array $pushed = [];

			/**
			 * Capture pushBookingEvent invocations.
			 *
			 * @param string $bookingId Booking UUID.
			 *
			 * @return void
			 */
			public function pushBookingEvent(string $bookingId): void {
				$this->pushed[] = $bookingId;
			}//end pushBookingEvent()
		};

		$service->setCalendarProvider(provider: $calendar);

		$service->createBooking(
			data: [
				'customerId' => 'cust-1',
				'serviceId' => 'svc-color',
				'startAt' => '2026-06-20T13:00:00+02:00',
				'endAt' => '2026-06-20T15:00:00+02:00',
			],
			source: 'portal'
		);

		$this->assertSame(expected: [], actual: $calendar->pushed);

	}//end testPendingDepositBookingSkipsCalendarPushSeam()

	/**
	 * The confirmBooking (pending-deposit → confirmed) path fires the calendar seam,
	 * mirroring the freshly-confirmed booking to the staff calendar.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/appointment-booking-10-calendar-sync/specs/appointment-booking/spec.md#req-apt-018
	 */
	public function testConfirmBookingFiresCalendarPushSeam(): void {
		$booking = [
			'@self' => ['id' => 'b-pending'],
			'customerId' => 'cust-1',
			'serviceId' => 'svc-color',
			'startAt' => '2026-07-01T10:00:00+02:00',
			'endAt' => '2026-07-01T12:00:00+02:00',
			'status' => 'pending-deposit',
			'statusHistory' => [
				[
					'status' => 'pending-deposit',
					'changedAt' => '2026-06-01T00:00:00+00:00',
					'changedBy' => 'portal',
					'reason' => 'Created',
				],
			],
			'depositAmount' => 20.0,
		];

		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity($booking));
		$object->method('saveObject')->willReturn(self::entity(['@self' => ['id' => 'b-pending']]));

		$calendar = new class {

			/**
			 * Tracks pushes.
			 *
			 * @var array<int, string>
			 */
			public array $pushed = [];

			/**
			 * Capture pushBookingEvent invocations.
			 *
			 * @param string $bookingId Booking UUID.
			 *
			 * @return void
			 */
			public function pushBookingEvent(string $bookingId): void {
				$this->pushed[] = $bookingId;
			}//end pushBookingEvent()
		};

		[$service] = $this->buildService(objectService: $object);
		$service->setCalendarProvider(provider: $calendar);

		$service->confirmBooking(bookingId: 'b-pending', reason: 'Deposit cleared');

		$this->assertSame(expected: ['b-pending'], actual: $calendar->pushed);

	}//end testConfirmBookingFiresCalendarPushSeam()

	/**
	 * Confirms completeBooking rejects transitions from already-terminal
	 * statuses (here: completed) and never persists.
	 *
	 * @return void
	 */
	public function testCompleteBookingRejectsTransitionFromTerminalStatus(): void {
		$booking = [
			'@self' => ['id' => 'b-done'],
			'customerId' => 'cust-1',
			'serviceId' => 'svc-haircut',
			'startAt' => '2026-05-01T10:00:00+02:00',
			'endAt' => '2026-05-01T10:30:00+02:00',
			'status' => 'completed',
			'statusHistory' => [],
			'depositAmount' => 0.0,
		];

		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity($booking));
		$object->expects($this->never())->method('saveObject');

		[$service] = $this->buildService(objectService: $object);

		$this->expectException(exception: InvalidArgumentException::class);

		$service->completeBooking(bookingId: 'b-done');

	}//end testCompleteBookingRejectsTransitionFromTerminalStatus()

	/**
	 * A confirmed booking fixture ready to be completed.
	 *
	 * @return array<string, mixed> The booking row.
	 */
	private function completableBooking(): array {
		return [
			'@self' => ['id' => 'b-finish'],
			'customerId' => 'cust-1',
			'serviceId' => 'svc-haircut',
			'startAt' => '2026-05-01T10:00:00+02:00',
			'endAt' => '2026-05-01T10:30:00+02:00',
			'status' => 'confirmed',
			'statusHistory' => [],
			'depositAmount' => 0.0,
		];
	}//end completableBooking()

	/**
	 * The completeBooking path SCHEDULES the walk-in queue rebalance (member 09) rather
	 * than running it inline, so the queue panel refreshes ETAs for waiting
	 * tickets without the completing request paying for the whole queue.
	 *
	 * @spec openspec/changes/appointment-booking-09-walkin-queue/specs/appointment-booking/spec.md#req-apt-012
	 *
	 * @return void
	 */
	public function testCompleteBookingSchedulesWalkInQueueRebalanceJob(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity($this->completableBooking()));
		$object->method('saveObject')->willReturn(self::entity(['@self' => ['id' => 'b-finish']]));

		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->expects($this->once())
			->method('add')
			->with(WalkInQueueRebalanceJob::class);

		[$service] = $this->buildService(objectService: $object, jobList: $jobList);

		$service->completeBooking(bookingId: 'b-finish');

	}//end testCompleteBookingSchedulesWalkInQueueRebalanceJob()

	/**
	 * The completing request must write exactly ONE object through this
	 * service's ObjectService — the booking itself. Before the rebalance was
	 * deferred, completeBooking() reached WalkInQueueService::rebalance(),
	 * which fans out to one saveObject() per waiting ticket (measured at
	 * QUEUE_PAGE_SIZE = 200 by
	 * WalkInQueueServiceTest::testRebalanceFansOutOneSaveObjectPerWaitingTicket).
	 *
	 * This pins the write budget of the completion path. It constrains any
	 * re-introduced fan-out that goes through the container-resolved
	 * ObjectService; it cannot see one routed through a separately-injected
	 * collaborator, which is why the enqueue itself is asserted separately.
	 *
	 * @spec openspec/changes/appointment-booking-09-walkin-queue/specs/appointment-booking/spec.md#req-apt-012
	 *
	 * @return void
	 */
	public function testCompleteBookingWritesOnlyTheBookingItself(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity($this->completableBooking()));
		$object->expects($this->once())
			->method('saveObject')
			->willReturn(self::entity(['@self' => ['id' => 'b-finish']]));

		[$service] = $this->buildService(objectService: $object);

		$service->completeBooking(bookingId: 'b-finish');

	}//end testCompleteBookingWritesOnlyTheBookingItself()

	/**
	 * A job list that cannot accept the rebalance must not fail the booking:
	 * the completion is already persisted, so the enqueue is best-effort.
	 *
	 * @spec openspec/changes/appointment-booking-09-walkin-queue/specs/appointment-booking/spec.md#req-apt-012
	 *
	 * @return void
	 */
	public function testCompleteBookingSurvivesAnUnavailableJobList(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity($this->completableBooking()));
		$object->method('saveObject')->willReturn(self::entity(['@self' => ['id' => 'b-finish']]));

		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->method('add')->willThrowException(new \RuntimeException('job list down'));

		[$service] = $this->buildService(objectService: $object, jobList: $jobList);

		$service->completeBooking(bookingId: 'b-finish');

		$this->addToAssertionCount(count: 1);

	}//end testCompleteBookingSurvivesAnUnavailableJobList()
}//end class
