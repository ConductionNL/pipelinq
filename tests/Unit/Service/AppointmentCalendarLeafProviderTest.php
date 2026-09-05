<?php

/**
 * Unit tests for AppointmentCalendarLeafProvider.
 *
 * Exercises the bridge between appointment-booking and the OR `calendar` leaf:
 * leaf VEVENT → HH:MM blocks for getBlockedTimes, confirmed Booking → leaf
 * createEvent for pushBookingEvent, and reschedule = move semantics.
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
 * @spec openspec/changes/appointment-booking-10-calendar-sync/specs/appointment-booking/spec.md#req-apt-018
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\AppointmentCalendarLeafProvider;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AppointmentCalendarLeafProvider.
 *
 * The leaf is an anonymous-class double whose `createEvent`, `getEventsForObject`
 * and `unlinkEventsForObject` methods record calls into properties the test
 * inspects — no live Nextcloud or CalDAV needed.
 */
class AppointmentCalendarLeafProviderTest extends TestCase {

	/**
	 * Build a provider under test with overridable mocks.
	 *
	 * @param ObjectServiceInterface|null $objectService Optional ObjectService mock.
	 * @param IUserManager|null $userManager Optional user manager mock.
	 *
	 * @return array{0: AppointmentCalendarLeafProvider, 1: ObjectServiceInterface}
	 */
	private function buildProvider(
		?ObjectServiceInterface $objectService = null,
		?IUserManager $userManager = null,
	): array {
		$objectService = ($objectService ?? $this->createMock(ObjectServiceInterface::class));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'pipelinq',
					'booking_schema' => 'booking',
					'service_schema' => 'service',
					'resource_schema' => 'appointmentResource',
					'contact_schema' => 'contact',
				];
				return ($values[$key] ?? $default);
			}
		);

		if ($userManager === null) {
			$userManager = $this->createMock(IUserManager::class);
			$userManager->method('get')->willReturn(null);
		}

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://nc.test' . $path
		);

		$logger = $this->createMock(LoggerInterface::class);

		$provider = new AppointmentCalendarLeafProvider(
			container: $container,
			appConfig: $appConfig,
			userManager: $userManager,
			urlGenerator: $urlGenerator,
			logger: $logger,
		);

		return [$provider, $objectService];
	}//end buildProvider()

	/**
	 * Wrap a fixture row as the ObjectEntity OpenRegister actually returns.
	 *
	 * Since ADR-084 `find()` is declared `?ObjectEntityInterface`, not the bare
	 * array these fixtures are written as. The UUID comes from the fixture's own
	 * `@self.id`, which `jsonSerialize()` rebuilds the `@self` envelope from.
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
	 * Build a leaf double that captures createEvent + unlinkEventsForObject calls.
	 *
	 * @param array<int, array<string, mixed>> $eventsForObject Optional canned events list.
	 *
	 * @return object
	 */
	private function buildLeaf(array $eventsForObject = []): object {
		// phpcs:ignore SlevomatCodingStandard.Classes.RequireSingleLineMethodSignature
		return new class($eventsForObject) {
			/**
			 * Capture of createEvent invocations.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $creates = [];

			/**
			 * Capture of unlinkEventsForObject invocations.
			 *
			 * @var array<int, string>
			 */
			public array $unlinks = [];

			/**
			 * Canned event list returned by getEventsForObject.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			private array $events;

			/**
			 * @param array<int, array<string, mixed>> $events Canned events.
			 */
			public function __construct(array $events) {
				$this->events = $events;
			}//end __construct()

			/**
			 * Stub getEventsForObject — returns the canned list.
			 *
			 * @param string $uuid Object UUID.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function getEventsForObject(string $uuid): array {
				return $this->events;
			}//end getEventsForObject()

			/**
			 * Stub createEvent — records the call.
			 *
			 * @param int $registerId Register id.
			 * @param int $schemaId Schema id.
			 * @param string $objectUuid Object UUID.
			 * @param string $objectTitle Object title.
			 * @param array $data VEVENT payload.
			 *
			 * @return array<string, mixed>
			 */
			public function createEvent(int $registerId, int $schemaId, string $objectUuid, string $objectTitle, array $data): array {
				$this->creates[] = [
					'registerId' => $registerId,
					'schemaId' => $schemaId,
					'objectUuid' => $objectUuid,
					'title' => $objectTitle,
					'data' => $data,
				];
				return ['id' => 'ev-' . count($this->creates)];
			}//end createEvent()

			/**
			 * Stub unlinkEventsForObject — records the call.
			 *
			 * @param string $uuid Object UUID.
			 *
			 * @return void
			 */
			public function unlinkEventsForObject(string $uuid): void {
				$this->unlinks[] = $uuid;
			}//end unlinkEventsForObject()
		};
	}//end buildLeaf()

	/**
	 * getBlockedTimes returns empty when the resource has no calendarSyncId.
	 *
	 * @return void
	 */
	public function testGetBlockedTimesEmptyWithoutCalendarSyncId(): void {
		$object = $this->createMock(ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity([
			'@self' => ['id' => 'res-sarah'],
			'name' => 'Sarah',
		]));

		[$provider] = $this->buildProvider(objectService: $object);
		$provider->setLeaf(leaf: $this->buildLeaf());

		$blocks = $provider->getBlockedTimes(resourceId: 'res-sarah', date: '2026-06-01');
		$this->assertSame(expected: [], actual: $blocks);
	}//end testGetBlockedTimesEmptyWithoutCalendarSyncId()

	/**
	 * getBlockedTimes converts overlapping leaf VEVENTs to HH:MM blocks.
	 *
	 * @return void
	 */
	public function testGetBlockedTimesConvertsLeafEventsToBlocks(): void {
		$object = $this->createMock(ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity([
			'@self' => ['id' => 'res-sarah'],
			'name' => 'Sarah',
			'calendarSyncId' => 'sarah-cal-uuid',
		]));

		$leaf = $this->buildLeaf(eventsForObject: [
			['dtstart' => '2026-06-01T12:00:00+00:00', 'dtend' => '2026-06-01T13:00:00+00:00'],
			// Outside the day window — must be dropped.
			['dtstart' => '2026-06-02T09:00:00+00:00', 'dtend' => '2026-06-02T10:00:00+00:00'],
		]);

		[$provider] = $this->buildProvider(objectService: $object);
		$provider->setLeaf(leaf: $leaf);

		date_default_timezone_set('UTC');
		$blocks = $provider->getBlockedTimes(resourceId: 'res-sarah', date: '2026-06-01');

		$this->assertCount(expectedCount: 1, haystack: $blocks);
		$this->assertSame(expected: '12:00', actual: $blocks[0]['startTime']);
		$this->assertSame(expected: '13:00', actual: $blocks[0]['endTime']);
	}//end testGetBlockedTimesConvertsLeafEventsToBlocks()

	/**
	 * pushBookingEvent creates one VEVENT per staff resource on the leaf.
	 *
	 * @return void
	 */
	public function testPushBookingEventCreatesVeventViaLeaf(): void {
		$booking = [
			'@self' => ['id' => 'b-1'],
			'customerId' => 'cust-1',
			'customerName' => 'Marieke',
			'serviceId' => 'svc-haircut',
			'startAt' => '2026-06-15T10:00:00+02:00',
			'endAt' => '2026-06-15T10:30:00+02:00',
			'status' => 'confirmed',
			'resourceAssignments' => [
				['stepIndex' => 0, 'resourceId' => 'res-sarah', 'startAt' => '2026-06-15T10:00:00+02:00', 'endAt' => '2026-06-15T10:30:00+02:00'],
			],
		];
		$service = [
			'@self' => ['id' => 'svc-haircut'],
			'name' => 'Knipbeurt',
			'description' => 'Een knipbeurt',
		];
		$resource = [
			'@self' => ['id' => 'res-sarah'],
			'name' => 'Sarah',
			'userId' => 'sarah',
		];

		$object = $this->createMock(ObjectServiceInterface::class);
		$object->method('find')->willReturnCallback(
			static function (string|int $id) use ($booking, $service, $resource): ?ObjectEntityInterface {
				if ($id === 'b-1') {
					return self::entity($booking);
				}

				if ($id === 'svc-haircut') {
					return self::entity($service);
				}

				if ($id === 'res-sarah') {
					return self::entity($resource);
				}

				return null;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('sarah@example.test');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$leaf = $this->buildLeaf();

		[$provider] = $this->buildProvider(objectService: $object, userManager: $userManager);
		$provider->setLeaf(leaf: $leaf);

		$provider->pushBookingEvent(bookingId: 'b-1');

		$this->assertCount(expectedCount: 1, haystack: $leaf->creates);
		$call = $leaf->creates[0];
		$this->assertSame(expected: 'b-1', actual: $call['objectUuid']);
		$this->assertStringContainsString(needle: 'Marieke', haystack: $call['title']);
		$this->assertStringContainsString(needle: 'Knipbeurt', haystack: $call['title']);
		$this->assertSame(expected: '2026-06-15T10:00:00+02:00', actual: $call['data']['dtstart']);
		$this->assertSame(expected: ['sarah@example.test'], actual: $call['data']['attendees']);
		$this->assertStringContainsString(needle: '/apps/pipelinq/booking/b-1', haystack: $call['data']['description']);
	}//end testPushBookingEventCreatesVeventViaLeaf()

	/**
	 * Reschedule semantics: the leaf's existing VEVENTs for the booking UUID
	 * are dropped first, then a fresh VEVENT is created.
	 *
	 * @return void
	 */
	public function testPushBookingEventMovesNotDuplicates(): void {
		$booking = [
			'@self' => ['id' => 'b-2'],
			'customerId' => 'cust-1',
			'serviceId' => 'svc-haircut',
			'startAt' => '2026-06-15T14:00:00+02:00',
			'endAt' => '2026-06-15T14:30:00+02:00',
			'status' => 'confirmed',
			'resourceAssignments' => [
				['stepIndex' => 0, 'resourceId' => 'res-sarah', 'startAt' => '2026-06-15T14:00:00+02:00', 'endAt' => '2026-06-15T14:30:00+02:00'],
			],
		];

		$object = $this->createMock(ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity($booking));

		$leaf = $this->buildLeaf();

		[$provider] = $this->buildProvider(objectService: $object);
		$provider->setLeaf(leaf: $leaf);

		$provider->pushBookingEvent(bookingId: 'b-2');

		$this->assertSame(expected: ['b-2'], actual: $leaf->unlinks);
		$this->assertCount(expectedCount: 1, haystack: $leaf->creates);
	}//end testPushBookingEventMovesNotDuplicates()

	/**
	 * pushBookingEvent is a no-op when the booking is not confirmed.
	 *
	 * @return void
	 */
	public function testPushBookingEventSkipsUnconfirmedBookings(): void {
		$object = $this->createMock(ObjectServiceInterface::class);
		$object->method('find')->willReturn(self::entity([
			'@self' => ['id' => 'b-pending'],
			'status' => 'pending-deposit',
		]));

		$leaf = $this->buildLeaf();
		[$provider] = $this->buildProvider(objectService: $object);
		$provider->setLeaf(leaf: $leaf);

		$provider->pushBookingEvent(bookingId: 'b-pending');

		$this->assertSame(expected: [], actual: $leaf->creates);
	}//end testPushBookingEventSkipsUnconfirmedBookings()
}//end class
