> SUPERSEDED 2026-06-02 (ADR-032): decomposed into the chain appointment-booking-01..12 (see openspec/changes/).

# Proposal: Appointment Booking

## Summary

Turn Pipelinq from a CRM that records past interactions into a system that schedules future ones. Implement a complete booking surface: per-resource availability calendars, customer-facing self-booking via a public portal, multi-step services with skill-based routing, bi-directional calendar sync so staff calendars are the source of truth for blocked time, confirmation + reminder + reschedule + cancellation flows over email and SMS, no-show tracking with optional fees, a walk-in queue for businesses that mix appointments with first-come-first-served service, and full audit trails for regulatory compliance (AVG, NL Boekhoudplicht, PSD2).

Based on market intelligence: **MKB service businesses** (hairdressers, garages, physiotherapists, consultants, tax advisors, beauty salons, dog groomers, repair shops, municipal-window appointments) are the entire long tail of Nextcloud's customer base. They run on pen-and-paper calendars, Google Calendar with manual entries, or third-party SaaS like Calendly and Setmore that doesn't talk to their CRM. Doubled-booked staff and no-shows costing 8-15% of revenue are the primary pain points.

## Problem

MKB service businesses urgently need better scheduling. Current tools fail because:

- **No CRM integration**: Appointment data lives in Calendly or Google Calendar, disconnected from customer records. When "Mrs. Janssen booked a haircut" the booking never appears in the CRM; when she reschedules, the CRM record stays stale.
- **No real-time availability**: Staff calendars (Outlook, Google Calendar, iCloud) contain blocked time (lunch, meetings, vacation) that isn't visible to the booking system. The portal shows free slots that collide with lunch breaks, forcing manual cancellations.
- **No compliance tracking**: Dutch regulations (AVG for customer data, NL Boekhoudplicht for 7-year retention, PSD2 for payment authentication, WCAG 2.1 AA for accessibility) are met through painful manual workarounds or not at all.
- **No confirmation workflows**: Customers book but don't show up (8-15% no-show rate costs revenue). Manual reminder calls are needed. Rescheduling requires email back-and-forth.
- **No skill-based routing**: "A color treatment needs a certified stylist" is invisible to the booking system; it shows any stylist as available even if they lack the skill.
- **No walk-in support**: Barbershops, urgent-repair shops, and front-office teams mix scheduled appointments with walk-ins. Today's tools support only one or the other.

## Solution

**Leaf-first boundary (ADR-022).** Calendar read/write and email/SMS dispatch are NOT built in pipelinq. They are delegated to `email-calendar-sync`, which consumes the OpenRegister `calendar` (`integration-calendar`) and `email` (`integration-email`) integration leaves; SMS/payment dispatch goes through openconnector. Pipelinq builds only the genuinely app-specific booking domain (Service/Resource/Booking/WalkInTicket entities, slot computation, skill-based routing, deposits, no-show, walk-in queue, public portal). Booking events are written to staff calendars through the calendar leaf's VEVENT create API — pipelinq adds no CalDAV client of its own.

Implement appointment booking as a new Pipelinq module using existing OpenRegister abstractions and the calendar/email leaves (via email-calendar-sync):

