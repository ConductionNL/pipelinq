# Tasks: appointment-booking

## Section 0: Deduplication Check

### Task 0.1: Verify no overlap with existing services [MVP]
- **Spec ref**: ADR-012
- **Files**: Search `lib/Service/`, existing Pipelinq services, OpenRegister built-in CRUD
- **Findings**:
  - `ObjectService` — reused for all Service/Resource/Booking/WalkInTicket CRUD (no custom CRUD built)
  - `SkillRoutingService` — existing service to query eligible resources by skill (do NOT rebuild)
  - `EmailService` — existing confirmation/reminder email dispatcher (reuse for booking confirmations)
  - `PaymentService` — existing openconnector payment integration (reuse for deposits/fees)
  - No prior booking or appointment scheduling service exists in Pipelinq
  - No overlap found with OpenRegister's built-in TimedJob, IMailManager, or calendar sync APIs
- [ ] Document deduplication check findings in PR description before merging

---

## Section 1: Seed Data [V1]

### Task 1.1: Add Service seed objects to pipelinq_register.json [V1]
- **Spec ref**: REQ-ABK-001, REQ-ABK-002
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: 5 Service seed objects with realistic Dutch values, varied multiStep configs, bookableOnline flag, pricing/deposit/policy
- [ ] Add 5 Service objects under `components.objects[]` with `@self` envelope (`register: pipelinq`, `schema: service`, unique slug)
- [ ] Each object includes: name (Dutch), description, durationMinutes, bufferBefore/AfterMinutes, price, currency, requiredSkills, multiStep (where applicable), bookableOnline, deposit/noShowFee, cancellationPolicy
- [ ] Example services: "Standaard Knippen" (30m, no deposit), "Kleuren + Knippen" (90m, deposit, multiStep), "Fysiotherapie Sessie" (45m, no-show fee), "Motordiagnose" (60m, multi-resource)
- [ ] Verify slugs match `service-*-NNN` pattern and are unique

### Task 1.2: Add Resource seed objects to pipelinq_register.json [V1]
- **Spec ref**: REQ-ABK-003, REQ-ABK-004
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: 5 Resource seed objects (3 staff, 1 equipment, 1 room) with realistic Dutch names, skills, working hours, vacation examples
- [ ] Add 5 Resource objects under `components.objects[]` with `@self` envelope (`register: pipelinq`, `schema: resource`, unique slug)
- [ ] Staff resources: "Alex (Kapper)", "Maya (Kapper)", "Jan (Fysiotherapeut)", "Piet (Monteur)" with skills array
- [ ] Equipment resource: "Diagnosetafel B" (equipment type, no userId)
- [ ] Each staff resource includes: workingHours array (Mon-Fri 09:00-17:00, varied times), one vacation example (e.g., "Maya" June 20 - July 5)
- [ ] Verify skills map to seed Service requiredSkills (e.g., "color-certified", "base-cut", "physiotherapy-licensed")

### Task 1.3: Add Booking seed objects to pipelinq_register.json [V1]
- **Spec ref**: REQ-ABK-001
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: 3 Booking seed objects with varied statuses, customer references, confirmation/reminder timestamps
- [ ] Add 3 Booking objects under `components.objects[]` with `@self` envelope (`register: pipelinq`, `schema: booking`, unique slug)
- [ ] Booking 1: serviceId=haircut, resourceId=Alex, status=confirmed, confirmationSentAt set (past)
- [ ] Booking 2: serviceId=color-cut, resourceId=Alex, status=pending-deposit, multiStep resourceAssignments (gap between steps)
- [ ] Booking 3: serviceId=physiotherapy, resourceId=Jan, status=confirmed, reminderSentAt set
- [ ] Verify startAt/endAt timestamps are ISO 8601 and realistic (future dates relative to seed creation date)

---

## Section 2: Backend Services [V1]

### Task 2.1: Create AvailabilityQueryService [V1]
- **Spec ref**: REQ-ABK-001, REQ-ABK-002, REQ-ABK-003, REQ-ABK-004
- **Files**: `lib/Service/AvailabilityQueryService.php`
- **Acceptance**: Service queries available 15-minute slots, respects skills, working hours, existing bookings, calendar sync blocks, multiStep gaps
- [ ] Implement `queryAvailableSlots(string $serviceId, string $date, ?string $resourceId = null): array` — return array of start times (15-min aligned) where a booking can fit. Filter by resourceId if provided.
- [ ] Implement `matchResourcesForService(string $serviceId): array` — call SkillRoutingService to find resources matching all requiredSkills. Return array of Resource UUIDs.
- [ ] Implement `buildAvailabilityCache(string $resourceId, string $date): array` — compute free 15-minute blocks:
  - Query Resource working hours (weekday match)
  - Subtract vacation blocks (if any date in vacation range)
  - Subtract existing Booking blocks for that resource on that date
  - Subtract calendar sync blocks (email-calendar-sync events)
  - Store result in AvailabilityCache, return freeBlocks array
