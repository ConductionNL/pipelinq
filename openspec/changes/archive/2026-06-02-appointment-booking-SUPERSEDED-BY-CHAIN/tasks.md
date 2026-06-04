# Tasks: Appointment Booking

> **Leaf-first (ADR-022).** Calendar read/write and email/SMS dispatch are delegated to
> `email-calendar-sync`, which consumes the OR `calendar` (`integration-calendar`) and `email`
> (`integration-email`) leaves; payments/SMS go through openconnector. Where these tasks
> reference `email-calendar-sync` for block-fetching or booking push, that path resolves to the
> `calendar` leaf's VEVENT link/create API — pipelinq adds NO CalDAV client and NO local
> calendar/email link schema. Build only the booking domain (Service/Resource/Booking/
> WalkInTicket, slots, skill routing, deposits, no-show, walk-in queue, portal).

## Section 0: Deduplication Check

### Task 0.1: Verify no overlap with existing services [MVP]
- **Spec ref**: ADR-012
- **Files**: Search `openspec/specs/`, `lib/Service/`, existing Pipelinq services, OpenRegister services
- **Findings**:
  - `ObjectService` — reused for all Service/Resource/Booking/WalkInTicket CRUD (no custom CRUD built)
  - `createObjectStore` — reused for Pinia stores (no hand-rolled stores)
  - No prior booking or scheduling service exists in Pipelinq
  - `AvailabilityService` is new (no overlap with existing slot/schedule logic)
  - `CnStatusBadge`, `CnDetailCard`, `CnIndexPage` — reused from @conduction/nextcloud-vue (no custom UI built)
  - `email-calendar-sync` provides email dispatch and calendar sync (no email/SMS code written here)
  - `skill-routing` provides skill matching (no skill logic duplicated)
  - `openconnector` provides payment processing (no payment code written here)
- [ ] Document deduplication check findings in PR description before merging

---

## Section 1: Data Model Registration [V1]

### Task 1.1: Register Service, Resource, Booking, WalkInTicket schemas in pipelinq_register.json [V1]
- **Spec ref**: REQ-APT-001, REQ-APT-002
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: Four new schemas registered with complete field definitions per design.md
- [ ] Add `service` schema with fields: name, description, durationMinutes, bufferBefore/After, price, currency, requiredSkills, requiredResourceTypes, multiStep (array), bookableOnline, requiresDeposit, depositAmount, noShowFee, cancellationPolicy, status
- [ ] Add `resource` schema with fields: name, type (enum: staff/room/equipment), skills, workingHours (array), vacations (array), calendarSyncId, bookable, userId, maxConcurrent, status
- [ ] Add `booking` schema with fields: customerId, serviceId, resourceAssignments (array), startAt, endAt, status (enum with all lifecycle values), statusHistory (array), notes, internalNotes, source, confirmationSentAt, reminderSentAt, depositPaidAt, depositAmount, noShowFeeChargedAt, cancellationReason, cancelledAt, cancelledBy, previousBookingId
- [ ] Add `walk-in-ticket` schema with fields: customerId, displayName, phone, serviceId, arrivedAt, estimatedReadyAt, status (enum: waiting/called/served/abandoned), assignedResourceId, actualServedAt
- [ ] Add `availability-cache` schema (internal, not queryable by users): resourceId, date, freeBlocks (array), generatedAt, expiresAt
- [ ] Verify all schemas have proper relations to customer/service/resource entities
- [ ] Verify multiStep, workingHours, vacations, statusHistory arrays have clear sub-schema definitions

### Task 1.2: Add seed data objects to pipelinq_register.json [V1]
- **Spec ref**: REQ-APT-001, REQ-APT-002
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: 4 Service, 4 Resource, 2 Booking seed objects with realistic Dutch values
- [ ] Add 4 Service seed objects: haircut (simple), color+cut (multi-step), oil-change, tax-consultation with varied requirements
- [ ] Each Service has unique slug (service-haircut-simple, service-color-and-cut, service-oil-change, service-consultation-tax)
- [ ] Add 4 Resource seed objects: Sarah (stylist with skills), Jan (mechanic), treatment-room-a, workshop-bay-1 with varied types
- [ ] Each Resource has unique slug, working hours for all weekdays, optionally vacations
- [ ] Add 2 Booking seed objects: booking-001-completed (past, status: completed), booking-002-confirmed (future, status: confirmed)
- [ ] Verify all seed objects use realistic Dutch names, times, and currency

---

## Section 2: Backend Services [V1]

