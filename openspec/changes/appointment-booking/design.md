# Design: appointment-booking

## Overview

This change adds appointment booking and resource scheduling to Pipelinq by implementing a complete booking engine, customer-facing portal, bi-directional calendar sync, and operator dashboard. The solution reuses existing OpenRegister infrastructure (Service, Resource, Booking, WalkInTicket, AvailabilityCache schemas defined in ADR-000) and integrates with skill-routing (resource skill matching), email-calendar-sync (confirmation/reminder emails, calendar sync), and openconnector (payment processing).

No new schemas are introduced. Seed data includes realistic Dutch service, resource, and booking examples for testing.

---

## Architecture

### Data Layer

All schemas are already defined in ADR-000 (`lib/Settings/pipelinq_register.json`). This change only adds seed data and wires the services to populate and query them.

#### Service (existing schema)

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| id | string | Yes | UUID |
| name | string | Yes | e.g., "Knippen" (haircut) |
| description | string | No | e.g., "Standaard damesknippen" |
| durationMinutes | integer | Yes | e.g., 30 |
| bufferBeforeMinutes | integer | No | e.g., 5 (setup time) |
| bufferAfterMinutes | integer | No | e.g., 5 (cleanup time) |
| price | number | No | e.g., 25.00 |
| currency | string | No | e.g., "EUR" |
| requiredSkills | array | No | e.g., ["base-cut"] (from skill-routing) |
| requiredResourceTypes | array | No | e.g., ["staff"] |
| multiStep | array | No | Array of `{durationMinutes, skill, resourceType, allowGap}` |
| bookableOnline | boolean | No | Whether the service can be self-booked via portal |
| requiresDeposit | boolean | No | e.g., true |
| depositAmount | number | No | e.g., 20.00 |
| noShowFee | number | No | e.g., 25.00 |
| cancellationPolicy | string | No | e.g., "free-until-24-hours-before" |

#### Resource (existing schema)

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| id | string | Yes | UUID |
| name | string | Yes | e.g., "Alex" (stylist), "Treatment Room B" |
| type | string | Yes | e.g., "staff", "room", "equipment" |
| skills | array | No | e.g., ["base-cut", "color-certified"] (from skill-routing) |
| workingHours | array | No | Array of `{weekday: "Mon", startTime: "09:00", endTime: "17:00"}` |
| vacations | array | No | Array of `{startDate, endDate}` (date ranges blocked) |
| calendarSyncId | string | No | Link to email-calendar-sync calendar (e.g., Outlook calendar ID) |
| bookable | boolean | No | Whether this resource can be booked |
| userId | string | No | Nextcloud user UID (for staff members) |

#### Booking (existing schema)

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| id | string | Yes | UUID |
| customerId | string | Yes | Reference to Customer from client-management |
| serviceId | string | Yes | Reference to Service |
| resourceAssignments | array | Yes | Array of `{resourceId, startAt, endAt}` per multi-step |
| startAt | string | Yes | ISO 8601 datetime |
| endAt | string | Yes | ISO 8601 datetime |
| status | string | Yes | e.g., "pending-deposit", "confirmed", "completed", "no-show", "cancelled-by-customer", "cancelled-by-business", "rescheduled" |
| notes | string | No | Customer-provided notes |
| internalNotes | string | No | Staff internal notes |
| source | string | No | e.g., "portal", "widget", "phone", "walk-in", "imported" |
| confirmationSentAt | string | No | ISO 8601 timestamp |
| reminderSentAt | string | No | ISO 8601 timestamp |
| depositPaidAt | string | No | ISO 8601 timestamp |
| noShowFeeChargedAt | string | No | ISO 8601 timestamp |
| previousBookingId | string | No | Reference to earlier Booking if rescheduled |

#### WalkInTicket (existing schema)

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| id | string | Yes | UUID |
| customerId | string | No | Reference to Customer (optional for anonymous walk-ins) |
| displayName | string | Yes | Customer's name (used if customerId not set) |
| phone | string | No | Customer's phone |
| serviceId | string | Yes | Reference to Service |
| arrivedAt | string | Yes | ISO 8601 timestamp |
| estimatedReadyAt | string | No | ISO 8601 timestamp (computed on arrival) |
| status | string | Yes | e.g., "waiting", "called", "served", "abandoned" |
| assignedResourceId | string | No | Resource assigned to serve the walk-in |

#### AvailabilityCache (existing schema)

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| resourceId | string | Yes | Reference to Resource |
| date | string | Yes | ISO 8601 date (e.g., "2026-05-23") |
| freeBlocks | array | Yes | Array of `{start: "09:00", end: "09:15"}` |

