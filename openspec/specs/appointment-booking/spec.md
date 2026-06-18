---
status: done
---

# appointment-booking Specification

## Purpose
Provides end-to-end appointment booking with services, resources, and availability computation, plus a public self-service portal where customers book, reschedule, and cancel without logging in. Handles deposits, reminders, no-show tracking, walk-in queues, and bi-directional calendar sync, with confirmation emails, AVG-compliant retention, and admin management of services and bookings.
## Requirements
### Requirement: REQ-APT-001 Service Entity Schema

The system MUST support Service entities with configurable duration, pricing,
skills, multi-step composition, and booking policies.

**Feature tier**: V1

#### Scenario: Service with simple duration

- **GIVEN** a Service is created with `name: "Haircut"`, `durationMinutes: 30`, `price: 25.00`, `bookableOnline: true`
- **WHEN** the Service is saved in OpenRegister
- **THEN** the Service MUST be queryable and usable in availability computations

#### Scenario: Multi-step service with skill requirements

- **GIVEN** a Service is created with `multiStep: [{45m, color-certified}, {gap, allowGap: true}, {15m, any-stylist}]` and `requiredSkills: ["color-certified"]`
- **WHEN** the Service is saved
- **THEN** the multiStep array MUST be persisted with each step's `durationMinutes`, `skillRequired`, `resourceType`, and `allowGap`

### Requirement: REQ-APT-002 Resource Entity Schema

The system MUST support Resource entities (staff, room, equipment) with working
hours, vacations, skills, and optional calendar sync.

**Feature tier**: V1

#### Scenario: Staff resource with working hours

- **GIVEN** a Resource is created with `type: "staff"`, `name: "Sarah"`, `workingHours: [{day: monday, openTime: 09:00, closeTime: 17:30}, ...]`
- **WHEN** the Resource is saved
- **THEN** the workingHours array MUST be persisted and queryable per weekday

#### Scenario: Resource with vacation blocks

- **GIVEN** a Resource has `vacations: [{startDate: 2026-06-01, endDate: 2026-06-15}]`
- **WHEN** the Resource is saved
- **THEN** the vacations array MUST be persisted with its date ranges

### Requirement: REQ-APT-016 AvailabilityCache Schema

The system MUST declare a read-only AvailabilityCache schema of free slots per
resource per date for sub-second queries.

**Feature tier**: V1

#### Scenario: AvailabilityCache schema is registered

- **GIVEN** the pipelinq register manifest is loaded
- **WHEN** schemas materialise
- **THEN** an `availability-cache` schema MUST exist with fields `resourceId`, `date`, `freeBlocks` (array of `{startTime, endTime, durationMinutes, bookable}`), `generatedAt`, and `expiresAt`

#### Scenario: Seed objects are queryable

- **GIVEN** the register seed data is imported
- **WHEN** `ObjectService::findObjects('pipelinq', 'service', [])` is called
- **THEN** the four seed Services (haircut-simple, color-and-cut, oil-change-standard, consultation-tax) MUST be returned

### Requirement: REQ-APT-003 Availability Computation

The system MUST compute available 15-minute-aligned slots per resource per date by
intersecting working hours, vacations, booked times, and calendar-synced blocks.

**Feature tier**: V1

#### Scenario: Slots computed from working hours and existing bookings

- **GIVEN** a Resource works 09:00-17:30 and has a 30-minute booking 10:00-10:30
- **WHEN** availability is computed for that date with a 45-minute Service
- **THEN** a slot at 09:00-09:45 MUST be available, a slot at 10:30-11:15 MUST be available, and no slot at 09:45-10:30 (overlaps booking)

#### Scenario: Slots are 15-minute-aligned

- **GIVEN** a Resource is available 09:00-12:00 and a Service needs 45 minutes
- **WHEN** availability is computed
- **THEN** available start times MUST be `09:00, 09:15, 09:30, 09:45, 10:00, 10:15, 10:30, 10:45, 11:15` (only 15-minute boundaries where the full 45 minutes fit)

#### Scenario: Buffers are applied

- **GIVEN** a Service has `bufferBeforeMinutes: 10, bufferAfterMinutes: 5`, requires 30 minutes, and a Resource is available 09:00-12:00
- **WHEN** availability is computed
- **THEN** a booking from 09:30-10:00 MUST actually block 09:20-10:05