### Task 2.1: Create AvailabilityService [V1]
- **Spec ref**: REQ-APT-003
- **Files**: `lib/Service/AvailabilityService.php`
- **Acceptance**: Service computes free 15-min-aligned slots by intersecting working hours, vacations, bookings, calendar blocks
- [ ] Implement `computeAvailability(string $resourceId, string $date, int $serviceDurationMinutes): array` — returns array of free blocks with startTime, endTime, durationMinutes
- [ ] Implement `getWorkingHours(string $resourceId, string $weekDay): ?array` — queries Resource.workingHours, returns {openTime, closeTime}
- [ ] Implement `getBlockedTimes(string $resourceId, string $date): array` — merges vacations, existing Bookings, calendar-synced blocks; returns array of {startTime, endTime}
- [ ] Implement `alignToSlots(string $startTime, string $endTime, int $intervalMinutes): array` — splits time range into 15-minute boundaries
- [ ] Implement `invalidateCache(string $resourceId, string $date): void` — deletes AvailabilityCache entry
- [ ] Implement `getOrComputeCache(string $resourceId, string $date): array` — check AvailabilityCache, regenerate if missing/stale
- [ ] Use `ObjectService::findObjects()` to query Resource, Booking, Vacation objects (3 positional args per ADR-015)
- [ ] Call `CalendarSyncService` to fetch calendar-synced blocks for this resource
- [ ] Add `@spec openspec/changes/appointment-booking/specs/appointment-booking/spec.md#req-apt-003` PHPDoc to class and all public methods

### Task 2.2: Create BookingService [V1]
- **Spec ref**: REQ-APT-005, REQ-APT-006, REQ-APT-008, REQ-APT-009, REQ-APT-011
- **Files**: `lib/Service/BookingService.php`
- **Acceptance**: Service handles booking lifecycle: create, confirm, reschedule, cancel, mark no-show, complete
- [ ] Implement `createBooking(array $data, string $source): string` — validate customer, service, time availability; create Booking with status pending-deposit or confirmed; return booking UUID
- [ ] Implement `getAvailableSlots(string $serviceId, string $date): array` — call AvailabilityService, query skill-routing for eligible resources, return merged slots
- [ ] Implement `getEligibleResources(string $serviceId): array` — call skill-routing for resources matching service requiredSkills
- [ ] Implement `confirmBooking(string $bookingId, string $reason): void` — transition status to confirmed, send confirmation email via EmailDispatchService, set confirmationSentAt
- [ ] Implement `rescheduleBooking(string $bookingId, string $newStartAt): string` — create new Booking for newStartAt, mark original as rescheduled, set previousBookingId, free old slot, return new booking UUID
- [ ] Implement `cancelBooking(string $bookingId, string $reason, string $cancelledBy): void` — validate cancellation policy, charge fee if applicable (call openconnector), transition to cancelled-by-customer or cancelled-by-business
- [ ] Implement `markNoShow(string $bookingId, string $staffUserId): void` — transition to no-show, increment customer's no-show count, charge noShowFee if applicable
- [ ] Implement `completeBooking(string $bookingId): void` — transition to completed, optionally update customer lifetime value
- [ ] Validate all status transitions (pending-deposit → confirmed, confirmed → completed/no-show/cancelled, etc.)
- [ ] Maintain statusHistory array with each change: {status, changedAt, changedBy, reason}
- [ ] Invalidate AvailabilityCache on Booking create/update/delete
- [ ] Add `@spec` PHPDoc to all methods

### Task 2.3: Create PortalController [V1]
- **Spec ref**: REQ-APT-005, REQ-APT-006
- **Files**: `lib/Controller/PortalController.php`, `appinfo/routes.php` (entries)
- **Acceptance**: Public API for portal (no auth). Endpoints: list services, get slots, create booking, view/reschedule/cancel by signed link
- [ ] Implement `GET /portal/services` — return all Services with `bookableOnline: true` in JSON (no auth required)
- [ ] Implement `GET /portal/availability?serviceId=X&date=YYYY-MM-DD` (no auth) — call `BookingService::getAvailableSlots()`, return array of free slots
- [ ] Implement `POST /portal/book` (no auth) — validate customerName, email, phone, serviceId, startAt; create Booking; if depositRequired, initiate payment; return confirmation JSON + payment redirect URL
- [ ] Implement `GET /portal/booking/{bookingId}` (no auth) — fetch booking by UUID; must validate signed link if link is provided (e.g., from email)
- [ ] Implement `POST /portal/reschedule` (no auth) — validate signed link, original bookingId, newStartAt; call `BookingService::rescheduleBooking()`; return confirmation
- [ ] Implement `POST /portal/cancel` (no auth) — validate signed link, bookingId; call `BookingService::cancelBooking()`; return confirmation
- [ ] All endpoints return JSON with appropriate HTTP status codes (200, 400 validation error, 404 not found, 410 link expired)
- [ ] Error responses use static messages, never $e->getMessage() (ADR-005)
- [ ] Generate signed deep-links using `IURLGenerator` with HMAC-SHA256 signature (expires after 30 days)
- [ ] All controller methods thin (<10 lines) — delegate logic to BookingService (ADR-003)
- [ ] Add routes to `appinfo/routes.php` (specific routes before wildcards)
- [ ] Add `@spec` PHPDoc