- [ ] Implement `invalidateCacheForResource(string $resourceId, string $date): void` — delete AvailabilityCache entries (called on booking/resource changes)
- [ ] Handle multiStep services: for each step, reserve the correct step duration + buffers from the same resource (unless gap allowed)
- [ ] Inject: ObjectService, SkillRoutingService, CalendarSyncService (for calendar blocks), IAppConfig
- [ ] Add `@spec openspec/changes/appointment-booking/tasks.md#task-2.1` PHPDoc to class and all public methods

### Task 2.2: Create BookingService [V1]
- **Spec ref**: REQ-ABK-001, REQ-ABK-005, REQ-ABK-006, REQ-ABK-007, REQ-ABK-009, REQ-ABK-010
- **Files**: `lib/Service/BookingService.php`
- **Acceptance**: Service creates/transitions/reschedules/cancels bookings; sends emails; enforces cancellation policy; handles deposits/no-show fees
- [ ] Implement `createBooking(string $serviceId, string $customerId, string $startAt, ?string $notes = null): array` — create Booking object, validate slot availability, determine status (confirmed or pending-deposit), send confirmation email, invalidate AvailabilityCache. Return created Booking.
- [ ] Implement `confirmBooking(string $bookingId): void` — transition pending-deposit to confirmed, send confirmation email, set depositPaidAt
- [ ] Implement `rescheduleBooking(string $bookingId, string $newStartAt): array` — validate new slot, create new Booking for newStartAt, mark original as rescheduled with previousBookingId link, free old slot, send reschedule notification
- [ ] Implement `cancelBooking(string $bookingId, string $cancelledBy = 'customer'): void` — mark as cancelled-by-customer or cancelled-by-business, enforce cancellation policy (charge fee if applicable), free slot, send cancellation email
- [ ] Implement `markNoShow(string $bookingId): void` — mark status no-show, increment Customer noShowCount, charge no-show fee if payment method on file (call PaymentService), send notification
- [ ] Implement `generateSignedLink(string $bookingId, string $action): string` — return signed URL (HMAC-SHA256) valid for 30 days for reschedule/cancel actions
- [ ] Implement `validateSignedLink(string $bookingId, string $token, string $action): bool` — verify signature, check expiry
- [ ] Inject: ObjectService, PaymentService, EmailService, AvailabilityQueryService, IAppConfig, ICrypto
- [ ] Add `@spec` PHPDoc to all public methods

### Task 2.3: Create WalkInQueueService [V1]
- **Spec ref**: REQ-ABK-008
- **Files**: `lib/Service/WalkInQueueService.php`
- **Acceptance**: Service creates walk-in tickets, assigns to resources, estimates wait times, updates queue on appointment completion
- [ ] Implement `createWalkInTicket(string $serviceId, string $displayName, ?string $customerId = null, ?string $phone = null): array` — create WalkInTicket with status waiting, call estimateReadyTime() to compute estimatedReadyAt, return ticket
- [ ] Implement `assignWalkIn(string $ticketId, string $resourceId): void` — set assignedResourceId, recompute estimatedReadyAt based on resource's current load + remaining walk-ins
- [ ] Implement `estimateReadyTime(string $serviceId, string $resourceId): string` — scan resource's AvailabilityCache for today, find first free block matching service duration, return estimated time. If no availability today, return null (queue message "No availability today")
- [ ] Implement `updateWaitingQueue(string $resourceId): void` — called on booking completion or walk-in served. Recompute estimatedReadyAt for all waiting tickets assigned to this resource
- [ ] Implement `callWalkIn(string $ticketId): void` — set status to called
- [ ] Implement `serveWalkIn(string $ticketId): void` — set status to served. If a customerId and userId exist, optionally create a Booking record with source: walk-in
- [ ] Inject: ObjectService, AvailabilityQueryService, IDateTimeFormatter
- [ ] Add `@spec` PHPDoc