### Requirement: REQ-APT-016 AvailabilityCache Behaviour

The system MUST maintain a read-only cache of free slots per resource per date,
regenerated on changes and expiring after 24 hours.

**Feature tier**: V1

#### Scenario: Cache is populated from availability computation

- **GIVEN** availability is computed for resource X on date Y
- **WHEN** the computation completes
- **THEN** an AvailabilityCache record MUST be stored with `freeBlocks` and `expiresAt: now + 24h`

#### Scenario: Stale cache is still usable until refreshed

- **GIVEN** an AvailabilityCache entry exists but is older than 24 hours
- **WHEN** availability is queried for that date
- **THEN** the stale cache MUST still be returned, but the next change event MUST trigger a refresh

### Requirement: REQ-APT-004 Skill-Based Routing

The system MUST query skill-routing to determine which Resources are eligible for a
Service, then intersect with availability.

**Feature tier**: V1

#### Scenario: Service requires skill, only eligible resources shown

- **GIVEN** a Service requires `requiredSkills: ["color-certified"]` and the tenant has three stylists (two with that skill, one without)
- **WHEN** eligible resources are computed for that Service
- **THEN** the result MUST only include the two certified stylists, never the uncertified one

#### Scenario: Resource with no skills is eligible for no-skill services

- **GIVEN** a Service has empty `requiredSkills` and a Resource has no skills
- **WHEN** eligibility is computed
- **THEN** the Resource MUST be eligible

#### Scenario: Multi-step service with varying skill requirements

- **GIVEN** a Service has `multiStep: [{step 1, color-certified}, {step 2, gap}, {step 3, any-stylist}]`
- **WHEN** eligibility is computed per step
- **THEN** step 1 MUST use only color-certified resources, the gap step MUST require no resource, and step 3 MUST accept any stylist

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

### Requirement: REQ-APT-005 Public Booking API

The system MUST provide a public, unauthenticated API at `/portal/*` where customers
list services, query availability, create bookings, and reschedule/cancel via signed
links.

**Feature tier**: V1

#### Scenario: List bookable services without auth

- **GIVEN** Services with `bookableOnline: true` exist
- **WHEN** `GET /portal/services` is called without authentication
- **THEN** the response MUST be 200 with the bookable services and MUST NOT require login

#### Scenario: Submitting booking creates Booking record

- **GIVEN** a valid booking form payload (name, email, phone, serviceId, startAt)
- **WHEN** `POST /portal/book` is called
- **THEN** a Booking MUST be created with `status: pending-deposit` (if deposit required) or `status: confirmed` (if no deposit), `source: "portal"`, and the customer's email/phone linked

#### Scenario: Invalid email is rejected

- **GIVEN** a booking payload with a malformed email
- **WHEN** `POST /portal/book` is called
- **THEN** the response MUST be 400 with a static validation message (never a stack trace)

#### Scenario: Reschedule requires a valid signed link

- **GIVEN** a reschedule request with an expired or invalid HMAC signature
- **WHEN** `POST /portal/reschedule` is called
- **THEN** the response MUST be 410 (link expired/invalid)

#### Scenario: Signed links are generated with expiry

- **GIVEN** a confirmed Booking
- **WHEN** reschedule/cancel deep-links are generated
- **THEN** they MUST be signed with HMAC-SHA256 via `IURLGenerator` and MUST expire after 30 days

### Requirement: REQ-APT-005A Public Booking Portal UI

The system MUST provide a public, unauthenticated web portal at `/book/{serviceSlug}`
where customers self-book appointments.

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

#### Scenario: Confirmation page shows booking summary

- **GIVEN** a booking was created successfully
- **WHEN** the customer lands on `/booking-confirmation/{bookingId}`
- **THEN** the page MUST display the service, resource, date/time, status, and price, plus a "confirmation email sent" notice and reschedule/cancel links

### Requirement: REQ-APT-006 Booking Confirmation Email

The system MUST send a confirmation email immediately after a Booking is created or a
deposit is paid, including an `.ics` calendar attachment and signed reschedule/cancel
links.

**Feature tier**: V1

#### Scenario: Confirmation email sent on booking creation

