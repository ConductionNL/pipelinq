# Design: Appointment Booking

## Overview

This change adds appointment scheduling to Pipelinq by implementing new OpenRegister schemas for Service, Resource, Booking, and WalkInTicket, along with availability computation, a public booking portal, and email/calendar workflows. The design reuses customer records from client-management, skill queries from skill-routing, email dispatch from email-calendar-sync, and payment processing from openconnector.

No changes to existing schemas; no duplication of core services. The AvailabilityCache is a read-only performance optimization (regenerated, never manually edited).

---

## Architecture

### Data Layer

Four new OpenRegister schemas are introduced. These schemas are defined in the Pipelinq register manifest — no changes to the global ADR-000 (those remain for other apps).

#### Service

A bookable offering (e.g., "Haircut", "Oil change", "Tax consultation"). Defines duration, pricing, skill requirements, multi-step composition, and policies.

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| name | string | Yes | Service name (e.g., "Color + Cut") |
| description | string | No | Detailed description for public portal |
| durationMinutes | integer | Yes | Total elapsed time for customer (e.g., 90 for color+cut) |
| bufferBeforeMinutes | integer | No | Buffer time before this service (e.g., 15 min setup); default 0 |
| bufferAfterMinutes | integer | No | Buffer time after this service (e.g., 15 min cleanup); default 0 |
| price | number | No | Service price in euros |
| currency | string | No | ISO currency code; default EUR |
| requiredSkills | array | No | Skill slugs from skill-routing (e.g., `["color-certified", "advanced-styling"]`) |
| requiredResourceTypes | array | No | Resource types that must be booked (e.g., `["staff", "room"]`) |
| multiStep | array | No | Array of sub-steps: each has `durationMinutes`, `skillRequired`, `resourceType`, `allowGap` (bool) |
| bookableOnline | boolean | No | Whether customers can self-book via portal; default true |
| requiresDeposit | boolean | No | Whether booking requires upfront deposit; default false |
| depositAmount | number | No | Deposit amount in euros if `requiresDeposit` true |
| noShowFee | number | No | Fee charged if customer no-shows (in euros) |
| cancellationPolicy | string | No | Enum: `free-until-N-hours-before` | `always-charge` | `no-charge`; default `free-until-24-hours-before` |
| cancellationHoursBefore | integer | No | Hours before appointment that cancellation is free; default 24 |
| status | string | No | Lifecycle status (active/archived); default active |

#### Resource

A staff member, room, or equipment that can be assigned to Bookings.

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| name | string | Yes | Resource name (e.g., "Sarah (Stylist)", "Treatment Room A", "Diagnostic Bay") |
| type | string | Yes | Enum: `staff`, `room`, `equipment` |
| skills | array | No | Skill slugs from skill-routing this resource possesses (e.g., `["color-certified", "advanced-styling"]`) |
| workingHours | array | No | Array of weekday entries: `{ day: "monday", openTime: "09:00", closeTime: "17:30", ... }` |
| vacations | array | No | Array of date ranges: `{ startDate: "2026-06-01", endDate: "2026-06-15", label: "Summer holiday" }` |
| calendarSyncId | string | No | ID linking to email-calendar-sync calendar (Outlook, Google, iCloud account) |
| bookable | boolean | No | Whether this resource is available for new bookings; default true |
| userId | string | No | Nextcloud user UID if type is staff (links to CRM agent) |
| maxConcurrent | integer | No | Maximum concurrent bookings for this resource (e.g., treatment room can handle 1, waiting area can handle 10) |
| status | string | No | Lifecycle status (active/archived); default active |

#### Booking