### Task 2.4: Create PaymentService [V1]
- **Spec ref**: REQ-ABK-010, REQ-ABK-007, REQ-ABK-009
- **Files**: `lib/Service/PaymentService.php`
- **Acceptance**: Service initiates/confirms deposits, charges cancellation/no-show fees via openconnector
- [ ] Implement `initiateDepositPayment(string $bookingId, float $amount): string` — call openconnector payment API to create session, return redirect URL to payment gateway
- [ ] Implement `confirmDepositPayment(string $bookingId, string $paymentId): bool` — verify payment success via openconnector, return true on success
- [ ] Implement `chargeCancellationFee(string $bookingId, float $amount): bool` — charge late-cancellation fee via openconnector. Return true if successful, false on error
- [ ] Implement `chargeNoShowFee(string $bookingId, float $amount): bool` — charge no-show fee. Check if payment method on file; if not, return false (no charge)
- [ ] All payment calls MUST catch exceptions and log errors — NEVER throw to caller (payment failure is not critical to booking creation)
- [ ] Inject: `OCA\OpenConnector\Service\PaymentService`, IAppConfig, LoggerInterface
- [ ] Add `@spec` PHPDoc

### Task 2.5: Create BookingController [V1]
- **Spec ref**: REQ-ABK-001
- **Files**: `lib/Controller/BookingController.php`, `appinfo/routes.php`
- **Acceptance**: REST endpoints for availability query, booking creation, reschedule, cancellation, no-show marking
- [ ] Implement `GET /api/booking/availability` — query params: serviceId (required), date (required YYYY-MM-DD), resourceId (optional). Call AvailabilityQueryService::queryAvailableSlots(). Return JSON array of start times.
- [ ] Implement `POST /api/booking/create` — body: serviceId, customerId (or auto-create), startAt, notes. Call BookingService::createBooking(). Return created Booking object.
- [ ] Implement `POST /api/booking/:id/reschedule` — body: newStartAt, reason. Verify signed link (if from email). Call BookingService::rescheduleBooking(). Return new Booking.
- [ ] Implement `POST /api/booking/:id/cancel` — body: reason. Verify signed link. Call BookingService::cancelBooking(). Return success
- [ ] Implement `POST /api/booking/:id/confirm-deposit` — body: paymentId. Call PaymentService::confirmDepositPayment(). Transition booking to confirmed. Return Booking.
- [ ] Implement `POST /api/booking/:id/no-show` — Require admin/staff permission. Call BookingService::markNoShow(). Return Booking.
- [ ] Implement `GET /api/booking/reschedule-link` — query params: bookingId, token. Verify link, return token validity status.
- [ ] Implement `GET /api/booking/cancel-link` — query params: bookingId, token. Return link validity status.
- [ ] All endpoints derive user identity from IUserSession or allow anonymous (for signed links). NEVER trust frontend user ID (ADR-005).
- [ ] Error responses use static messages, not $e->getMessage() (ADR-015)
- [ ] Controller methods MUST be <10 lines — delegate to services (ADR-003)
- [ ] Add routes to `appinfo/routes.php`
- [ ] Add `@spec` PHPDoc

### Task 2.6: Create WalkInQueueController [V1]
- **Spec ref**: REQ-ABK-008
- **Files**: `lib/Controller/WalkInQueueController.php`, `appinfo/routes.php`
- **Acceptance**: REST endpoints for walk-in queue management (create, assign, call, serve, list)
- [ ] Implement `POST /api/walk-in/create` — body: serviceId, displayName, customerId (optional), phone (optional). Call WalkInQueueService::createWalkInTicket(). Return ticket.
- [ ] Implement `POST /api/walk-in/:id/assign` — body: resourceId. Call WalkInQueueService::assignWalkIn(). Return ticket.
- [ ] Implement `POST /api/walk-in/:id/call` — Call WalkInQueueService::callWalkIn(). Return ticket.
- [ ] Implement `POST /api/walk-in/:id/serve` — Call WalkInQueueService::serveWalkIn(). Return ticket.
- [ ] Implement `GET /api/walk-in/queue` — query params: resourceId (optional), serviceId (optional). Call ObjectService to query WalkInTickets with status=waiting. Return array.
- [ ] Require admin/staff permission for all endpoints except public queue view
- [ ] Add routes to `appinfo/routes.php`
- [ ] Add `@spec` PHPDoc

### Task 2.7: Create ReminderEmailJob [V1]
- **Spec ref**: REQ-ABK-005
- **Files**: `lib/BackgroundJob/ReminderEmailJob.php`, `appinfo/info.xml`
- **Acceptance**: Scheduled job runs daily, finds bookings 24 hours before start, sends reminder emails
- [ ] Extend `OCP\BackgroundJob\TimedJob` with `setInterval(86400)` (24 hours / 1 day)
- [ ] In `run()`: 
  - Query ObjectService for Bookings with status=confirmed AND startAt between now+23h and now+25h AND reminderSentAt is null
  - For each booking, send reminder email via EmailService
  - Set reminderSentAt to current timestamp
  - Log count of reminders sent
  - Catch exceptions per-booking and log (do not abort on one failure)
