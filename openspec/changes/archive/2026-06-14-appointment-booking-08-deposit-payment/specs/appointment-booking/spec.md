# Appointment Booking — Deposit & Payment (Member 08) Delta Spec

## Purpose

Support deposit-required bookings (slot held, PSD2 payment → confirmed) and no-show /
late-cancellation fee charging, all via openconnector.

---

## ADDED Requirements

### Requirement: REQ-APT-010 Deposit-Required Bookings

The system MUST support optional deposits: bookings requiring deposits are created
with `status: "pending-deposit"`, the slot is held for 15 minutes, and on successful
payment `status` transitions to `confirmed`.

**Feature tier**: V1

#### Scenario: Deposit required booking holds slot

- **GIVEN** a Service with `requiresDeposit: true`, `depositAmount: 20.00`
- **WHEN** a customer submits the booking form
- **THEN** a Booking MUST be created with `status: "pending-deposit"`, the slot MUST be held for 15 minutes, and a payment session MUST be initiated via openconnector

#### Scenario: Slot released on payment timeout

- **GIVEN** a Booking with `status: "pending-deposit"` and payment not completed within 15 minutes
- **WHEN** 15 minutes elapse
- **THEN** the Booking MUST transition to `cancelled-by-business` and the slot MUST be released

#### Scenario: Status transitions to confirmed on payment success

- **GIVEN** a Booking with `status: "pending-deposit"` and the customer completes PSD2-compliant payment
- **WHEN** openconnector confirms payment
- **THEN** the Booking MUST transition to `confirmed`, `depositPaidAt` MUST be set, and a confirmation email MUST be sent

### Requirement: REQ-APT-011A No-Show and Late-Cancellation Fee Charging

The system MUST charge no-show and late-cancellation fees via openconnector when a
payment method is on file.

**Feature tier**: V1

#### Scenario: No-show fee is charged if configured

- **GIVEN** a Service with `noShowFee: 25.00` and a Booking marked no-show with a payment method on file
- **WHEN** the no-show status is set
- **THEN** a 25 EUR charge MUST be queued via openconnector with `noShowFeeChargedAt` set on success

#### Scenario: No-show without payment method is logged but not charged

- **GIVEN** a Booking is marked no-show and the customer has no payment method on file
- **WHEN** the status is set
- **THEN** no charge MUST be attempted (the no-show is still recorded by member 04)

#### Scenario: Late cancellation fee is charged

- **GIVEN** a late cancellation that member 04 has determined is chargeable (50 EUR)
- **WHEN** the cancellation completes
- **THEN** a 50 EUR charge MUST be queued via openconnector
