# Appointment Booking — Delta Spec

## Purpose

Implement appointment scheduling in Pipelinq by adding Service, Resource, Booking, and WalkInTicket entities with availability computation, a public self-booking portal, and email/calendar workflows for confirmations, reminders, reschedules, and no-show tracking.

**Leaf-first boundary (ADR-022).** Calendar read/write and email dispatch are NOT built in pipelinq. They are delegated to `email-calendar-sync`, which consumes the OpenRegister `calendar` (`integration-calendar`) and `email` (`integration-email`) integration leaves. Pipelinq builds only the genuinely app-specific booking domain — Service/Resource/Booking/WalkInTicket entities, 15-minute slot computation, skill-based routing, deposit holds, no-show tracking, the walk-in queue, and the public portal. Calendar VEVENT linkage and `.ics`/confirmation email mechanics belong to the leaves.

**Standards**: Schema.org (`schema:Event`, `schema:Service`, `schema:LocalBusiness`), iCalendar (RFC 5545 for `.ics` attachments), vCard email matching, Dutch locale formatting (WCAG 2.1 AA for accessibility)
**Feature tier**: V1 (core booking, portal, email flows), V2 (automation triggers, advanced rules), V3 (mobile app, waitlist)
**Entities used**: Service, Resource, Booking, WalkInTicket (new to Pipelinq); Customer (from client-management)
**OCP interfaces**: `IUserManager`, `IUserSession`, `IAppConfig`, `ICacheFactory`

---

## ADDED Requirements

---

### Requirement: REQ-APT-001 Service Entity Schema (renumbered for delta validation)

The system MUST support Service entities with configurable duration, pricing, skills, multi-step composition, and booking policies.

**Feature tier**: V1

#### Scenario: Service with simple duration

- **GIVEN** a Service is created with `name: "Haircut"`, `durationMinutes: 30`, `price: 25.00`, `bookableOnline: true`
- **WHEN** the Service is saved in OpenRegister
- **THEN** the Service MUST be queryable and usable in availability computations

#### Scenario: Multi-step service with skill requirements

- **GIVEN** a Service is created with `multiStep: [{45m, color-certified}, {gap, allowGap: true}, {15m, any-stylist}]` and `requiredSkills: ["color-certified"]`
- **WHEN** availability is computed for this Service
- **THEN** the first 45 minutes MUST require a resource with "color-certified" skill, the gap 30m MUST be unblocked (other staff can use that time), and the final 15m can use any stylist

---

### Requirement: REQ-APT-002 Resource Entity Schema

The system MUST support Resource entities (staff, room, equipment) with working hours, vacations, skills, and optional calendar sync.

**Feature tier**: V1

#### Scenario: Staff resource with working hours

- **GIVEN** a Resource is created with `type: "staff"`, `name: "Sarah"`, `workingHours: [{day: monday, openTime: 09:00, closeTime: 17:30}, ...]`
- **WHEN** availability is computed for that resource on a Monday
- **THEN** no slots MUST be available before 09:00 or after 17:30

#### Scenario: Resource with vacation blocks

- **GIVEN** a Resource has `vacations: [{startDate: 2026-06-01, endDate: 2026-06-15}]`
- **WHEN** availability is computed for June 10
- **THEN** no slots MUST be available for that date

#### Scenario: Resource linked to calendar sync

- **GIVEN** a Resource is created with `calendarSyncId: "sarah@nextcloud.local"` and email-calendar-sync has indexed her lunch block 12:00-13:00
- **WHEN** availability is computed
- **THEN** no slots MUST overlap 12:00-13:00

---

### Requirement: REQ-APT-003 Availability Computation

The system MUST compute available 15-minute-aligned slots per resource per date by intersecting working hours, vacations, booked times, and calendar-synced blocks.

**Feature tier**: V1

#### Scenario: Slots computed from working hours and existing bookings

- **GIVEN** a Resource works 09:00-17:30 and has a 30-minute booking 10:00-10:30
- **WHEN** availability is computed for that date with a 45-minute Service
- **THEN** a slot at 09:00-09:45 MUST be available (before the booking), and a slot at 10:30-11:15 MUST be available (after the booking); no slot at 09:45-10:30 (overlaps booking)

#### Scenario: Slots are 15-minute-aligned

- **GIVEN** a Resource is available 09:00-12:00 and a Service needs 45 minutes
- **WHEN** availability is computed
- **THEN** available start times MUST be `09:00, 09:15, 09:30, 09:45, 10:00, 10:15, 10:30, 10:45, 11:15` (only 15-minute boundaries, and only times where the full 45 minutes fit)