- [ ] Inject: ObjectService, EmailService, IDateTimeFormatter, LoggerInterface
- [ ] Register in `appinfo/info.xml` under `<background-jobs>`
- [ ] Add `@spec` PHPDoc

### Task 2.8: Create DepositPaymentTimeoutJob [V1]
- **Spec ref**: REQ-ABK-010
- **Files**: `lib/BackgroundJob/DepositPaymentTimeoutJob.php`, `appinfo/info.xml`
- **Acceptance**: Job runs every 10 minutes, finds pending-deposit bookings older than 15 minutes, cancels and releases slots
- [ ] Extend `OCP\BackgroundJob\TimedJob` with `setInterval(600)` (10 minutes)
- [ ] In `run()`:
  - Query ObjectService for Bookings with status=pending-deposit AND createdAt older than 15 minutes
  - For each, call BookingService::cancelBooking(reason: "Payment timeout")
  - Invalidate AvailabilityCache for the resource/date
  - Send notification email to customer: "Your booking has been cancelled due to unpaid deposit"
  - Log canceled count
- [ ] Catch exceptions per-booking (do not abort)
- [ ] Register in `appinfo/info.xml`
- [ ] Add `@spec` PHPDoc

---

## Section 3: Frontend Components [V1]

### Task 3.1: Create BookingPortal.vue [V1]
- **Spec ref**: REQ-ABK-001, REQ-ABK-012
- **Files**: `resources/js/views/BookingPortal.vue`
- **Acceptance**: Public portal for self-service booking; WCAG AA compliant; 15-minute slot selection; customer form; deposit payment redirect
- [ ] Mounted hook: 
  - Capture serviceSlug from route params
  - Call API GET /api/booking/availability to fetch Service details
  - Store in component state (service, resource, slots)
