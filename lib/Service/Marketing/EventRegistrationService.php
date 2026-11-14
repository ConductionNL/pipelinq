<?php

/**
 * Pipelinq EventRegistrationService.
 *
 * Takes the registrations for a campaign event and keeps its places honest: a
 * sign-up gets a place while one is free and goes on the waiting list when none
 * is, the same person never takes a second place, a closed event takes nobody,
 * and a cancellation hands its place to the first person waiting. It also marks
 * who came, which counts as an `attend` touchpoint of the campaign.
 *
 * Places are counted at write time and counted again after the write, so of two
 * sign-ups that both saw the last free place the later one (by `registeredAt`,
 * then id) moves to the waiting list before anybody is mailed.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Kernel\Contracts\EventRegistrationServiceInterface;
use OCA\Pipelinq\Service\TouchpointService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Registrations for a campaign event: register, cancel, mark attendance.
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
 */
class EventRegistrationService implements EventRegistrationServiceInterface {

	/**
	 * App-config key and default slug of the event schema.
	 */
	private const EVENT_KEY = 'campaignEvent_schema';

	/**
	 * App-config key and default slug of the registration schema.
	 */
	private const REGISTRATION_KEY = 'eventRegistration_schema';

	/**
	 * Event statuses under which registrations are taken.
	 *
	 * @var array<int, string>
	 */
	private const TAKING = ['open', 'full'];

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore   $store       The object store.
	 * @param TouchpointService $touchpoints Where attendance is counted.
	 * @param ITimeFactory      $time        The clock.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
	 */
	public function __construct(
		private ListObjectStore $store,
		private TouchpointService $touchpoints,
		private ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The event a sign-up on this campaign's landing page registers for.
	 *
	 * An event taking registrations wins, the one starting first; otherwise the
	 * campaign's latest event that is not a draft, so a late sign-up can be told
	 * registration has closed. Null when the campaign has no such event.
	 *
	 * @param string $campaignId The campaign id.
	 *
	 * @return array<string, mixed>|null The event.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
	 */
	public function eventForCampaign(string $campaignId): ?array {
		if (trim($campaignId) === '') {
			return null;
		}

		$events = $this->store->findAll(schemaSlug: $this->eventSlug(), filters: ['campaign' => $campaignId]);
		$taking = array_values(
			array_filter($events, static fn (array $event): bool => in_array((string)($event['signupStatus'] ?? ''), self::TAKING, true))
		);
		if ($taking !== []) {
			usort($taking, static fn (array $a, array $b): int => strcmp((string)($a['startAt'] ?? ''), (string)($b['startAt'] ?? '')));
			return $taking[0];
		}

		$others = array_values(
			array_filter($events, static fn (array $event): bool => (string)($event['signupStatus'] ?? 'draft') !== 'draft')
		);
		if ($others === []) {
			return null;
		}

		usort($others, static fn (array $a, array $b): int => strcmp((string)($b['startAt'] ?? ''), (string)($a['startAt'] ?? '')));
		return $others[0];
	}//end eventForCampaign()

	/**
	 * The events, other than drafts and cancelled ones, that start inside a window.
	 *
	 * @param int $from Unix time the window opens (inclusive).
	 * @param int $to   Unix time the window closes (exclusive).
	 *
	 * @return array<int, array<string, mixed>> The events.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function eventsStartingBetween(int $from, int $to): array {
		$out = [];
		foreach ($this->store->findAll(schemaSlug: $this->eventSlug()) as $event) {
			if (in_array((string)($event['signupStatus'] ?? 'draft'), ['draft', 'cancelled'], true) === true) {
				continue;
			}

			$start = $this->timestamp(value: ($event['startAt'] ?? null));
			if ($start !== null && $start >= $from && $start < $to) {
				$out[] = $event;
			}
		}

		return $out;
	}//end eventsStartingBetween()

	/**
	 * Read one event.
	 *
	 * @param string $eventId The event id.
	 *
	 * @return array<string, mixed>|null The event.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function event(string $eventId): ?array {
		return $this->store->find(schemaSlug: $this->eventSlug(), id: $eventId);
	}//end event()

	/**
	 * Read one registration.
	 *
	 * @param string $registrationId The registration id.
	 *
	 * @return array<string, mixed>|null The registration.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function registration(string $registrationId): ?array {
		return $this->store->find(schemaSlug: $this->registrationSlug(), id: $registrationId);
	}//end registration()

	/**
	 * Whether an event takes registrations now.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return bool True while its status takes registrations and its closing time has not passed.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
	 */
	public function isTaking(array $event): bool {
		if (in_array((string)($event['signupStatus'] ?? ''), self::TAKING, true) === false) {
			return false;
		}

		$closes = $this->timestamp(value: ($event['signupClosesAt'] ?? null));
		return $closes === null || $closes > $this->time->getTime();
	}//end isTaking()

	/**
	 * Register a person for an event.
	 *
	 * @param array<string, mixed> $event  The event.
	 * @param array<string, mixed> $person `name`, `email`, and optionally `contact`, `lead`,
	 *                                     `source`, `partySize`, `address`, `question`.
	 *
	 * @return EventRegistrationOutcome What happened, and the registration as it stands.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
	 */
	public function register(array $event, array $person): EventRegistrationOutcome {
		$eventId = $this->store->idOf(payload: $event);
		$email = strtolower(trim((string)($person['email'] ?? '')));
		if ($eventId === '' || $this->isTaking(event: $event) === false) {
			return new EventRegistrationOutcome(status: EventRegistrationOutcome::CLOSED);
		}

		$existing = $this->activeRegistrationFor(eventId: $eventId, email: $email);
		if ($existing !== null) {
			return new EventRegistrationOutcome(status: EventRegistrationOutcome::DUPLICATE, registration: $existing);
		}

		$size = $this->sizeOf(registration: $person);
		$capacity = (int)($event['capacity'] ?? 0);
		$hasPlace = $capacity <= 0 || ($this->placesTaken(eventId: $eventId) + $size) <= $capacity;

		$row = $this->newRegistration(eventId: $eventId, email: $email, person: $person, size: $size);
		$row['status'] = 'confirmed';
		if ($hasPlace === false) {
			$row = $this->waitlisted(row: $row, eventId: $eventId);
		}

		$saved = $this->store->save(schemaSlug: $this->registrationSlug(), payload: $row);
		if ($saved === null) {
			return new EventRegistrationOutcome(status: EventRegistrationOutcome::NONE);
		}

		if ($hasPlace === true && $capacity > 0) {
			$saved = $this->recount(event: $event, saved: $saved);
		}

		$this->syncFull(event: $event);

		if ((string)($saved['status'] ?? '') === 'confirmed') {
			return new EventRegistrationOutcome(status: EventRegistrationOutcome::REGISTERED, registration: $saved);
		}

		return new EventRegistrationOutcome(status: EventRegistrationOutcome::WAITLISTED, registration: $saved);
	}//end register()

	/**
	 * Cancel a registration; a freed place goes to the first person waiting who fits.
	 *
	 * @param string $registrationId The registration id.
	 *
	 * @return EventRegistrationOutcome The cancelled registration and whoever moved up.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function cancel(string $registrationId): EventRegistrationOutcome {
		$registration = $this->registration(registrationId: $registrationId);
		if ($registration === null) {
			return new EventRegistrationOutcome(status: EventRegistrationOutcome::NONE);
		}

		$wasStatus = (string)($registration['status'] ?? '');
		if ($wasStatus === 'cancelled') {
			return new EventRegistrationOutcome(status: EventRegistrationOutcome::CANCELLED, registration: $registration);
		}

		$registration['status'] = 'cancelled';
		$registration['cancelledAt'] = $this->now();
		unset($registration['waitlistPosition']);
		$cancelled = ($this->store->save(schemaSlug: $this->registrationSlug(), payload: $registration, id: $registrationId) ?? $registration);

		$eventId = (string)($registration['event'] ?? '');
		$event = $this->event(eventId: $eventId);
		$promoted = null;
		if ($event !== null && $wasStatus === 'confirmed') {
			$promoted = $this->promote(event: $event);
		}

		if ($event !== null) {
			$this->renumberWaitlist(eventId: $eventId);
			$this->syncFull(event: $event);
		}

		return new EventRegistrationOutcome(
			status: EventRegistrationOutcome::CANCELLED,
			registration: $cancelled,
			promoted: $promoted
		);
	}//end cancel()

	/**
	 * Mark whether a person came; coming adds one `attend` touchpoint to the campaign.
	 *
	 * @param string $registrationId The registration id.
	 * @param bool   $attended       True for attended, false for no-show.
	 *
	 * @return array<string, mixed>|null The registration, or null when it does not exist.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-the-team-keeps-the-attendee-list-and-who-came-req-mev-004
	 */
	public function markAttendance(string $registrationId, bool $attended): ?array {
		$registration = $this->registration(registrationId: $registrationId);
		if ($registration === null) {
			return null;
		}

		$wasAttended = (string)($registration['attendance'] ?? '') === 'attended';
		$registration['attendance'] = 'no-show';
		if ($attended === true) {
			$registration['attendance'] = 'attended';
			if (trim((string)($registration['checkedInAt'] ?? '')) === '') {
				$registration['checkedInAt'] = $this->now();
			}
		}

		$saved = ($this->store->save(schemaSlug: $this->registrationSlug(), payload: $registration, id: $registrationId) ?? $registration);

		if ($attended === true && $wasAttended === false) {
			$this->appendAttendTouchpoint(registrationId: $registrationId, registration: $registration);
		}

		return $saved;
	}//end markAttendance()

	/**
	 * Stamp a time field on a registration (confirmation or reminder sent).
	 *
	 * @param array<string, mixed> $registration The registration.
	 * @param string               $field        `confirmationSentAt` or `reminderSentAt`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function stamp(array $registration, string $field): void {
		$id = $this->store->idOf(payload: $registration);
		if ($id === '') {
			return;
		}

		$registration[$field] = $this->now();
		$this->store->save(schemaSlug: $this->registrationSlug(), payload: $registration, id: $id);
	}//end stamp()

	/**
	 * The registrations of an event with a given status.
	 *
	 * @param string $eventId The event id.
	 * @param string $status  `confirmed`, `waitlist` or `cancelled`.
	 *
	 * @return array<int, array<string, mixed>> The registrations, in the order they came in.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-a-landing-page-sign-up-registers-for-the-event-req-mev-002
	 */
	public function registrationsWithStatus(string $eventId, string $status): array {
		$rows = $this->store->findAll(
			schemaSlug: $this->registrationSlug(),
			filters: ['event' => $eventId, 'status' => $status]
		);
		usort($rows, fn (array $a, array $b): int => $this->arrivalOrder(a: $a, b: $b));
		return $rows;
	}//end registrationsWithStatus()

	/**
	 * The registration of this email address that still holds or waits for a place.
	 *
	 * @param string $eventId The event id.
	 * @param string $email   The lower-cased address.
	 *
	 * @return array<string, mixed>|null The registration.
	 */
	private function activeRegistrationFor(string $eventId, string $email): ?array {
		if ($email === '') {
			return null;
		}

		foreach ($this->store->findAll(schemaSlug: $this->registrationSlug(), filters: ['event' => $eventId]) as $row) {
			$sameAddress = strtolower(trim((string)($row['email'] ?? ''))) === $email;
			if ($sameAddress === true && (string)($row['status'] ?? '') !== 'cancelled') {
				return $row;
			}
		}

		return null;
	}//end activeRegistrationFor()

	/**
	 * The registration row to write.
	 *
	 * @param string               $eventId The event id.
	 * @param string               $email   The lower-cased address.
	 * @param array<string, mixed> $person  The person.
	 * @param int                  $size    How many people come.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function newRegistration(string $eventId, string $email, array $person, int $size): array {
		$row = [
			'event' => $eventId,
			'name' => trim((string)($person['name'] ?? '')),
			'email' => $email,
			'partySize' => $size,
			'source' => (string)($person['source'] ?? 'web-form'),
			'registeredAt' => $this->now(),
		];
		foreach (['contact', 'lead', 'address', 'question'] as $field) {
			$value = trim((string)($person[$field] ?? ''));
			if ($value !== '') {
				$row[$field] = $value;
			}
		}

		if ($row['name'] === '') {
			$row['name'] = $email;
		}

		return $row;
	}//end newRegistration()

	/**
	 * Put a row on the end of the waiting list.
	 *
	 * @param array<string, mixed> $row     The registration.
	 * @param string               $eventId The event id.
	 *
	 * @return array<string, mixed> The row, waitlisted.
	 */
	private function waitlisted(array $row, string $eventId): array {
		$row['status'] = 'waitlist';
		$row['waitlistPosition'] = (count($this->registrationsWithStatus(eventId: $eventId, status: 'waitlist')) + 1);
		return $row;
	}//end waitlisted()

	/**
	 * Count again after writing; past the capacity, the saved registration moves to the waiting list.
	 *
	 * @param array<string, mixed> $event The event.
	 * @param array<string, mixed> $saved The registration just written with a place.
	 *
	 * @return array<string, mixed> The registration as it stands.
	 */
	private function recount(array $event, array $saved): array {
		$eventId = $this->store->idOf(payload: $event);
		$savedId = $this->store->idOf(payload: $saved);
		$capacity = (int)($event['capacity'] ?? 0);

		$taken = 0;
		foreach ($this->registrationsWithStatus(eventId: $eventId, status: 'confirmed') as $row) {
			$taken += $this->sizeOf(registration: $row);
			if ($this->store->idOf(payload: $row) !== $savedId) {
				continue;
			}

			if ($taken <= $capacity) {
				return $saved;
			}

			$moved = $this->waitlisted(row: $saved, eventId: $eventId);
			return ($this->store->save(schemaSlug: $this->registrationSlug(), payload: $moved, id: $savedId) ?? $moved);
		}

		return $saved;
	}//end recount()

	/**
	 * Give a freed place to the first person waiting whose party fits.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return array<string, mixed>|null The registration that moved up.
	 */
	private function promote(array $event): ?array {
		$eventId = $this->store->idOf(payload: $event);
		$capacity = (int)($event['capacity'] ?? 0);
		$free = PHP_INT_MAX;
		if ($capacity > 0) {
			$free = ($capacity - $this->placesTaken(eventId: $eventId));
		}

		foreach ($this->registrationsWithStatus(eventId: $eventId, status: 'waitlist') as $row) {
			if ($this->sizeOf(registration: $row) > $free) {
				continue;
			}

			$row['status'] = 'confirmed';
			unset($row['waitlistPosition']);
			$id = $this->store->idOf(payload: $row);
			return ($this->store->save(schemaSlug: $this->registrationSlug(), payload: $row, id: $id) ?? $row);
		}

		return null;
	}//end promote()

	/**
	 * Number the waiting list 1, 2, 3 in arrival order.
	 *
	 * @param string $eventId The event id.
	 *
	 * @return void
	 */
	private function renumberWaitlist(string $eventId): void {
		$position = 0;
		foreach ($this->registrationsWithStatus(eventId: $eventId, status: 'waitlist') as $row) {
			$position++;
			if ((int)($row['waitlistPosition'] ?? 0) === $position) {
				continue;
			}

			$row['waitlistPosition'] = $position;
			$this->store->save(schemaSlug: $this->registrationSlug(), payload: $row, id: $this->store->idOf(payload: $row));
		}
	}//end renumberWaitlist()

	/**
	 * Keep the event's `full` status in step with its places.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return void
	 */
	private function syncFull(array $event): void {
		$status = (string)($event['signupStatus'] ?? '');
		$capacity = (int)($event['capacity'] ?? 0);
		if ($capacity <= 0 || in_array($status, self::TAKING, true) === false) {
			return;
		}

		$eventId = $this->store->idOf(payload: $event);
		$wanted = 'open';
		if ($this->placesTaken(eventId: $eventId) >= $capacity) {
			$wanted = 'full';
		}

		if ($wanted === $status) {
			return;
		}

		$event['signupStatus'] = $wanted;
		$this->store->save(schemaSlug: $this->eventSlug(), payload: $event, id: $eventId);
	}//end syncFull()

	/**
	 * Append the `attend` touchpoint, once per registration.
	 *
	 * @param string               $registrationId The registration id.
	 * @param array<string, mixed> $registration   The registration.
	 *
	 * @return void
	 */
	private function appendAttendTouchpoint(string $registrationId, array $registration): void {
		$event = $this->event(eventId: (string)($registration['event'] ?? ''));
		$campaignId = trim((string)($event['campaign'] ?? ''));
		$nonce = 'attend:' . $registrationId;
		if ($campaignId === '' || $this->touchpoints->existsForNonce(nonce: $nonce) === true) {
			return;
		}

		$this->touchpoints->append(
			touchpoint: [
				'campaignId' => $campaignId,
				'contactId' => (string)($registration['contact'] ?? ''),
				'leadId' => (string)($registration['lead'] ?? ''),
				'kind' => 'attend',
				'channel' => 'event',
				'sourceRef' => (string)($registration['event'] ?? ''),
				'occurredAt' => (string)($registration['checkedInAt'] ?? $this->now()),
				'nonce' => $nonce,
			]
		);
	}//end appendAttendTouchpoint()

	/**
	 * Places taken by confirmed registrations.
	 *
	 * @param string $eventId The event id.
	 *
	 * @return int The places taken.
	 */
	private function placesTaken(string $eventId): int {
		$taken = 0;
		foreach ($this->registrationsWithStatus(eventId: $eventId, status: 'confirmed') as $row) {
			$taken += $this->sizeOf(registration: $row);
		}

		return $taken;
	}//end placesTaken()

	/**
	 * How many places one registration takes, at least one.
	 *
	 * @param array<string, mixed> $registration The registration or person.
	 *
	 * @return int The places.
	 */
	private function sizeOf(array $registration): int {
		return max(1, (int)($registration['partySize'] ?? 1));
	}//end sizeOf()

	/**
	 * Arrival order: `registeredAt`, then id.
	 *
	 * @param array<string, mixed> $a One registration.
	 * @param array<string, mixed> $b The other.
	 *
	 * @return int The comparison.
	 */
	private function arrivalOrder(array $a, array $b): int {
		$byTime = strcmp((string)($a['registeredAt'] ?? ''), (string)($b['registeredAt'] ?? ''));
		if ($byTime !== 0) {
			return $byTime;
		}

		return strcmp($this->store->idOf(payload: $a), $this->store->idOf(payload: $b));
	}//end arrivalOrder()

	/**
	 * A timestamp field as Unix time.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null The time, or null when empty or unreadable.
	 */
	private function timestamp(mixed $value): ?int {
		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		$time = strtotime($text);
		if ($time === false) {
			return null;
		}

		return $time;
	}//end timestamp()

	/**
	 * Now, ISO-8601 UTC.
	 *
	 * @return string The time.
	 */
	private function now(): string {
		return gmdate('Y-m-d\TH:i:sP', $this->time->getTime());
	}//end now()

	/**
	 * The event schema slug.
	 *
	 * @return string The slug.
	 */
	private function eventSlug(): string {
		return $this->store->schemaSlug(configKey: self::EVENT_KEY, default: 'campaignEvent');
	}//end eventSlug()

	/**
	 * The registration schema slug.
	 *
	 * @return string The slug.
	 */
	private function registrationSlug(): string {
		return $this->store->schemaSlug(configKey: self::REGISTRATION_KEY, default: 'eventRegistration');
	}//end registrationSlug()
}//end class