#### Scenario: Buffers are applied

- **GIVEN** a Service has `bufferBeforeMinutes: 10, bufferAfterMinutes: 5`, requires 30 minutes, and a Resource is available 09:00-12:00
- **WHEN** availability is computed
- **THEN** a booking from 09:30-10:00 MUST actually block 09:20-10:05 (buffer_before + duration + buffer_after)

---

### Requirement: REQ-APT-004 Skill-Based Routing

The system MUST query skill-routing to determine which Resources are eligible for a Service, then intersect with availability.

**Feature tier**: V1

#### Scenario: Service requires skill, only eligible resources shown

- **GIVEN** a Service requires `requiredSkills: ["color-certified"]` and the tenant has three stylists (two with that skill, one without)
- **WHEN** `BookingService::getAvailableSlots()` is called for that Service
- **THEN** available slots MUST only include the two certified stylists, never the uncertified one

#### Scenario: Multi-step service with varying skill requirements

- **GIVEN** a Service has `multiStep: [{step 1, color-certified}, {step 2, gap}, {step 3, any-stylist}]`
- **WHEN** availability is computed
- **THEN** step 1 MUST use only color-certified resources, step 3 MUST use any stylist

---

### Requirement: REQ-APT-005 Public Booking Portal

The system MUST provide a public, unauthenticated web portal at `/book/{serviceSlug}` where customers self-book appointments.

**Feature tier**: V1

#### Scenario: Customer books without login

- **GIVEN** a Service with `bookableOnline: true` and available slots exist next Tuesday
- **WHEN** a customer visits `/book/haircut`
- **THEN** they MUST see the service details, a date picker, an available slot picker (15-minute intervals), and a form for name+email+phone
- **AND** they MUST NOT be required to log in or create an account

#### Scenario: Unavailable dates are disabled in picker

- **GIVEN** available slots exist only on Tuesday and Thursday
- **WHEN** the customer views the date picker
- **THEN** dates other than Tuesday/Thursday MUST be visually disabled or grayed out

#### Scenario: Submitting booking creates Booking record

- **GIVEN** the customer fills the booking form and clicks submit
- **WHEN** `POST /portal/book` is called
- **THEN** a Booking MUST be created with `status: pending-deposit` (if deposit required) or `status: confirmed` (if no deposit), `source: "portal"`, and the customer's email/phone linked

---

### Requirement: REQ-APT-006 Booking Confirmation Email

The system MUST send a confirmation email immediately after a Booking is created or deposit is paid, including an `.ics` calendar attachment and signed reschedule/cancel links.

**Feature tier**: V1

#### Scenario: Confirmation email sent on booking creation

- **GIVEN** a Booking is created with `status: "confirmed"`
- **WHEN** the create action completes
- **THEN** a confirmation email MUST be sent to the customer within 1 minute
- **AND** `confirmationSentAt` MUST be set on the Booking
- **AND** the email MUST include: service name, date/time, resource name, price, and signed deep-links for reschedule and cancel

#### Scenario: Email includes `.ics` attachment

- **GIVEN** the confirmation email is being sent
- **WHEN** the email is composed
- **THEN** it MUST include an `.ics` (iCalendar) attachment per RFC 5545 so customers can add the appointment to their calendar without re-entering details

---

### Requirement: REQ-APT-007 Reminder Email and SMS

The system MUST send a 24-hour reminder email and optional SMS before each appointment.

**Feature tier**: V1

#### Scenario: Reminder sent 24 hours before appointment

- **GIVEN** a confirmed Booking with `startAt: 2026-05-25T14:00:00Z`
- **WHEN** the ReminderDispatchJob runs on 2026-05-24 14:00 (24 hours before)
- **THEN** a reminder email MUST be sent
- **AND** `reminderSentAt` MUST be set on the Booking
- **AND** if SMS is configured, an SMS reminder MUST also be sent

#### Scenario: Reminder includes reschedule/cancel links

- **GIVEN** the reminder is being sent
- **WHEN** the email is composed
- **THEN** it MUST include the same signed deep-links for reschedule and cancel as the confirmation email

---

### Requirement: REQ-APT-008 Reschedule via Signed Link

The system MUST allow customers to reschedule appointments via a signed email link without logging in, preserving the audit trail by marking the original Booking as rescheduled and creating a new Booking.

**Feature tier**: V1

#### Scenario: Customer reschedules to new time