1. **Service** — a bookable offering with duration, skills required, pricing, deposit policy, cancellation policy, multi-step sub-steps.
2. **Resource** — staff member, room, or equipment with working hours, vacation dates, skills, and optional calendar sync link so their Outlook/Google Calendar blocks are fetched automatically.
3. **Booking** — a scheduled appointment linked to a Customer, Service, and Resource assignments, with status lifecycle (pending-deposit → confirmed → completed/no-show/cancelled) and audit trail for every state change.
4. **WalkInTicket** — a queue entry for unscheduled arrivals, separately tracked so barbershops can mix both model.
5. **AvailabilityCache** — per-resource per-day free blocks (regenerated on Resource or Booking change) for fast slot queries.
6. **Customer-facing portal** — a public website at `/book/{service-slug}` where customers pick date + time and self-book without logging in. Integrates skill-routing to never show unqualified resources.
7. **Bi-directional calendar sync** — via email-calendar-sync, which consumes the OR `calendar` leaf: staff calendar blocks are ingested every 5 minutes, and booking VEVENTs are pushed back to staff calendars through the leaf's create API (no pipelinq-local CalDAV client).
8. **Confirmation + reminder flows** — email-calendar-sync dispatches confirmation on booking creation, reminder 24 hours before, SMS optional. Signed deep-links allow reschedule/cancel without logging in.
9. **No-show tracking and fees** — staff marks booking as no-show; system increments customer lifetime no-show count; optional deposit or fee is charged via openconnector (Mollie/Stripe).
10. **Reschedule audit trail** — original booking transitions to `rescheduled`, new booking created with `previousBookingId` reference, audit trail preserved.

Routing is delegated to skill-routing: `"Color treatment"` service requires skill `"color-certified"`. The booking engine queries skill-routing, filters resources, intersects with availability, and presents only bookable slots to the customer.

## Scope

### In scope

- Service and Resource entity creation and management (admin UI)
- Customer self-booking via public `/book/{serviceSlug}` portal with 15-minute-aligned slot picker
- Multi-step services with per-step skill requirements and gaps (e.g., 45m color + 30m gap + 15m cut)
- Skill-based routing via skill-routing queries (never show unqualified resources)
- Per-resource availability computed from working hours, vacations, existing Bookings, and calendar-synced blocks (lunch, meetings)
- AvailabilityCache (per-resource per-day free blocks) for sub-second slot queries
- Booking status lifecycle: pending-deposit → confirmed → completed/no-show/cancelled-by-customer/cancelled-by-business/rescheduled
- Confirmation email on booking creation (or on deposit payment if deposit required); includes `.ics` attachment for customer calendar
- Reminder email + optional SMS 24 hours before (via email-calendar-sync and openconnector)
- Reschedule + cancellation via signed email links (no login required)
- Cancellation policy enforcement (free-until-N-hours-before, always-charge, no-charge) with optional payment charges
- Deposit-required bookings: slot held for 15 minutes pending payment, status transitions to `confirmed` on PSD2-compliant payment
- No-show tracking: staff marks booking as no-show, customer's lifetime no-show count increments, optional fee charged via openconnector
- Walk-in queue (WalkInTicket) for unscheduled arrivals; queue rebalances as appointments complete
- Bi-directional calendar sync: staff's blocked time (vacation, lunch, meetings) synced from Google/Outlook every 5 minutes; bookings pushed to staff calendars
- Customer timeline view in CRM (Booking appears on customer record)
- Full audit trail for regulatory compliance (AVG right-to-be-forgotten, NL Boekhoudplicht 7-year retention)

### Out of scope

- Mobile booking app (web portal only in V1)
- Email compose/send from within Pipelinq (email-calendar-sync handles dispatch)
- Payment gateway abstraction beyond openconnector (Mollie/Stripe/Adyen delegated to openconnector)
- Healthcare-adjacent restrictions (NEN-7510, WGBO) — deferred to healthcare-booking-extension
- Bulk import of existing customer appointments (V2)
- Customer login / account history (portal is anonymous; booking confirmation/reschedule via email only)
- Waitlist / overbooked handling (V2)
- Resource availability by time-of-day rules (e.g., "Dr. A only sees patients 9-12") — working hours only
- SMS reminders if openconnector SMS not configured (fallback to email-only)

## Acceptance Criteria

1. **GIVEN** a Service with `bookableOnline: true`, `durationMinutes: 30`, `requiredSkills: ["barber"]`, and three eligible Resources (barbers with that skill) with availability next Tuesday, **WHEN** a customer visits `/book/haircut`, picks Tuesday 14:00, and submits their name+email+phone, **THEN** a Booking is created with `status: "confirmed"`, a confirmation email is sent within 1 minute, and the slot is no longer available for other bookings.