- **GIVEN** a Booking is created with `status: "confirmed"`
- **WHEN** the create action completes
- **THEN** a confirmation email MUST be dispatched within 1 minute
- **AND** `confirmationSentAt` MUST be set on the Booking
- **AND** the email MUST include service name, date/time, resource name, price, and signed deep-links for reschedule and cancel

#### Scenario: Email includes `.ics` attachment

- **GIVEN** the confirmation email is being composed
- **WHEN** the content is built
- **THEN** it MUST include an `.ics` (iCalendar) attachment per RFC 5545

### Requirement: REQ-APT-007 Reminder Email and SMS

The system MUST send a 24-hour reminder email and optional SMS before each
appointment.

**Feature tier**: V1

#### Scenario: Reminder sent 24 hours before appointment

- **GIVEN** a confirmed Booking with `startAt: 2026-05-25T14:00:00Z`
- **WHEN** the ReminderDispatchJob runs on 2026-05-24 14:00
- **THEN** a reminder email MUST be dispatched
- **AND** `reminderSentAt` MUST be set on the Booking
- **AND** if SMS is configured, an SMS reminder MUST also be sent

#### Scenario: Reminder includes reschedule/cancel links

- **GIVEN** the reminder is being composed
- **WHEN** the content is built
- **THEN** it MUST include the same signed deep-links for reschedule and cancel as the confirmation email

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

### Requirement: REQ-APT-012 Walk-In Queue

The system MUST support walk-in arrivals (WalkInTicket) for businesses that mix
scheduled and unscheduled service.

**Feature tier**: V1

#### Scenario: Walk-in ticket created on arrival

- **GIVEN** a customer walks into a barbershop without an appointment
- **WHEN** staff creates a WalkInTicket with `serviceId: (haircut)`, `displayName: "Mr. Jansen"`
- **THEN** a WalkInTicket MUST be created with `status: "waiting"`, `arrivedAt: now`, and `estimatedReadyAt` computed from gaps in the current schedule

#### Scenario: Queue rebalances as appointments complete

- **GIVEN** the queue has 3 waiting customers
- **WHEN** a staff member completes an appointment (Booking status → completed)
- **THEN** the WalkInQueueRebalanceJob MUST recalculate `estimatedReadyAt` for all waiting tickets

#### Scenario: Staff calls next customer from queue

- **GIVEN** the WalkInQueuePanel is displayed with a "Call next" button
- **WHEN** staff clicks "Call next"
- **THEN** the first waiting WalkInTicket MUST transition to `status: "called"`

#### Scenario: Serving and abandoning tickets

- **GIVEN** a called WalkInTicket
- **WHEN** staff clicks "Serve" (or "Abandon")
- **THEN** the ticket MUST transition to `served` with `actualServedAt` set (or `abandoned`)

### Requirement: REQ-APT-018 Bi-Directional Calendar Sync

The system MUST react to staff calendar blocks (vacation, lunch, meetings) synced by
the `calendar` leaf every 5 minutes, and MUST push created Bookings back to staff
calendars by creating VEVENTs through the leaf.

**Feature tier**: V1

#### Scenario: Calendar-synced block is respected in availability

- **GIVEN** a staff member has a "lunch" event 12:00-13:00 in their calendar
- **WHEN** the `calendar` leaf (via email-calendar-sync) syncs the calendar
- **THEN** within 5 minutes the AvailabilityCache MUST be invalidated, and no customer can book that staff member 12:00-13:00

#### Scenario: Booking is pushed to staff calendar via the leaf

- **GIVEN** a Booking is created for staff member X on 2026-05-25 14:00
- **WHEN** the Booking is confirmed
- **THEN** a VEVENT MUST be created in X's calendar **through the `calendar` leaf's create API** (not a pipelinq-local CalDAV write) with customer name, service, and a deep-link back to the Booking

#### Scenario: AvailabilityCache is refreshed hourly

- **GIVEN** the AvailabilityCacheRefreshJob runs
- **WHEN** `run()` executes
- **THEN** the cache for all active Resources MUST be invalidated for today+30 days, with per-resource errors logged and skipped

### Requirement: REQ-APT-014 Customer Timeline Integration

The system MUST display all Bookings on the Customer detail page, showing past and
future appointments with status, service, resource, and time.

