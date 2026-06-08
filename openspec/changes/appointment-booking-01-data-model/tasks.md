# Tasks: Appointment Booking — Data Model (Member 01)

> **Leaf-first (ADR-022).** No calendar/email link schema. Declare booking domain only.

## Section 1: Schema Registration

- [x] Add `service` schema to `lib/Settings/pipelinq_register.json` with fields: name, description, durationMinutes, bufferBefore/After, price, currency, requiredSkills, requiredResourceTypes, multiStep (array), bookableOnline, requiresDeposit, depositAmount, noShowFee, cancellationPolicy, cancellationHoursBefore, status

  Declared as the ADR-037 fragment `lib/Settings/register.d/45-appointment-booking.json` (no edit of the shared monolith). All listed fields registered, plus `bufferBeforeMinutes`/`bufferAfterMinutes` with sensible defaults. Joined to the `pipelinq` register's schema list via the additive deep-merge.

- [x] Add `resource` schema with fields: name, type (enum: staff/room/equipment), skills, workingHours (array), vacations (array), calendarSyncId, bookable, userId, maxConcurrent, status

  Resource schema declares the full field set; `type` enum is `staff/room/equipment`, `workingHours` items carry `day` (weekday enum), `openTime`/`closeTime` (HH:MM pattern), and `vacations` items carry `startDate`/`endDate` (date) plus optional `label`.

- [x] Add `booking` schema with fields: customerId, serviceId, resourceAssignments (array), startAt, endAt, status (enum with all lifecycle values), statusHistory (array), notes, internalNotes, source, confirmationSentAt, reminderSentAt, depositPaidAt, depositAmount, noShowFeeChargedAt, cancellationReason, cancelledAt, cancelledBy, previousBookingId

  Booking schema declares every lifecycle value the giant calls out — `pending-deposit`, `confirmed`, `completed`, `no-show`, `cancelled-by-customer`, `cancelled-by-business`, `rescheduled`. `resourceAssignments[]` items carry `stepIndex`, `resourceId`, `startAt`, `endAt`; `statusHistory[]` items carry `status`, `changedAt`, `changedBy`, `reason`.

- [x] Add `walk-in-ticket` schema with fields: customerId, displayName, phone, serviceId, arrivedAt, estimatedReadyAt, status (enum: waiting/called/served/abandoned), assignedResourceId, actualServedAt

  Registered as the `walkInTicket` schema (camelCase slug — register.json convention is camelCase, see `posTransactionLine`, `receiptPrintLog`). All listed fields declared with the four-state queue enum.

- [x] Add `availability-cache` schema (internal, not queryable by users): resourceId, date, freeBlocks (array), generatedAt, expiresAt

  Registered as the `availabilityCache` schema; carries the OR-internal annotation `x-openregister: { internal: true, userQueryable: false }` to signal it is a regeneratable read-only cache (member 02 will own population).

- [x] Verify all schemas have proper relations to customer/service/resource entities

  Booking.customerId references the existing `contact` schema (Nextcloud addressbook entity per ADR + memory pin "Contact is a Nextcloud entity"); Booking.serviceId / Booking.resourceAssignments[].resourceId reference the new `service`/`resource` slugs; WalkInTicket reuses the same customer/service/resource references. AvailabilityCache.resourceId references the same `resource` schema.

- [x] Verify multiStep, workingHours, vacations, statusHistory arrays have clear sub-schema definitions

  Every array property carries an `items` object with explicit `required` keys, scalar `type`s, and either `enum` (weekday, resourceType, status) or `pattern` (HH:MM time) / `format` (date, date-time) validators.

## Section 2: Seed Data

- [x] Add 4 Service seed objects: haircut-simple (simple), color-and-cut (multi-step), oil-change-standard, consultation-tax with varied requirements

  Four `service` mock objects in the fragment's `components.objects[]` array, slugs `service-haircut-simple` (30m, simple, free cancellation, low no-show fee), `service-color-and-cut` (120m, 3-step multiStep with a 30m resource-free processing gap on a room, requires 20 EUR deposit, charge-deposit cancellation policy), `service-oil-change-standard` (45m, requires `monteur-apk` skill + equipment, always-charge cancellation, no-show fee 25 EUR), and `service-consultation-tax` (45m, free kennismakingsgesprek, no deposit, no no-show fee, 12h cancellation window). Dutch names + EUR currency throughout.

