# Design: Appointment Booking — Deposit & Payment (Member 08)

## Overview

Fill the payment seams declared in members 04/05. All payment transport goes
through openconnector (`OCA\OpenConnector` PaymentService); pipelinq never touches
3D-Secure or PSD2 SCA directly (ADR-012).

## Backend (per giant design.md)

### Deposit flow (REQ-APT-010)
- In `POST /portal/book`: if `requiresDeposit`, create a Booking with
  `status: pending-deposit`, hold the slot for 15 minutes, and initiate a payment
  session via openconnector. Return the payment session URL.
- On payment success (webhook/callback): transition to `confirmed`, set
  `depositPaidAt`, trigger confirmation email (member 07).
- On 15-minute timeout: transition to `cancelled-by-business`, release the slot.

### No-show / late-cancel fee (REQ-APT-011 charge path)
- In `BookingService::markNoShow`: if `noShowFee > 0` and a payment method is on
  file, queue a charge via openconnector; set `noShowFeeChargedAt` on success.
- In `cancelBooking`: late-cancellation charge per policy (member 04 computes
  applicability; this member performs the charge).
- If no payment method: log, do not attempt charge.

## Security (ADR-005)

Payment callbacks are validated server-side; booking status is derived from the
authoritative payment result, never the client. Static error messages.

## Tests

Unit tests for: deposit booking creates pending-deposit + payment session; payment
success → confirmed + depositPaidAt; timeout releases slot; no-show fee charged
when configured; no charge without payment method. Mock openconnector PaymentService.
