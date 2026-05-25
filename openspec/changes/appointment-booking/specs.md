---
status: draft
---

# Specs: appointment-booking

**Feature tier**: MVP
**Spec refs**: `openspec/changes/appointment-booking/design.md`
**Standards**: RFC 5545 (iCalendar), RFC 3339 (timestamps), WCAG 2.1 AA, AVG/GDPR, PSD2/SCA, NL Boekhoudplicht (7-year retention)

---

## REQ-ABK-001: Self-book via public portal

A customer can visit `/book/:service-slug` and self-service book an appointment for an available date and time. The portal queries availability, shows 15-minute aligned slots, collects customer info, and creates a Booking.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/appointment-booking/design.md#Public Portal`
**Files**: `resources/js/views/BookingPortal.vue`, `lib/Controller/BookingController.php`

### Scenario REQ-ABK-001-01: Portal displays available slots

- GIVEN a Service "Haircut" with `bookableOnline: true`, 30-minute duration, and a Resource "Alex" (stylist) working Mon-Fri 09:00-17:00 with no bookings on Tuesday
- WHEN a customer visits `/book/haircut` and selects Tuesday
- THEN the portal displays a dropdown or grid of 15-minute aligned start times: 09:00, 09:15, 09:30, …, 16:30 (all are available because each 30-min slot fits within Alex's working hours)

### Scenario REQ-ABK-001-02: Portal respects skill requirements

- GIVEN a Service "Color Treatment" requiring skill "color-certified" and two stylists: Alex (has skill) and Maya (no skill)
- WHEN the customer visits the portal
- THEN available slots on a given date MUST only be offered for dates/times when Alex is free (not when Maya is free)
- AND the availability query internally calls skill-routing to filter eligible resources

### Scenario REQ-ABK-001-03: Customer submits booking form

- GIVEN a customer has selected Tuesday 10:00 for "Haircut"
- WHEN they fill in the form (name "Jan Pieterzoon", email "jan@example.nl", phone "0612345678") and click "Confirm"
- THEN the system MUST POST to `POST /api/booking/create` with serviceId, customerId (or create if new), startAt, notes
- AND the Booking MUST be created with status `confirmed` or `pending-deposit` (depending on deposit requirement)
- AND a confirmation email is sent to jan@example.nl with subject "Your appointment is confirmed"

### Scenario REQ-ABK-001-04: Confirmation email includes .ics attachment

- GIVEN a Booking is created
- WHEN the confirmation email is sent
- THEN the email MUST include a `.ics` (iCalendar) attachment with event details: service name, resource name, start/end times, customer name
- AND the customer can add the appointment to their own calendar (Outlook, Google, etc.) by importing the attachment

---

## REQ-ABK-002: Multi-step services block correct resources

A Service can define multiple sequential steps, each with duration, skill requirement, and optional gap. The booking engine reserves each step with the correct resource.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/appointment-booking/design.md#Service Schema`
**Files**: `lib/Service/AvailabilityQueryService.php`, `lib/Service/BookingService.php`

### Scenario REQ-ABK-002-01: Color + Cut reserves one stylist for both steps

- GIVEN a Service "Color + Cut" with multiStep: `[{45m, color-certified}, {30m gap allowed, any-stylist}]`
- AND a stylist "Maya" with skills ["base-cut", "color-certified"]
- WHEN a customer books for 10:00
- THEN the resourceAssignments MUST be: `[{Maya, 10:00-10:45}, {Maya, 11:15-11:45}]` (gap 10:45-11:15 is NOT reserved on any resource)
- AND the AvailabilityCache for Maya on that day removes both 45-minute and 30-minute blocks (plus buffers)
- AND the customer is charged for 90 minutes elapsed time (10:00-11:45), not 120 minutes

### Scenario REQ-ABK-002-02: Multi-step with different resource types

- GIVEN a Service "Engine Diagnostics" requiring: step 1 (60m, mechanic + diagnostic equipment)
- WHEN a customer books for 08:00
- THEN resourceAssignments MUST reserve: `{mechanic: Piet, equipment: DiagnosticBay-B}` for the same 08:00-09:00 block
- AND both resources' AvailabilityCaches are invalidated for that time slot

---

## REQ-ABK-003: Skill-routing gates resource eligibility

Availability queries consult skill-routing to ensure only resources with required skills are offered.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/appointment-booking/design.md#AvailabilityQueryService`
**Files**: `lib/Service/AvailabilityQueryService.php`

### Scenario REQ-ABK-003-01: Only certified stylists offered for color service

- GIVEN a Service "Hair Color" requiring skill "color-certified"
- AND three stylists: Alex (has color-certified), Maya (no color-certified), Petra (has color-certified)
- WHEN the customer views available slots
- THEN the engine MUST query skill-routing for resources matching `color-certified`
- AND only Alex and Petra's free slots are offered
- AND Maya is never mentioned in available slots, even if she is free

---

## REQ-ABK-004: Bi-directional calendar sync blocks availability

A Resource with `calendarSyncId` synced to Outlook/Google/iCloud: blocking events (lunch, meetings, vacation) pulled by email-calendar-sync immediately make those time blocks unavailable for booking.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/appointment-booking/design.md#AvailabilityQueryService`
**Files**: `lib/Service/AvailabilityQueryService.php` (consumes email-calendar-sync blocks)

### Scenario REQ-ABK-004-01: Lunch block prevents booking

- GIVEN a Resource "Alex" with `calendarSyncId` linked to Alex's Outlook calendar
- AND Alex's Outlook calendar has an event "Lunch" 12:00-13:00 on Tuesday
- WHEN email-calendar-sync pulls events from Outlook (within 5 minutes)
- THEN the AvailabilityCache for Alex on Tuesday MUST remove the 12:00-13:00 block
- AND a customer viewing available slots for Tuesday MUST NOT see any slots in the 12:00-13:00 range for Alex
- AND other resources' availability for 12:00-13:00 is unaffected

### Scenario REQ-ABK-004-02: Vacation blocks entire date range

- GIVEN a Resource "Maya" with a vacation entry `{startDate: 2026-06-20, endDate: 2026-07-05}`
- WHEN a customer tries to book Maya for any date between June 20 and July 5
- THEN NO slots are offered for Maya on any of those dates
- AND other resources' availability is unaffected

---

## REQ-ABK-005: Confirmation and reminder flows

Confirmation emails are sent immediately on booking. Reminder emails are sent 24 hours before the appointment start time.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/appointment-booking/design.md#BookingService`
**Files**: `lib/Service/BookingService.php`, `lib/BackgroundJob/ReminderEmailJob.php`

### Scenario REQ-ABK-005-01: Confirmation sent immediately

- GIVEN a customer completes a booking form for Tuesday 14:00
- WHEN the Booking is created
- THEN an email MUST be sent immediately to the customer's email address
- AND the email subject MUST be "Your appointment is confirmed: [Service Name]"
- AND the email body MUST include: service name, date, time, resource (stylist/mechanic/etc.) name, location (if available)
- AND `confirmationSentAt` MUST be set to the current timestamp

### Scenario REQ-ABK-005-02: Reminder sent 24 hours before

- GIVEN a confirmed Booking for Tuesday 14:00
- WHEN the ReminderEmailJob runs (executed daily via ITimedJob)
- AND the current time is Monday 14:00 (exactly 24 hours before)
- THEN a reminder email MUST be sent to the customer
- AND the email MUST include: service name, date, time, location, and a signed link to reschedule or cancel
- AND `reminderSentAt` MUST be set

### Scenario REQ-ABK-005-03: Reschedule link valid without login

- GIVEN a reminder email with a signed reschedule link `https://tenant.pipelinq.nl/booking/ABC123/reschedule?token=signed-xyz`
- WHEN the customer clicks the link
- THEN the system MUST verify the signature (valid 30 days from creation)
- AND the customer sees a reschedule form (new date/time picker) WITHOUT needing to log in
- AND on reschedule form submission, the original Booking is marked `rescheduled` and a new Booking is created

### Scenario REQ-ABK-005-04: Cancel link valid without login

- GIVEN a confirmation or reminder email with a signed cancel link
- WHEN the customer clicks the link
- THEN the system MUST verify the signature
- AND the customer sees a cancellation confirmation form with the cancellation policy displayed
- AND on confirmation, the Booking is marked `cancelled-by-customer` and the slot is freed

---

## REQ-ABK-006: Reschedule preserves audit trail

When a customer reschedules, the original Booking transitions to `rescheduled` status and a new Booking is created with `previousBookingId` pointing to the original.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/appointment-booking/design.md#BookingService`
**Files**: `lib/Service/BookingService.php`

### Scenario REQ-ABK-006-01: Original Booking marked rescheduled

- GIVEN a confirmed Booking (ID: booking-001) for Tuesday 14:00
- WHEN the customer reschedules to Thursday 10:00 via the signed email link
- THEN Booking booking-001 MUST transition to status `rescheduled`
- AND a new Booking (ID: booking-002) is created for Thursday 10:00
- AND booking-002.previousBookingId MUST reference booking-001
- AND the Tuesday 14:00 slot is freed in AvailabilityCache (Alex's availability on Tuesday increases)

### Scenario REQ-ABK-006-02: Staff calendar event is moved

- GIVEN a staff Resource "Alex" with `calendarSyncId` linked to Outlook
- AND a Booking for Tuesday 14:00 created a calendar event in Alex's Outlook
- WHEN the customer reschedules to Thursday 10:00
- THEN the calendar event MUST be MOVED (not duplicated) to Thursday 10:00 in Outlook
- AND the calendar event title and attendees remain the same

---

## REQ-ABK-007: No-show tracking and optional fee

When a Booking passes its start time without being marked completed by staff, the operator can mark it no-show. The Customer's lifetime no-show count increments. If a no-show fee is configured and a payment method is on file, the fee is charged.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/appointment-booking/design.md#BookingService, PaymentService`
**Files**: `lib/Service/BookingService.php`, `lib/Service/PaymentService.php`

### Scenario REQ-ABK-007-01: No-show mark increments customer counter

- GIVEN a confirmed Booking for 10:00 (now 10:35, appointment is 35 minutes overdue)
- AND the staff has NOT marked the Booking `completed`
- WHEN the operator clicks "Mark as no-show" in the dashboard
- THEN the Booking status MUST transition to `no-show`
- AND the Customer record's `noShowCount` field MUST increment by 1
- AND the Booking's audit trail MUST record: who marked it no-show, timestamp

### Scenario REQ-ABK-007-02: No-show fee charged if payment method on file

- GIVEN a Service with `noShowFee: 30.00`
- AND a Booking with `status: no-show`
- AND the Customer has a payment method on file (from a prior deposit payment)
- WHEN the operator marks the Booking no-show
- THEN the system MUST call openconnector to charge 30.00 EUR
- AND on success, Booking.noShowFeeChargedAt MUST be set to the current timestamp
- AND the customer receives a notification: "No-show fee of €30.00 has been charged to your account"

### Scenario REQ-ABK-007-03: No charge if no payment method on file

- GIVEN a Booking with `status: no-show`, no `noShowFeeChargedAt`, and no stored payment method
- WHEN the operator marks the Booking no-show
- THEN NO payment charge is attempted
- AND the Booking remains marked `no-show`, ready for manual follow-up

---

## REQ-ABK-008: Walk-in queue mixes with appointments

A barbershop or urgent-care business can accept walk-ins via a digital queue. The engine estimates wait time based on existing appointments and staff gaps.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/appointment-booking/design.md#WalkInQueueService`
**Files**: `lib/Service/WalkInQueueService.php`, `lib/Controller/WalkInQueueController.php`

### Scenario REQ-ABK-008-01: Walk-in ticket created with estimated ready time

- GIVEN a barbershop with 2 staff (Alex and Maya) and 3 appointments this afternoon: Alex busy 14:00-14:30, 15:00-15:30; Maya busy 14:15-14:45
- AND a walk-in arrives at 14:20 for "Haircut" (30 min service)
- WHEN the operator creates a WalkInTicket
- THEN a ticket is created with `status: waiting`, `arrivedAt: 14:20`, `serviceId: haircut-uuid`
- AND the system scans both staff's upcoming gaps:
  - Alex: 14:30-15:00 (30 min free) — can fit walk-in, ready at 14:30
  - Maya: 14:45-next (free) — can fit walk-in, ready at 14:45
- AND `estimatedReadyAt` is set to 14:30 (earliest available)

### Scenario REQ-ABK-008-02: Walk-in assigned to resource

- GIVEN a waiting WalkInTicket with estimatedReadyAt 14:30
- WHEN the operator assigns the ticket to Alex
- THEN ticket.assignedResourceId is set to Alex's UUID
- AND the ticket status updates in the queue display

### Scenario REQ-ABK-008-03: Queue re-balances when appointment completes

- GIVEN three waiting walk-in tickets with Alex assigned to the first one
- AND Alex's current appointment ends at 14:30
- WHEN Alex marks their appointment as completed (or the system auto-completes it)
- THEN the WalkInQueueService.updateWaitingQueue(Alex's UUID) is called
- AND remaining walk-in tickets' estimatedReadyAt times are recomputed
- AND the operator sees updated wait times in the queue view

---

## REQ-ABK-009: Cancellation policy enforces business rules

A Service defines a cancellation policy: free-until-N-hours-before, always-charge, or no-charge. The booking system enforces the policy when a customer cancels.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/appointment-booking/design.md#BookingService, PaymentService`
**Files**: `lib/Service/BookingService.php`, `lib/Service/PaymentService.php`

### Scenario REQ-ABK-009-01: Free cancellation within allowed window

- GIVEN a Service with `cancellationPolicy: "free-until-24-hours-before"` and price 50 EUR
- AND a confirmed Booking starting in 48 hours
- WHEN the customer clicks "Cancel" and confirms
- THEN the Booking transitions to `cancelled-by-customer`
- AND NO charge is applied (cancellation is free)
- AND the customer receives confirmation: "Your appointment has been cancelled. No charge."
- AND the slot is freed in AvailabilityCache

### Scenario REQ-ABK-009-02: Late cancellation charge enforced

- GIVEN the same Service and policy, but the Booking now starts in 18 hours (within the 24-hour charge window)
- WHEN the customer cancels
- THEN a cancellation form shows: "Cancellation within 24 hours incurs a charge of €50.00"
- AND the customer MUST confirm they accept the charge
- THEN the charge is applied via openconnector
- AND the Booking transitions to `cancelled-by-customer`

### Scenario REQ-ABK-009-03: Always-charge policy

- GIVEN a Service with `cancellationPolicy: "always-charge"` and price 100 EUR
- AND a confirmed Booking
- WHEN the customer cancels (regardless of timing)
- THEN the full price is charged
- AND the Booking is marked `cancelled-by-customer`

### Scenario REQ-ABK-009-04: No-charge policy

- GIVEN a Service with `cancellationPolicy: "no-charge"`
- WHEN the customer cancels
- THEN NO charge is applied
- AND the Booking is marked `cancelled-by-customer`

---

## REQ-ABK-010: Deposit-required booking holds slot pending payment

A Service with `requiresDeposit: true` requires payment before the Booking is confirmed. The system holds the slot for 15 minutes. On payment success, status becomes `confirmed`. On timeout or failure, the Booking is cancelled and the slot is released.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/appointment-booking/design.md#PaymentService`
**Files**: `lib/Service/BookingService.php`, `lib/Service/PaymentService.php`

### Scenario REQ-ABK-010-01: Booking created in pending-deposit status

- GIVEN a Service "Color Treatment" with `requiresDeposit: true` and `depositAmount: 25.00`
- WHEN a customer submits the booking form
- THEN the Booking is created with `status: pending-deposit`
- AND the slot is held in AvailabilityCache (not released for 15 minutes)
- AND a payment session is initiated via openconnector (Mollie/Stripe)
- AND the customer is redirected to the payment gateway

### Scenario REQ-ABK-010-02: Confirmation on payment success

- GIVEN a Booking with `status: pending-deposit`
- WHEN the customer completes payment in Mollie (or Stripe)
- AND Mollie posts a payment confirmation webhook to Pipelinq
- THEN the Booking status MUST transition to `confirmed`
- AND Booking.depositPaidAt is set to the current timestamp
- AND a confirmation email is sent immediately
- AND the slot is held permanently (not released after 15 minutes)

### Scenario REQ-ABK-010-03: Timeout expires booking after 15 minutes

- GIVEN a Booking with `status: pending-deposit` created at 10:00
- WHEN 15 minutes elapse (now 10:15) and the customer has NOT paid
- THEN a cleanup job MUST find this unpaid Booking
- AND the Booking transitions to `cancelled-by-business` with reason "Payment timeout"
- AND the slot is released in AvailabilityCache
- AND the customer receives a notification: "Your booking has been cancelled due to unpaid deposit"

### Scenario REQ-ABK-010-04: Payment failure cancels booking

- GIVEN a Booking with `status: pending-deposit`
- WHEN the customer's payment fails in the payment gateway (e.g., "Card declined")
- AND the payment provider posts a failure webhook
- THEN the Booking transitions to `cancelled-by-business` with reason "Payment failed"
- AND a notification is sent to the customer with an option to retry

---

## REQ-ABK-011: Customer timeline shows linked Bookings

The Customer detail view (from pipelinq-base) displays a timeline section showing past and upcoming Bookings.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/appointment-booking/design.md#Customer Integration`
**Files**: `pipelinq/src/views/ClientDetail.vue` (timeline card component)

### Scenario REQ-ABK-011-01: Upcoming bookings visible

- GIVEN a Customer "Jan Pieterzoon" with 2 upcoming Bookings (Tuesday 14:00 "Haircut", Thursday 10:00 "Color Treatment")
- WHEN an agent views the Customer detail page
- THEN a "Appointments" section appears in the timeline
- AND both upcoming bookings are listed with: date, time, service name, resource (stylist) name
- AND clicking a booking shows full details (notes, cancellation policy, status)

### Scenario REQ-ABK-011-02: Past bookings and no-shows tracked

- GIVEN a Customer with past Bookings (some completed, some marked no-show)
- WHEN the Customer detail timeline is displayed
- THEN past bookings appear below upcoming appointments
- AND no-show bookings are visually distinguished (e.g., red color or "No-show" badge)
- AND a summary card shows: "Lifetime bookings: 12", "No-shows: 2", "Last appointment: 2026-05-15"

---

## REQ-ABK-012: Booking portal is WCAG 2.1 AA compliant

The public booking portal passes WCAG 2.1 AA accessibility standards: keyboard navigation, screen reader friendly, sufficient color contrast.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/appointment-booking/design.md#Public Portal`
**Files**: `resources/js/views/BookingPortal.vue`
**Standards**: WCAG 2.1 AA, axe-core validation

### Scenario REQ-ABK-012-01: Portal keyboard navigable

- GIVEN a user with keyboard-only navigation
- WHEN they visit the booking portal
- THEN all form fields, buttons, and links MUST be reachable via Tab key
- AND form submission MUST be possible via Enter key on the submit button
- AND focus indicators MUST be visible (not removed with `outline: none`)

### Scenario REQ-ABK-012-02: Screen reader support

- GIVEN a user with NVDA or JAWS screen reader
- WHEN they navigate the booking portal
- THEN all form labels MUST be properly associated with inputs (via `<label for>`)
- AND button text MUST be descriptive ("Confirm Booking", not "Submit")
- AND form errors MUST be announced by the screen reader (aria-live region)

### Scenario REQ-ABK-012-03: Color contrast meets AA standard

- GIVEN the booking portal CSS
- WHEN the colors are tested with axe-core or WCAG contrast checker
- THEN normal text MUST have at least 4.5:1 contrast ratio (WCAG AA)
- AND large text (18pt+) MUST have at least 3:1 contrast ratio
- AND buttons and interactive elements MUST not rely solely on color to convey state (e.g., also use icon or text)

---

## REQ-ABK-013: Compliance and Data Retention

Bookings and customer contact data are retained for 7 years (NL Boekhoudplicht). Right-to-be-forgotten requests pseudonymize Booking history.

**Feature tier**: MVP
**Spec ref**: `openspec/changes/appointment-booking/proposal.md#Standards`
**Files**: `lib/Service/BookingService.php`, `lib/Controller/GdprController.php`

### Scenario REQ-ABK-013-01: Booking data retained 7 years

- GIVEN a completed Booking from 2019
- WHEN a compliance audit queries Booking history
- THEN the Booking record MUST still exist (not deleted) in the database
- AND the audit trail (who created it, who modified it, timestamps) MUST be intact

### Scenario REQ-ABK-013-02: Right-to-be-forgotten pseudonymizes

- GIVEN a Customer "Maria de Vries" with 15 past Bookings
- WHEN Maria requests right-to-be-forgotten (GDPR article 17)
- THEN the system MUST pseudonymize her Booking history:
  - Replace `customerId` with a hash
  - Replace `notes` field with "***" (clear content)
  - Clear name and email from AvailabilityCache (if stored)
  - Keep `createdAt`, `status`, `service`, `resource` for compliance reporting
- AND Maria's Customer record in client-management is marked deleted
- AND future Bookings for Maria cannot be created (her ID is retired)

### Scenario REQ-ABK-013-03: Deposit and payment data retention

- GIVEN a Booking with `depositPaidAt` timestamp
- WHEN the payment method is captured from the payment provider
- THEN payment card details (PAN, CVV) MUST NOT be stored in Pipelinq
- AND only the masked card (last 4 digits) and payment reference from the provider are stored
- AND payment records are retained in the openconnector audit trail (not in Pipelinq's own tables)
