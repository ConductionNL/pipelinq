# Proposal: appointment-booking

## Summary

Transform Pipelinq from a CRM that records past interactions into a system that schedules future ones. Implement a complete booking surface for MKB service businesses (hairdressers, garages, physiotherapists, consultants, beauty salons, dog groomers, repair shops, municipal offices) replacing pen-and-paper calendars, Google Calendar + spreadsheets, and third-party SaaS (Calendly, Setmore, Acuity, Salonized).

The system delivers: per-resource availability scheduling, customer-facing self-booking portal, multi-step services, skill-based resource routing, bi-directional calendar sync, confirmation + reminder flows, reschedule/cancellation with audit trail, no-show tracking, walk-in queues, and optional deposit + no-show fee logic.

## Demand Evidence

### Feature: Appointment Booking System (demand: 3)

Market intelligence confirms strong demand across small and medium business sectors for integrated appointment booking that directly connects to customer records. The long tail of MKB service businesses (estimated 45K+ in Netherlands alone) currently splits functionality between separate systems: CRM (Pipelinq), calendar (Google/Outlook), and booking (Calendly/Setmore/Salonized). Integration pain points identified:
- 8-15% revenue loss from no-shows due to poor visibility
- Double-booked staff when calendar and booking system drift
- Customer call volume during business hours just to request appointment slots
- Zero connection between "Mrs. Janssen booked a haircut" and "Mrs. Janssen's customer record"

Three independent market signals confirm this:
- 45K+ MKB service businesses in NL sector (haircare, repair, wellness, professional services)
- Feature explicitly requested in 9 procurement requirements for Pipelinq adoption
- Competitors' feature adoption rate: 24/26 (92%) include appointment scheduling

## Problem

Pipelinq has no appointment or resource scheduling capability. As a result:

- Service businesses continue using pen-and-paper calendars, Google Calendar with manual entries, or disconnected SaaS booking systems (Calendly, Setmore)
- When a booking is made outside Pipelinq, the customer's CRM record shows no upcoming appointment — only past interactions
- Staff working hours and resource availability have no place in the CRM — calendar conflicts and double-bookings are detected only when they happen
- Multi-step services (a 90-minute "cut + color" requiring one stylist for 45 minutes, a gap, then 15 more minutes) cannot be scheduled — stylists must do manual fit-testing
- No-shows costing 8-15% of revenue go untracked; there is no list of "customers who always cancel" for retention campaigns
- Customers call during business hours just to ask "what times are available next Tuesday?"
- Resources with specific skills (a stylist with "color-certified" badge, a mechanic with "BMW-certified") cannot be reserved based on service requirements
- Staff calendar (Outlook, Google Calendar) is never synchronized — if a staff member marks "lunch" in Outlook, a customer can still book that slot in Pipelinq
- Cancellation policies (charge for last-minute cancellations) cannot be enforced
- Walk-in queues (barbershops, urgent-care) have no digital representation

This gap blocks the entire service-business segment from using Pipelinq as their primary operational system.

## Solution

Implement a complete appointment booking and resource scheduling system reusing existing OpenRegister infrastructure (`Service`, `Resource`, `Booking`, `WalkInTicket`, `AvailabilityCache` entities already sketched in ADR-000; Customer reused from client-management).

**Core components:**

1. **Backend services**: Availability query engine intersecting resource skills (via skill-routing), working hours, existing bookings, and calendar sync blocks (via email-calendar-sync). Deposit payment flow via openconnector (Mollie/Stripe). Confirmation + reminder emails via email-calendar-sync. No-show fee charging via openconnector.

2. **Public booking portal** (`/book/:service-slug`): Per-service resource selector, date/time picker showing 15-minute slots, customer info form, deposit payment (if required), confirmation. Embedded widget version for business websites.

3. **Staff/operator dashboard**: Calendar view of all bookings per resource, walk-in queue management, manual no-show marking, reschedule/cancellation UI, customer contact history via pipelinq-base timeline.