### Task 2.4: Create BackgroundJobs [V1]
- **Spec ref**: REQ-APT-007, REQ-APT-012, REQ-APT-016
- **Files**: `lib/BackgroundJob/AvailabilityCacheRefreshJob.php`, `lib/BackgroundJob/ReminderDispatchJob.php`, `lib/BackgroundJob/WalkInQueueRebalanceJob.php`
- **Acceptance**: Three jobs handle cache refresh, reminders, and queue rebalance

#### AvailabilityCacheRefreshJob
- [ ] Extend `OCP\BackgroundJob\TimedJob` with `setInterval(3600)` (1 hour)
- [ ] In `run()`: iterate all active Resources, call `AvailabilityService::invalidateCache()` for today+30 days
- [ ] Catch per-resource errors, log, and continue for remaining resources
- [ ] Register in `appinfo/info.xml` under `<background-jobs>`

#### ReminderDispatchJob
- [ ] Extend `OCP\BackgroundJob\TimedJob` with `setInterval(300)` (5 minutes)
- [ ] In `run()`: query Bookings with `startAt` between now+23h and now+24h, `status: confirmed`, `reminderSentAt: null`
- [ ] For each: call email-calendar-sync to send reminder email + optional SMS, set `reminderSentAt` on success
- [ ] Log counts and errors

#### WalkInQueueRebalanceJob
- [ ] Extend `OCP\BackgroundJob\Job` (on-demand trigger, not timed)
- [ ] Register as event listener on Booking status → completed (or call manually from BookingService::completeBooking)
- [ ] In `run()`: query all WalkInTickets with `status: waiting`, recalculate `estimatedReadyAt` for each
- [ ] Log counts

- [ ] Add `@spec` PHPDoc to all jobs

---

## Section 3: Unit Tests [V1]

### Task 3.1: Unit tests for AvailabilityService [V1]
- **Spec ref**: REQ-APT-003, REQ-APT-019
- **Files**: `tests/Unit/Service/AvailabilityServiceTest.php`
- **Acceptance**: ≥4 test methods; covers working hours, vacations, booking conflicts, slot alignment
- [ ] Test `computeAvailability()` returns free slots when resource is available 09:00-17:00, duration 30min
- [ ] Test `computeAvailability()` excludes booked times
- [ ] Test `computeAvailability()` excludes vacation dates
- [ ] Test `alignToSlots()` returns 15-minute-aligned boundaries (09:00, 09:15, 09:30, ...)
- [ ] Test cache hit when available-cache is recent
- [ ] Test cache miss/regenerate when older than 24h
- [ ] Mock `ObjectService`, `CalendarSyncService` — no real DB

### Task 3.2: Unit tests for BookingService [V1]
- **Spec ref**: REQ-APT-005, REQ-APT-008, REQ-APT-009, REQ-APT-011, REQ-APT-019
- **Files**: `tests/Unit/Service/BookingServiceTest.php`
- **Acceptance**: ≥6 test methods covering create, reschedule, cancel with policy, no-show
- [ ] Test `createBooking()` creates confirmed booking when no deposit required
- [ ] Test `createBooking()` creates pending-deposit booking when depositRequired
- [ ] Test `rescheduleBooking()` marks original as rescheduled, creates new booking with previousBookingId
- [ ] Test `cancelBooking()` with free-until-24-hours policy (no charge within window)
- [ ] Test `cancelBooking()` with free-until-24-hours policy (charges after window)
- [ ] Test `markNoShow()` increments customer no-show count and charges fee if configured
- [ ] Test invalid status transitions are rejected
- [ ] Mock ObjectService, AvailabilityService, openconnector

### Task 3.3: Unit tests for PortalController [V1]
- **Spec ref**: REQ-APT-005, REQ-APT-019
- **Files**: `tests/Unit/Controller/PortalControllerTest.php`
- **Acceptance**: ≥5 test methods per endpoint (success, validation error, not found)
- [ ] Test `GET /portal/services` returns 200 with services list
- [ ] Test `GET /portal/availability` returns 200 with slots for valid date
- [ ] Test `POST /portal/book` returns 200 and creates booking for valid input
- [ ] Test `POST /portal/book` returns 400 for invalid email
- [ ] Test `POST /portal/reschedule` with valid signed link returns 200
- [ ] Test `POST /portal/reschedule` with expired/invalid signature returns 410
- [ ] Test `/portal/cancel` endpoints similarly
- [ ] Mock BookingService

