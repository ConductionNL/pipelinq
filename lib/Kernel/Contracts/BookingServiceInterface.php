<?php

/**
 * Contract of BookingService
 *
 * The public surface of BookingService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

use InvalidArgumentException;
use RuntimeException;

/**
 * Contract of BookingService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BookingServiceInterface {

	/**
	 * App-config key for the Booking schema id/slug.
	 *
	 * @var string
	 */
	public const BOOKING_SCHEMA_KEY = 'booking_schema';

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
	public function cancelBooking(string $bookingId, string $reason, string $cancelledBy): void;

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
	public function completeBooking(string $bookingId): void;

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
	public function confirmBooking(string $bookingId, string $reason): void;

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
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function createBooking(array $data, string $source): string;

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
	public function getAvailableSlots(string $serviceId, string $date): array;

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
	public function markNoShow(string $bookingId, string $staffUserId): void;

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
	public function rescheduleBooking(string $bookingId, string $newStartAt): string;
}//end interface