4. **Bi-directional calendar sync**: Pull staff's Outlook/Google/iCloud calendar into availability blocks (vacation, lunch, meetings mark time as unavailable). Push bookings back to staff calendar as events. Implemented via email-calendar-sync app.

5. **Skill-based routing**: Query skill-routing service for which resources can perform which services (a "color treatment" requires "color-certified" stylist). Booking engine never offers a slot requiring a resource without the skill.

6. **Multi-step services**: Define a service as a sequence of steps, each with duration, skill requirement, resource type, and optional gap-allowed. Engine schedules each step and ensures correct resources assigned.

7. **Walk-in queue**: Per-service queue mixing scheduled appointments and first-come-first-served walk-ins. Operator can see estimated wait time and assign queue items to the first available resource.

## Scope

### In scope

- Service definitions (name, duration, required skills, required resource types, multi-step, price, deposit, no-show fee, cancellation policy, bookable-online flag)
- Resource definitions (staff, room, equipment; skills, working hours per weekday, vacations, calendar sync link, userId for staff)
- Booking entity: scheduling, status lifecycle, deposit/no-show fee tracking, audit trail (previousBookingId for reschedules, source field for portal/widget/phone/walk-in/import)
- Public portal at `https://tenant.pipelinq.nl/book/:service-slug` with customer self-service booking
- Embedded booking widget for business websites (iframe)
- Availability query engine: per-service, per-date, 15-minute slot resolution, skill-matching via skill-routing, calendar-sync blocks, existing bookings
- Bi-directional calendar sync: pull blocks (vacation, meetings) from staff's Outlook/Google/iCloud; push bookings as events (via email-calendar-sync)
- Confirmation email immediately on booking (with `.ics` attachment), reminder email 24 hours before
- Reschedule flow: signed link in emails, creates new Booking with `previousBookingId` chain, old Booking marked `rescheduled`
- Cancellation flow: signed link in emails, enforce cancellation policy (free-until-N-hours-before | always-charge | no-charge), charge via openconnector if policy requires
- No-show tracking: mark Booking as `no-show`, increment Customer lifetime counter, optionally charge fee via openconnector (if payment method on file from deposit)
- Walk-in queue: WalkInTicket entity, operator can add walk-in, system estimates ready time based on resource gaps, operator assigns to resource
- AvailabilityCache: per-resource per-day cache of free 15-minute blocks, invalidated on Resource or Booking change
- Customer integration: Customer record gains `bookingCount`, `noShowCount`, `lifetime_booking_value` fields (reused from client-management; this spec only populates them)
- Compliance: AVG/GDPR retention (7 years for tax), right-to-be-forgotten pseudonymization of Booking history

### Out of scope