A scheduled appointment linking Customer → Service → Resource(s).

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| customerId | string | Yes | UUID reference to Customer (from client-management) |
| serviceId | string | Yes | UUID reference to Service |
| resourceAssignments | array | Yes | Per-step resource assignment: `{ stepIndex, resourceId, startAt, endAt }` for multi-step services |
| startAt | string | Yes | ISO 8601 datetime when appointment begins |
| endAt | string | Yes | ISO 8601 datetime when appointment ends (computed from durationMinutes) |
| status | string | Yes | Lifecycle: `pending-deposit`, `confirmed`, `completed`, `no-show`, `cancelled-by-customer`, `cancelled-by-business`, `rescheduled` |
| statusHistory | array | No | Audit trail of status transitions: `{ status, changedAt, changedBy, reason }` |
| notes | string | No | Customer-visible booking notes (e.g., "Extra pillow requested") |
| internalNotes | string | No | Staff-only notes (e.g., "Customer is hard of hearing, speak clearly") |
| source | string | No | Enum: `portal`, `widget`, `phone`, `walk-in`, `imported`; default `portal` |
| confirmationSentAt | string | No | ISO datetime when confirmation email was sent |
| reminderSentAt | string | No | ISO datetime when reminder email/SMS was sent |
| depositPaidAt | string | No | ISO datetime when deposit payment was received (if applicable) |
| depositAmount | number | No | Amount of deposit paid (stored for audit) |
| noShowFeeChargedAt | string | No | ISO datetime when no-show fee was charged |
| cancellationReason | string | No | Reason provided by customer (if cancelled) |
| cancelledAt | string | No | ISO datetime when booking was cancelled |
| cancelledBy | string | No | Nextcloud user UID of staff who cancelled (if cancelled-by-business) or 'customer' |
| previousBookingId | string | No | UUID of original booking if this is a reschedule (links reschedule chain) |

#### WalkInTicket

A queue entry for unscheduled arrivals.

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| customerId | string | No | UUID reference to Customer (may be anonymous) |
| displayName | string | No | Display name if customer is not in CRM |
| phone | string | No | Contact phone if customer is anonymous |
| serviceId | string | Yes | UUID reference to Service (what are they here for?) |
| arrivedAt | string | Yes | ISO datetime when they walked in |
| estimatedReadyAt | string | No | ISO datetime when we estimate they'll be served (computed from gaps in schedule) |
| status | string | Yes | Enum: `waiting`, `called`, `served`, `abandoned` |
| assignedResourceId | string | No | UUID of Resource assigned to serve them (if status is not waiting) |
| actualServedAt | string | No | ISO datetime when they were actually served |

#### AvailabilityCache

Read-only cache of free blocks per resource per day. Regenerated on Service/Resource/Booking changes; never edited directly.

| Property | Type | Required | Notes |
|----------|------|----------|-------|
| resourceId | string | Yes | UUID reference to Resource |
| date | string | Yes | ISO date (YYYY-MM-DD) |
| freeBlocks | array | Yes | Array of `{ startTime, endTime, durationMinutes, bookable: true/false }` — unblocked 15-min-aligned slots |
| generatedAt | string | Yes | ISO datetime when cache was last computed |
| expiresAt | string | No | Cache expires after 24h; stale cache still usable but regenerated on next change |

---

## Backend

### AvailabilityService (`lib/Service/AvailabilityService.php`)

Computes free slots per resource per day by intersecting working hours, vacations, booked times, and calendar-synced blocks.

**Dependencies:**
- `OCA\OpenRegister\Service\ObjectService` — Query Service, Resource, Booking objects
- `OCA\EmailCalendarSync\Service\CalendarSyncService` — Fetch calendar-synced blocks for a resource
- `OCP\ICacheFactory` — Cache manager for AvailabilityCache TTL
- `OCP\IUserManager` — Lookup Nextcloud user for staff resources

**Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `computeAvailability` | `computeAvailability(string $resourceId, string $date, int $serviceId): array` | Return array of free 15-min-aligned blocks for the resource on that date that fit the service duration |
| `getWorkingHours` | `getWorkingHours(string $resourceId, string $weekDay): ?array` | Return `{ openTime, closeTime }` for resource on weekday |
| `getBlockedTimes` | `getBlockedTimes(string $resourceId, string $date): array` | Return all time blocks occupied by vacations, existing bookings, and calendar-synced events |
| `alignToSlots` | `alignToSlots(string $startTime, string $endTime, int $intervalMinutes): array` | Split a time range into N-minute-aligned slots (e.g., 15-min) |
| `invalidateCache` | `invalidateCache(string $resourceId, string $date): void` | Manually invalidate cache (called on Booking create/update/delete) |