- **GIVEN** a customer receives a reschedule link and visits it
- **WHEN** they pick a new date/time and confirm
- **THEN** the original Booking MUST transition to `status: "rescheduled"`, a new Booking MUST be created for the new time with `previousBookingId` pointing at the original, the old time slot MUST be freed, and the staff's calendar event MUST be moved (not duplicated)

#### Scenario: Rescheduled booking inherits customer and service

- **GIVEN** the new Booking is created during reschedule
- **WHEN** it is saved
- **THEN** it MUST have the same `customerId` and `serviceId` as the original, but a new `startAt`/`endAt` and `resourceAssignments`

---

### Requirement: REQ-APT-009 Cancellation with Policy Enforcement

The system MUST enforce configurable cancellation policies: free-until-N-hours-before, always-charge, or no-charge. Late cancellations trigger optional payment charges.

**Feature tier**: V1

#### Scenario: Free cancellation within policy window

- **GIVEN** a Service with `cancellationPolicy: "free-until-24-hours-before"` and a Booking starting in 48 hours
- **WHEN** the customer cancels via the signed link
- **THEN** the system MUST show the policy (cancellation is free), the Booking MUST transition to `cancelled-by-customer`, and NO charge MUST be applied

#### Scenario: Late cancellation triggers charge

- **GIVEN** a Service with `cancellationPolicy: "free-until-24-hours-before"`, `price: 50.00`, and a Booking starting in 18 hours (within the 24-hour window)
- **WHEN** the customer cancels via the signed link
- **THEN** the system MUST show the policy (cancellation will charge 50 EUR), the customer must confirm, and a 50 EUR charge MUST be queued via openconnector

#### Scenario: Staff can cancel anytime (e.g., for emergencies)

- **GIVEN** a confirmed Booking
- **WHEN** a staff member opens the Booking detail page and clicks "Cancel"
- **THEN** the Booking MUST transition to `cancelled-by-business` without a charge, regardless of policy

---

### Requirement: REQ-APT-010 Deposit-Required Bookings

The system MUST support optional deposits: bookings requiring deposits are created with `status: "pending-deposit"`, the slot is held for 15 minutes, and on successful payment `status` transitions to `confirmed`.

**Feature tier**: V1

#### Scenario: Deposit required booking holds slot

- **GIVEN** a Service with `requiresDeposit: true`, `depositAmount: 20.00`
- **WHEN** a customer submits the booking form
- **THEN** a Booking MUST be created with `status: "pending-deposit"`, the slot MUST be held (no other booking can claim it for 15 minutes), and a payment session MUST be initiated via openconnector

#### Scenario: Slot released on payment timeout

- **GIVEN** a Booking with `status: "pending-deposit"` and payment was not completed within 15 minutes
- **WHEN** 15 minutes have elapsed
- **THEN** the Booking MUST transition to `cancelled-by-business`, the slot MUST be released, and the customer may see it available again

#### Scenario: Status transitions to confirmed on payment success

- **GIVEN** a Booking with `status: "pending-deposit"` and the customer completes PSD2-compliant payment
- **WHEN** openconnector confirms payment
- **THEN** the Booking MUST transition to `status: "confirmed"`, `depositPaidAt` MUST be set, and confirmation email MUST be sent

---

### Requirement: REQ-APT-011 No-Show Tracking and Fees

The system MUST track no-shows, increment customer lifetime no-show count, and optionally charge fees.

**Feature tier**: V1

#### Scenario: Staff marks booking as no-show

- **GIVEN** a confirmed Booking with `startAt` 30 minutes in the past and the staff has not marked it completed
- **WHEN** staff clicks "Mark as no-show" on the Booking detail page
- **THEN** the Booking status MUST transition to `no-show`, the customer's no-show count (on their Customer record) MUST increment by 1, and `noShowFeeChargedAt` MUST be set

#### Scenario: No-show fee is charged if configured

- **GIVEN** a Service with `noShowFee: 25.00` and a Booking is marked no-show with a payment method on file
- **WHEN** the no-show status is set
- **THEN** a 25 EUR charge MUST be queued via openconnector with `noShowFeeChargedAt` set on success

#### Scenario: No-show without payment method is logged but not charged

- **GIVEN** a Booking is marked no-show and the customer has no payment method on file
- **WHEN** the status is set
- **THEN** the no-show MUST be recorded, the count incremented, but no charge MUST be attempted

---

### Requirement: REQ-APT-012 Walk-In Queue

The system MUST support walk-in arrivals (WalkInTicket) for businesses that mix scheduled and unscheduled service.

**Feature tier**: V1

#### Scenario: Walk-in ticket created on arrival