### Task 3.4: Unit tests for BackgroundJobs [V1]
- **Spec ref**: REQ-APT-007, REQ-APT-019
- **Files**: `tests/Unit/BackgroundJob/ReminderDispatchJobTest.php`, `tests/Unit/BackgroundJob/AvailabilityCacheRefreshJobTest.php`
- **Acceptance**: ≥2 test methods per job
- [ ] Test ReminderDispatchJob queries bookings and sends reminders
- [ ] Test ReminderDispatchJob continues on per-booking errors
- [ ] Test AvailabilityCacheRefreshJob iterates all resources and invalidates cache
- [ ] Mock ObjectService, EmailDispatchService, AvailabilityService

---

## Section 4: Frontend — Public Portal [V1]

### Task 4.1: Create BookingPortal.vue (public route) [V1]
- **Spec ref**: REQ-APT-005
- **Files**: `src/views/portal/BookingPortal.vue`
- **Acceptance**: Public portal at `/book/{serviceSlug}` with date picker, slot picker, booking form, no auth required, WCAG 2.1 AA
- [ ] Add `<!-- SPDX-License-Identifier: EUPL-1.2 -->` as first line
- [ ] Route prop: `serviceSlug` from route params
- [ ] On mount: fetch Service by slug via `GET /portal/services` (filter), or direct API call
- [ ] Display service header: name, description, duration, price
- [ ] Date picker: calendar showing only dates with available slots; fetch availability via `GET /portal/availability?serviceId=X&date=Y` on each date click
- [ ] Time slot picker: display available times as buttons (15-min intervals), allow customer to select one
- [ ] Booking form: fields for name (required), email (required, validated), phone (optional), notes (optional)
- [ ] Submit button: `POST /portal/book` with form data + selected serviceId + startAt time
- [ ] On success: show confirmation message + booking summary; if depositRequired, redirect to payment provider (openconnector)
- [ ] On error: show user-friendly error message (validation or server error), not stack trace
- [ ] WCAG 2.1 AA: keyboard-navigable (tab through form fields), proper labels, color contrast, screen-reader announcements
- [ ] Language: all labels/buttons via `this.t('pipelinq', 'key')` (en + nl via l10n files)
- [ ] Styling: CSS variables only (NL Design System tokens), no hardcoded colors
- [ ] Components: use @conduction/nextcloud-vue only (no @nextcloud/vue direct imports)
- [ ] Add `@spec` PHPDoc

### Task 4.2: Create BookingConfirmationPage.vue [V1]
- **Spec ref**: REQ-APT-006
- **Files**: `src/views/portal/BookingConfirmationPage.vue`
- **Acceptance**: Confirmation page shown after successful booking, displays booking summary, email sent notification
- [ ] Route: `/booking-confirmation/{bookingId}`
- [ ] Fetch booking via `GET /portal/booking/{bookingId}` (no auth)
- [ ] Display: customer name, service, resource, date/time, status, price
- [ ] Show: "Confirmation email sent to {email}" message
- [ ] If deposit pending: show "Awaiting payment" with payment status
- [ ] Include reschedule/cancel signed links (same as in email)
- [ ] All strings translated (en + nl)
- [ ] Add SPDX header

---

## Section 5: Frontend — Admin UI [V1]

### Task 5.1: Create ServiceList.vue and ServiceDetail.vue [V1]
- **Spec ref**: REQ-APT-001, REQ-APT-015
- **Files**: `src/views/services/ServiceList.vue`, `src/views/services/ServiceDetail.vue`
- **Acceptance**: Admin list/detail for Services using standard CnIndexPage and CnDetailPage patterns

#### ServiceList.vue
- [ ] Use `CnIndexPage` with `useListView('service', { objectStore, sidebarState })`
- [ ] Columns: name, duration, price, status (with badge)
- [ ] Filters: status (active/archived), bookableOnline (true/false)
- [ ] Add button creates new Service
- [ ] Row click navigates to ServiceDetail

#### ServiceDetail.vue
- [ ] Two modes: view (CnDetailPage) and edit (form)
- [ ] Edit form has fields: name, description, durationMinutes, bufferBefore/AfterMinutes, price, currency, requiredSkills (multi-select), multiStep (sub-table with drag-to-reorder), bookableOnline (toggle), requiresDeposit (toggle), depositAmount, noShowFee, cancellationPolicy, cancellationHoursBefore
- [ ] On save: validate multiStep (each step has positive duration), call `ObjectService.saveObject()`
- [ ] After save: invalidate AvailabilityCache for all Resources using this Service (call backend endpoint or service method)
- [ ] Use `CnDetailCard` sections for readability
- [ ] MultiStep sub-table: columns for stepIndex, duration, skill, resourceType, allowGap; buttons to add/delete/reorder rows
- [ ] Delete button with confirmation dialog (use CnDeleteDialog)
- [ ] All strings translated
- [ ] Add SPDX header