- [ ] Template sections:
  - Service header: name, description, price, duration
  - Date picker (calendar or dropdown for next 30 days, disabled dates: vacations, weekends if business doesn't operate)
  - Available time slots grid (15-min resolution, disabled slots greyed out)
  - Customer form: name, email, phone, notes textarea
  - Deposit warning (if service requires deposit): "A deposit of €X is required. You will be directed to payment after booking."
  - Submit button: "Confirm Booking"
  - Terms/cancellation policy display (collapsed)
- [ ] Methods:
  - `onDateSelected()` — call API GET /api/booking/availability?serviceId=X&date=Y, populate slots
  - `onSlotSelected()` — highlight selected slot
  - `submitForm()` — validate form fields (name, email required), POST to /api/booking/create, handle response:
    - If status=confirmed: show confirmation screen (booking details + .ics download)
    - If status=pending-deposit: redirect to payment gateway URL
  - `validateEmail()` — basic email regex
  - `formatTime()` — display 09:00-09:30 format
- [ ] Accessibility:
  - Proper `<label for>` associations on all inputs
  - aria-live region for form errors
  - Sufficient color contrast (test with axe-core)
  - Tab order correct, no outline removed
- [ ] Styling: Use branding tokens from global CSS variables, mobile-responsive (flexbox/grid), 100% viewport width
- [ ] Error handling: Show toast/alert on API errors, suggest retry or contact support

### Task 3.2: Create BookingDashboard.vue [V1]
- **Spec ref**: REQ-ABK-008, REQ-ABK-007
- **Files**: `resources/js/views/BookingDashboard.vue`
- **Acceptance**: Staff/operator view showing bookings calendar, walk-in queue, booking details panel, no-show marking
- [ ] Layout: 3-column or tabbed:
  - Left: Resource selector (dropdown or list)
  - Center: Calendar week view showing bookings as blocks (color by service)
  - Right: Side panel (booking details or walk-in queue)
- [ ] Calendar features:
  - Week view with 30-minute slots
  - Bookings rendered as colored blocks (service-specific color)
  - Click booking → show detail panel
  - Drag-to-reschedule (optional V2, can be manual reschedule form for V1)
- [ ] Booking detail panel:
  - Customer name, email, phone
  - Service name, duration, price
  - Resource(s) assigned
  - Booking status badge (confirmed, no-show, etc.)
  - Notes (customer + internal)
  - Action buttons: Reschedule, Cancel, Mark as Complete, Mark as No-Show
  - "Mark as No-Show" button only visible if booking start time is in the past
- [ ] Walk-in queue tab:
  - List of waiting tickets (status=waiting)
  - For each: displayName, estimatedReadyAt, serviceId
  - Assign-to-resource dropdown
  - Call (change status to called)
  - Serve (change status to served)
- [ ] Methods:
  - `loadResourceBookings(resourceId, dateRange)` — call API, populate calendar
  - `onBookingClick(bookingId)` — show detail panel
  - `onMarkNoShow()` — confirmation dialog, POST /api/booking/:id/no-show, refresh
  - `onReschedule()` — form or dialog to pick new date/time, POST /api/booking/:id/reschedule
  - `onAssignWalkIn(ticketId, resourceId)` — POST /api/walk-in/:id/assign, refresh queue
  - `loadWalkInQueue()` — call API GET /api/walk-in/queue, populate list
- [ ] Real-time updates (optional V1): Polling every 30 seconds to refresh bookings/queue

### Task 3.3: Create BookingWidget.vue [V1]
- **Spec ref**: REQ-ABK-001
- **Files**: `resources/js/components/BookingWidget.vue`
- **Acceptance**: Compact embeddable widget for business websites (iframe); customer form + slot picker
- [ ] Props: `serviceId` (UUID), `tenantUrl` (base URL for API calls)
- [ ] Template:
  - Compact form: Service name + date picker + time selector + name/email/phone fields
  - Submit button: "Book Now"
  - Styles: Minimal, no header/footer (embeds cleanly in website)
- [ ] Methods:
  - `submitForm()` — POST to tenantUrl/api/booking/create, show confirmation or redirect
- [ ] iframe embed example in README or docs:
  ```html
  <iframe src="https://tenant.pipelinq.nl/widget/booking?serviceId=XXX"></iframe>
  ```

### Task 3.4: Add Booking timeline card to ClientDetail.vue [V1]
- **Spec ref**: REQ-ABK-011
- **Files**: `pipelinq/src/views/ClientDetail.vue` (reuse existing component or create BookingTimelineCard.vue)
- **Acceptance**: Timeline section on Customer detail showing upcoming/past bookings with no-show tracking
- [ ] Add "Appointments" section to timeline (after Contactmomenten, before Tasks)
- [ ] Show upcoming bookings (status!=cancelled) grouped by date
- [ ] For each booking:
  - Service name, date/time, resource name
  - Status badge (confirmed, pending-deposit, no-show)
  - Click to open booking detail modal (or link to dashboard)
- [ ] Summary card above timeline:
  - "Lifetime bookings: X"
  - "No-shows: Y"
  - "Last appointment: DATE" (formatted as Dutch date)
  - "Next appointment: DATE at TIME"
- [ ] Call API GET /api/customer/:id/bookings to fetch related bookings
- [ ] Styling: Match existing timeline card design, consistent with other CRM cards

---

## Section 4: Database / OpenRegister Integration [V1]

### Task 4.1: Register Service, Resource, Booking, WalkInTicket schemas in pipelinq_register.json [V1]
- **Spec ref**: ADR-000, ADR-015
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: All 4 schemas properly defined with field types, examples, constraints
- [ ] Verify each schema has:
  - `name` (schema slug)
  - `title` (human-readable)
  - `description`
  - `type: object`
  - `properties` (field definitions with type, description)
  - `required` array (mandatory fields)
  - Examples section with valid objects
- [ ] Service schema: all fields from design.md (name, duration, buffers, price, skills, multiStep, bookableOnline, deposit, fees, policy)
- [ ] Resource schema: type (staff/room/equipment), skills, workingHours, vacations, calendarSyncId, userId
- [ ] Booking schema: customerId, serviceId, resourceAssignments, startAt/endAt, status enum, source enum
- [ ] WalkInTicket schema: customerId (optional), displayName, serviceId, arrivedAt, estimatedReadyAt, status enum, assignedResourceId

### Task 4.2: Create AvailabilityCache seed data [V1]
- **Spec ref**: REQ-ABK-004
- **Files**: `lib/Settings/pipelinq_register.json`
- **Acceptance**: Initial AvailabilityCache entries for seed resources on next 7 days
- [ ] For each seed Resource (Alex, Maya, Jan, Piet, DiagnosticBay):
  - Generate AvailabilityCache entries for next 7 days
  - For each day:
    - Query Resource workingHours (weekday match)
    - Subtract seed Bookings
    - Generate freeBlocks array (15-min intervals)
    - Create object with resourceId, date, freeBlocks
- [ ] Example: Alex on Monday 2026-05-25
  - Working hours: 09:00-17:00
  - Seed booking: 10:00-10:30
  - freeBlocks: [09:00-10:00, 10:30-17:00] (with 15-min precision: [09:00, 09:15, …, 10:30, 10:45, …])

---

## Section 5: Unit Tests [V1]

### Task 5.1: Unit tests for AvailabilityQueryService [V1]
- **Spec ref**: REQ-ABK-001, REQ-ABK-003, REQ-ABK-004
- **Files**: `tests/Unit/Service/AvailabilityQueryServiceTest.php`
- **Acceptance**: ≥6 test methods covering availability computation, skill matching, calendar blocks
- [ ] Test `queryAvailableSlots()` returns correct 15-min slots when resource is free
- [ ] Test `queryAvailableSlots()` filters out 15-min blocks occupied by existing bookings
- [ ] Test `queryAvailableSlots()` filters resources by required skills (skill-routing match)
- [ ] Test `buildAvailabilityCache()` subtracts vacation blocks
- [ ] Test `buildAvailabilityCache()` respects working hours (no slots outside 09:00-17:00 if that's the schedule)
- [ ] Test multiStep service: gap-allowed step does NOT reserve gap time on resources
- [ ] Mock ObjectService, SkillRoutingService, CalendarSyncService — do NOT use real DB

### Task 5.2: Unit tests for BookingService [V1]
- **Spec ref**: REQ-ABK-001, REQ-ABK-005, REQ-ABK-006, REQ-ABK-009, REQ-ABK-010
- **Files**: `tests/Unit/Service/BookingServiceTest.php`
- **Acceptance**: ≥8 test methods
- [ ] Test `createBooking()` with confirmed status (no deposit required)
- [ ] Test `createBooking()` with pending-deposit status (deposit required)
- [ ] Test `createBooking()` invalidates AvailabilityCache on success
- [ ] Test `rescheduleBooking()` marks original as rescheduled, creates new with previousBookingId link
- [ ] Test `cancelBooking()` with free cancellation policy (no charge)
- [ ] Test `cancelBooking()` with always-charge policy
- [ ] Test `markNoShow()` increments Customer noShowCount
- [ ] Test `generateSignedLink()` and `validateSignedLink()` (signature verification)
- [ ] Test `confirmBooking()` transitions pending-deposit → confirmed
- [ ] Mock ObjectService, PaymentService, EmailService, AvailabilityQueryService

### Task 5.3: Unit tests for WalkInQueueService [V1]
- **Spec ref**: REQ-ABK-008
- **Files**: `tests/Unit/Service/WalkInQueueServiceTest.php`
- **Acceptance**: ≥4 test methods
- [ ] Test `createWalkInTicket()` computes estimatedReadyAt based on resource gaps
- [ ] Test `estimateReadyTime()` returns first available free block for service duration
- [ ] Test `assignWalkIn()` updates ticket assignedResourceId and recalculates estimatedReadyAt
- [ ] Test `updateWaitingQueue()` recomputes times for remaining tickets when resource is freed
- [ ] Mock ObjectService, AvailabilityQueryService

---

## Section 6: API Integration Tests [V1]

### Task 6.1: Test POST /api/booking/create [V1]
- **Spec ref**: REQ-ABK-001
- **Files**: `tests/Integration/BookingControllerTest.php`
- **Acceptance**: Happy path and error cases
- [ ] Test successful booking creation: 201 response, Booking object in response
- [ ] Test with required deposit: 201, status=pending-deposit, paymentUrl in response
- [ ] Test with invalid serviceId: 400 Bad Request
- [ ] Test with fully booked slot: 409 Conflict (slot no longer available)

### Task 6.2: Test GET /api/booking/availability [V1]
- **Spec ref**: REQ-ABK-001
- **Files**: `tests/Integration/BookingControllerTest.php`
- **Acceptance**: Returns correct available slots
- [ ] Test query with valid serviceId and date: 200, array of start times
- [ ] Test query with invalid date format: 400 Bad Request
- [ ] Test query with fully booked date: 200, empty array

### Task 6.3: Test POST /api/booking/:id/reschedule [V1]
- **Spec ref**: REQ-ABK-006
- **Files**: `tests/Integration/BookingControllerTest.php`
- **Acceptance**: Reschedule flow
- [ ] Test reschedule with valid newStartAt: 200, new Booking in response
- [ ] Test reschedule with invalid token: 401 Unauthorized
- [ ] Test reschedule with expired token: 401 Unauthorized

---

## Section 7: Frontend Tests (E2E) [V1]

### Task 7.1: Test BookingPortal form submission [V1]
- **Spec ref**: REQ-ABK-001, REQ-ABK-012
- **Files**: `tests/e2e/booking-portal.spec.ts` (Playwright or similar)
- **Acceptance**: Portal UX flows (slot selection, form submission, confirmation)
- [ ] Test navigate to /book/haircut, select date, select time, fill form, submit → confirmation screen
- [ ] Test form validation: submit with empty name → error message
- [ ] Test deposit redirect: booking with required deposit → redirected to payment gateway
- [ ] Test keyboard navigation: Tab through all form fields, submit via Enter
- [ ] Test mobile responsive: portal visible and usable on 375px width (mobile phone)

---

## Section 8: Documentation [V1]

### Task 8.1: API documentation for booking endpoints [V1]
- **Spec ref**: All REQ-ABK-*
- **Files**: `docs/api/booking.md` (or inline OpenAPI spec)
- **Acceptance**: Complete endpoint reference with request/response examples
- [ ] Document all 7 booking endpoints:
  - GET /api/booking/availability
  - POST /api/booking/create
  - POST /api/booking/:id/reschedule
  - POST /api/booking/:id/cancel
  - POST /api/booking/:id/confirm-deposit
  - POST /api/booking/:id/no-show
  - GET /api/booking/:id/reschedule-link
- [ ] For each: method, URL, auth required, request body/params, response body, error cases

### Task 8.2: User guide for public portal [V1]
- **Spec ref**: REQ-ABK-001
- **Files**: `docs/user-guide/public-booking-portal.md`
- **Acceptance**: Customer-facing guide (Dutch + English)
- [ ] How to book: step-by-step with screenshots
- [ ] Cancellation and rescheduling via email links
- [ ] FAQ: What is a deposit? What if I can't pay? When will I get a reminder?
- [ ] Accessibility info: Keyboard shortcuts, screen reader support

### Task 8.3: Admin configuration guide [V1]
- **Spec ref**: REQ-ABK-001, REQ-ABK-008
- **Files**: `docs/admin/booking-setup.md`
- **Acceptance**: How to set up services, resources, working hours, skills
- [ ] Creating a Service (duration, price, required skills, multiStep, deposit, cancellation policy)
- [ ] Creating a Resource (type, skills, working hours, vacation, calendar sync)
- [ ] Linking resources to skill-routing
- [ ] Configuring calendar sync (Outlook, Google, iCloud)
- [ ] Testing the public portal

---

## Section 9: Compliance & Security [V1]

### Task 9.1: Implement GDPR right-to-be-forgotten for Bookings [V1]
- **Spec ref**: REQ-ABK-013
- **Files**: `lib/Service/GdprService.php` (new), `lib/Controller/GdprController.php` (update)
- **Acceptance**: Customer anonymization preserves audit/tax compliance
- [ ] Add `pseudonymizeCustomer(string $customerId)` method:
  - Replace all Booking.customerId with hash
  - Clear Booking.notes (set to "***")
  - Clear Booking.internalNotes
  - Keep Booking.createdAt, status, serviceId, resourceAssignments (for compliance reporting)
  - Remove Customer from client-management
- [ ] Verify 7-year retention is honored (Booking records not deleted, only pseudonymized)

### Task 9.2: Verify payment security (PSD2/SCA compliance) [V1]
- **Spec ref**: REQ-ABK-010
- **Files**: `lib/Service/PaymentService.php`
- **Acceptance**: Payment handling defers to openconnector (which handles PSD2/SCA)
- [ ] Verify no card data (PAN, CVV, expiry) is stored in Pipelinq
- [ ] Verify payment references are from openconnector (transaction ID, not card info)
- [ ] Verify deposit and no-show charge calls use openconnector's PSD2-compliant providers (Mollie, Stripe)

### Task 9.3: Test signed link security [V1]
- **Spec ref**: REQ-ABK-005, REQ-ABK-006
- **Files**: `tests/Unit/Service/BookingServiceTest.php` (add signature tests)
- **Acceptance**: Signed links cannot be forged or replayed
- [ ] Test signed link signature verification with correct HMAC key
- [ ] Test fake signature rejected (malformed or from wrong key)
- [ ] Test expired link rejected (30 days after creation)
- [ ] Test replay attack: same link used multiple times (optional: add one-time-use flag for V2)

---

## Section 10: Integration with Dependent Apps [V1]

### Task 10.1: Verify skill-routing integration [V1]
- **Spec ref**: REQ-ABK-003
- **Files**: Integration test: `tests/Integration/SkillRoutingIntegrationTest.php`
- **Acceptance**: AvailabilityQueryService correctly calls skill-routing and filters resources
- [ ] Mock skill-routing service: return Resources with skill "color-certified"
- [ ] Call queryAvailableSlots for "Color Treatment" service
- [ ] Verify returned slots only include certified stylists

### Task 10.2: Verify email-calendar-sync integration [V1]
- **Spec ref**: REQ-ABK-004, REQ-ABK-005
- **Files**: Integration test: `tests/Integration/EmailCalendarSyncIntegrationTest.php`
- **Acceptance**: Calendar sync blocks populate AvailabilityCache; confirmation/reminder emails sent
- [ ] Mock email-calendar-sync calendar blocks (lunch 12:00-13:00)
- [ ] Query availability during lunch → no slots offered
- [ ] Create booking → confirmation email sent via email-calendar-sync

### Task 10.3: Verify openconnector integration [V1]
- **Spec ref**: REQ-ABK-010, REQ-ABK-007, REQ-ABK-009
- **Files**: Integration test: `tests/Integration/PaymentIntegrationTest.php`
- **Acceptance**: Payment calls routed correctly to openconnector (no real charges in test)
- [ ] Mock openconnector payment API
- [ ] Create booking with deposit → initiateDepositPayment returns redirect URL
- [ ] Verify charge calls include correct amount and reason

### Task 10.4: Verify client-management integration [V1]
- **Spec ref**: REQ-ABK-011
- **Files**: Integration test: `tests/Integration/ClientBookingIntegrationTest.php`
- **Acceptance**: Customer record linked to Bookings; counters updated
- [ ] Create Booking for Customer
- [ ] Verify Customer.bookingCount increments
- [ ] Mark Booking no-show → Customer.noShowCount increments
- [ ] Verify Customer detail view timeline shows Bookings

---

## Section 11: Rollout & Monitoring [V1]

### Task 11.1: Feature flag for public booking portal [V1]
- **Spec ref**: ADR-011 (feature flags)
- **Files**: `lib/Settings/AppConfig.php` (or similar config management)
- **Acceptance**: Portal can be disabled/enabled per tenant without code changes
- [ ] Add config: `booking.portal.enabled` (default false, opt-in per tenant)
- [ ] Add config: `booking.payment_provider` (mollie | stripe | adyen) + provider API keys
- [ ] Add config: `booking.deposit_timeout_minutes` (default 15)
- [ ] Add config: `booking.reminder_hours_before` (default 24)
- [ ] Admins can toggle in Settings UI (or via API endpoint if settings UI doesn't exist yet)

### Task 11.2: Logging & monitoring for booking operations [V1]
- **Spec ref**: Operational observability
- **Files**: Services (BookingService, PaymentService, etc.)
- **Acceptance**: Key operations logged for debugging and SLA monitoring
- [ ] Log on booking creation (DEBUG level): service, customer, slot, status
- [ ] Log on payment initiation (DEBUG): amount, provider
- [ ] Log on payment confirmation (INFO): booking ID, amount, transaction ID
- [ ] Log on reminder email send (DEBUG): booking ID, customer email
- [ ] Log on errors: exception class, message (not full trace in production)
- [ ] Structured logging with context tags: `booking_id`, `customer_id`, `service_id`

### Task 11.3: Performance baseline for availability queries [V1]
- **Spec ref**: Non-functional requirements
- **Files**: Load test or performance test
- **Acceptance**: Availability queries responsive under load
- [ ] Benchmark: queryAvailableSlots() with 100 existing bookings per resource → should respond in <500ms
- [ ] Test: Calendar week view with 5 resources × 35 bookings → dashboard renders in <2 seconds
- [ ] Verify AvailabilityCache hit rate: >90% of queries should hit cache (not compute fresh)

---

## Section 12: Acceptance & Closure

### Task 12.1: Manual smoke test of public portal [FINAL]
- **Files**: Manual testing or recorded E2E test
- **Acceptance**: Portal user journey works end-to-end
- [ ] Navigate to `/book/haircut`, select available time, enter customer info, submit
- [ ] Receive confirmation email with appointment details and `.ics` attachment
- [ ] Click reschedule link in email (no login required), select new time, confirm
- [ ] Verify old booking marked rescheduled, new booking created
- [ ] Click cancel link in email, confirm cancellation
- [ ] Verify cancellation policy displayed (if applicable) and fee charged (or not, depending on policy)

### Task 12.2: Verify no regressions in existing CRM functionality [FINAL]
- **Files**: Automated test suite
- **Acceptance**: Existing CRM features unaffected
- [ ] Client detail timeline still shows contactmomenten, requests, tasks (Booking card added below)
- [ ] Skill-routing queries unchanged (this spec reads from skill-routing, does not modify)
- [ ] Email-calendar-sync confirmation emails unaffected by new booking emails
- [ ] OpenRegister CRUD for other schemas (client, contact, lead, request) unchanged
- [ ] Run full test suite: all existing tests pass

### Task 12.3: Prepare for V2 feature pipeline [FINAL]
- **Files**: Document in PR description or issue
- **Acceptance**: Clear backlog for next iterations
- [ ] V2 candidates:
  - Bulk reminder emails (batch SMS dispatch)
  - Booking analytics dashboard (no-show rates, utilization)
  - Recurring bookings / subscriptions
  - Custom booking form fields per service
  - Waitlist / backlog for fully-booked services
  - Advanced rescheduling (drag-to-reschedule on dashboard calendar)
- [ ] Healthcare-booking-extension (NEN-7510 + WGBO) for physio/psych/GP