- **GIVEN** a customer walks into a barbershop without an appointment
- **WHEN** staff creates a WalkInTicket with `serviceId: (haircut)`, `customerId: (optional)`, `displayName: "Mr. Jansen"`
- **THEN** a WalkInTicket MUST be created with `status: "waiting"`, `arrivedAt: now`, and `estimatedReadyAt` computed from gaps in the current schedule

#### Scenario: Queue rebalances as appointments complete

- **GIVEN** the queue has 3 waiting customers (estimated wait times based on current schedule)
- **WHEN** a staff member completes an appointment (Booking status → completed)
- **THEN** the WalkInQueueRebalanceJob MUST recalculate `estimatedReadyAt` for all waiting tickets

#### Scenario: Staff calls next customer from queue

- **GIVEN** the WalkInQueuePanel is displayed and a "Call next" button is visible
- **WHEN** staff clicks "Call next"
- **THEN** the first waiting WalkInTicket MUST transition to `status: "called"`, and optionally emit a notification/sound for that customer

---

### Requirement: REQ-APT-013 Booking Status Lifecycle

The system MUST enforce a valid status transition flow: pending-deposit → confirmed → completed/no-show/cancelled, with rescheduled as a parallel branch.

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
- **THEN** `statusHistory` MUST be appended with `{status, changedAt, changedBy, reason}` for full audit trail

---

### Requirement: REQ-APT-014 Customer Timeline Integration

The system MUST display all Bookings on the Customer detail page in the CRM, showing past and future appointments with status, service, resource, and time.

**Feature tier**: V1

#### Scenario: Bookings visible on customer detail

- **GIVEN** a Customer has 5 past and 2 future Bookings
- **WHEN** an agent opens the Customer detail page
- **THEN** a "Bookings" section MUST display all 7 bookings chronologically with: service name, resource name, date/time, status badge, and a link to the Booking detail

#### Scenario: Bookings section shows empty state if none exist

- **GIVEN** a Customer with no Bookings
- **WHEN** the Customer detail page is loaded
- **THEN** the Bookings section MUST display an empty state message (not an error)

---

### Requirement: REQ-APT-015 Admin Booking Management

The system MUST provide admin views for staff to manage Services, Resources, and Bookings: list, create, edit, and delete.

**Feature tier**: V1

#### Scenario: Service list shows all services with filters

- **GIVEN** the admin opens the Services list page
- **WHEN** the page loads
- **THEN** a `CnIndexPage` list MUST display all Services with columns for name, duration, price, and status
- **AND** filters MUST allow by status (active/archived) and bookableOnline (true/false)

#### Scenario: Service detail allows editing all fields

- **GIVEN** an admin opens a Service detail page in edit mode
- **WHEN** they modify name, price, or multiStep configuration
- **THEN** changes MUST be saved to OpenRegister, the AvailabilityCache MUST be invalidated for all Resources using that Service, and the portal MUST reflect the changes immediately

#### Scenario: Booking detail shows status actions

- **GIVEN** an admin opens a confirmed Booking detail page
- **WHEN** the booking is in the future
- **THEN** buttons for "Mark completed", "Mark no-show", "Reschedule", and "Cancel" MUST be available

---

### Requirement: REQ-APT-016 AvailabilityCache

The system MUST maintain a read-only cache of free slots per resource per date for sub-second queries. Cache is regenerated on Resource/Booking/vacation changes and expires after 24 hours.

**Feature tier**: V1

#### Scenario: Cache is populated from service availability computation

- **GIVEN** availability is computed for resource X on date Y
- **WHEN** the computation completes
- **THEN** an AvailabilityCache record MUST be stored with freeBlocks array and `expiresAt: now + 24h`

#### Scenario: Cache is invalidated on booking creation

- **GIVEN** a new Booking is created on resource X for date Y
- **WHEN** the Booking is saved
- **THEN** the AvailabilityCache record for (X, Y) MUST be invalidated (deleted or marked stale)

#### Scenario: Stale cache is still usable until refreshed

- **GIVEN** an AvailabilityCache entry exists but is older than 24 hours
- **WHEN** availability is queried for that date
- **THEN** the stale cache MUST still be returned (to avoid extra computation), but the next change event MUST trigger a refresh

---

### Requirement: REQ-APT-017 Compliance and Audit Trails

The system MUST provide full audit trails for regulatory compliance: AVG right-to-be-forgotten (pseudonymize, not delete), NL Boekhoudplicht 7-year retention, and WCAG 2.1 AA accessibility on the public portal.

**Feature tier**: V1

#### Scenario: Audit trail includes all status changes