### Task 5.2: Create ResourceList.vue and ResourceDetail.vue [V1]
- **Spec ref**: REQ-APT-002, REQ-APT-015
- **Files**: `src/views/resources/ResourceList.vue`, `src/views/resources/ResourceDetail.vue`
- **Acceptance**: Admin list/detail for Resources (staff, rooms, equipment)

#### ResourceList.vue
- [ ] Use `CnIndexPage` with `useListView('resource', {...})`
- [ ] Columns: name, type (icon+label), status, bookable
- [ ] Filter: type (staff/room/equipment), status, bookable
- [ ] Add button creates new Resource
- [ ] Row click navigates to ResourceDetail

#### ResourceDetail.vue
- [ ] Edit form has fields: name, type (select: staff/room/equipment), skills (multi-select, if type=staff), workingHours (sub-table: weekday, openTime, closeTime), vacations (sub-table: startDate, endDate, label), calendarSyncId (selector for linked calendars from email-calendar-sync), userId (if type=staff, selector for Nextcloud user), bookable (toggle), maxConcurrent, status
- [ ] WorkingHours sub-table: 7 rows (one per weekday), editable time fields; clone to all weekdays button
- [ ] Vacations sub-table: add/delete rows, date pickers, text input for label
- [ ] CalendarSyncId: dropdown populated from email-calendar-sync available calendars (fetch via API)
- [ ] On save: validate working hours (openTime < closeTime), validate vacations (startDate <= endDate)
- [ ] Invalidate AvailabilityCache for this resource on save
- [ ] Delete button with confirmation
- [ ] All strings translated
- [ ] Add SPDX header

### Task 5.3: Create BookingList.vue and BookingDetail.vue [V1]
- **Spec ref**: REQ-APT-015
- **Files**: `src/views/bookings/BookingList.vue`, `src/views/bookings/BookingDetail.vue`
- **Acceptance**: Admin list/detail for Bookings with status actions

#### BookingList.vue
- [ ] Use `CnIndexPage` with `useListView('booking', {...})`
- [ ] Columns: customer name, service, resource, date/time, status (badge), source
- [ ] Filters: date range (from/to), status (all lifecycle states), resource, service, source (portal/phone/walk-in/etc.)
- [ ] Row click navigates to BookingDetail

#### BookingDetail.vue
- [ ] Header: customer name (link to customer detail), service, resource, date/time, status badge
- [ ] Sections:
  - Booking Details: customer email/phone, service details, resource details, notes, internalNotes
  - Resource Assignments: table showing per-step resource, startAt, endAt (for multi-step services)
  - Audit Trail: statusHistory table showing all transitions with timestamp, who, reason
  - Timeline: linked emails (via email-calendar-sync), linked calendar events
- [ ] Status Actions (buttons vary by status and time):
  - If pending-deposit: "Complete Payment", "Cancel"
  - If confirmed and in future: "Reschedule", "Cancel", "Send Reminder"
  - If confirmed and past: "Mark Completed", "Mark No-show"
  - If rescheduled: "View Original Booking" (link to previousBookingId)
- [ ] Reschedule button opens dialog (date/time picker), calls `POST /api/booking/{id}/reschedule`
- [ ] Cancel button opens confirmation, calls `POST /api/booking/{id}/cancel`
- [ ] Mark no-show button confirms, calls `POST /api/booking/{id}/no-show`
- [ ] Edit button allows editing notes/internalNotes only (booking details locked after creation)
- [ ] All strings translated
- [ ] Add SPDX header

### Task 5.4: Create BookingsCard.vue (customer timeline) [V1]
- **Spec ref**: REQ-APT-014
- **Files**: `src/components/bookings/BookingsCard.vue`
- **Acceptance**: Component for customer detail showing past and future bookings

- [ ] Add SPDX header
- [ ] Props: `customerId` (string)
- [ ] On mount: fetch Bookings linked to this customer via object store filter
- [ ] Display: list of bookings sorted by startAt (future first, then past)
- [ ] Each row: service name, resource name, date/time formatted (locale-aware), status badge, link to Booking detail
- [ ] Empty state: "No appointments booked yet" (translated)
- [ ] Use `CnDetailCard` wrapper
- [ ] All strings translated
- [ ] Add `try/catch` around store queries with user error feedback

### Task 5.5: Create WalkInQueuePanel.vue [V1]
- **Spec ref**: REQ-APT-012
- **Files**: `src/components/bookings/WalkInQueuePanel.vue`
- **Acceptance**: Real-time queue view for barbershops and urgent-repair shops

- [ ] Add SPDX header
- [ ] Display: list of WalkInTickets with status = waiting or called, sorted by arrivedAt
- [ ] Each row: customer displayName, service, arrivedAt time, estimatedReadyAt, actions (Call next, Serve, Abandon)
- [ ] "Call next" button: transition first waiting ticket to "called" (visual highlight or emit sound)
- [ ] "Serve" button: transition to "served", set actualServedAt
- [ ] "Abandon" button (for walkouts): transition to "abandoned"
- [ ] On action, refresh list and update estimatedReadyAt for remaining waiting tickets
- [ ] Auto-refresh every 10 seconds (poll via setInterval)
- [ ] Empty state when no waiting/called tickets
- [ ] All strings translated
- [ ] Add `@spec` PHPDoc