### BookingService (`lib/Service/BookingService.php`)

Booking lifecycle: creation, confirmation, reminders, cancellation, rescheduling, no-show handling.

**Dependencies:**
- `OCA\OpenRegister\Service\ObjectService` — CRUD Booking objects
- `OCA\SkillRouting\Service\SkillMatchService` — Query eligible resources for a service
- `AvailabilityService` — Check if slot is free
- `OCA\EmailCalendarSync\Service\EmailDispatchService` — Send confirmation/reminder emails
- `OCA\OpenConnector\Service\PaymentService` — Process deposits and no-show fees
- `OCP\IUserSession`, `OCP\IUserManager` — Current user context
- `OCP\IAppConfig` — App settings (payment provider config, etc.)

**Methods:**

| Method | Signature | Purpose |
|--------|-----------|---------|
| `createBooking` | `createBooking(array $data, string $source): string` | Create a new Booking with validation. Return booking UUID. |
| `getAvailableSlots` | `getAvailableSlots(string $serviceId, string $date): array` | Return all bookable slots for a service on a date (intersects eligible resources via skill-routing) |
| `confirmBooking` | `confirmBooking(string $bookingId, string $reason): void` | Transition booking to confirmed, send confirmation email |
| `rescheduleBooking` | `rescheduleBooking(string $bookingId, string $newStartAt): string` | Create a new Booking for the new time, mark original as rescheduled, return new UUID |
| `cancelBooking` | `cancelBooking(string $bookingId, string $reason, string $cancelledBy): void` | Transition booking to cancelled-by-customer or cancelled-by-business, apply cancellation fee if applicable |
| `markNoShow` | `markNoShow(string $bookingId, string $staffUserId): void` | Transition booking to no-show, increment customer no-show count, charge fee if applicable |
| `completeBooking` | `completeBooking(string $bookingId): void` | Transition booking to completed (called by staff after appointment ends) |
| `getEligibleResources` | `getEligibleResources(string $serviceId): array` | Query skill-routing for resources matching service's requiredSkills |

### PortalController (`lib/Controller/PortalController.php`)

Public API for the booking portal (no auth required).

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `GET` | `/portal/services` | Public | List all `bookableOnline: true` services with details |
| `GET` | `/portal/availability` | Public | Get available slots: `?serviceId=X&date=YYYY-MM-DD` |
| `POST` | `/portal/book` | Public | Create a booking from portal form; return confirmation + payment redirect if needed |
| `GET` | `/portal/booking/{bookingId}` | Public | Fetch booking details (no auth, but link must be signed/valid) |
| `POST` | `/portal/reschedule` | Public | Reschedule booking via signed link (validates signature) |
| `POST` | `/portal/cancel` | Public | Cancel booking via signed link (validates signature) |

**Dependencies**: Same as BookingService, plus `IURLGenerator` for signed deep-links

### BackgroundJobs

#### AvailabilityCacheRefreshJob (`lib/BackgroundJob/AvailabilityCacheRefreshJob.php`)

Refreshes AvailabilityCache for all resources every hour (tunable). Runs regardless of changes; catches stale cache.

- **Interval**: 3600 seconds (1 hour)
- **Method**: Iterate all active Resources, call `AvailabilityService::invalidateCache()` for today+30 days
- **Logging**: Log count of cache entries refreshed, any errors

#### ReminderDispatchJob (`lib/BackgroundJob/ReminderDispatchJob.php`)

Sends 24-hour reminders for upcoming bookings.

- **Interval**: 300 seconds (5 minutes)
- **Method**: Query Bookings with `startAt` between now+23h and now+24h, `status: confirmed`, `reminderSentAt: null`. Call `EmailDispatchService::sendReminder()`. Set `reminderSentAt` on success.

#### WalkInQueueRebalanceJob (`lib/BackgroundJob/WalkInQueueRebalanceJob.php`)

Rebalances walk-in queue whenever a Booking completes.