2. **GIVEN** a Service with multi-step `[{45m, color-certified}, {30m, gap, allowGap: true}, {15m, any-stylist}]` and a customer books for 10:00, **THEN** the Booking has resourceAssignments `[{stylist-A, 10:00-10:45}, {stylist-A-or-B, 11:15-11:30}]`, the 10:45-11:15 gap is unblocked, and AvailabilityCache shows both blocks removed from that stylist's day.

3. **GIVEN** a Resource with `calendarSyncId` linked to Outlook and a staff member adds "lunch 12:00-13:00" directly in Outlook, **WHEN** the sync job runs, **THEN** within 5 minutes AvailabilityCache is invalidated for that day and no customer can book 12:00-13:00 on that Resource.

4. **GIVEN** a confirmed Booking for next Tuesday 14:00, **WHEN** the Booking is created, **THEN** a confirmation email is sent (subject "Your appointment is confirmed: Haircut, Tuesday 14:00") with an `.ics` attachment and signed reschedule/cancel links; 24 hours before, a reminder email + SMS is sent.

5. **GIVEN** a customer receives a reschedule link in an email and clicks it, **WHEN** they pick Thursday 10:00, **THEN** the original Booking status transitions to `rescheduled`, a new Booking is created for Thursday with `previousBookingId` pointing at the original, and the original time slot is freed.

6. **GIVEN** a Booking with `noShowFee: 25.00` and start time 30 minutes in the past, **WHEN** staff marks it as no-show in the dashboard, **THEN** status transitions to `no-show`, customer's lifetime no-show count increments, and a 25 EUR charge is queued via openconnector.

7. **GIVEN** a Service with `requiresDeposit: true` and `depositAmount: 20.00`, **WHEN** a customer completes the booking form, **THEN** the Booking is created with `status: "pending-deposit"`, a payment session is initiated via openconnector, the slot is held for 15 minutes, and on payment success `status` transitions to `confirmed`.

8. **GIVEN** a barbershop with 2 barbers and 5 appointments this afternoon, **WHEN** a walk-in arrives and a WalkInTicket is created, **THEN** the system computes the earliest 30-min gap in both barbers' schedules and assigns the walk-in with `estimatedReadyAt` calculated.

9. **GIVEN** a Service with `cancellationPolicy: "free-until-24-hours-before"` and a Booking starting in 18 hours, **WHEN** the customer cancels via the signed link, **THEN** the system shows the policy (will charge if cancelled), the customer confirms, `status` transitions to `cancelled-by-customer`, and the full price is charged via openconnector.

10. **GIVEN** the Customer record for a booked client, **WHEN** an agent views the detail page, **THEN** a Bookings section shows all past and future appointments with status, service name, resource name, and time.

## Dependencies

- **client-management** (completed) — Customer entity must exist for booking records to link to; lifetime metrics (booking count, no-show count, lifetime value) stored on Customer
- **skill-routing** (assumed available) — Source of truth for which Resources can perform which Services; booking engine queries skill-routing for eligible resource lists
- **email-calendar-sync** (completed) — Confirmation, reminder, and reschedule email dispatch; bi-directional calendar sync of staff calendars; SMS reminders
- **openconnector** (assumed available) — Payment processing (Mollie, Stripe, Adyen) for deposits and no-show fees; SMS dispatch (Twilio, MessageBird)
- **pipelinq-base** (completed) — Nextcloud user auth, CRM dashboard, customer timeline
- **OpenRegister** — CRUD, audit trails, relations, file attachments for Service, Resource, Booking, WalkInTicket entities
- **Nextcloud OCP interfaces** — `IUserManager`, `IUserSession`, `IAppConfig`, `ICacheFactory` (for AvailabilityCache), calendar sync via email-calendar-sync