---

## Section 6: Frontend — Store and Router [V1]

### Task 6.1: Create Pinia stores [V1]
- **Spec ref**: ADR-004
- **Files**: `src/store/modules/services.js`, `src/store/modules/resources.js`, `src/store/modules/bookings.js`, `src/store/modules/walk-in-tickets.js`
- **Acceptance**: Four stores created using `createObjectStore` pattern

- [ ] Each store: `defineStore` using `createObjectStore('service'/'resource'/'booking'/'walk-in-ticket', 'service'/'resource'/'booking'/'walk-in-ticket', 'pipelinq')`
- [ ] Register in `src/store/store.js` `initializeStores()` function
- [ ] Use store plugins: auditTrailsPlugin, relationsPlugin, lifecyclePlugin, selectionPlugin, searchPlugin

### Task 6.2: Create booking routes [V1]
- **Spec ref**: ADR-004
- **Files**: `src/router/booking-routes.js`, update `src/router/index.js`
- **Acceptance**: Router configuration for booking module

- [ ] Public routes (no auth):
  - `/book/:serviceSlug` → BookingPortal.vue
  - `/booking-confirmation/:bookingId` → BookingConfirmationPage.vue
  - `/portal/booking/:bookingId` → (API response, handled by PortalController)
- [ ] Admin routes (auth required):
  - `/services` → ServiceList.vue
  - `/services/:id` → ServiceDetail.vue
  - `/resources` → ResourceList.vue
  - `/resources/:id` → ResourceDetail.vue
  - `/bookings` → BookingList.vue
  - `/bookings/:id` → BookingDetail.vue
- [ ] All route names match pattern: EntityList, EntityDetail (e.g., ServiceList, ServiceDetail)
- [ ] Params via arrow functions (not string concatenation)

### Task 6.3: Add navigation items [V1]
- **Spec ref**: ADR-004
- **Files**: `src/App.vue` (MainMenu), update `src/views/MainMenu.vue` if separate
- **Acceptance**: Navigation shows Services, Resources, Bookings

- [ ] Add main nav item "Bookings" with sub-items: Services, Resources, Bookings
- [ ] Icons: calendar for Bookings, service-icon for Services, users for Resources
- [ ] Icons from MDI (use CnIcon component)

---

## Section 7: Email and Payment Integration [V1]

### Task 7.1: Create email template for confirmation [V1]
- **Spec ref**: REQ-APT-006, REQ-APT-020
- **Files**: Email templates (location TBD based on email-calendar-sync pattern)
- **Acceptance**: Confirmation email with `.ics` attachment and signed reschedule/cancel links

- [ ] Subject: "Uw afspraak is bevestigd: [Service Name], [Date] [Time]" (Dutch)
- [ ] Body includes: customer name, service, resource, date/time, location/notes, price
- [ ] `.ics` attachment: calendar event per RFC 5545 with DTSTART, DTEND, SUMMARY, DESCRIPTION, ATTENDEES
- [ ] Signed deep-links: "Afspraak verplaatsen" → `/portal/reschedule?link=SIGNED_TOKEN&bookingId=X`
- [ ] Signed deep-links: "Afspraak annuleren" → `/portal/cancel?link=SIGNED_TOKEN&bookingId=X`
- [ ] All strings localized (en + nl)
- [ ] Generate links using `IURLGenerator` with HMAC-SHA256 signature (expires 30 days)

### Task 7.2: Create email template for reminder [V1]
- **Spec ref**: REQ-APT-007, REQ-APT-020
- **Files**: Email templates
- **Acceptance**: Reminder email sent 24 hours before, with reschedule/cancel links

- [ ] Subject: "Herinnering: Uw afspraak morgen om [Time]" (Dutch)
- [ ] Body: brief reminder, service, time, resource, price, same signed links as confirmation
- [ ] All strings localized

### Task 7.3: Create payment session initiator [V1]
- **Spec ref**: REQ-APT-010
- **Files**: Integration with openconnector (verify method/pattern)
- **Acceptance**: Deposit payments via Mollie/Stripe with PSD2 SCA

- [ ] In PortalController::POST /portal/book: if depositRequired, call openconnector API to create payment session
- [ ] Return payment session URL to frontend
- [ ] Frontend redirects customer to payment provider
- [ ] On payment success: webhook or callback updates Booking status → confirmed
- [ ] On payment failure: Booking status remains pending-deposit (not deleted), slot released after 15 min timeout
- [ ] PSD2 SCA handled by openconnector (no custom 3D Secure code)

