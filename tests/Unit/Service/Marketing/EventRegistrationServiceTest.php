<?php

/**
 * Unit tests for EventRegistrationService.
 *
 * A place while one is free, the waiting list when none is, one registration
 * per address, nothing after the closing time, the re-count that settles two
 * sign-ups for the last place, a cancellation that moves the first person
 * waiting up, and attendance counted once as an `attend` touchpoint.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\EventRegistrationOutcome;
use OCA\Pipelinq\Service\Marketing\EventRegistrationService;
use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../Support/InMemoryListObjectStore.php';

/**
 * Tests for the registrations of a campaign event.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\EventRegistrationService
 * @uses   \OCA\Pipelinq\Service\Marketing\EventRegistrationOutcome
 * @uses   \OCA\Pipelinq\Service\Marketing\ListObjectStore
 * @uses   \OCA\Pipelinq\Service\TouchpointService
 */
class EventRegistrationServiceTest extends TestCase {

	/**
	 * Now, as Unix time: 2026-11-01T10:00:00Z.
	 */
	private const NOW = 1793527200;

	/**
	 * The store.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * Build the service over a store seeded with one event.
	 *
	 * @param array<string, mixed>             $event         Event fields over the defaults.
	 * @param array<int, array<string, mixed>> $registrations Registrations to seed.
	 *
	 * @return EventRegistrationService
	 */
	private function service(array $event = [], array $registrations = []): EventRegistrationService {
		$this->store = new InMemoryListObjectStore(
			[
				'campaignEvent' => [
					array_merge(
						['uuid' => 'ev-1', 'title' => 'Webinar', 'campaign' => 'camp-1', 'signupStatus' => 'open', 'capacity' => 2, 'startAt' => '2026-11-12T14:00:00+00:00'],
						$event
					),
				],
				'eventRegistration' => $registrations,
			]
		);

		return $this->serviceOver(store: $this->store);
	}//end service()

