# Tasks: Appointment Booking — Deposit & Payment (Member 08)

## Section 1: Deposit / Payment Session

- [ ] In PortalController::POST /portal/book: if depositRequired, call openconnector API to create payment session
- [ ] Return payment session URL to frontend
- [ ] On payment success: webhook or callback updates Booking status → confirmed, set depositPaidAt, trigger confirmation email (member 07)
- [ ] On payment failure: Booking status remains pending-deposit (not deleted), slot released after 15 min timeout → cancelled-by-business
- [ ] PSD2 SCA handled by openconnector (no custom 3D Secure code)

## Section 2: No-Show / Late-Cancellation Fee Charge

- [ ] In BookingService::markNoShow(): if noShowFee > 0 and payment method on file, call openconnector to queue charge
- [ ] openconnector handles: lookup customer payment method, initiate charge, handle 3D Secure 2
- [ ] On success: set noShowFeeChargedAt on Booking
- [ ] If no payment method: log the no-show but don't attempt charge
- [ ] In cancelBooking(): perform the late-cancellation charge openconnector when member 04 marks it chargeable

## Section 3: Unit Tests

- [ ] Test deposit booking creates pending-deposit + payment session
- [ ] Test payment success transitions to confirmed and sets depositPaidAt
- [ ] Test 15-minute timeout releases the slot
- [ ] Test no-show fee charged when configured; no charge without payment method
- [ ] Mock openconnector PaymentService