- **Trigger**: Event listener on Booking status change to completed
- **Method**: Query WalkInTickets with `status: waiting`, recompute `estimatedReadyAt` for each based on updated schedule

---

## Frontend

### Public Booking Portal (`src/views/portal/BookingPortal.vue`)

**Route**: `/book/{serviceSlug}` (no auth required, public)

**Components**:
- Service detail header (name, description, duration, price)
- Date picker (calendar showing available dates)
- Slot picker (15-min-aligned times for selected date, filtered by skill-routing eligible resources)
- Booking form (name, email, phone, optional notes)
- Submit → `POST /portal/book`
- On success: confirmation message + payment redirect (if deposit required) or booking confirmed page

**Props**: `serviceSlug` from route

**Composables**: None (minimal Pinia usage for non-authenticated portal)

**Styling**: NL Design System tokens, WCAG 2.1 AA (keyboard-navigable, screen-reader-friendly, Dutch + English)

### BookingManagement Components

#### ServiceList.vue (`src/views/services/ServiceList.vue`)

Admin view for managing Services. Uses standard `CnIndexPage` with `useListView`.

#### ServiceDetail.vue (`src/views/services/ServiceDetail.vue`)

Edit/view Service with fields from schema. Multi-step section uses a sub-table or draggable list.

#### ResourceList.vue (`src/views/resources/ResourceList.vue`)

Admin list of Resources (staff, rooms, equipment).

#### ResourceDetail.vue (`src/views/resources/ResourceDetail.vue`)

Edit Resource with working hours table (weekday rows, open/close time fields), vacations array, calendar sync selector.

#### BookingList.vue (`src/views/bookings/BookingList.vue`)

Admin list of Bookings with filters: date range, status, resource, service. Uses `CnIndexPage` with `useListView`.

#### BookingDetail.vue (`src/views/bookings/BookingDetail.vue`)

View/edit Booking with:
- Booking header: customer name, service, resource, date/time, status badge
- Status actions: if `pending-deposit`, show payment button; if `confirmed`, show "Mark as completed" + "Mark as no-show" buttons
- Customer timeline section showing linked emails (via email-calendar-sync)
- Reschedule/cancel actions (if staff, always allowed; if customer, only via signed link in email)
- Resource assignments table (for multi-step services)
- Audit trail (status history)

#### WalkInQueuePanel.vue (`src/components/bookings/WalkInQueuePanel.vue`)

Real-time queue view showing waiting customers, estimated ready time, "Call next" button.

### Customer Timeline Integration

#### BookingsCard.vue (`src/components/bookings/BookingsCard.vue`)

Added to `CustomerDetail.vue` to show past and future Bookings on the customer record. Links to BookingDetail for each.

---

## Reuse Analysis

Per ADR-012, the following services are reused directly — no custom rebuilding:

| Capability | Reused From | Usage |
|------------|-------------|-------|
| Object CRUD | `ObjectService.saveObject()`, `findObjects()` | Create/query Service, Resource, Booking, WalkInTicket |
| Audit trail | Automatic via OpenRegister | All Booking state changes tracked |
| Relations | OpenRegister relations plugin | Link Booking → Customer, → Service, → Resource |
| Email dispatch | `EmailDispatchService` (email-calendar-sync) | Confirmation, reminder, reschedule, cancellation emails |
| SMS dispatch | `OCA\OpenConnector` via openconnector | SMS reminders if configured |
| Payment processing | `OCA\OpenConnector` | Deposit and no-show fee charges (PSD2-compliant) |
| Calendar sync | `CalendarSyncService` (email-calendar-sync) | Bi-directional sync of staff blocked time and bookings |
| Skill routing | `OCA\SkillRouting\Service\SkillMatchService` | Query eligible resources for multi-step services |
| Pinia store | `createObjectStore()` | Frontend state for Service, Resource, Booking entities |
| List views | `CnIndexPage` + `useListView` | Service, Resource, Booking admin lists |
| Detail views | `CnDetailPage` + sidebar | Service, Resource, Booking detail pages |
| Status badge | `CnStatusBadge` | Booking status display |
| Date/time pickers | Nextcloud UI components | Slot picker, reschedule dialogs |
| Form validation | `CnFormDialog` / `CnAdvancedFormDialog` | Service and Resource edit forms |