	/**
	 * The service over a given store, at NOW.
	 *
	 * @param InMemoryListObjectStore $store The store.
	 *
	 * @return EventRegistrationService
	 */
	private function serviceOver(InMemoryListObjectStore $store): EventRegistrationService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new EventRegistrationService($store, new TouchpointService($store), $time);
	}//end serviceOver()

	/**
	 * A confirmed registration row.
	 *
	 * @param string $id    The id.
	 * @param string $email The address.
	 * @param string $at    When it came in.
	 *
	 * @return array<string, mixed>
	 */
	private function confirmed(string $id, string $email, string $at = '2026-10-01T09:00:00+00:00'): array {
		return ['uuid' => $id, 'event' => 'ev-1', 'name' => $id, 'email' => $email, 'status' => 'confirmed', 'registeredAt' => $at];
	}//end confirmed()

	/**
	 * The seeded event.
	 *
	 * @return array<string, mixed>
	 */
	private function event(): array {
		return $this->store->rows['campaignEvent']['ev-1'];
	}//end event()

	/**
	 * A free place gives a confirmed registration carrying the person, contact and lead.
	 *
	 * @return void
	 */
	public function testAFreePlaceGivesARegistration(): void {
		$service = $this->service();

		$outcome = $service->register(
			event: $this->event(),
			person: ['name' => 'Anna', 'email' => ' Anna@Example.NL ', 'contact' => 'c-1', 'lead' => 'l-1', 'source' => 'web-form']
		);

		$this->assertSame(EventRegistrationOutcome::REGISTERED, $outcome->status);
		$this->assertSame('confirmed', $outcome->registration['status']);
		$this->assertSame('anna@example.nl', $outcome->registration['email']);
		$this->assertSame('ev-1', $outcome->registration['event']);
		$this->assertSame('c-1', $outcome->registration['contact']);
		$this->assertSame('l-1', $outcome->registration['lead']);
		$this->assertSame('2026-11-01T10:00:00+00:00', $outcome->registration['registeredAt']);
		$this->assertSame('open', $this->event()['signupStatus']);
	}//end testAFreePlaceGivesARegistration()

	/**
	 * A full event puts the next person on the waiting list, numbered after those already waiting.
	 *
	 * @return void
	 */
	public function testAFullEventWaitlists(): void {
		$service = $this->service(
			registrations: [
				$this->confirmed(id: 'r-1', email: 'a@example.nl'),
				$this->confirmed(id: 'r-2', email: 'b@example.nl'),
				['uuid' => 'r-3', 'event' => 'ev-1', 'email' => 'c@example.nl', 'status' => 'waitlist', 'waitlistPosition' => 1],
			]
		);

		$outcome = $service->register(event: $this->event(), person: ['name' => 'Dirk', 'email' => 'd@example.nl']);

		$this->assertSame(EventRegistrationOutcome::WAITLISTED, $outcome->status);
		$this->assertSame('waitlist', $outcome->registration['status']);
		$this->assertSame(2, $outcome->registration['waitlistPosition']);
	}//end testAFullEventWaitlists()

	/**
	 * The last place taken marks the event full.
	 *
	 * @return void
	 */
	public function testTakingTheLastPlaceMarksTheEventFull(): void {
		$service = $this->service(registrations: [$this->confirmed(id: 'r-1', email: 'a@example.nl')]);

		$outcome = $service->register(event: $this->event(), person: ['name' => 'Bea', 'email' => 'b@example.nl']);

		$this->assertSame(EventRegistrationOutcome::REGISTERED, $outcome->status);
		$this->assertSame('full', $this->event()['signupStatus']);
	}//end testTakingTheLastPlaceMarksTheEventFull()

	/**
	 * The same address twice gives one registration, whatever its case.
	 *
	 * @return void
	 */
	public function testTheSameAddressTwiceGivesOneRegistration(): void {
		$service = $this->service(registrations: [$this->confirmed(id: 'r-1', email: 'anna@example.nl')]);

		$outcome = $service->register(event: $this->event(), person: ['name' => 'Anna', 'email' => 'ANNA@example.nl']);

		$this->assertSame(EventRegistrationOutcome::DUPLICATE, $outcome->status);
		$this->assertSame('r-1', $outcome->registration['uuid']);
		$this->assertCount(1, $this->store->rows['eventRegistration']);
	}//end testTheSameAddressTwiceGivesOneRegistration()

	/**
	 * A cancelled registration does not block registering again.
	 *
	 * @return void
	 */
	public function testACancelledAddressMayRegisterAgain(): void {
		$cancelled = array_merge($this->confirmed(id: 'r-1', email: 'anna@example.nl'), ['status' => 'cancelled']);
		$service = $this->service(registrations: [$cancelled]);

		$outcome = $service->register(event: $this->event(), person: ['name' => 'Anna', 'email' => 'anna@example.nl']);

		$this->assertSame(EventRegistrationOutcome::REGISTERED, $outcome->status);
		$this->assertCount(2, $this->store->rows['eventRegistration']);
	}//end testACancelledAddressMayRegisterAgain()

	/**
	 * After the closing time, or for an event not taking registrations, nothing is written.
	 *
	 * @return void
	 */
	public function testAClosedEventWritesNothing(): void {
		foreach ([['signupClosesAt' => '2026-11-01T09:59:59+00:00'], ['signupStatus' => 'closed'], ['signupStatus' => 'draft']] as $fields) {
			$service = $this->service(event: $fields);

			$outcome = $service->register(event: $this->event(), person: ['name' => 'Anna', 'email' => 'anna@example.nl']);

			$this->assertSame(EventRegistrationOutcome::CLOSED, $outcome->status);
			$this->assertNull($outcome->registration);
			$this->assertSame([], ($this->store->rows['eventRegistration'] ?? []));
		}
	}//end testAClosedEventWritesNothing()

	/**
	 * An event whose closing time is still ahead takes registrations.
	 *
	 * @return void
	 */
	public function testAnEventBeforeItsClosingTimeTakesRegistrations(): void {
		$service = $this->service(event: ['signupClosesAt' => '2026-11-01T10:00:01+00:00', 'signupStatus' => 'full']);

		$this->assertTrue($service->isTaking(event: $this->event()));
	}//end testAnEventBeforeItsClosingTimeTakesRegistrations()

	/**
	 * Two sign-ups that both saw the last place: the later one is moved to the waiting list.
	 *
	 * @return void
	 */
	public function testTwoWritesForTheLastPlaceLeaveOneRegistered(): void {
		$store = new class (
			[
				'campaignEvent' => [['uuid' => 'ev-1', 'campaign' => 'camp-1', 'signupStatus' => 'open', 'capacity' => 1]],
				'eventRegistration' => [],
			]
		) extends InMemoryListObjectStore {
			/**
			 * Whether the competing write has happened.
			 *
			 * @var bool
			 */
			public bool $raced = false;

			/**
			 * Save, first slipping in a competing confirmed registration from the same second.
			 *
			 * @param string               $schemaSlug The schema.
			 * @param array<string, mixed> $payload    The row.
			 * @param string|null          $id         The id when updating.
			 *
			 * @return array<string, mixed>|null
			 */
			public function save(string $schemaSlug, array $payload, ?string $id = null): ?array {
				if ($this->raced === false && $schemaSlug === 'eventRegistration' && $id === null) {
					$this->raced = true;
					parent::save(
						schemaSlug: $schemaSlug,
						payload: ['event' => 'ev-1', 'email' => 'first@example.nl', 'status' => 'confirmed', 'registeredAt' => '2026-11-01T10:00:00+00:00'],
						id: 'a-first'
					);
				}

				return parent::save(schemaSlug: $schemaSlug, payload: $payload, id: $id);
			}
		};
		$service = $this->serviceOver(store: $store);

		$outcome = $service->register(event: $store->rows['campaignEvent']['ev-1'], person: ['name' => 'Late', 'email' => 'late@example.nl']);

		$this->assertSame(EventRegistrationOutcome::WAITLISTED, $outcome->status);
		$this->assertSame('confirmed', $store->rows['eventRegistration']['a-first']['status']);
		$this->assertSame('waitlist', $store->rows['eventRegistration'][$outcome->registration['uuid']]['status']);
		$this->assertSame(1, $outcome->registration['waitlistPosition']);
	}//end testTwoWritesForTheLastPlaceLeaveOneRegistered()

	/**
	 * A cancellation frees the place for the first person waiting and renumbers the list.
	 *
	 * @return void
	 */
	public function testACancellationMovesTheFirstWaitingPersonUp(): void {
		$service = $this->service(
			event: ['signupStatus' => 'full'],
			registrations: [
				$this->confirmed(id: 'r-1', email: 'a@example.nl'),
				$this->confirmed(id: 'r-2', email: 'b@example.nl'),
				['uuid' => 'w-2', 'event' => 'ev-1', 'email' => 'd@example.nl', 'status' => 'waitlist', 'waitlistPosition' => 2, 'registeredAt' => '2026-10-03T09:00:00+00:00'],
				['uuid' => 'w-1', 'event' => 'ev-1', 'email' => 'c@example.nl', 'status' => 'waitlist', 'waitlistPosition' => 1, 'registeredAt' => '2026-10-02T09:00:00+00:00'],
			]
		);

		$outcome = $service->cancel(registrationId: 'r-1');

		$rows = $this->store->rows['eventRegistration'];
		$this->assertSame(EventRegistrationOutcome::CANCELLED, $outcome->status);
		$this->assertSame('cancelled', $rows['r-1']['status']);
		$this->assertSame('2026-11-01T10:00:00+00:00', $rows['r-1']['cancelledAt']);
		$this->assertSame('w-1', $outcome->promoted['uuid']);
		$this->assertSame('confirmed', $rows['w-1']['status']);
		$this->assertArrayNotHasKey('waitlistPosition', $rows['w-1']);
		$this->assertSame(1, $rows['w-2']['waitlistPosition']);
		$this->assertSame('full', $this->event()['signupStatus']);
	}//end testACancellationMovesTheFirstWaitingPersonUp()

	/**
	 * Cancelling a place nobody waits for reopens a full event; cancelling twice changes nothing.
	 *
	 * @return void
	 */
	public function testACancellationWithoutWaitersReopensTheEvent(): void {
		$service = $this->service(
			event: ['signupStatus' => 'full'],
			registrations: [$this->confirmed(id: 'r-1', email: 'a@example.nl'), $this->confirmed(id: 'r-2', email: 'b@example.nl')]
		);

		$first = $service->cancel(registrationId: 'r-1');
		$writes = $this->store->writes['eventRegistration'];
		$second = $service->cancel(registrationId: 'r-1');

		$this->assertNull($first->promoted);
		$this->assertSame('open', $this->event()['signupStatus']);
		$this->assertSame(EventRegistrationOutcome::CANCELLED, $second->status);
		$this->assertSame($writes, $this->store->writes['eventRegistration']);
		$this->assertSame(EventRegistrationOutcome::NONE, $service->cancel(registrationId: 'missing')->status);
	}//end testACancellationWithoutWaitersReopensTheEvent()

	/**
	 * Attending adds one `attend` touchpoint for the campaign, contact and lead, however often it is marked.
	 *
	 * @return void
	 */
	public function testAttendingCountsOnceAsATouchpoint(): void {
		$registration = array_merge($this->confirmed(id: 'r-1', email: 'a@example.nl'), ['contact' => 'c-1', 'lead' => 'l-1']);
		$service = $this->service(registrations: [$registration]);

		$saved = $service->markAttendance(registrationId: 'r-1', attended: true);
		$service->markAttendance(registrationId: 'r-1', attended: true);

		$this->assertSame('attended', $saved['attendance']);
		$this->assertSame('2026-11-01T10:00:00+00:00', $saved['checkedInAt']);
		$touchpoints = array_values($this->store->rows['touchpoint']);
		$this->assertCount(1, $touchpoints);
		$this->assertSame('attend', $touchpoints[0]['kind']);
		$this->assertSame('camp-1', $touchpoints[0]['campaignId']);
		$this->assertSame('c-1', $touchpoints[0]['contactId']);
		$this->assertSame('l-1', $touchpoints[0]['leadId']);
		$this->assertSame('attend:r-1', $touchpoints[0]['nonce']);
	}//end testAttendingCountsOnceAsATouchpoint()

	/**
	 * A no-show is recorded without a touchpoint; an unknown registration is null.
	 *
	 * @return void
	 */
	public function testANoShowAddsNoTouchpoint(): void {
		$service = $this->service(registrations: [$this->confirmed(id: 'r-1', email: 'a@example.nl')]);

		$saved = $service->markAttendance(registrationId: 'r-1', attended: false);

		$this->assertSame('no-show', $saved['attendance']);
		$this->assertArrayNotHasKey('checkedInAt', $saved);
		$this->assertArrayNotHasKey('touchpoint', $this->store->rows);
		$this->assertNull($service->markAttendance(registrationId: 'missing', attended: true));
	}//end testANoShowAddsNoTouchpoint()

	/**
	 * The campaign's event taking registrations wins, then the latest that is not a draft.
	 *
	 * @return void
	 */
	public function testTheEventForACampaign(): void {
		$this->store = new InMemoryListObjectStore(
			[
				'campaignEvent' => [
					['uuid' => 'draft', 'campaign' => 'camp-1', 'signupStatus' => 'draft', 'startAt' => '2026-12-01T10:00:00+00:00'],
					['uuid' => 'closed', 'campaign' => 'camp-1', 'signupStatus' => 'closed', 'startAt' => '2026-11-20T10:00:00+00:00'],
					['uuid' => 'open-late', 'campaign' => 'camp-1', 'signupStatus' => 'open', 'startAt' => '2026-11-30T10:00:00+00:00'],
					['uuid' => 'open-soon', 'campaign' => 'camp-1', 'signupStatus' => 'full', 'startAt' => '2026-11-10T10:00:00+00:00'],
					['uuid' => 'other', 'campaign' => 'camp-2', 'signupStatus' => 'draft'],
				],
			]
		);
		$service = $this->serviceOver(store: $this->store);

		$this->assertSame('open-soon', $service->eventForCampaign(campaignId: 'camp-1')['uuid']);
		$this->assertNull($service->eventForCampaign(campaignId: 'camp-2'));
		$this->assertNull($service->eventForCampaign(campaignId: ''));

		unset($this->store->rows['campaignEvent']['open-late'], $this->store->rows['campaignEvent']['open-soon']);
		$this->assertSame('closed', $service->eventForCampaign(campaignId: 'camp-1')['uuid']);
	}//end testTheEventForACampaign()

	/**
	 * Only events that are neither draft nor cancelled and start inside the window are due.
	 *
	 * @return void
	 */
	public function testEventsStartingInAWindow(): void {
		$this->store = new InMemoryListObjectStore(
			[
				'campaignEvent' => [
					['uuid' => 'in', 'signupStatus' => 'open', 'startAt' => '2026-11-02T09:30:00+00:00'],
					['uuid' => 'edge', 'signupStatus' => 'closed', 'startAt' => '2026-11-02T09:00:00+00:00'],
					['uuid' => 'after', 'signupStatus' => 'open', 'startAt' => '2026-11-02T10:00:00+00:00'],
					['uuid' => 'cancelled', 'signupStatus' => 'cancelled', 'startAt' => '2026-11-02T09:30:00+00:00'],
					['uuid' => 'no-start', 'signupStatus' => 'open'],
				],
			]
		);
		$service = $this->serviceOver(store: $this->store);

		$due = $service->eventsStartingBetween(from: (self::NOW + (23 * 3600)), to: (self::NOW + (24 * 3600)));

		$this->assertSame(['in', 'edge'], array_column($due, 'uuid'));
	}//end testEventsStartingInAWindow()

	/**
	 * Stamping writes the time on the registration; a row without an id is left alone.
	 *
	 * @return void
	 */
	public function testStampWritesTheTime(): void {
		$service = $this->service(registrations: [$this->confirmed(id: 'r-1', email: 'a@example.nl')]);

		$service->stamp(registration: $this->store->rows['eventRegistration']['r-1'], field: 'reminderSentAt');
		$service->stamp(registration: ['email' => 'x@example.nl'], field: 'reminderSentAt');

		$this->assertSame('2026-11-01T10:00:00+00:00', $this->store->rows['eventRegistration']['r-1']['reminderSentAt']);
		$this->assertCount(1, $this->store->rows['eventRegistration']);
	}//end testStampWritesTheTime()
}//end class
