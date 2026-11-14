<?php

/**
 * Edge cases of EventRegistrationService: a refused write, blank addresses,
 * unlimited events, a waiter too large for the freed place, an event without
 * a campaign, and an unreadable closing time.
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
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\EventRegistrationOutcome;
use OCA\Pipelinq\Service\Marketing\EventRegistrationService;
use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the EventRegistrationService branches the main test does not reach.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\EventRegistrationService
 * @uses   \OCA\Pipelinq\Service\Marketing\EventRegistrationOutcome
 * @uses   \OCA\Pipelinq\Service\TouchpointService
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md
 */
class EventRegistrationServiceEdgesTest extends TestCase {

	/**
	 * Now: 2026-11-01T10:00:00Z.
	 */
	private const NOW = 1793527200;

	/**
	 * The store.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * A store that refuses every registration write answers NONE and leaves the event alone.
	 *
	 * @return void
	 */
	public function testARefusedWriteRegistersNobody(): void {
		$this->store = new class (['campaignEvent' => [['uuid' => 'ev-1', 'signupStatus' => 'open', 'capacity' => 1]]]) extends InMemoryListObjectStore {
			/**
			 * Refuse registrations; keep everything else.
			 *
			 * @param string               $schemaSlug The schema.
			 * @param array<string, mixed> $payload    The row.
			 * @param string|null          $id         The id when updating.
			 *
			 * @return array<string, mixed>|null
			 */
			public function save(string $schemaSlug, array $payload, ?string $id = null): ?array {
				if ($schemaSlug === 'eventRegistration') {
					return null;
				}

				return parent::save($schemaSlug, $payload, $id);
			}//end save()
		};

		$outcome = $this->service()->register(event: $this->store->rows['campaignEvent']['ev-1'], person: ['email' => 'a@example.nl']);

		$this->assertSame(EventRegistrationOutcome::NONE, $outcome->status);
		$this->assertNull($outcome->registration);
		$this->assertSame('open', $this->store->rows['campaignEvent']['ev-1']['signupStatus']);
		$this->assertArrayNotHasKey('campaignEvent', $this->store->writes);
	}//end testARefusedWriteRegistersNobody()

	/**
	 * A nameless person is named by their address; optional fields are
	 * trimmed and copied only when present; party size is at least one.
	 *
	 * @return void
	 */
	public function testANamelessPersonIsNamedByTheirAddress(): void {
		$this->seed(event: ['capacity' => 0]);

		$outcome = $this->service()->register(
			event: $this->store->rows['campaignEvent']['ev-1'],
			person: ['email' => ' A@Example.NL ', 'contact' => ' c-1 ', 'lead' => '', 'question' => 'Parking?', 'partySize' => 0, 'source' => 'import']
		);

		$this->assertSame(EventRegistrationOutcome::REGISTERED, $outcome->status);
		$row = $outcome->registration;
		$this->assertSame('a@example.nl', $row['name']);
		$this->assertSame('a@example.nl', $row['email']);
		$this->assertSame('c-1', $row['contact']);
		$this->assertSame('Parking?', $row['question']);
		$this->assertArrayNotHasKey('lead', $row);
		$this->assertArrayNotHasKey('address', $row);
		$this->assertSame(1, $row['partySize']);
		$this->assertSame('import', $row['source']);
		$this->assertSame('2026-11-01T10:00:00+00:00', $row['registeredAt']);
	}//end testANamelessPersonIsNamedByTheirAddress()

	/**
	 * Without an address there is nothing to recognise a repeat by, and an
	 * unlimited event never changes its own signup status.
	 *
	 * @return void
	 */
	public function testBlankAddressesAreNeverDuplicatesAndAnUnlimitedEventStaysOpen(): void {
		$this->seed(event: ['capacity' => 0]);
		$service = $this->service();
		$event = $this->store->rows['campaignEvent']['ev-1'];

		$this->assertSame(EventRegistrationOutcome::REGISTERED, $service->register(event: $event, person: ['name' => 'One'])->status);
		$this->assertSame(EventRegistrationOutcome::REGISTERED, $service->register(event: $event, person: ['name' => 'Two'])->status);

		$this->assertCount(2, $this->store->rows['eventRegistration']);
		$this->assertArrayNotHasKey('campaignEvent', $this->store->writes);
	}//end testBlankAddressesAreNeverDuplicatesAndAnUnlimitedEventStaysOpen()