---

## Seed Data

### Services

```json
[
  {
    "slug": "service-haircut-001",
    "@self": {"register": "pipelinq", "schema": "service"},
    "name": "Standaard Knippen",
    "description": "Dames- of herenknippen inclusief styling",
    "durationMinutes": 30,
    "bufferBeforeMinutes": 5,
    "bufferAfterMinutes": 5,
    "price": 25.00,
    "currency": "EUR",
    "requiredSkills": ["base-cut"],
    "requiredResourceTypes": ["staff"],
    "bookableOnline": true,
    "requiresDeposit": false,
    "cancellationPolicy": "free-until-24-hours-before"
  },
  {
    "slug": "service-color-cut-002",
    "@self": {"register": "pipelinq", "schema": "service"},
    "name": "Kleuren + Knippen",
    "description": "Volledige haarbehandeling: kleuren en knippen",
    "durationMinutes": 90,
    "bufferBeforeMinutes": 10,
    "bufferAfterMinutes": 5,
    "price": 75.00,
    "currency": "EUR",
    "requiredSkills": ["base-cut", "color-certified"],
    "multiStep": [
      {"durationMinutes": 45, "skill": "color-certified", "resourceType": "staff"},
      {"durationMinutes": 30, "skill": "base-cut", "resourceType": "staff", "allowGap": true}
    ],
    "bookableOnline": true,
    "requiresDeposit": true,
    "depositAmount": 25.00,
    "cancellationPolicy": "always-charge"
  },
  {
    "slug": "service-physiotherapy-003",
    "@self": {"register": "pipelinq", "schema": "service"},
    "name": "Fysiotherapie Sessie",
    "description": "Individuele behandeling (45 minuten)",
    "durationMinutes": 45,
    "bufferBeforeMinutes": 5,
    "bufferAfterMinutes": 5,
    "price": 60.00,
    "currency": "EUR",
    "requiredSkills": ["physiotherapy-licensed"],
    "requiredResourceTypes": ["staff"],
    "bookableOnline": true,
    "requiresDeposit": false,
    "noShowFee": 30.00,
    "cancellationPolicy": "free-until-48-hours-before"
  },
  {
    "slug": "service-garage-diag-004",
    "@self": {"register": "pipelinq", "schema": "service"},
    "name": "Motordiagnose",
    "description": "Elektronische diagnose van motorproblemen",
    "durationMinutes": 60,
    "bufferBeforeMinutes": 0,
    "bufferAfterMinutes": 0,
    "price": 85.00,
    "currency": "EUR",
    "requiredSkills": ["bmw-certified", "diagnostic-equipment"],
    "requiredResourceTypes": ["staff", "equipment"],
    "bookableOnline": true,
    "requiresDeposit": true,
    "depositAmount": 40.00,
    "cancellationPolicy": "free-until-48-hours-before"
  },
  {
    "slug": "service-consultation-005",
    "@self": {"register": "pipelinq", "schema": "service"},
    "name": "Belastingadvies Consult",
    "description": "Eenmalige besprekking (60 minuten)",
    "durationMinutes": 60,
    "bufferBeforeMinutes": 15,
    "bufferAfterMinutes": 15,
    "price": 150.00,
    "currency": "EUR",
    "requiredSkills": ["tax-advisor"],
    "requiredResourceTypes": ["staff"],
    "bookableOnline": true,
    "requiresDeposit": false,
    "cancellationPolicy": "always-charge"
  }
]
```

### Resources

