# Design: Appointment Booking — Admin UI (Member 11)

## Overview

Staff-facing admin views, customer-timeline integration, Pinia stores, router, and
navigation. Standard CnIndexPage/CnDetailPage patterns (ADR-004/017); no custom
stores (createObjectStore).

## Frontend (per giant design.md)

### Services
- `ServiceList.vue` — `CnIndexPage` + `useListView('service', ...)`; columns name,
  duration, price, status; filters status + bookableOnline.
- `ServiceDetail.vue` — view/edit; fields incl. multiStep sub-table (add/delete/
  reorder), bookableOnline/requiresDeposit toggles; on save invalidate cache for
  affected resources; CnDeleteDialog.

### Resources
- `ResourceList.vue` — columns name, type, status, bookable; filter type/status.
- `ResourceDetail.vue` — workingHours sub-table (7 weekdays), vacations sub-table,
  calendarSyncId selector (from email-calendar-sync), userId selector; validate
  open<close and start<=end; invalidate cache on save.

### Bookings
- `BookingList.vue` — columns customer, service, resource, date/time, status, source;
  filters date range/status/resource/service/source.
- `BookingDetail.vue` — header + Booking Details / Resource Assignments / Audit Trail
  (statusHistory) / Timeline sections; status actions vary by status+time (Reschedule,
  Cancel, Send Reminder, Mark Completed, Mark No-show, Complete Payment); actions call
  the member-04/08 endpoints.

### Customer timeline (REQ-APT-014)
- `BookingsCard.vue` on `CustomerDetail.vue` — props `customerId`; fetch bookings via
  object store filter; future-first sorted; empty state; CnDetailCard wrapper;
  try/catch with user feedback.

## Stores / Router / Nav (ADR-004)

- Stores `services.js`, `resources.js`, `bookings.js`, `walk-in-tickets.js` via
  `createObjectStore`; registered in `src/store/store.js`.
- `src/router/booking-routes.js` — admin routes `/services`, `/services/:id`,
  `/resources`, `/resources/:id`, `/bookings`, `/bookings/:id`; imported by
  `src/router/index.js`. (Public portal routes added in member 06.)
- Nav: "Bookings" with Services/Resources/Bookings sub-items, MDI icons via CnIcon.

## Constraints

All strings translated (final i18n sweep is member 12); SPDX headers; axios only;
@conduction/nextcloud-vue only.