	/**
	 * A freed place goes to the first waiter who fits in it; the larger
	 * party ahead keeps waiting at the head of the list.
	 *
	 * @return void
	 */
	public function testAFreedPlaceGoesToTheFirstWaiterWhoFits(): void {
		$this->seed(
			event: ['capacity' => 2, 'signupStatus' => 'full'],
			registrations: [
				['uuid' => 'r-1', 'event' => 'ev-1', 'email' => 'a@x.nl', 'status' => 'confirmed', 'registeredAt' => '2026-10-01T09:00:00+00:00'],
				['uuid' => 'r-2', 'event' => 'ev-1', 'email' => 'b@x.nl', 'status' => 'confirmed', 'registeredAt' => '2026-10-01T09:01:00+00:00'],
				['uuid' => 'r-3', 'event' => 'ev-1', 'email' => 'c@x.nl', 'status' => 'waitlist', 'waitlistPosition' => 1, 'partySize' => 3, 'registeredAt' => '2026-10-02T09:00:00+00:00'],
				['uuid' => 'r-4', 'event' => 'ev-1', 'email' => 'd@x.nl', 'status' => 'waitlist', 'waitlistPosition' => 2, 'registeredAt' => '2026-10-03T09:00:00+00:00'],
			]
		);

		$outcome = $this->service()->cancel(registrationId: 'r-1');

		$this->assertSame(EventRegistrationOutcome::CANCELLED, $outcome->status);
		$this->assertSame('r-4', $outcome->promoted['uuid']);
		$this->assertSame('confirmed', $this->store->rows['eventRegistration']['r-4']['status']);
		$this->assertArrayNotHasKey('waitlistPosition', $this->store->rows['eventRegistration']['r-4']);
		$this->assertSame('waitlist', $this->store->rows['eventRegistration']['r-3']['status']);
		$this->assertSame(1, $this->store->rows['eventRegistration']['r-3']['waitlistPosition']);
		$this->assertSame('full', $this->store->rows['campaignEvent']['ev-1']['signupStatus']);
	}//end testAFreedPlaceGoesToTheFirstWaiterWhoFits()

	/**
	 * Attending an event that belongs to no campaign records attendance but no touchpoint.
	 *
	 * @return void
	 */
	public function testAttendingAnEventWithoutACampaignAddsNoTouchpoint(): void {
		$this->seed(
			event: ['campaign' => '  '],
			registrations: [['uuid' => 'r-1', 'event' => 'ev-1', 'email' => 'a@x.nl', 'status' => 'confirmed', 'checkedInAt' => '2026-11-01T09:30:00+00:00']]
		);

		$saved = $this->service()->markAttendance(registrationId: 'r-1', attended: true);

		$this->assertSame('attended', $saved['attendance']);
		$this->assertSame('2026-11-01T09:30:00+00:00', $saved['checkedInAt']);
		$this->assertArrayNotHasKey('touchpoint', $this->store->rows);
	}//end testAttendingAnEventWithoutACampaignAddsNoTouchpoint()

	/**
	 * An unreadable closing time does not close signup; a past one does.
	 *
	 * @return void
	 */
	public function testAnUnreadableClosingTimeDoesNotCloseSignup(): void {
		$this->seed();
		$service = $this->service();

		$this->assertTrue($service->isTaking(event: ['signupStatus' => 'open', 'signupClosesAt' => 'whenever']));
		$this->assertTrue($service->isTaking(event: ['signupStatus' => 'full', 'signupClosesAt' => '  ']));
		$this->assertFalse($service->isTaking(event: ['signupStatus' => 'open', 'signupClosesAt' => '2026-10-31T23:59:59+00:00']));
		$this->assertFalse($service->isTaking(event: ['signupStatus' => 'draft']));
	}//end testAnUnreadableClosingTimeDoesNotCloseSignup()

	/**
	 * Seed one campaign event and its registrations.
	 *
	 * @param array<string, mixed>             $event         Overrides on the event.
	 * @param array<int, array<string, mixed>> $registrations The registrations.
	 *
	 * @return void
	 */
	private function seed(array $event = [], array $registrations = []): void {
		$this->store = new InMemoryListObjectStore(
			[
				'campaignEvent' => [
					array_merge(['uuid' => 'ev-1', 'title' => 'Webinar', 'campaign' => 'camp-1', 'signupStatus' => 'open', 'capacity' => 2], $event),
				],
				'eventRegistration' => $registrations,
			]
		);
	}//end seed()

	/**
	 * The service over the current store at a fixed time.
	 *
	 * @return EventRegistrationService
	 */
	private function service(): EventRegistrationService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new EventRegistrationService($this->store, new TouchpointService($this->store), $time);
	}//end service()
}//end class