```json
[
  {
    "slug": "resource-stylist-alex-001",
    "@self": {"register": "pipelinq", "schema": "resource"},
    "name": "Alex (Kapper)",
    "type": "staff",
    "skills": ["base-cut", "color-certified"],
    "workingHours": [
      {"weekday": "Mon", "startTime": "09:00", "endTime": "17:00"},
      {"weekday": "Tue", "startTime": "09:00", "endTime": "17:00"},
      {"weekday": "Wed", "startTime": "09:00", "endTime": "17:00"},
      {"weekday": "Thu", "startTime": "09:00", "endTime": "17:00"},
      {"weekday": "Fri", "startTime": "09:00", "endTime": "17:00"}
    ],
    "vacations": [],
    "bookable": true,
    "userId": "alex"
  },
  {
    "slug": "resource-stylist-maya-002",
    "@self": {"register": "pipelinq", "schema": "resource"},
    "name": "Maya (Kapper)",
    "type": "staff",
    "skills": ["base-cut"],
    "workingHours": [
      {"weekday": "Mon", "startTime": "10:00", "endTime": "18:00"},
      {"weekday": "Tue", "startTime": "10:00", "endTime": "18:00"},
      {"weekday": "Wed", "startTime": "10:00", "endTime": "18:00"},
      {"weekday": "Sat", "startTime": "09:00", "endTime": "17:00"}
    ],
    "vacations": [
      {"startDate": "2026-06-20", "endDate": "2026-07-05"}
    ],
    "bookable": true,
    "userId": "maya"
  },
  {
    "slug": "resource-therapist-jan-003",
    "@self": {"register": "pipelinq", "schema": "resource"},
    "name": "Jan (Fysiotherapeut)",
    "type": "staff",
    "skills": ["physiotherapy-licensed"],
    "workingHours": [
      {"weekday": "Mon", "startTime": "08:00", "endTime": "16:00"},
      {"weekday": "Tue", "startTime": "08:00", "endTime": "16:00"},
      {"weekday": "Wed", "startTime": "08:00", "endTime": "16:00"},
      {"weekday": "Thu", "startTime": "08:00", "endTime": "16:00"},
      {"weekday": "Fri", "startTime": "08:00", "endTime": "16:00"}
    ],
    "vacations": [],
    "bookable": true,
    "userId": "jan"
  },
  {
    "slug": "resource-mechanic-piet-004",
    "@self": {"register": "pipelinq", "schema": "resource"},
    "name": "Piet (Monteur)",
    "type": "staff",
    "skills": ["bmw-certified", "diagnostic-equipment"],
    "workingHours": [
      {"weekday": "Mon", "startTime": "08:00", "endTime": "17:00"},
      {"weekday": "Tue", "startTime": "08:00", "endTime": "17:00"},
      {"weekday": "Wed", "startTime": "08:00", "endTime": "17:00"},
      {"weekday": "Thu", "startTime": "08:00", "endTime": "17:00"},
      {"weekday": "Fri", "startTime": "08:00", "endTime": "17:00"}
    ],
    "vacations": [],
    "bookable": true,
    "userId": "piet"
  },
  {
    "slug": "resource-diagnostic-bay-005",
    "@self": {"register": "pipelinq", "schema": "resource"},
    "name": "Diagnosetafel B",
    "type": "equipment",
    "skills": ["diagnostic-equipment"],
    "workingHours": [
      {"weekday": "Mon", "startTime": "08:00", "endTime": "17:00"},
      {"weekday": "Tue", "startTime": "08:00", "endTime": "17:00"},
      {"weekday": "Wed", "startTime": "08:00", "endTime": "17:00"},
      {"weekday": "Thu", "startTime": "08:00", "endTime": "17:00"},
      {"weekday": "Fri", "startTime": "08:00", "endTime": "17:00"}
    ],
    "vacations": [],
    "bookable": true
  }
]
```

### Example Bookings (seed data)

```json
[
  {
    "slug": "booking-001",
    "@self": {"register": "pipelinq", "schema": "booking"},
    "customerId": "customer-001-uuid",
    "serviceId": "service-haircut-001-uuid",
    "resourceAssignments": [
      {"resourceId": "resource-stylist-alex-001-uuid", "startAt": "2026-05-25T10:00:00Z", "endAt": "2026-05-25T10:30:00Z"}
    ],
    "startAt": "2026-05-25T10:00:00Z",
    "endAt": "2026-05-25T10:30:00Z",
    "status": "confirmed",
    "source": "portal",
    "confirmationSentAt": "2026-05-22T14:30:00Z"
  },
  {
    "slug": "booking-002",
    "@self": {"register": "pipelinq", "schema": "booking"},
    "customerId": "customer-002-uuid",
    "serviceId": "service-color-cut-002-uuid",
    "resourceAssignments": [
      {"resourceId": "resource-stylist-alex-001-uuid", "startAt": "2026-05-26T14:00:00Z", "endAt": "2026-05-26T14:45:00Z"},
      {"resourceId": "resource-stylist-alex-001-uuid", "startAt": "2026-05-26T15:15:00Z", "endAt": "2026-05-26T15:30:00Z"}
    ],
    "startAt": "2026-05-26T14:00:00Z",
    "endAt": "2026-05-26T15:30:00Z",
    "status": "pending-deposit",
    "notes": "Grijze uiterst",
    "source": "portal"
  },
  {
    "slug": "booking-003",
    "@self": {"register": "pipelinq", "schema": "booking"},
    "customerId": "customer-003-uuid",
    "serviceId": "service-physiotherapy-003-uuid",
    "resourceAssignments": [
      {"resourceId": "resource-therapist-jan-003-uuid", "startAt": "2026-05-27T09:00:00Z", "endAt": "2026-05-27T09:45:00Z"}
    ],
    "startAt": "2026-05-27T09:00:00Z",
    "endAt": "2026-05-27T09:45:00Z",
    "status": "confirmed",
    "source": "portal",
    "confirmationSentAt": "2026-05-22T13:00:00Z"
  }
]
```

