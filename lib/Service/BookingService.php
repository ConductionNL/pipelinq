<?php

/**
 * Pipelinq BookingService.
 *
 * Booking lifecycle for the appointment-booking surface (member 04 of 12).
 * Owns create / confirm / reschedule / cancel / no-show / complete with a
 * validated state machine, statusHistory audit trail (ADR-005), cancellation
 * policy enforcement, and AvailabilityCache invalidation on every write.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md
 * @spec openspec/specs/appointment-booking/spec.md
 * @spec openspec/specs/appointment-booking/spec.md
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\WalkInQueueRebalanceJob;
use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * BookingService — booking lifecycle service.
 *
 * Composes member 02 (AvailabilityService) for slot lookups and validates a
 * strict status state machine. Payment fee charging (member 08) and email
 * dispatch (member 07) are seams: the service appends the intent to
 * `statusHistory` and invokes the optional seam when injected, never blocking
 * on its absence.
 *
 * Status state machine:
 *   pending-deposit -> confirmed
 *   pending-deposit -> cancelled-by-customer | cancelled-by-business
 *   confirmed       -> completed | no-show | cancelled-by-customer | cancelled-by-business | rescheduled
 *   rescheduled is a terminal state (the new booking lives in its own row).
 *   cancelled-*, completed, no-show are terminal.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Cohesive booking-lifecycle service; splitting would fragment one state machine.
 * @SuppressWarnings(PHPMD.TooManyMethods)           Cohesive booking-lifecycle service; splitting would fragment one state machine.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Cohesive booking-lifecycle service; splitting would fragment one state machine.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
class BookingService {
	/**
	 * App-config key for the Booking schema id/slug.
	 *
	 * @var string
	 */
	public const BOOKING_SCHEMA_KEY = 'booking_schema';

	/**
	 * Schema slug whose `x-openregister-lifecycle` declares the Booking status graph.
	 *
	 * @var string
	 */
	private const BOOKING_SCHEMA_SLUG = 'booking';

	/**
	 * App-config key for the Service schema id/slug.
	 *
	 * @var string
	 */
	public const SERVICE_SCHEMA_KEY = 'service_schema';

	/**
	 * App-config key for the customer schema id/slug.
	 *
	 * Customers are Nextcloud contacts but a denormalised mirror lives in the
	 * pipelinq register for query speed and to carry lifetime metrics (no-show
	 * count, lifetime value). Empty means the mirror lookup is skipped.
	 *
	 * @var string
	 */
	public const CUSTOMER_SCHEMA_KEY = 'contact_schema';

	/**
	 * Allowed status values (matches schema enum in 45-appointment-booking.json).
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		'pending-deposit',
		'confirmed',
		'completed',
		'no-show',
		'cancelled-by-customer',
		'cancelled-by-business',
		'rescheduled',
	];

	/**
	 * Booking sources mirrored from the Booking schema enum.
	 *
	 * @var array<int, string>
	 */
	public const SOURCES = ['portal', 'admin', 'phone', 'walk-in', 'import'];

	/**
	 * Sentinel used in `changedBy` when no user session is available (cron, CLI).
	 *
	 * @var string
	 */
	public const ACTOR_SYSTEM = 'system';

	/**
	 * Optional payment seam (member 08).
	 *
	 * Implementations expose `chargeNoShowFee(string $bookingId, float $amount): void`
	 * and `chargeCancellationFee(string $bookingId, float $amount): void`. When
	 * unset the booking still transitions; the fee is queued in statusHistory.
	 *
	 * @var object|null
	 */
	private ?object $paymentProvider = null;

	/**
	 * Optional email seam (member 07).
	 *
	 * Implementations expose `sendConfirmation(string $bookingId): void`.
	 *
	 * @var object|null
	 */
	private ?object $emailProvider = null;

	/**
	 * Optional calendar seam (member 10).
	 *
	 * Implementations expose `pushBookingEvent(string $bookingId): void`. Called
	 * after every transition into `confirmed` (create-as-confirmed, deposit
	 * cleared confirm, reschedule). When unset the calendar mirror is skipped
	 * silently — the booking still transitions and persists.
	 *
	 * @var object|null
	 */
	private ?object $calendarProvider = null;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param IUserSession $userSession The current user session (ADR-005).
	 * @param AvailabilityService $availabilityService Member 02 — invalidated on every write.
	 * @param EligibilityService $eligibilityService Member 03 — skill-eligible resource filter.
	 * @param LoggerInterface $logger The logger.
	 * @param IJobList $jobList The background-job list (member 09 rebalance is deferred to it).
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IUserSession $userSession,
		private AvailabilityService $availabilityService,
		private EligibilityService $eligibilityService,
		private LoggerInterface $logger,
		private IJobList $jobList,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Inject a payment provider seam (member 08).
	 *
	 * @param object|null $provider Provider exposing `chargeNoShowFee`/`chargeCancellationFee`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function setPaymentProvider(?object $provider): void {
		$this->paymentProvider = $provider;
	}//end setPaymentProvider()

	/**
	 * Inject an email provider seam (member 07).
	 *
	 * @param object|null $provider Provider exposing `sendConfirmation(string)`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function setEmailProvider(?object $provider): void {
		$this->emailProvider = $provider;
	}//end setEmailProvider()

	/**
	 * Inject a calendar provider seam (member 10).
	 *
	 * @param object|null $provider Provider exposing `pushBookingEvent(string)`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function setCalendarProvider(?object $provider): void {
		$this->calendarProvider = $provider;
	}//end setCalendarProvider()

	/**
	 * Create a new Booking.
	 *
	 * Validates the customer + service references, normalises the source enum,
	 * snapshots the deposit amount from the Service, picks the initial status
	 * (pending-deposit when the Service requires a deposit, otherwise confirmed)
	 * and appends the first statusHistory entry. The new Booking UUID is
	 * returned to the caller (portal controller or admin UI).
	 *
	 * @param array<string, mixed> $data Booking payload (customerId, serviceId, startAt, endAt, resourceAssignments, notes).
	 * @param string $source One of {@see self::SOURCES}.
	 *
	 * @return string The new Booking UUID.
	 *
	 * @throws InvalidArgumentException If validation fails.
	 * @throws RuntimeException If OpenRegister is unavailable.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential validation guards; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Sequential validation guards; extraction adds no clarity.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function createBooking(array $data, string $source): string {
		$customerId = trim((string)($data['customerId'] ?? ''));
		$serviceId = trim((string)($data['serviceId'] ?? ''));
		$startAt = trim((string)($data['startAt'] ?? ''));
		$endAt = trim((string)($data['endAt'] ?? ''));

		if ($customerId === '' || $serviceId === '' || $startAt === '' || $endAt === '') {
			throw new InvalidArgumentException('customerId, serviceId, startAt and endAt are required');
		}

		if ($this->isValidIso(value: $startAt) === false || $this->isValidIso(value: $endAt) === false) {
			throw new InvalidArgumentException('startAt and endAt must be ISO-8601 timestamps');
		}

		if (strtotime($startAt) >= strtotime($endAt)) {
			throw new InvalidArgumentException('startAt must be earlier than endAt');
		}

		$normalisedSource = $this->normaliseSource(source: $source);

		$service = $this->loadService(serviceId: $serviceId);
		$requireDeposit = (bool)($service['requiresDeposit'] ?? false);
		$depositAmount = (float)($service['depositAmount'] ?? 0.0);
		$initialStatus = 'confirmed';
		if ($requireDeposit === true && $depositAmount > 0.0) {
			$initialStatus = 'pending-deposit';
		}

		$nowIso = $this->nowIso();
		$changer = $this->actorUid();

		$payload = [
			'customerId' => $customerId,
			'serviceId' => $serviceId,
			'resourceAssignments' => $this->normaliseAssignments(value: ($data['resourceAssignments'] ?? [])),
			'startAt' => $startAt,
			'endAt' => $endAt,
			'status' => $initialStatus,
			'statusHistory' => [
				[
					'status' => $initialStatus,
					'changedAt' => $nowIso,
					'changedBy' => $changer,
					'reason' => sprintf('Booking created via %s', $normalisedSource),
				],
			],
			'notes' => (string)($data['notes'] ?? ''),
			'internalNotes' => (string)($data['internalNotes'] ?? ''),
			'source' => $normalisedSource,
			'depositAmount' => max(0.0, $depositAmount),
		];

		$saved = $this->saveBooking(payload: $payload, uuid: null);
		$uuid = $this->idOf(object: $saved);
		if ($uuid === '') {
			throw new RuntimeException('Booking save returned no id');
		}

		$this->invalidateAvailability(payload: $payload);

		if ($initialStatus === 'confirmed') {
			$this->dispatchConfirmationEmail(bookingId: $uuid);
			$this->pushCalendarEvent(bookingId: $uuid);
		}

		return $uuid;
	}//end createBooking()

	/**
	 * Compute available slots for a service on a date.
	 *
	 * Resolves the Service's required resource types and durations, then asks
	 * the AvailabilityService for each candidate resource's free slots and
	 * merges them. Eligibility (skills, member 03) is consulted when its seam
	 * has been wired into the AvailabilityService via {@see filterByEligibility}.
	 *
	 * @param string $serviceId Service UUID/slug.
	 * @param string $date ISO date `YYYY-MM-DD`.
	 *
	 * @return array<int, array{startTime: string, endTime: string, durationMinutes: int, resourceId: string}>
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function getAvailableSlots(string $serviceId, string $date): array {
		if ($serviceId === '' || $this->isValidDate(value: $date) === false) {
			return [];
		}

		try {
			$service = $this->loadService(serviceId: $serviceId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: service lookup failed in getAvailableSlots',
				['service' => $serviceId, 'date' => $date]
			);
			return [];
		}

		$duration = (int)($service['durationMinutes'] ?? 0);
		if ($duration <= 0) {
			return [];
		}

		$candidates = $this->loadCandidateResources(service: $service);
		$merged = [];
		foreach ($candidates as $resourceId) {
			$slots = $this->availabilityService->computeAvailability(
				resourceId: $resourceId,
				date: $date,
				serviceDurationMinutes: $duration
			);
			foreach ($slots as $slot) {
				$merged[] = [
					'startTime' => $slot['startTime'],
					'endTime' => $slot['endTime'],
					'durationMinutes' => $slot['durationMinutes'],
					'resourceId' => $resourceId,
				];
			}
		}

		return $merged;
	}//end getAvailableSlots()

	/**
	 * Confirm a pending-deposit booking.
	 *
	 * Sets `confirmationSentAt` (member 07 marks the seam) and appends a
	 * statusHistory entry. No-op when the booking is already confirmed.
	 *
	 * @param string $bookingId Booking UUID.
	 * @param string $reason Human-readable reason (e.g. "Deposit cleared").
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the booking cannot be transitioned.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function confirmBooking(string $bookingId, string $reason): void {
		$booking = $this->loadBooking(bookingId: $bookingId);
		$current = (string)($booking['status'] ?? '');
		if ($current === 'confirmed') {
			return;
		}

		$this->assertTransitionAllowed(from: $current, to: 'confirmed');

		$nowIso = $this->nowIso();
		$booking['status'] = 'confirmed';
		$booking['statusHistory'] = $this->appendHistory(
			history: ($booking['statusHistory'] ?? []),
			status: 'confirmed',
			changedAt: $nowIso,
			reason: $reason
		);
		$booking['confirmationSentAt'] = $nowIso;

		$this->saveBooking(payload: $this->stripSelf(payload: $booking), uuid: $bookingId);
		$this->invalidateAvailability(payload: $booking);
		$this->dispatchConfirmationEmail(bookingId: $bookingId);
		$this->pushCalendarEvent(bookingId: $bookingId);
	}//end confirmBooking()

	/**
	 * Reschedule a booking to a new start time.
	 *
	 * Creates a new Booking carrying the original's customer + service +
	 * resource assignments (shifted by the delta) with `previousBookingId`
	 * pointing at the original; the original is transitioned to `rescheduled`.
	 * The new Booking starts in `confirmed` status (deposit considered paid on
	 * the original) and the old slot is freed via AvailabilityCache invalidation.
	 *
	 * @param string $bookingId Original Booking UUID.
	 * @param string $newStartAt New ISO-8601 start timestamp.
	 *
	 * @return string The new Booking UUID.
	 *
	 * @throws InvalidArgumentException If validation fails.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function rescheduleBooking(string $bookingId, string $newStartAt): string {
		if ($this->isValidIso(value: $newStartAt) === false) {
			throw new InvalidArgumentException('newStartAt must be an ISO-8601 timestamp');
		}

		$original = $this->loadBooking(bookingId: $bookingId);
		$current = (string)($original['status'] ?? '');
		$this->assertTransitionAllowed(from: $current, to: 'rescheduled');

		$originalStart = (string)($original['startAt'] ?? '');
		$originalEnd = (string)($original['endAt'] ?? '');
		if ($originalStart === '' || $originalEnd === '') {
			throw new InvalidArgumentException('original booking is missing startAt/endAt');
		}

		$deltaSeconds = (strtotime($newStartAt) - strtotime($originalStart));
		if ($deltaSeconds === 0) {
			throw new InvalidArgumentException('newStartAt must differ from current startAt');
		}

		$newEnd = $this->shiftIso(iso: $originalEnd, seconds: $deltaSeconds);
		$nowIso = $this->nowIso();

		$newAssignments = [];
		foreach (($original['resourceAssignments'] ?? []) as $assignment) {
			if (is_array($assignment) === false) {
				continue;
			}

			$newAssignments[] = [
				'stepIndex' => (int)($assignment['stepIndex'] ?? 0),
				'resourceId' => (string)($assignment['resourceId'] ?? ''),
				'startAt' => $this->shiftIso(iso: (string)($assignment['startAt'] ?? ''), seconds: $deltaSeconds),
				'endAt' => $this->shiftIso(iso: (string)($assignment['endAt'] ?? ''), seconds: $deltaSeconds),
			];
		}

		$newPayload = [
			'customerId' => (string)($original['customerId'] ?? ''),
			'serviceId' => (string)($original['serviceId'] ?? ''),
			'resourceAssignments' => $newAssignments,
			'startAt' => $newStartAt,
			'endAt' => $newEnd,
			'status' => 'confirmed',
			'statusHistory' => [
				[
					'status' => 'confirmed',
					'changedAt' => $nowIso,
					'changedBy' => $this->actorUid(),
					'reason' => sprintf('Rescheduled from %s', $bookingId),
				],
			],
			'notes' => (string)($original['notes'] ?? ''),
			'internalNotes' => (string)($original['internalNotes'] ?? ''),
			'source' => (string)($original['source'] ?? 'portal'),
			'depositAmount' => (float)($original['depositAmount'] ?? 0.0),
			'previousBookingId' => $bookingId,
		];

		$saved = $this->saveBooking(payload: $newPayload, uuid: null);
		$newUuid = $this->idOf(object: $saved);
		if ($newUuid === '') {
			throw new RuntimeException('Reschedule save returned no id');
		}

		// Transition the original to `rescheduled` (terminal branch).
		$original['status'] = 'rescheduled';
		$original['statusHistory'] = $this->appendHistory(
			history: ($original['statusHistory'] ?? []),
			status: 'rescheduled',
			changedAt: $nowIso,
			reason: sprintf('Rescheduled to booking %s', $newUuid)
		);
		$this->saveBooking(payload: $this->stripSelf(payload: $original), uuid: $bookingId);

		// Free the old slot AND warm-bust the new slot.
		$this->invalidateAvailability(payload: $original);
		$this->invalidateAvailability(payload: $newPayload);

		// Move the calendar mirror to the new booking (the provider drops any
		// existing leaf events linked to the original UUID and creates fresh
		// VEVENTs tied to the new booking — guaranteeing reschedule = move).
		$this->pushCalendarEvent(bookingId: $newUuid);

		return $newUuid;
	}//end rescheduleBooking()

	/**
	 * Cancel a booking with policy enforcement.
	 *
	 * The policy is read from the linked Service: `free` never charges,
	 * `always-charge` charges within the cancellation window, `charge-deposit`
	 * forfeits the deposit when cancelled inside the window. Staff cancellations
	 * (cancelledBy != customerId) ALWAYS skip the charge (REQ-APT-009 scenario 3).
	 *
	 * @param string $bookingId Booking UUID.
	 * @param string $reason Customer-supplied reason (echoed into statusHistory).
	 * @param string $cancelledBy Identifier of the actor cancelling (customer id, user id, or 'system').
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the booking cannot be transitioned.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function cancelBooking(string $bookingId, string $reason, string $cancelledBy): void {
		$booking = $this->loadBooking(bookingId: $bookingId);
		$current = (string)($booking['status'] ?? '');

		$isCustomerCancel = ($cancelledBy === (string)($booking['customerId'] ?? ''));
		$targetStatus = 'cancelled-by-business';
		if ($isCustomerCancel === true) {
			$targetStatus = 'cancelled-by-customer';
		}

		$this->assertTransitionAllowed(from: $current, to: $targetStatus);

		$service = $this->loadServiceQuiet(serviceId: (string)($booking['serviceId'] ?? ''));
		$shouldCharge = $this->shouldChargeCancellation(
			booking: $booking,
			service: $service,
			isCustomerCancel: $isCustomerCancel
		);
		$chargeAmount = $this->cancellationChargeAmount(booking: $booking, service: $service);

		$nowIso = $this->nowIso();
		$booking['status'] = $targetStatus;
		$booking['cancellationReason'] = $this->truncate(value: $reason, max: 1000);
		$booking['cancelledAt'] = $nowIso;
		$cancelActor = $cancelledBy;
		if ($cancelActor === '') {
			$cancelActor = self::ACTOR_SYSTEM;
		}

		$booking['cancelledBy'] = $cancelActor;
		$booking['statusHistory'] = $this->appendHistory(
			history: ($booking['statusHistory'] ?? []),
			status: $targetStatus,
			changedAt: $nowIso,
			reason: $this->truncate(value: $reason, max: 1000)
		);

		$this->saveBooking(payload: $this->stripSelf(payload: $booking), uuid: $bookingId);
		$this->invalidateAvailability(payload: $booking);

		if ($shouldCharge === true && $chargeAmount > 0.0) {
			$this->chargeCancellationFee(bookingId: $bookingId, amount: $chargeAmount);
		}
	}//end cancelBooking()

	/**
	 * Mark a booking as a no-show.
	 *
	 * Increments the customer's lifetime no-show count and (when wired) queues
	 * the no-show fee through the payment seam — but the fee is OPTIONAL: when
	 * the customer has no payment method on file the no-show is still recorded
	 * (REQ-APT-011 scenario 2).
	 *
	 * @param string $bookingId Booking UUID.
	 * @param string $staffUserId Staff user UID (echoed into statusHistory).
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the booking cannot be transitioned.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function markNoShow(string $bookingId, string $staffUserId): void {
		$booking = $this->loadBooking(bookingId: $bookingId);
		$current = (string)($booking['status'] ?? '');

		$this->assertTransitionAllowed(from: $current, to: 'no-show');

		$nowIso = $this->nowIso();
		$actor = $staffUserId;
		if ($actor === '') {
			$actor = $this->actorUid();
		}

		$booking['status'] = 'no-show';
		$booking['statusHistory'] = $this->appendHistoryWithActor(
			history: ($booking['statusHistory'] ?? []),
			status: 'no-show',
			changedAt: $nowIso,
			changedBy: $actor,
			reason: 'No-show marked by staff'
		);

		$this->saveBooking(payload: $this->stripSelf(payload: $booking), uuid: $bookingId);
		$this->invalidateAvailability(payload: $booking);

		$this->incrementCustomerNoShowCount(customerId: (string)($booking['customerId'] ?? ''));

		$service = $this->loadServiceQuiet(serviceId: (string)($booking['serviceId'] ?? ''));
		$fee = (float)($service['noShowFee'] ?? 0.0);
		if ($fee > 0.0) {
			$this->chargeNoShowFee(bookingId: $bookingId, amount: $fee);
		}
	}//end markNoShow()

	/**
	 * Mark a confirmed booking as completed.
	 *
	 * @param string $bookingId Booking UUID.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the booking cannot be transitioned.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function completeBooking(string $bookingId): void {
		$booking = $this->loadBooking(bookingId: $bookingId);
		$current = (string)($booking['status'] ?? '');

		$this->assertTransitionAllowed(from: $current, to: 'completed');

		$nowIso = $this->nowIso();
		$booking['status'] = 'completed';
		$booking['statusHistory'] = $this->appendHistory(
			history: ($booking['statusHistory'] ?? []),
			status: 'completed',
			changedAt: $nowIso,
			reason: 'Service completed'
		);

		$this->saveBooking(payload: $this->stripSelf(payload: $booking), uuid: $bookingId);
		$this->invalidateAvailability(payload: $booking);
		$this->rebalanceWalkInQueue();
	}//end completeBooking()

	/**
	 * Schedule the walk-in queue rebalance (member 09).
	 *
	 * The rebalance recomputes ETAs for every waiting walk-in ticket so the
	 * queue panel reflects the freshly freed slot. It is DEFERRED to
	 * {@see WalkInQueueRebalanceJob}, which is what
	 * `openspec/specs/appointment-booking/spec.md` ("Queue rebalances as
	 * appointments complete") requires: *the WalkInQueueRebalanceJob MUST
	 * recalculate `estimatedReadyAt` for all waiting tickets*.
	 *
	 * It used to call `WalkInQueueService::rebalance()` inline. That walked up
	 * to `WalkInQueueService::QUEUE_PAGE_SIZE` (200) waiting tickets and did an
	 * availability computation plus a `saveObject()` for EACH — up to 200
	 * object writes inside the HTTP request that completes one booking, with
	 * every error swallowed. Request latency scaled with queue depth and a
	 * total failure of the rebalance was invisible to the caller. That is the
	 * openregister#2420 family: a synchronous write fan-out on a request path.
	 *
	 * `IJobList::add()` is idempotent for an identical (class, argument) pair,
	 * so N completions in one cron window collapse to ONE queued rebalance —
	 * which is also strictly more correct, since the rebalance is a whole-queue
	 * recomputation and running it once after the last completion produces the
	 * same ETAs as running it after each.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	private function rebalanceWalkInQueue(): void {
		try {
			$this->jobList->add(WalkInQueueRebalanceJob::class);
		} catch (\Throwable $e) {
			// Enqueueing is best-effort: the booking completion has already
			// been persisted and must not be rolled back because the ETA
			// refresh could not be scheduled.
			$this->logger->warning(
				'Pipelinq: could not schedule the walk-in queue rebalance',
				['exception' => $e->getMessage()]
			);
		}
	}//end rebalanceWalkInQueue()

	/**
	 * Assert that a status transition is permitted by the state machine.
	 *
	 * @param string $from Current status.
	 * @param string $to Target status.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the transition is rejected.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function assertTransitionAllowed(string $from, string $to): void {
		$allowed = self::allowedTransitions();
		if (isset($allowed[$from]) === false) {
			throw new InvalidArgumentException(
				sprintf('Unknown source status: %s', $from)
			);
		}

		if (in_array($to, $allowed[$from], true) === false) {
			throw new InvalidArgumentException(
				sprintf('Invalid status transition: %s -> %s', $from, $to)
			);
		}
	}//end assertTransitionAllowed()

	/**
	 * The state-machine adjacency map.
	 *
	 * Derived from the Booking schema's `x-openregister-lifecycle` declaration
	 * (ADR-031) — the single source of truth that OpenRegister's
	 * LifecycleValidationListener also enforces on save. `fullAdjacencyFor()` seeds
	 * a key for every declared state, so terminal states (completed / no-show /
	 * cancelled-* / rescheduled) appear with an empty target list — preserving the
	 * "Unknown source status" vs "Invalid status transition" distinction in
	 * {@see assertTransitionAllowed()}. Falls back to the mirrored constant only
	 * when the declaration is unreadable, so a broken register file never regresses.
	 *
	 * @return array<string, array<int, string>>
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 * @spec openspec/specs/openregister-integration/spec.md
	 */
	public static function allowedTransitions(): array {
		$graph = (new SchemaLifecycleGraph())->fullAdjacencyFor(schemaSlug: self::BOOKING_SCHEMA_SLUG);
		if ($graph === []) {
			return self::FALLBACK_TRANSITIONS;
		}

		return $graph;
	}//end allowedTransitions()

	/**
	 * Fallback state-machine adjacency map used only when the schema declaration
	 * is unreadable. The canonical source of truth is the Booking schema's
	 * `x-openregister-lifecycle` annotation (ADR-031); this constant must mirror it.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const FALLBACK_TRANSITIONS = [
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
	];

	/**
	 * Decide whether a cancellation triggers a charge.
	 *
	 * Free policy: never charges. Always-charge: charges within the window.
	 * Charge-deposit: forfeits the deposit within the window. Staff cancellations
	 * always skip the charge (REQ-APT-009 scenario 3).
	 *
	 * @param array<string, mixed> $booking Booking entity.
	 * @param array<string, mixed>|null $service Service entity (null when unresolved).
	 * @param bool $isCustomerCancel True when the customer (not staff) cancelled.
	 *
	 * @return bool
	 */
	private function shouldChargeCancellation(array $booking, ?array $service, bool $isCustomerCancel): bool {
		if ($isCustomerCancel === false || $service === null) {
			return false;
		}

		$policy = (string)($service['cancellationPolicy'] ?? 'free');
		if ($policy === 'free') {
			return false;
		}

		$windowHours = (int)($service['cancellationHoursBefore'] ?? 24);
		$startAt = (string)($booking['startAt'] ?? '');
		if ($startAt === '') {
			return false;
		}

		$secondsUntilStart = (strtotime($startAt) - strtotime($this->nowIso()));
		$windowSeconds = ($windowHours * 3600);
		return ($secondsUntilStart < $windowSeconds);
	}//end shouldChargeCancellation()

	/**
	 * Resolve the cancellation charge amount.
	 *
	 * For `charge-deposit` it is the booking's snapshot depositAmount;
	 * for `always-charge` it is the service price; otherwise zero.
	 *
	 * @param array<string, mixed> $booking Booking entity.
	 * @param array<string, mixed>|null $service Service entity (or null).
	 *
	 * @return float
	 */
	private function cancellationChargeAmount(array $booking, ?array $service): float {
		if ($service === null) {
			return 0.0;
		}

		$policy = (string)($service['cancellationPolicy'] ?? 'free');
		if ($policy === 'charge-deposit') {
			$deposit = (float)($booking['depositAmount'] ?? ($service['depositAmount'] ?? 0.0));
			return max(0.0, $deposit);
		}

		if ($policy === 'always-charge') {
			return max(0.0, (float)($service['price'] ?? 0.0));
		}

		return 0.0;
	}//end cancellationChargeAmount()

	/**
	 * Append a statusHistory entry signed with the current user UID.
	 *
	 * @param array<int, mixed> $history Existing history.
	 * @param string $status New status.
	 * @param string $changedAt ISO timestamp.
	 * @param string $reason Human-readable reason.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function appendHistory(array $history, string $status, string $changedAt, string $reason): array {
		return $this->appendHistoryWithActor(
			history: $history,
			status: $status,
			changedAt: $changedAt,
			changedBy: $this->actorUid(),
			reason: $reason
		);
	}//end appendHistory()

	/**
	 * Append a statusHistory entry with an explicit `changedBy`.
	 *
	 * @param array<int, mixed> $history Existing history.
	 * @param string $status New status.
	 * @param string $changedAt ISO timestamp.
	 * @param string $changedBy Actor id (UID, customer id, or 'system').
	 * @param string $reason Human-readable reason.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function appendHistoryWithActor(
		array $history,
		string $status,
		string $changedAt,
		string $changedBy,
		string $reason,
	): array {
		$entries = [];
		foreach ($history as $entry) {
			if (is_array($entry) === true) {
				$entries[] = [
					'status' => (string)($entry['status'] ?? ''),
					'changedAt' => (string)($entry['changedAt'] ?? ''),
					'changedBy' => (string)($entry['changedBy'] ?? ''),
					'reason' => (string)($entry['reason'] ?? ''),
				];
			}
		}

		$entries[] = [
			'status' => $status,
			'changedAt' => $changedAt,
			'changedBy' => $changedBy,
			'reason' => $reason,
		];
		return $entries;
	}//end appendHistoryWithActor()

	/**
	 * Current actor's Nextcloud UID, falling back to `system` for cron/CLI.
	 *
	 * ADR-005: the audit trail records the UID, never the display name.
	 *
	 * @return string
	 */
	private function actorUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return self::ACTOR_SYSTEM;
		}

		$uid = $user->getUID();
		if ($uid === '') {
			return self::ACTOR_SYSTEM;
		}

		return $uid;
	}//end actorUid()

	/**
	 * Load a Booking by id, throwing when not found.
	 *
	 * @param string $bookingId Booking UUID.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException If empty.
	 * @throws RuntimeException If not found.
	 */
	private function loadBooking(string $bookingId): array {
		if ($bookingId === '') {
			throw new InvalidArgumentException('bookingId must be a non-empty string');
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::BOOKING_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Booking register/schema not configured');
		}

		try {
			$found = $this->getObjectService()->find(
				id: $bookingId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: booking load failed', ['booking' => $bookingId]);
			throw new RuntimeException('Booking lookup failed', 0, $e);
		}

		$data = $this->toArray(object: $found);
		if ($data === null) {
			throw new RuntimeException(sprintf('Booking %s not found', $bookingId));
		}

		return $data;
	}//end loadBooking()

	/**
	 * Load a Service by id (throwing).
	 *
	 * @param string $serviceId Service UUID/slug.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When empty.
	 * @throws RuntimeException When the service cannot be resolved.
	 */
	private function loadService(string $serviceId): array {
		$service = $this->loadServiceQuiet(serviceId: $serviceId);
		if ($service === null) {
			throw new RuntimeException(sprintf('Service %s not found', $serviceId));
		}

		return $service;
	}//end loadService()

	/**
	 * Load a Service by id without throwing.
	 *
	 * @param string $serviceId Service UUID/slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function loadServiceQuiet(string $serviceId): ?array {
		if ($serviceId === '') {
			return null;
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::SERVICE_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$found = $this->getObjectService()->find(
				id: $serviceId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: service load failed', ['service' => $serviceId]);
			return null;
		}

		return $this->toArray(object: $found);
	}//end loadServiceQuiet()

	/**
	 * Resolve candidate resource ids for a Service.
	 *
	 * Delegates to EligibilityService (member 03) for skill-aware filtering —
	 * the single source of truth per ADR-012 — and then narrows the result to
	 * resources whose `type` matches one of the Service's
	 * `requiredResourceTypes` (empty filter = no constraint), active status and
	 * `bookable: true`.
	 *
	 * @param array<string, mixed> $service Service entity.
	 *
	 * @return array<int, string>
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential filtering guards; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Sequential filtering guards; extraction adds no clarity.
	 */
	private function loadCandidateResources(array $service): array {
		$serviceId = $this->idOf(object: $service);
		if ($serviceId === '') {
			return [];
		}

		try {
			$eligible = $this->eligibilityService->getEligibleResources(serviceId: $serviceId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: eligibility lookup failed',
				['service' => $serviceId]
			);
			return [];
		}

		$requiredTypes = ($service['requiredResourceTypes'] ?? []);
		if (is_array($requiredTypes) === false) {
			$requiredTypes = [];
		}

		$candidates = [];
		foreach ($eligible as $resource) {
			if (($resource['bookable'] ?? true) === false) {
				continue;
			}

			$status = (string)($resource['status'] ?? 'active');
			if ($status !== 'active') {
				continue;
			}

			if ($requiredTypes !== []) {
				$type = (string)($resource['type'] ?? '');
				if (in_array($type, $requiredTypes, true) === false) {
					continue;
				}
			}

			$id = $this->idOf(object: $resource);
			if ($id !== '') {
				$candidates[] = $id;
			}
		}//end foreach

		return $candidates;
	}//end loadCandidateResources()

	/**
	 * Increment the customer's lifetime no-show count.
	 *
	 * Best-effort: when the customer mirror schema is unconfigured or the
	 * lookup fails, the no-show is still recorded on the Booking — only the
	 * lifetime counter is skipped (REQ-APT-011 scenario 2 tolerance).
	 *
	 * @param string $customerId Customer UUID.
	 *
	 * @return void
	 */
	private function incrementCustomerNoShowCount(string $customerId): void {
		if ($customerId === '') {
			return;
		}

		$register = $this->registerId();
		$schema = $this->schemaId(key: self::CUSTOMER_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$customer = $this->getObjectService()->find(
				id: $customerId,
				register: $register,
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: customer load for no-show failed', ['customer' => $customerId]);
			return;
		}

		$data = $this->toArray(object: $customer);
		if ($data === null) {
			return;
		}

		$count = (int)($data['noShowCount'] ?? 0);
		$data['noShowCount'] = ($count + 1);

		try {
			$this->getObjectService()->saveObject(
				object: $this->stripSelf(payload: $data),
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $customerId
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: customer no-show count save failed',
				['customer' => $customerId]
			);
		}
	}//end incrementCustomerNoShowCount()

	/**
	 * Persist a Booking (create when uuid is null, update otherwise).
	 *
	 * @param array<string, mixed> $payload Booking payload.
	 * @param string|null $uuid UUID for updates.
	 *
	 * @return array<string, mixed>|object
	 */
	private function saveBooking(array $payload, ?string $uuid): array|object {
		$register = $this->registerId();
		$schema = $this->schemaId(key: self::BOOKING_SCHEMA_KEY);
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Booking register/schema not configured');
		}

		return $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);
	}//end saveBooking()

	/**
	 * Invalidate AvailabilityCache rows for every resource the booking touches.
	 *
	 * The booking may span multiple resources (multiStep services); each
	 * assignment is invalidated by date. Errors are swallowed — the cache is
	 * a regenerable read-side optimisation.
	 *
	 * @param array<string, mixed> $payload Booking payload.
	 *
	 * @return void
	 */
	private function invalidateAvailability(array $payload): void {
		$assignments = ($payload['resourceAssignments'] ?? []);
		if (is_array($assignments) === false) {
			return;
		}

		$seen = [];
		foreach ($assignments as $assignment) {
			if (is_array($assignment) === false) {
				continue;
			}

			$resourceId = (string)($assignment['resourceId'] ?? '');
			$startAt = (string)($assignment['startAt'] ?? '');
			if ($resourceId === '' || $startAt === '') {
				continue;
			}

			$date = substr($startAt, 0, 10);
			$key = ($resourceId . '|' . $date);
			if (isset($seen[$key]) === true) {
				continue;
			}

			$seen[$key] = true;
			try {
				$this->availabilityService->invalidateCache(resourceId: $resourceId, date: $date);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Pipelinq: availability invalidation failed',
					['resource' => $resourceId, 'date' => $date]
				);
			}
		}//end foreach
	}//end invalidateAvailability()

	/**
	 * Dispatch a confirmation email via the optional seam (member 07).
	 *
	 * @param string $bookingId Booking UUID.
	 *
	 * @return void
	 */
	private function dispatchConfirmationEmail(string $bookingId): void {
		if ($this->emailProvider === null) {
			return;
		}

		if (method_exists($this->emailProvider, 'sendConfirmation') === false) {
			return;
		}

		try {
			// @phpstan-ignore-next-line dynamic provider seam
			$this->emailProvider->sendConfirmation($bookingId);
		} catch (\Throwable $e) {
			$this->logger->warning('Pipelinq: confirmation email seam failed', ['booking' => $bookingId]);
		}
	}//end dispatchConfirmationEmail()

	/**
	 * Push the confirmed Booking to the staff calendar via the leaf (member 10).
	 *
	 * Best-effort: a missing provider, a missing method, or a leaf failure is
	 * absorbed — the booking still persists and the customer still gets their
	 * confirmation. Only the calendar mirror is lost.
	 *
	 * @param string $bookingId Booking UUID.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	private function pushCalendarEvent(string $bookingId): void {
		if ($this->calendarProvider === null) {
			return;
		}

		if (method_exists($this->calendarProvider, 'pushBookingEvent') === false) {
			return;
		}

		try {
			// @phpstan-ignore-next-line dynamic provider seam (member 10 leaf bridge).
			$this->calendarProvider->pushBookingEvent($bookingId);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: calendar push seam failed',
				['booking' => $bookingId]
			);
		}
	}//end pushCalendarEvent()

	/**
	 * Queue the no-show fee through the payment seam (member 08).
	 *
	 * @param string $bookingId Booking UUID.
	 * @param float $amount Fee amount.
	 *
	 * @return void
	 */
	private function chargeNoShowFee(string $bookingId, float $amount): void {
		if ($this->paymentProvider === null) {
			return;
		}

		if (method_exists($this->paymentProvider, 'chargeNoShowFee') === false) {
			return;
		}

		try {
			// @phpstan-ignore-next-line dynamic provider seam
			$this->paymentProvider->chargeNoShowFee($bookingId, $amount);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: no-show fee charge failed',
				['booking' => $bookingId, 'amount' => $amount]
			);
		}
	}//end chargeNoShowFee()

	/**
	 * Queue a cancellation fee through the payment seam (member 08).
	 *
	 * @param string $bookingId Booking UUID.
	 * @param float $amount Fee amount.
	 *
	 * @return void
	 */
	private function chargeCancellationFee(string $bookingId, float $amount): void {
		if ($this->paymentProvider === null) {
			return;
		}

		if (method_exists($this->paymentProvider, 'chargeCancellationFee') === false) {
			return;
		}

		try {
			// @phpstan-ignore-next-line dynamic provider seam
			$this->paymentProvider->chargeCancellationFee($bookingId, $amount);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: cancellation fee charge failed',
				['booking' => $bookingId, 'amount' => $amount]
			);
		}
	}//end chargeCancellationFee()

	/**
	 * Strip OpenRegister metadata before re-saving an existing object.
	 *
	 * The OR engine reads `@self` for register/schema/id; including it on save
	 * is harmless but `register`/`schema`/`uuid` are passed explicitly so we
	 * remove the duplicate to keep payloads small.
	 *
	 * @param array<string, mixed> $payload Booking payload.
	 *
	 * @return array<string, mixed>
	 */
	private function stripSelf(array $payload): array {
		if (array_key_exists('@self', $payload) === true) {
			unset($payload['@self']);
		}

		return $payload;
	}//end stripSelf()

	/**
	 * Normalise the resourceAssignments payload list.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normaliseAssignments(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $assignment) {
			if (is_array($assignment) === false) {
				continue;
			}

			$out[] = [
				'stepIndex' => (int)($assignment['stepIndex'] ?? 0),
				'resourceId' => (string)($assignment['resourceId'] ?? ''),
				'startAt' => (string)($assignment['startAt'] ?? ''),
				'endAt' => (string)($assignment['endAt'] ?? ''),
			];
		}

		return $out;
	}//end normaliseAssignments()

	/**
	 * Normalise the booking source value against the allowed enum.
	 *
	 * @param string $source Raw source value.
	 *
	 * @return string
	 */
	private function normaliseSource(string $source): string {
		$lower = strtolower(trim($source));
		if (in_array($lower, self::SOURCES, true) === true) {
			return $lower;
		}

		return 'portal';
	}//end normaliseSource()

	/**
	 * Truncate a string to a maximum length.
	 *
	 * @param string $value Raw value.
	 * @param int $max Maximum length.
	 *
	 * @return string
	 */
	private function truncate(string $value, int $max): string {
		if (strlen($value) <= $max) {
			return $value;
		}

		return substr($value, 0, $max);
	}//end truncate()

	/**
	 * True when the value is a parseable ISO-8601 datetime.
	 *
	 * @param string $value Candidate string.
	 *
	 * @return bool
	 */
	private function isValidIso(string $value): bool {
		if ($value === '') {
			return false;
		}

		$timestamp = strtotime($value);
		return ($timestamp !== false);
	}//end isValidIso()

	/**
	 * True when the value is a YYYY-MM-DD date.
	 *
	 * @param string $value Candidate.
	 *
	 * @return bool
	 */
	private function isValidDate(string $value): bool {
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
			return false;
		}

		return ((bool)strtotime($value));
	}//end isValidDate()

	/**
	 * Shift an ISO timestamp by N seconds.
	 *
	 * @param string $iso Source timestamp.
	 * @param int $seconds Delta in seconds (positive or negative).
	 *
	 * @return string
	 */
	private function shiftIso(string $iso, int $seconds): string {
		if ($iso === '') {
			return '';
		}

		$timestamp = strtotime($iso);
		if ($timestamp === false) {
			return $iso;
		}

		$shifted = ($timestamp + $seconds);
		$dateTime = (new DateTimeImmutable('@' . $shifted))->setTimezone(new DateTimeZone('UTC'));
		return $dateTime->format('Y-m-d\TH:i:sP');
	}//end shiftIso()

	/**
	 * Now in ISO-8601 UTC.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
	}//end nowIso()

	/**
	 * The pipelinq register id from app config.
	 *
	 * Fails closed: '' means "unconfigured", and every caller refuses the
	 * OpenRegister call on it. An empty register must never be handed to
	 * OpenRegister — ObjectService skips setRegister() for an empty value, so
	 * the query silently inherits whatever register context an earlier call in
	 * the same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @return string The configured register id, or '' when unconfigured.
	 */
	private function registerId(): string {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($registerId === '') {
			$this->logger->warning(
				'Pipelinq: app-config "register" is not configured; OpenRegister calls are refused, not run unscoped'
			);
		}

		return $registerId;
	}//end registerId()

	/**
	 * Resolve a schema id by app-config key.
	 *
	 * @param string $key App-config key.
	 *
	 * @return string
	 */
	private function schemaId(string $key): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, '');
	}//end schemaId()

	/**
	 * Pull the canonical id out of a normalised OpenRegister object.
	 *
	 * @param array<string, mixed>|object $object Normalised object or entity.
	 *
	 * @return string
	 */
	private function idOf(array|object $object): string {
		$arr = $this->toArray(object: $object);
		if ($arr === null) {
			return '';
		}

		if (isset($arr['@self']) === true && is_array($arr['@self']) === true) {
			$self = $arr['@self'];
			if (isset($self['id']) === true) {
				return (string)$self['id'];
			}

			if (isset($self['uuid']) === true) {
				return (string)$self['uuid'];
			}
		}

		if (isset($arr['id']) === true) {
			return (string)$arr['id'];
		}

		if (isset($arr['uuid']) === true) {
			return (string)$arr['uuid'];
		}

		return '';
	}//end idOf()

	/**
	 * Normalise an OpenRegister entity (or array) to a plain array.
	 *
	 * @param mixed $object Entity, array, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function toArray(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'toArray') === true) {
				$arr = $object->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$object;
		}

		return null;
	}//end toArray()

	/**
	 * Resolve the OpenRegister ObjectService via the DI container.
	 *
	 * @return object The ObjectService instance.
	 *
	 * @throws RuntimeException If OpenRegister is not available.
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
