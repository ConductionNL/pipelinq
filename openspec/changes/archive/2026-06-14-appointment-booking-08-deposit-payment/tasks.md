# Tasks: Appointment Booking — Deposit & Payment (Member 08)

## Section 1: Deposit / Payment Session

- [x] In PortalController::POST /portal/book: if depositRequired, call openconnector API to create payment session
- [x] Return payment session URL to frontend
- [x] On payment success: webhook or callback updates Booking status → confirmed, set depositPaidAt, trigger confirmation email (member 07)
- [x] On payment failure: Booking status remains pending-deposit (not deleted), slot released after 15 min timeout → cancelled-by-business
- [x] PSD2 SCA handled by openconnector (no custom 3D Secure code)

## Section 2: No-Show / Late-Cancellation Fee Charge

- [x] In BookingService::markNoShow(): if noShowFee > 0 and payment method on file, call openconnector to queue charge
- [x] openconnector handles: lookup customer payment method, initiate charge, handle 3D Secure 2
- [x] On success: set noShowFeeChargedAt on Booking
- [x] If no payment method: log the no-show but don't attempt charge
- [x] In cancelBooking(): perform the late-cancellation charge openconnector when member 04 marks it chargeable

## Section 3: Unit Tests

- [x] Test deposit booking creates pending-deposit + payment session
- [x] Test payment success transitions to confirmed and sets depositPaidAt
- [x] Test 15-minute timeout releases the slot
- [x] Test no-show fee charged when configured; no charge without payment method
- [x] Mock openconnector PaymentService