**No overlap found** with existing Pipelinq services for booking or scheduling — Pipelinq has no prior appointment booking code.

---

## Seed Data

Per the company-wide seed data requirement, the following seed objects are included in `lib/Settings/pipelinq_register.json` under `components.objects[]` with `x-openregister.type: "mock"`.

### Service Seed Objects

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "service",
      "slug": "haircut-simple"
    },
    "name": "Eenvoudig knippen",
    "description": "Standaard haarsnit zonder kleur",
    "durationMinutes": 30,
    "bufferBeforeMinutes": 5,
    "bufferAfterMinutes": 5,
    "price": 25.00,
    "currency": "EUR",
    "requiredSkills": ["stylist-basic"],
    "requiredResourceTypes": ["staff"],
    "bookableOnline": true,
    "requiresDeposit": false,
    "noShowFee": 10.00,
    "cancellationPolicy": "free-until-24-hours-before",
    "cancellationHoursBefore": 24,
    "status": "active"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "service",
      "slug": "color-and-cut"
    },
    "name": "Kleuren + knippen",
    "description": "Volledige haarkleuringbehandeling met professioneel knippen",
    "durationMinutes": 90,
    "bufferBeforeMinutes": 10,
    "bufferAfterMinutes": 5,
    "price": 85.00,
    "currency": "EUR",
    "requiredSkills": ["stylist-color-certified"],
    "multiStep": [
      { "durationMinutes": 45, "skillRequired": "stylist-color-certified", "resourceType": "staff", "allowGap": false },
      { "durationMinutes": 30, "skillRequired": null, "resourceType": null, "allowGap": true },
      { "durationMinutes": 15, "skillRequired": "stylist-basic", "resourceType": "staff", "allowGap": false }
    ],
    "bookableOnline": true,
    "requiresDeposit": true,
    "depositAmount": 25.00,
    "noShowFee": 15.00,
    "cancellationPolicy": "free-until-24-hours-before",
    "cancellationHoursBefore": 24,
    "status": "active"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "service",
      "slug": "oil-change-standard"
    },
    "name": "Standaard olievervanging",
    "description": "Auto olievervanging inclusief filter",
    "durationMinutes": 45,
    "bufferBeforeMinutes": 10,
    "bufferAfterMinutes": 5,
    "price": 49.50,
    "currency": "EUR",
    "requiredSkills": ["mechanic-certified"],
    "requiredResourceTypes": ["staff", "equipment"],
    "bookableOnline": true,
    "requiresDeposit": false,
    "noShowFee": 20.00,
    "cancellationPolicy": "always-charge",
    "status": "active"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "service",
      "slug": "consultation-tax"
    },
    "name": "Belastingadvies — eerste consult",
    "description": "Kosteloos eerste kennismakingsconsult met belastingadviseur",
    "durationMinutes": 45,
    "bufferBeforeMinutes": 5,
    "bufferAfterMinutes": 5,
    "price": 0.00,
    "currency": "EUR",
    "requiredSkills": ["tax-advisor"],
    "requiredResourceTypes": ["staff"],
    "bookableOnline": true,
    "requiresDeposit": false,
    "cancellationPolicy": "free-until-24-hours-before",
    "cancellationHoursBefore": 24,
    "status": "active"
  }
]
```

### Resource Seed Objects

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "resource",
      "slug": "sarah-stylist"
    },
    "name": "Sarah (Kapster)",
    "type": "staff",
    "skills": ["stylist-basic", "stylist-color-certified"],
    "workingHours": [
      { "day": "monday", "openTime": "09:00", "closeTime": "17:30" },
      { "day": "tuesday", "openTime": "09:00", "closeTime": "17:30" },
      { "day": "wednesday", "openTime": "09:00", "closeTime": "17:30" },
      { "day": "thursday", "openTime": "09:00", "closeTime": "17:30" },
      { "day": "friday", "openTime": "09:00", "closeTime": "17:30" },
      { "day": "saturday", "openTime": "10:00", "closeTime": "14:00" }
    ],
    "vacations": [],
    "calendarSyncId": "sarah@nextcloud.local",
    "bookable": true,
    "maxConcurrent": 1,
    "status": "active"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "resource",
      "slug": "jan-mechanic"
    },
    "name": "Jan (Monteur)",
    "type": "staff",
    "skills": ["mechanic-certified", "diesel-specialist"],
    "workingHours": [
      { "day": "monday", "openTime": "08:00", "closeTime": "17:00" },
      { "day": "tuesday", "openTime": "08:00", "closeTime": "17:00" },
      { "day": "wednesday", "openTime": "08:00", "closeTime": "17:00" },
      { "day": "thursday", "openTime": "08:00", "closeTime": "17:00" },
      { "day": "friday", "openTime": "08:00", "closeTime": "17:00" }
    ],
    "vacations": [],
    "calendarSyncId": "jan@nextcloud.local",
    "bookable": true,
    "maxConcurrent": 1,
    "status": "active"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "resource",
      "slug": "treatment-room-a"
    },
    "name": "Behandelkamer A",
    "type": "room",
    "skills": [],
    "workingHours": [
      { "day": "monday", "openTime": "09:00", "closeTime": "17:00" },
      { "day": "tuesday", "openTime": "09:00", "closeTime": "17:00" },
      { "day": "wednesday", "openTime": "09:00", "closeTime": "17:00" },
      { "day": "thursday", "openTime": "09:00", "closeTime": "17:00" },
      { "day": "friday", "openTime": "09:00", "closeTime": "17:00" }
    ],
    "vacations": [],
    "bookable": true,
    "maxConcurrent": 1,
    "status": "active"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "resource",
      "slug": "workshop-bay-1"
    },
    "name": "Werkplaats B1",
    "type": "equipment",
    "skills": [],
    "workingHours": [
      { "day": "monday", "openTime": "08:00", "closeTime": "17:00" },
      { "day": "tuesday", "openTime": "08:00", "closeTime": "17:00" },
      { "day": "wednesday", "openTime": "08:00", "closeTime": "17:00" },
      { "day": "thursday", "openTime": "08:00", "closeTime": "17:00" },
      { "day": "friday", "openTime": "08:00", "closeTime": "17:00" }
    ],
    "vacations": [],
    "bookable": true,
    "maxConcurrent": 1,
    "status": "active"
  }
]
```