**Feature tier**: V1

#### Scenario: Bookings visible on customer detail

- **GIVEN** a Customer has 5 past and 2 future Bookings
- **WHEN** an agent opens the Customer detail page
- **THEN** a "Bookings" section MUST display all 7 bookings chronologically with service name, resource name, date/time, status badge, and a link to the Booking detail

#### Scenario: Bookings section shows empty state if none exist

- **GIVEN** a Customer with no Bookings
- **WHEN** the Customer detail page is loaded
- **THEN** the Bookings section MUST display an empty state message (not an error)

### Requirement: REQ-APT-015 Admin Booking Management

The system MUST provide admin views for staff to manage Services, Resources, and
Bookings: list, create, edit, and delete.

**Feature tier**: V1

#### Scenario: Service list shows all services with filters

- **GIVEN** the admin opens the Services list page
- **WHEN** the page loads
- **THEN** a `CnIndexPage` list MUST display all Services with columns for name, duration, price, and status, with filters by status and bookableOnline

#### Scenario: Service detail allows editing all fields

- **GIVEN** an admin opens a Service detail page in edit mode
- **WHEN** they modify name, price, or multiStep configuration
- **THEN** changes MUST be saved to OpenRegister and the AvailabilityCache MUST be invalidated for all Resources using that Service

#### Scenario: Booking detail shows status actions

- **GIVEN** an admin opens a confirmed Booking detail page in the future
- **WHEN** the page renders
- **THEN** buttons for "Mark completed", "Mark no-show", "Reschedule", and "Cancel" MUST be available, wired to the booking endpoints

### Requirement: REQ-APT-017 Compliance and Audit Trails

The system MUST provide AVG right-to-be-forgotten (pseudonymize, not delete), NL
Boekhoudplicht 7-year retention, and WCAG 2.1 AA accessibility on the public portal.

**Feature tier**: V1

#### Scenario: AVG right-to-be-forgotten pseudonymizes data

- **GIVEN** a customer exercises right-to-be-forgotten
- **WHEN** the request is processed
- **THEN** customer name, email, and phone on Bookings MUST be replaced with hashes (e.g. `sha256(email)`), but Booking records MUST NOT be deleted (7-year retention)
- **AND** aggregates (counts, totals) MUST remain unchanged

#### Scenario: Bookings are never auto-deleted

- **GIVEN** Bookings in terminal statuses (completed/cancelled/no-show)
- **WHEN** any retention sweep runs
- **THEN** the Booking records MUST be retained (no auto-delete), with the audit trail preserved

#### Scenario: Portal is WCAG 2.1 AA accessible

- **GIVEN** the public booking portal is tested with axe-core and keyboard navigation
- **WHEN** the tests run
- **THEN** zero WCAG AA violations MUST be found, all form fields MUST have associated labels, and colour MUST NOT be the sole carrier of information

### Requirement: REQ-APT-019 Unit Tests and Code Quality

Every new PHP service, controller, and background job MUST have PHPUnit tests; code
MUST pass phpcs, phpstan, and phpmd.

**Feature tier**: V1

#### Scenario: Code passes linters

- **GIVEN** all new PHP code is committed
- **WHEN** pre-commit hooks run
- **THEN** `phpcs`, `phpstan`, and `phpmd` MUST report zero violations

#### Scenario: Services meet coverage threshold

- **GIVEN** the booking services have unit tests
- **WHEN** `composer test` runs
- **THEN** all tests MUST pass with ≥80% coverage for services

### Requirement: REQ-APT-020 Internationalization (i18n)

All user-visible strings in the portal, admin UI, and email templates MUST have
English and Dutch translations. No hardcoded strings.

**Feature tier**: V1

#### Scenario: Portal displays in Dutch when locale is nl

- **GIVEN** the Nextcloud instance is configured for Dutch locale
- **WHEN** a customer visits `/book/haircut`
- **THEN** all labels, buttons, and placeholders MUST display in Dutch

#### Scenario: Translation key parity

- **GIVEN** `l10n/en.json` and `l10n/nl.json`
- **WHEN** the files are compared
- **THEN** they MUST have identical key sets (no missing keys) and no component MUST contain hardcoded user-visible strings