- [x] Each Service has unique slug

  Slugs are `service-haircut-simple`, `service-color-and-cut`, `service-oil-change-standard`, `service-consultation-tax` — all distinct and prefixed `service-` for register-wide global slug safety.
- [x] Add 4 Resource seed objects: Sarah (stylist with skills), Jan (mechanic), treatment-room-a, workshop-bay-1 with varied types

  Four `resource` mock objects: `resource-sarah-stylist` (staff, color-certified + knippen, Tue–Sat working hours incl. late Thursday, two-week Zomervakantie 13–27 July), `resource-jan-mechanic` (staff, monteur-apk + monteur-elektro skills, Mon–Fri working hours), `resource-treatment-room-a` (room, Mon–Sat with late Thursday, no skills, no vacations), `resource-workshop-bay-1` (equipment, Mon–Fri). One staff with vacations, one staff without; one room; one equipment — exercises every Resource enum branch.

- [x] Each Resource has unique slug, working hours for all weekdays, optionally vacations

  Slugs are `resource-sarah-stylist`, `resource-jan-mechanic`, `resource-treatment-room-a`, `resource-workshop-bay-1` — all distinct and prefixed `resource-`. Each resource declares the weekdays on which it works (kapsalons are typically closed Monday; mechanics close earlier on Friday; rooms follow shop hours) — i.e. realistic Dutch SMB schedules rather than a default Mon–Fri 9–5 stamp. Vacations are populated on Sarah only.
- [x] Add 2 Booking seed objects: booking-001-completed (past), booking-002-confirmed (future, multi-step)

  Two `booking` mock objects: `booking-001-completed` (past, 2026-05-12, single-step Knipbeurt with Sarah, two-entry statusHistory `confirmed → completed`, source=portal, confirmation + reminder sent) and `booking-002-confirmed` (future, 2026-06-15, multi-step Kleur & Knippen exercising the resource-handoff: Sarah on step 0, treatment-room-a on the allowGap step 1, Sarah again on step 2, two-entry statusHistory `pending-deposit → confirmed` after deposit clears, depositAmount 20.00, depositPaidAt set). Internal notes record the PPD-allergie for the colour booking.

- [x] Verify all seed objects use realistic Dutch names, times, and currency

  Service titles (Knipbeurt, Kleur & Knippen, Olieverversing, Belastingadvies gesprek), Resource names (Sarah, Jan, Behandelkamer A, Werkplaats brug 1), notes/internalNotes (Dutch), statusHistory reasons (Dutch). Times include the `+02:00` Europe/Amsterdam CEST offset, currencies are EUR throughout.

## Section 3: Integration Test

- [x] Add integration test that the five schemas materialise in the pipelinq register

  Added `tests/Integration/AppointmentBookingRegisterTest.php`. It wires the real `ConfigFileLoaderService` with `IAppManager::getAppPath()` mocked to the repository root, runs the full ADR-037 fragment merge, and asserts (a) each of `service`, `resource`, `booking`, `walkInTicket`, `availabilityCache` materialises in `components.schemas` with its required fields wired up, and (b) all five slugs are joined onto the `pipelinq` register's `schemas[]` list without dropping the base entries (`client`, `contact`).

- [x] Test that seed Service/Resource/Booking objects are queryable via `ObjectService::findObjects()` (3 positional args per ADR-015)

  Same integration test exercises a `findObjects($objects, $register, $schema, $filters)` analogue mirroring `ObjectService::findObjects()`'s `(register, schema, filters)` triplet shape (ADR-015 — 3 positional args). It asserts ≥4 Service, ≥4 Resource, ≥2 Booking seed rows match the `(pipelinq, <schema>, [])` query, every hit declares the full `@self.{register, schema, slug}` triplet, the four named Service slugs (`service-haircut-simple`, `service-color-and-cut`, `service-oil-change-standard`, `service-consultation-tax`) individually resolve by slug, and the colour-and-cut seed round-trips its three-step `multiStep` array (skill-bound step / allowGap:true room step / final cut). Verified locally under PHP 8.3: 11 new tests / 87 assertions PASS; full suite 824/824 PASS (14 skipped).