### Task 7.4: Create no-show fee charge logic [V1]
- **Spec ref**: REQ-APT-011
- **Files**: Integration with openconnector
- **Acceptance**: No-show fees charged via openconnector if payment method on file

- [ ] In BookingService::markNoShow(): if noShowFee > 0, call openconnector to queue charge
- [ ] openconnector handles: lookup customer payment method, initiate charge, handle 3D Secure 2
- [ ] On success: set noShowFeeChargedAt on Booking
- [ ] If no payment method: log the no-show but don't attempt charge (optional: send invoice/reminder email)

---

## Section 8: Calendar and Skill Routing Integration [V1]

### Task 8.1: Integrate with email-calendar-sync for booking push [V1]
- **Spec ref**: REQ-APT-018
- **Files**: BookingService, or new CalendarEventPushService
- **Acceptance**: When a Booking is confirmed, create an event in staff's calendar

- [ ] After Booking status → confirmed: call email-calendar-sync to create calendar event
- [ ] Event details: summary = customer name + service name, description = service details, attendees = staff resource's email
- [ ] Deep-link in event description: link back to Booking in Pipelinq
- [ ] Event UID: unique identifier based on Booking.id
- [ ] Fetch staff email from Resource.userId (Nextcloud user email)

### Task 8.2: Integrate with email-calendar-sync for block fetching [V1]
- **Spec ref**: REQ-APT-018
- **Files**: AvailabilityService
- **Acceptance**: Calendar-synced blocks (lunch, meetings) are fetched every 5 min and block availability

- [ ] In AvailabilityService::getBlockedTimes(): call CalendarSyncService::getBlockedTimes() for resource with calendarSyncId
- [ ] Merge returned blocks into total blocked list (already implemented by email-calendar-sync)

### Task 8.3: Integrate with skill-routing for eligibility [V1]
- **Spec ref**: REQ-APT-004
- **Files**: BookingService
- **Acceptance**: Query skill-routing to determine which Resources can perform a Service

- [ ] In BookingService::getEligibleResources(serviceId): query skill-routing for resources matching service.requiredSkills
- [ ] Return resource list filtered to only eligible ones
- [ ] Handle: resource has no skills (eligible for services with no skill requirements)
- [ ] Handle: multi-step services with step-specific skills

---

## Section 9: Compliance and Audit [V1]

### Task 9.1: Implement statusHistory and audit trail [V1]
- **Spec ref**: REQ-APT-013, REQ-APT-017
- **Files**: BookingService, OpenRegister audit trail
- **Acceptance**: All Booking status changes logged with timestamp, user, reason

- [ ] Every status change: append {status, changedAt, changedBy (Nextcloud user UID), reason} to statusHistory
- [ ] Use `IUserSession::getUser()->getUID()` for changedBy (NEVER display name, ADR-005)
- [ ] Reason field: auto-filled based on action (e.g., "Customer rescheduled", "Staff marked no-show", "Payment received")
- [ ] OpenRegister automatic audit trail captures all field changes as well

### Task 9.2: Implement AVG right-to-be-forgotten pseudonymization [V1]
- **Spec ref**: REQ-APT-017
- **Files**: New DataDeletionService or script in lib/
- **Acceptance**: GDPR compliance - customer data pseudonymized, not deleted

- [ ] Create DataDeletionService with method: `pseudonymizeCustomerBookings(customerId): void`
- [ ] Replace customer name, email, phone on all Bookings linked to that customer with SHA-256 hashes (e.g., `hash('sha256', original_email)`)
- [ ] Keep Booking records themselves (7-year Boekhoudplicht retention)
- [ ] Keep aggregates (count, totals) unchanged
- [ ] Log the pseudonymization action with timestamp

### Task 9.3: Ensure NL Boekhoudplicht 7-year retention [V1]
- **Spec ref**: REQ-APT-017
- **Files**: Booking entity design
- **Acceptance**: Bookings are never automatically deleted; audit trail preserved for 7 years

- [ ] Bookings have no auto-delete mechanism (no soft-delete or archival that purges after time)
- [ ] Audit trail preserved via OpenRegister (immutable)
- [ ] Status = "completed", "cancelled", "no-show" = terminal states, not deleted
- [ ] No data retention policy should delete older bookings

### Task 9.4: WCAG 2.1 AA compliance testing [V1]
- **Spec ref**: REQ-APT-017, REQ-APT-020
- **Files**: Portal and admin UI components
- **Acceptance**: Portal passes axe-core scan, keyboard-navigable, screen-reader-friendly

- [ ] Run axe-core on BookingPortal.vue and all forms: zero WCAG AA violations
- [ ] Test keyboard navigation: Tab through all form fields, can submit form with Enter
- [ ] Test screen reader: all form fields have associated labels, buttons have aria-labels, error messages announced
- [ ] Test color contrast: all text meets 4.5:1 ratio (normal) or 3:1 (large)
- [ ] Test on mobile (320px viewport): critical functionality works on small screens
- [ ] Test English and Dutch: strings render correctly in both languages