- **GIVEN** a Booking changes from confirmed → no-show → cancelled
- **WHEN** the changes are made
- **THEN** `statusHistory` MUST include all transitions with timestamps and who initiated each change

#### Scenario: AVG right-to-be-forgotten pseudonymizes data

- **GIVEN** a customer exercises right-to-be-forgotten
- **WHEN** the request is processed
- **THEN** customer name, email, and phone on Bookings MUST be replaced with hashes (e.g., `sha256(email)`), but Booking records themselves MUST NOT be deleted (7-year retention for Boekhoudplicht)
- **AND** aggregates (counts, totals) MUST remain unchanged

#### Scenario: Portal is WCAG 2.1 AA accessible

- **GIVEN** the public booking portal is tested with axe-core and keyboard navigation
- **WHEN** the tests run
- **THEN** zero WCAG AA violations MUST be found
- **AND** all form fields MUST have associated labels, colors MUST NOT be the sole method of conveying information, and screen reader announcements MUST be present

---

### Requirement: REQ-APT-018 Bi-Directional Calendar Sync

**Leaf-first (ADR-022).** Calendar read/write is NOT implemented in pipelinq. Staff calendar blocks are read, and Booking events are written, through the OpenRegister **`calendar` leaf** (`integration-calendar`) — mediated by `email-calendar-sync`, which itself consumes the calendar leaf's `CalendarProvider`/VEVENT link+create API. Pipelinq MUST NOT add its own CalDAV client or `X-PIPELINQ-*` VEVENT properties (that would reproduce the ADR-022 "parallel link table" / "duplicate CalDAV sync" anti-pattern observed on decidesk). Pipelinq owns only the availability-cache invalidation that reacts to the leaf's synced blocks.

The system MUST react to staff calendar blocks (vacation, lunch, meetings) synced by the `calendar` leaf every 5 minutes, and MUST push created Bookings back to staff calendars by creating VEVENTs through the leaf.

**Feature tier**: V1

#### Scenario: Calendar-synced block is respected in availability

- **GIVEN** a staff member has a "lunch" event 12:00-13:00 in their calendar
- **WHEN** the `calendar` leaf (via email-calendar-sync) syncs the calendar
- **THEN** within 5 minutes the AvailabilityCache MUST be invalidated, and no customer can book that staff member 12:00-13:00

#### Scenario: Booking is pushed to staff calendar via the leaf

- **GIVEN** a Booking is created for staff member X on 2026-05-25 14:00
- **WHEN** the Booking is confirmed
- **THEN** a VEVENT MUST be created in X's calendar **through the `calendar` leaf's create API** (not a pipelinq-local CalDAV write) with customer name, service, and a deep-link back to the Booking in Pipelinq

---

### Requirement: REQ-APT-019 Unit Tests and Code Quality

Every new PHP service, controller, and background job MUST have PHPUnit tests with at least 3 test methods. Code MUST pass phpcs, phpstan, and phpmd linters.

**Feature tier**: V1

#### Scenario: Service logic is tested

- **GIVEN** `AvailabilityServiceTest.php` and `BookingServiceTest.php` exist
- **WHEN** `composer test` runs
- **THEN** all tests MUST pass with ≥80% code coverage for services

#### Scenario: Controller endpoints are tested

- **GIVEN** `PortalControllerTest.php` exists
- **WHEN** tests run
- **THEN** each endpoint (GET /portal/services, POST /portal/book, etc.) MUST have ≥2 test cases (success + error)

#### Scenario: Code passes linters

- **GIVEN** all new PHP code is committed
- **WHEN** pre-commit hooks run
- **THEN** `phpcs`, `phpstan`, and `phpmd` MUST report zero violations

---

### Requirement: REQ-APT-020 Internationalization (i18n)

All user-visible strings in the portal, admin UI, and email templates MUST have translations in English and Dutch. No hardcoded strings.

**Feature tier**: V1

#### Scenario: Portal displays in Dutch when locale is nl

- **GIVEN** the Nextcloud instance is configured for Dutch locale
- **WHEN** a customer visits `/book/haircut`
- **THEN** all labels, buttons, and form placeholders MUST display in Dutch (e.g., "Beschikbare tijd kiezen", not "Select time")

#### Scenario: Email templates are translated

- **GIVEN** a confirmation email is sent to a customer
- **WHEN** the email is generated
- **THEN** it MUST use the customer's locale (from their Nextcloud settings if logged in, or Dutch default if not)
- **AND** all email strings (subject, body, button text) MUST be from translation files (l10n/en.json, l10n/nl.json)

---