### Booking Seed Objects

```json
[
  {
    "@self": {
      "register": "pipelinq",
      "schema": "booking",
      "slug": "booking-001-completed"
    },
    "customerId": "00000000-0000-0000-0000-000000000201",
    "serviceId": "service-haircut-simple",
    "resourceAssignments": [
      { "stepIndex": 0, "resourceId": "resource-sarah-stylist", "startAt": "2026-05-15T10:00:00+02:00", "endAt": "2026-05-15T10:30:00+02:00" }
    ],
    "startAt": "2026-05-15T10:00:00+02:00",
    "endAt": "2026-05-15T10:30:00+02:00",
    "status": "completed",
    "statusHistory": [
      { "status": "confirmed", "changedAt": "2026-05-14T14:30:00+02:00", "changedBy": "portal" },
      { "status": "completed", "changedAt": "2026-05-15T10:45:00+02:00", "changedBy": "sarah@nextcloud.local" }
    ],
    "notes": "",
    "source": "portal",
    "confirmationSentAt": "2026-05-14T14:31:00+02:00",
    "reminderSentAt": "2026-05-15T10:00:00+02:00"
  },
  {
    "@self": {
      "register": "pipelinq",
      "schema": "booking",
      "slug": "booking-002-confirmed"
    },
    "customerId": "00000000-0000-0000-0000-000000000202",
    "serviceId": "service-color-and-cut",
    "resourceAssignments": [
      { "stepIndex": 0, "resourceId": "resource-sarah-stylist", "startAt": "2026-05-25T14:00:00+02:00", "endAt": "2026-05-25T14:45:00+02:00" },
      { "stepIndex": 2, "resourceId": "resource-sarah-stylist", "startAt": "2026-05-25T15:15:00+02:00", "endAt": "2026-05-25T15:30:00+02:00" }
    ],
    "startAt": "2026-05-25T14:00:00+02:00",
    "endAt": "2026-05-25T15:30:00+02:00",
    "status": "confirmed",
    "statusHistory": [
      { "status": "pending-deposit", "changedAt": "2026-05-22T09:00:00+02:00", "changedBy": "portal" },
      { "status": "confirmed", "changedAt": "2026-05-22T09:05:00+02:00", "changedBy": "portal-payment" }
    ],
    "notes": "Grijze highlights op bovenkant",
    "source": "portal",
    "confirmationSentAt": "2026-05-22T09:05:00+02:00",
    "depositPaidAt": "2026-05-22T09:05:00+02:00",
    "depositAmount": 25.00
  }
]
```