---

## Section 10: Translations [V1]

### Task 10.1: Add i18n strings to l10n/en.json and l10n/nl.json [V1]
- **Spec ref**: REQ-APT-020
- **Files**: `l10n/en.json`, `l10n/nl.json`
- **Acceptance**: All user-visible strings have translations; no hardcoded English/Dutch in components

- [ ] Portal strings: "Select a date", "Available times", "Your name", "Email address", "Phone number", "Book appointment", "Confirmation email sent", etc.
- [ ] Admin strings: "Services", "Resources", "Bookings", "Service name", "Duration", "Price", "Status", "Active", "Archived", etc.
- [ ] Email subjects: "Your appointment is confirmed", "Reminder: Your appointment tomorrow"
- [ ] Email body: "Dear", "Appointment", "Date", "Time", "Location", "Reschedule appointment", "Cancel appointment"
- [ ] Error messages: "Invalid email", "Date not available", "Booking failed", "Payment declined", "Link expired"
- [ ] Status values: "pending-deposit" → "Awaiting payment", "confirmed" → "Confirmed", "completed" → "Completed", "no-show" → "No-show", "cancelled-by-customer" → "Cancelled", "cancelled-by-business" → "Staff cancelled", "rescheduled" → "Rescheduled"
- [ ] Verify key parity: both files have identical key sets (no missing keys)
- [ ] Run grep to verify no hardcoded strings in components: `grep -rn "'" src/ --include='*.vue' | grep -v "this\.t\|import\|//\|console"` → should return zero matches for bookings components

---

## Section 11: Pre-commit Verification [V1]

### Task 11.1: Code quality checks [V1]
- **Spec ref**: ADR-015
- [ ] SPDX headers: `grep -rL 'SPDX-License-Identifier' lib/Service/Availability* lib/Service/Booking* lib/Controller/Portal* lib/BackgroundJob/*Booking* lib/BackgroundJob/Reminder* --include='*.php'` → zero results
- [ ] SPDX headers: `grep -rL 'SPDX-License-Identifier' src/views/portal/ src/views/services/ src/views/resources/ src/views/bookings/ src/components/bookings/ --include='*.vue'` → zero results
- [ ] ObjectService calls: verify all `saveObject`/`findObjects`/`findObject` use 3 positional args (register, schema, data/filter)
- [ ] Error responses: `grep -rn 'getMessage()' lib/Controller/ --include='*.php'` → zero results (use static error messages)
- [ ] Auth checks: verify no `#[NoAdminRequired]` endpoints skip per-object authorization checks (ADR-005)
- [ ] Store registration: verify `service`, `resource`, `booking`, `walk-in-ticket` registered in `src/store/store.js`
- [ ] Router: verify all routes defined in `src/router/booking-routes.js`
- [ ] Translations: `npm run lint` → no missing i18n keys
- [ ] No raw fetch: `grep -rn 'fetch(' src/ --include='*.vue'` → zero results (use axios from @nextcloud/axios)
- [ ] No direct @nextcloud/vue imports: `grep -rn "from '@nextcloud/vue'" src/` → zero results (use @conduction/nextcloud-vue only)
- [ ] Component imports: for every `<CnFoo>` and `<NcFoo>` in templates, verify import AND `components: {}` entry

### Task 11.2: Linter and test runs [V1]
- **Spec ref**: ADR-015
- [ ] `npm run lint` — zero errors in Vue/JS code
- [ ] `php -l` on all new PHP files — zero syntax errors
- [ ] `composer test` — all unit tests pass with ≥80% code coverage
- [ ] `composer lint` (phpcs, phpstan, phpmd) — zero violations
- [ ] `npm run build` — zero errors, production bundle created

### Task 11.3: Manual smoke tests [V1]
- **Spec ref**: ADR-015
- [ ] Visit `/book/haircut-simple` (public portal) without login → displays service, date picker, slot picker, booking form
- [ ] Select a date/time and submit form → booking confirmed or payment redirect
- [ ] Open Services admin list → shows seed services with correct names/prices
- [ ] Open a Service detail → can edit fields, multiStep table shows steps
- [ ] Open Resources admin list → shows seed resources (Sarah stylist, Jan mechanic, etc.)
- [ ] Open a Resource detail → working hours and vacations visible/editable
- [ ] Open Bookings admin list → shows seed bookings with correct statuses
- [ ] Open a Booking detail → shows customer, service, resource, status actions (buttons vary by status)
- [ ] Click "Mark no-show" on a past confirmed booking → status changes to no-show, logs in audit trail
- [ ] Open a Customer detail → Bookings section shows their appointments

---