---

## Backend Services

### AvailabilityQueryService (`lib/Service/AvailabilityQueryService.php`)

Core engine for computing available booking slots.

**Dependencies:**
- `OCA\OpenRegister\Service\ObjectService` — Query Resource, Booking, AvailabilityCache objects
- `OCA\Pipelinq\Service\SkillRoutingService` — Query eligible resources for a service's required skills
- `OCA\Pipelinq\Service\CalendarSyncService` — Pull calendar blocks (vacation, synced events) for resources
- `OCP\IAppConfig` — Read organization timezone

**Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `queryAvailableSlots` | `queryAvailableSlots(string $serviceId, string $date, ?string $resourceId = null): array` | Return list of available 15-minute slot start times for a service on a given date. Optionally filter by resource. Intersects skill-routing matches with working hours, existing bookings, and calendar blocks. |
| `matchResourcesForService` | `matchResourcesForService(string $serviceId): array` | Query skill-routing for resources matching a service's required skills. Returns array of Resource UUIDs. |
| `buildAvailabilityCache` | `buildAvailabilityCache(string $resourceId, string $date): array` | Compute free 15-minute blocks for a resource on a date. Considers working hours, vacations, existing bookings, and calendar sync blocks. Stores in AvailabilityCache. |
| `invalidateCacheForResource` | `invalidateCacheForResource(string $resourceId, string $date): void` | Delete AvailabilityCache entries for a resource on a date (called on booking/resource changes). |

### BookingService (`lib/Service/BookingService.php`)

Booking lifecycle management: creation, status transitions, reschedule, cancellation.

**Dependencies:**
- `OCA\OpenRegister\Service\ObjectService` — CRUD Booking, WalkInTicket, Customer objects
- `OCA\Pipelinq\Service\PaymentService` — Deposit payment, no-show fee charging
- `OCA\Pipelinq\Service\EmailService` — Confirmation, reminder, cancellation emails
- `OCA\Pipelinq\Service\AvailabilityQueryService` — Validate slot availability
- `OCP\IAppConfig` — Read payment provider config (Mollie/Stripe)
- `OCP\IDateTimeFormatter` — Format dates/times for emails

**Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `createBooking` | `createBooking(string $serviceId, string $customerId, string $startAt, ?array $resourceAssignments = null): array` | Create Booking, validate slot, initiate deposit payment if required, send confirmation email. Returns created Booking object. |
| `confirmBooking` | `confirmBooking(string $bookingId): void` | Transition Booking from `pending-deposit` to `confirmed` after payment success. Send confirmation email. |
| `rescheduleBooking` | `rescheduleBooking(string $bookingId, string $newStartAt): array` | Create new Booking for new time, mark original as `rescheduled`, link with `previousBookingId`. Send notification to customer. Return new Booking. |
| `cancelBooking` | `cancelBooking(string $bookingId, string $reason = null): void` | Mark Booking as `cancelled-by-customer` or `cancelled-by-business`, free the slot, optionally charge cancellation fee. Send cancellation email. |
| `markNoShow` | `markNoShow(string $bookingId): void` | Mark Booking as `no-show`, increment Customer `noShowCount`, optionally charge no-show fee via payment provider. |
| `generateSignedLink` | `generateSignedLink(string $bookingId, string $action): string` | Generate a signed link for reschedule/cancel actions (valid 30 days). |

### WalkInQueueService (`lib/Service/WalkInQueueService.php`)

Walk-in queue management: arrival, assignment, wait-time estimation.

**Dependencies:**
- `OCA\OpenRegister\Service\ObjectService` — CRUD WalkInTicket objects
- `OCA\Pipelinq\Service\AvailabilityQueryService` — Compute resource gaps for estimated ready time
- `OCP\IDateTimeFormatter` — Time formatting

**Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `createWalkInTicket` | `createWalkInTicket(string $serviceId, string $displayName, ?string $customerId = null): array` | Create WalkInTicket, estimate ready time based on resource gaps, set `status: waiting`. Return ticket. |
| `assignWalkIn` | `assignWalkIn(string $ticketId, string $resourceId): void` | Assign ticket to resource, update `assignedResourceId`, update `estimatedReadyAt` based on resource's current load. |
| `updateWaitingQueue` | `updateWaitingQueue(string $resourceId): void` | Called when a booking completes for a resource. Rebalance remaining walk-in tickets, update estimated times for affected tickets. |
| `callWalkIn` | `callWalkIn(string $ticketId): void` | Operator marks ticket as `called`. |
| `serveWalkIn` | `serveWalkIn(string $ticketId): void` | Operator marks ticket as `served`. If a Nextcloud user is linked, optionally create a Booking record with `source: walk-in`. |

### PaymentService (`lib/Service/PaymentService.php`)

Payment processing via openconnector (Mollie/Stripe/Adyen).

**Dependencies:**
- `OCA\OpenConnector\Service\PaymentService` — Charge deposits, cancellation fees, no-show fees
- `OCP\IAppConfig` — Read payment provider config

**Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `initiateDepositPayment` | `initiateDepositPayment(string $bookingId, float $amount): string` | Create payment session via openconnector, return redirect URL (customer redirected to payment gateway). |
| `confirmDepositPayment` | `confirmDepositPayment(string $bookingId): bool` | Verify payment success, update Booking `depositPaidAt`. Return true if successful. |
| `chargeCancellationFee` | `chargeCancellationFee(string $bookingId): bool` | Charge late-cancellation fee via openconnector. Return true if successful. |
| `chargeNoShowFee` | `chargeNoShowFee(string $bookingId): bool` | Charge no-show fee via openconnector. Return true if successful. |

---

## Frontend Components

### Public Portal (`resources/js/views/BookingPortal.vue`)

Customer-facing public booking interface.

**Props:** `serviceSlug` (URL param)

**Template structure:**
- Service name, description, price
- Calendar date picker
- Available time slots (15-minute resolution, updated on date change)
- Customer info form (name, email, phone)
- Notes field (optional)
- Deposit warning + payment button (if `requiresDeposit`)
- Confirmation screen post-booking with `.ics` attachment download

**Key methods:**
- `loadServiceBySlug()` — Fetch service details
- `loadAvailableSlots()` — Call availability API on date change
- `submitBooking()` — POST to booking API
- `onDepositPaymentReturn()` — Handle post-payment redirect

### Booking Dashboard (`resources/js/views/BookingDashboard.vue`)

Operator/staff view: calendar, walk-in queue, booking details.

**Main sections:**
- **Calendar view** (week/month): Bookings per resource, color-coded by service, drag-to-reschedule
- **Walk-in queue** (list): Pending walk-ins with estimated ready times, assign-to-resource dropdown
- **Booking details** (side panel): Customer info, notes, reschedule/cancel buttons, mark-as-no-show option

**Methods:**
- `loadResourceBookings()` — Fetch bookings for selected resource and date range
- `loadWalkInQueue()` — Fetch pending walk-in tickets
- `onBookingClick()` — Show booking detail panel
- `markNoShow()` — Trigger no-show marking with confirmation

### Booking Widget (`resources/js/components/BookingWidget.vue`)

Embeddable iframe widget for business websites.

**Props:** `serviceId`, `tenantUrl`

**Behavior:** Render a compact booking form (date, time, customer info) that posts to the portal backend. On success, display confirmation or redirect to confirmation page on the portal.

---

## API Routes

New REST endpoints (appinfo/routes.php):

- `GET /api/booking/availability` — Query available slots (params: serviceId, date, resourceId)
- `POST /api/booking/create` — Create booking from portal (body: serviceId, customerId, startAt, notes)
- `POST /api/booking/:id/confirm-deposit` — Confirm deposit payment success
- `POST /api/booking/:id/reschedule` — Reschedule booking (body: newStartAt)
- `POST /api/booking/:id/cancel` — Cancel booking (body: reason)
- `POST /api/booking/:id/no-show` — Mark as no-show
- `GET /api/booking/:id/confirm-link` — Verify signed confirmation link
- `GET /api/booking/:id/reschedule-link` — Verify signed reschedule link
- `GET /api/booking/:id/cancel-link` — Verify signed cancel link
- `POST /api/walk-in/create` — Create walk-in ticket
- `POST /api/walk-in/:id/assign` — Assign to resource
- `POST /api/walk-in/:id/call` — Mark as called
- `POST /api/walk-in/:id/serve` — Mark as served
- `GET /api/walk-in/queue` — List pending walk-ins for operator dashboard

---

## Database / Seed Data Integration

Seed Service, Resource, Booking, WalkInTicket objects are added to `lib/Settings/pipelinq_register.json` under `components.objects[]` with `@self` envelopes per ADR-015. Each object includes realistic Dutch names and values for testing.
