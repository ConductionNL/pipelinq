# Tasks: Appointment Booking — Admin UI (Member 11)

## Section 1: Service views

- [ ] ServiceList.vue: `CnIndexPage` + `useListView('service', ...)`; columns name, duration, price, status; filters status + bookableOnline; add button; row → detail
- [ ] ServiceDetail.vue: view (CnDetailPage) + edit form (name, description, durationMinutes, buffers, price, currency, requiredSkills, multiStep sub-table, toggles, depositAmount, noShowFee, cancellationPolicy/HoursBefore)
- [ ] ServiceDetail.vue: on save validate multiStep + `ObjectService.saveObject()`; invalidate AvailabilityCache for affected resources; CnDeleteDialog; SPDX header

## Section 2: Resource views

- [ ] ResourceList.vue: `CnIndexPage` + `useListView('resource', ...)`; columns name, type, status, bookable; filter type/status; add button; row → detail
- [ ] ResourceDetail.vue: edit form (name, type, skills, workingHours sub-table of 7 weekdays, vacations sub-table, calendarSyncId selector, userId selector, bookable, maxConcurrent, status)
- [ ] ResourceDetail.vue: validate open<close + start<=end; invalidate cache on save; delete confirmation; SPDX header

## Section 3: Booking views

- [ ] BookingList.vue: `CnIndexPage` + `useListView('booking', ...)`; columns customer, service, resource, date/time, status, source; filters date range/status/resource/service/source
- [ ] BookingDetail.vue: header + sections (Booking Details, Resource Assignments, Audit Trail/statusHistory, Timeline)
- [ ] BookingDetail.vue: status actions varying by status+time (Reschedule, Cancel, Send Reminder, Mark Completed, Mark No-show, Complete Payment) wired to booking endpoints; edit notes/internalNotes only; SPDX header

## Section 4: Customer timeline

- [ ] BookingsCard.vue: props customerId; fetch bookings via object store filter; future-first sorted; each row service/resource/date/status/link; empty state; CnDetailCard; try/catch with user feedback; SPDX header
- [ ] Add BookingsCard section to CustomerDetail.vue

## Section 5: Stores, router, nav

- [ ] Create stores services.js, resources.js, bookings.js, walk-in-tickets.js via `createObjectStore`; register in `src/store/store.js`
- [ ] Create `src/router/booking-routes.js` (admin routes /services, /services/:id, /resources, /resources/:id, /bookings, /bookings/:id); import in `src/router/index.js`
- [ ] Add "Bookings" main nav item with Services/Resources/Bookings sub-items; MDI icons via CnIcon