---

## File Changes

### New Files

| File | Purpose |
|------|---------|
| `lib/Service/AvailabilityService.php` | Compute free slots per resource per day |
| `lib/Service/BookingService.php` | Booking lifecycle (create, confirm, reschedule, cancel, no-show) |
| `lib/Controller/PortalController.php` | Public API for booking portal (no auth) |
| `lib/BackgroundJob/AvailabilityCacheRefreshJob.php` | Refresh AvailabilityCache hourly |
| `lib/BackgroundJob/ReminderDispatchJob.php` | Send 24-hour reminders |
| `lib/BackgroundJob/WalkInQueueRebalanceJob.php` | Rebalance walk-in queue on booking changes |
| `src/views/portal/BookingPortal.vue` | Public booking portal (route `/book/{serviceSlug}`) |
| `src/views/services/ServiceList.vue` | Admin list of services |
| `src/views/services/ServiceDetail.vue` | Admin detail/edit service |
| `src/views/resources/ResourceList.vue` | Admin list of resources |
| `src/views/resources/ResourceDetail.vue` | Admin detail/edit resource |
| `src/views/bookings/BookingList.vue` | Admin list of bookings |
| `src/views/bookings/BookingDetail.vue` | Admin detail/edit booking with status actions |
| `src/components/bookings/BookingsCard.vue` | Customer timeline card showing bookings |
| `src/components/bookings/WalkInQueuePanel.vue` | Real-time walk-in queue view |
| `src/router/booking-routes.js` | Router configuration for booking module |
| `src/store/modules/services.js` | Pinia store for Service entities |
| `src/store/modules/resources.js` | Pinia store for Resource entities |
| `src/store/modules/bookings.js` | Pinia store for Booking entities |
| `src/store/modules/walk-in-tickets.js` | Pinia store for WalkInTicket entities |
| `tests/Unit/Service/AvailabilityServiceTest.php` | Unit tests for availability computation |
| `tests/Unit/Service/BookingServiceTest.php` | Unit tests for booking lifecycle |
| `tests/Unit/Controller/PortalControllerTest.php` | Unit tests for portal API |
| `tests/Unit/BackgroundJob/ReminderDispatchJobTest.php` | Unit tests for reminder dispatch |
| `appinfo/routes.php` (entries) | Add `/book/*`, `/api/booking/*`, `/api/portal/*` routes |

### Modified Files

| File | Change |
|------|--------|
| `lib/Settings/pipelinq_register.json` | Register Service, Resource, Booking, WalkInTicket schemas; add seed data |
| `src/App.vue` | Add "Bookings" navigation item in main menu |
| `src/router/index.js` | Import booking-routes |
| `src/store/store.js` | Register Service, Resource, Booking, WalkInTicket object stores |
| `src/views/customers/CustomerDetail.vue` | Add `BookingsCard` section showing customer's bookings |
| `appinfo/info.xml` | Register background jobs; declare email-calendar-sync and skill-routing dependencies |
| `l10n/en.json` | Add translation keys for portal UI, booking admin, queue panel |
| `l10n/nl.json` | Add Dutch translations |

