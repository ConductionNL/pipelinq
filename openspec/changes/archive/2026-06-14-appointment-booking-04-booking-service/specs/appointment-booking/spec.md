# Appointment Booking — Booking Service (Member 04) Delta Spec

## Purpose

Implement the booking lifecycle: create, confirm, reschedule, cancel, mark no-show,
complete — with validated status transitions and a status-history audit trail.

---

## ADDED Requirements

### Requirement: REQ-APT-008 Reschedule via Signed Link

The system MUST allow rescheduling an appointment by marking the original Booking as
rescheduled and creating a new Booking, preserving the audit trail.

**Feature tier**: V1

#### Scenario: Customer reschedules to new time

- **GIVEN** a Booking is rescheduled to a new date/time
- **WHEN** the reschedule completes
- **THEN** the original Booking MUST transition to `status: "rescheduled"`, a new Booking MUST be created for the new time with `previousBookingId` pointing at the original, and the old time slot MUST be freed

#### Scenario: Rescheduled booking inherits customer and service

- **GIVEN** the new Booking is created during reschedule
- **WHEN** it is saved
- **THEN** it MUST have the same `customerId` and `serviceId` as the original, but a new `startAt`/`endAt` and `resourceAssignments`

### Requirement: REQ-APT-009 Cancellation with Policy Enforcement

The system MUST enforce configurable cancellation policies: free-until-N-hours-before,
always-charge, or no-charge. Late cancellations trigger optional payment charges.

**Feature tier**: V1

#### Scenario: Free cancellation within policy window

- **GIVEN** a Service with `cancellationPolicy: "free-until-24-hours-before"` and a Booking starting in 48 hours
- **WHEN** the booking is cancelled
- **THEN** the Booking MUST transition to `cancelled-by-customer` and NO charge MUST be applied

#### Scenario: Late cancellation triggers charge

- **GIVEN** a Service with `cancellationPolicy: "free-until-24-hours-before"`, `price: 50.00`, and a Booking starting in 18 hours
- **WHEN** the booking is cancelled
- **THEN** a 50 EUR charge MUST be queued (via the payment seam, member 08)

#### Scenario: Staff can cancel anytime

- **GIVEN** a confirmed Booking
- **WHEN** a staff member cancels it
- **THEN** the Booking MUST transition to `cancelled-by-business` without a charge, regardless of policy

### Requirement: REQ-APT-011 No-Show Tracking

The system MUST track no-shows and increment customer lifetime no-show count.

**Feature tier**: V1

#### Scenario: Staff marks booking as no-show

- **GIVEN** a confirmed Booking with `startAt` 30 minutes in the past
- **WHEN** staff marks it as no-show
- **THEN** the Booking status MUST transition to `no-show` and the customer's no-show count MUST increment by 1

#### Scenario: No-show is recorded even without payment method

- **GIVEN** a Booking is marked no-show and the customer has no payment method on file
- **WHEN** the status is set
- **THEN** the no-show MUST be recorded and the count incremented (fee charging is deferred to member 08)

### Requirement: REQ-APT-013 Booking Status Lifecycle

The system MUST enforce a valid status transition flow: pending-deposit → confirmed
→ completed/no-show/cancelled, with rescheduled as a parallel branch.

**Feature tier**: V1

#### Scenario: Status transitions are validated

- **GIVEN** a Booking with `status: "confirmed"`
- **WHEN** an attempt is made to transition directly to `"pending-deposit"`
- **THEN** the system MUST reject the transition (invalid direction)

#### Scenario: Rescheduled bookings preserve original

- **GIVEN** a Booking with `status: "confirmed"` is rescheduled
- **WHEN** the reschedule completes
- **THEN** the original Booking MUST have `status: "rescheduled"` (not deleted), and its data MUST remain for audit purposes

#### Scenario: Status history is logged

- **GIVEN** any Booking status change occurs
- **WHEN** the change is saved
- **THEN** `statusHistory` MUST be appended with `{status, changedAt, changedBy, reason}` where `changedBy` is the Nextcloud user UID