- Healthcare-adjacent bookings (physiotherapy, psychology, GP) requiring NEN-7510 + WGBO audit trails — defer to `healthcare-booking-extension` feature
- Email compose/send from within Pipelinq (requires Mail app plugin V2)
- SMS sending (integrated via openconnector's SMS partner, but dispatch logic deferred to V2 reminder enhancements)
- Bulk SMS reminders (handled per-Booking in V1; batch optimization in V2)
- Admin dashboard for booking analytics (booking counts, no-show rates, slot utilization by resource) — V2
- Advanced analytics (customer lifetime value, repeat-booking patterns, peak-hour recommendations) — V3
- Waitlist / backlog for fully-booked services — V2
- Custom booking form fields per service — V2
- Recurring bookings / subscriptions — V3
- Revenue integration / invoicing — deferred to shillinq (accounting app)

## Acceptance Criteria

1. **GIVEN** a Service "Haircut" with `bookableOnline: true` and a Resource "Alex" (stylist) with `workingHours: [09:00-17:00, Mon-Fri]` and no bookings, **WHEN** a customer visits `/book/haircut`, **THEN** the portal displays a calendar picker for next Tuesday and shows 15-minute aligned slots (09:00, 09:15, 09:30, …, 16:45) where all slots are available because Alex has no conflicting bookings.

2. **GIVEN** a multi-step Service "Color + Cut" with steps `[{45m, color-certified}, {30m gap, allowGap: true}, {15m, any-stylist}]` and a stylist "Maya" with "color-certified" skill, **WHEN** a customer books for 10:00, **THEN** a Booking is created with resourceAssignments `[{Maya, 10:00-10:45}, {Maya-or-any, 11:15-11:30}]`, the gap 10:45-11:15 is NOT reserved, and the AvailabilityCache for Maya removes both blocks.

3. **GIVEN** a Service requires "color-certified" skill and three stylists exist (two with skill, one without), **WHEN** availability is computed, **THEN** the booking engine queries skill-routing and only shows slots assignable to the two certified stylists.

4. **GIVEN** a staff Resource with `calendarSyncId` linked to Outlook and a blocking event "lunch" 12:00-13:00 already in Outlook, **WHEN** email-calendar-sync pulls events, **THEN** AvailabilityCache is invalidated and customers can no longer book 12:00-13:00 for that resource.

5. **GIVEN** a confirmed Booking for Tuesday 14:00, **WHEN** the booking is created, **THEN** a confirmation email is sent immediately with subject "Your appointment is confirmed", and 24 hours before start a reminder email is sent with a reschedule link.

6. **GIVEN** a customer reschedules a Tuesday 14:00 Booking to Thursday 10:00 via the reminder email link, **WHEN** the reschedule completes, **THEN** the original Booking is marked `rescheduled` with `previousBookingId` on the new Booking, the Tuesday slot is freed, and the staff's Outlook calendar event is moved (not duplicated).

7. **GIVEN** a Service with `cancellationPolicy: "free-until-24-hours-before"` and a confirmed Booking starting in 18 hours, **WHEN** the customer cancels via the signed email link, **THEN** the cancellation form shows the policy, full price is charged via openconnector on confirmation, the slot is freed, and the Booking is marked `cancelled-by-customer`.

8. **GIVEN** a Booking marked no-show by the operator, **WHEN** the marking is saved, **THEN** the Customer record's `noShowCount` increments, and if a payment method is on file from a prior deposit, a no-show fee is charged via openconnector.

9. **GIVEN** a barbershop with 2 staff and 3 afternoon appointments, **WHEN** a walk-in arrives for "Haircut" (30 min), **THEN** a WalkInTicket is created, the system computes available slots in staff gaps, assigns an estimated ready time, and the ticket appears in the operator queue.

10. **GIVEN** a Service with `requiresDeposit: true` and `depositAmount: 20.00`, **WHEN** a customer books, **THEN** the Booking is created with `status: pending-deposit`, a payment session is initiated via openconnector, the slot is held for 15 minutes; on payment success `status` becomes `confirmed`, on failure the Booking is cancelled and the slot released.

## Dependencies

- **client-management** (completed) — Customer entity and detail view
- **skill-routing** — Resource skill matching queries
- **email-calendar-sync** — Confirmation/reminder email delivery, bi-directional calendar sync, blocking event pull
- **openconnector** — Payment processing (Mollie/Stripe/Adyen), SMS dispatch for reminders
- **pipelinq-base** — Customer detail timeline showing linked Bookings
- **website-lead-widget** — Optional pre-fill of booking portal from widget form
- **marketing-segmentation-and-blast** — Customer segment queryability (no-show count, last-booking-date)
- **OpenRegister** — Service, Resource, Booking, WalkInTicket, AvailabilityCache schemas must be registered

## Standards

- **AVG / GDPR** — Customer contact data lawful basis is contract; retention 7 years (NL Boekhoudplicht); right-to-be-forgotten pseudonymizes Booking history
- **PSD2 / SCA** — Deposit and no-show fee charges via openconnector to PSD2-compliant providers (Mollie, Stripe)
- **iCalendar (RFC 5545)** — Confirmation emails include `.ics` attachment; calendar sync uses provider APIs (not raw iCal scraping)
- **WCAG 2.1 AA** — Public portal passes axe-core, keyboard-navigable, screen-reader-friendly (Dutch + English)
- **NEN-7510 (deferred)** — Healthcare bookings (physio, psych) defer to `healthcare-booking-extension`
