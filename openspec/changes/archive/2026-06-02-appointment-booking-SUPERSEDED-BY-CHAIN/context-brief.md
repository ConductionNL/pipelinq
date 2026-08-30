---
status: draft
app: pipelinq
spec: appointment-booking
owner: pipelinq-team
created: 2026-05-21
depends_on: [pipelinq-base, client-management, skill-routing, email-calendar-sync]
---

# Appointment Booking

## Purpose

Appointment Booking turns pipelinq from a CRM that records past interactions into a system that schedules future ones. It exists because the entire long tail of MKB service businesses — hairdressers, garages, physiotherapists, consultants, tax advisors, beauty salons, dog groomers, repair shops, municipal-window appointments — runs on either pen-and-paper calendars, Google Calendar with manual entries, or third-party SaaS (Calendly, Setmore, Acuity, Salonized) that doesn't talk to their CRM. The pain is doubled-booked staff, no-shows costing 8-15% of revenue, customers calling during business hours just to ask for a slot, and zero connection between "Mrs. Janssen booked a haircut" and "Mrs. Janssen's customer record."

The spec delivers a complete booking surface: per-resource availability (a stylist's working hours, a treatment-room's open slots, a mechanic's bay schedule, a consultant's calendar), customer-facing self-booking via a public portal (or embedded widget on the business's website), multi-step services (a 90-minute color treatment that needs a stylist for 45 minutes, a 30-minute development gap, then 15 more minutes), confirmation + reminder + reschedule + cancellation flows over email and SMS, no-show tracking with optional deposit / no-show fee logic, a walk-in queue for businesses that mix appointments with first-come-first-served (barbershops, urgent-care), and bi-directional calendar sync so the resource's Google/Outlook/iCloud calendar is the source of truth for blocked time (vacation, lunch, meetings) and bookings appear there too.

Routing is delegated to skill-routing: a "color treatment" service requires a stylist with the "color-certified" skill; a "engine diagnostics" booking requires a mechanic with the "BMW-certified" skill and bay B (which has the diagnostic equipment). The booking engine queries skill-routing for eligible resources, intersects with availability, and presents the customer with bookable slots — never showing a slot that would require a resource without the skill.

Compliance lives where it always does in this domain: AVG for handling customer contact data, retention for booking history (typically 7 years for tax purposes in NL), and for healthcare-adjacent businesses (physiotherapy, psychology) the much stricter NEN-7510 + WGBO regime which we flag but defer to a future healthcare-booking extension.

## Data Model

Reuses `Customer` from client-management. Adds:

- **Service**: a bookable offering. Fields: `id`, `name`, `description`, `durationMinutes`, `bufferBeforeMinutes`, `bufferAfterMinutes`, `price?`, `currency`, `requiredSkills[]` (referenced from skill-routing), `requiredResourceTypes[]` (staff | room | equipment), `multiStep[]` (sub-step sub-schema: durationMinutes, skill, resourceType, allowGap), `bookableOnline` (boolean), `requiresDeposit` (boolean), `depositAmount?`, `noShowFee?`, `cancellationPolicy` (free-until-N-hours-before | always-charge | no-charge).
- **Resource**: staff member, room, or equipment. Fields: `id`, `name`, `type` (staff | room | equipment), `skills[]`, `workingHours[]` (per-weekday open/close), `vacations[]` (date ranges blocked), `calendarSyncId?` (link to email-calendar-sync calendar), `bookable` (boolean), `userId?` (link to NC user for staff).
- **Booking**: a scheduled appointment. Fields: `id`, `customerId`, `serviceId`, `resourceAssignments[]` (per multi-step: resourceId, startAt, endAt), `startAt`, `endAt`, `status` (pending-deposit | confirmed | completed | no-show | cancelled-by-customer | cancelled-by-business | rescheduled), `notes`, `internalNotes`, `source` (portal | widget | phone | walk-in | imported), `confirmationSentAt?`, `reminderSentAt?`, `depositPaidAt?`, `noShowFeeChargedAt?`, `previousBookingId?` (for reschedules).
- **WalkInTicket**: queue for unscheduled arrivals. Fields: `id`, `customerId?` (anonymous allowed), `displayName`, `phone?`, `serviceId`, `arrivedAt`, `estimatedReadyAt`, `status` (waiting | called | served | abandoned), `assignedResourceId?`.
- **AvailabilityCache**: per-resource per-day, regenerated on Resource or Booking change. Fields: `resourceId`, `date`, `freeBlocks[]` (start, end) — exists for fast slot-query performance, never authoritative.

## Requirements

### REQ-001: Self-book via public portal

**GIVEN** a Service with `bookableOnline: true` and three eligible Resources with availability next Tuesday
**WHEN** a customer visits `https://acme.pipelinq.nl/book/haircut`, picks Tuesday, and sees available slots
**THEN** the portal queries `GET /api/booking/availability?serviceId=X&date=Tuesday` which returns all 15-minute-aligned start times where at least one eligible Resource has a free block of `durationMinutes + buffers`; the customer picks a slot and submits name+email+phone; a Booking is created with `status: "confirmed"` (or `"pending-deposit"` if deposit required) and a confirmation email is sent.

### REQ-002: Multi-step service blocks correct resources

**GIVEN** a "Color + Cut" Service with multi-step `[{45m, color-certified-stylist}, {30m, gap, allowGap: true}, {15m, any-stylist}]`
**WHEN** a customer books for 10:00
**THEN** the Booking's resourceAssignments are `[{stylist-A, 10:00-10:45}, {stylist-A or any, 11:15-11:30}]`, the gap 10:45-11:15 is NOT blocked on any stylist, the AvailabilityCache for stylist-A on that day removes both 45m and 15m blocks, and the customer pays for 90 minutes elapsed-time even though only 60 minutes of stylist work.

### REQ-003: Skill-routing gates resource eligibility

**GIVEN** a Service requires skill "color-certified" and the tenant has three stylists of whom two hold that skill
**WHEN** availability is computed
**THEN** the engine asks skill-routing for resources matching `requiredSkills` and intersects with `workingHours` and existing Bookings, NEVER offering a slot that would require the non-certified stylist for the color step.

### REQ-004: Bi-directional calendar sync via email-calendar-sync

**GIVEN** a staff Resource with `calendarSyncId` linked to their Outlook calendar
**WHEN** the staff member adds a "lunch" event 12:00-13:00 directly in Outlook
**THEN** within 5 minutes the email-calendar-sync pulls the event, the AvailabilityCache is invalidated for that day, and customers can no longer book the 12:00-13:00 block; conversely when a Booking is created in pipelinq, an event is pushed to the staff's Outlook calendar with customer name, service, and a deep-link back to the Booking.

### REQ-005: Confirmation and reminder flows

**GIVEN** a Booking is confirmed for next Tuesday 14:00
**WHEN** the Booking is created
**THEN** an immediate confirmation email is sent (subject "Your appointment is confirmed: ..."), `confirmationSentAt` is set; 24 hours before `startAt` a reminder email + optional SMS is sent and `reminderSentAt` is set; the customer can reschedule or cancel via signed links in both emails without logging in.

### REQ-006: Reschedule preserves audit trail

**GIVEN** a confirmed Booking for Tuesday 14:00
**WHEN** the customer reschedules to Thursday 10:00 via the signed link
**THEN** the original Booking transitions to `status: "rescheduled"` with `endAt` unchanged, a new Booking is created for Thursday 10:00 with `previousBookingId` pointing at the original, the Thursday Booking inherits customer + service + notes, the original time slot is freed in AvailabilityCache, and the staff calendar event is moved (not duplicated).

### REQ-007: No-show tracking and optional fee

**GIVEN** a confirmed Booking with `startAt` 30 minutes in the past and `noShowFee: 25.00`, and the staff has not marked it `completed`
**WHEN** the business operator marks the Booking as no-show in the dashboard
**THEN** status transitions to `no-show`, the customer's lifetime no-show count on their Customer record increments, and (if a payment method is on file from the deposit flow) a 25 EUR charge is queued via openconnector to the configured payment source with `noShowFeeChargedAt` set on success.

### REQ-008: Walk-in queue mixes with appointments

**GIVEN** a barbershop with 2 staff Resources and 5 appointments scheduled this afternoon
**WHEN** a walk-in arrives and a WalkInTicket is created for "Haircut" (30 min)
**THEN** the engine looks at both staff's upcoming gaps (between appointments) and the current moment, computes the earliest 30-min slot that fits both the walk-in and existing appointments without delay, assigns it (without creating a Booking row — WalkInTickets are separate), sets `estimatedReadyAt`, and updates as appointments complete; when a staff finishes early the queue re-balances.

### REQ-009: Cancellation policy enforces business rules

**GIVEN** a Service with `cancellationPolicy: "free-until-24-hours-before"` and a confirmed Booking starting in 18 hours
**WHEN** the customer attempts to cancel via the signed link
**THEN** the system shows the policy (cancel + charge full price, or keep the booking), the customer chooses cancel-with-charge, status transitions to `cancelled-by-customer`, the full Service price is charged via openconnector, the slot is freed, and the customer's record notes the late-cancellation; cancellation more than 24 hours ahead skips the charge step.

### REQ-010: Deposit-required booking holds slot pending payment

**GIVEN** a Service with `requiresDeposit: true` and `depositAmount: 20.00`
**WHEN** a customer completes the booking form
**THEN** the Booking is created with `status: "pending-deposit"`, the slot IS held in AvailabilityCache for 15 minutes, a payment session is initiated via openconnector (Mollie/Stripe per tenant config), the customer is redirected to pay; on payment success `status` transitions to `confirmed` and `depositPaidAt` is set; on payment failure or 15-minute timeout the Booking transitions to `cancelled-by-business` and the slot is released.

## Standards

- **AVG / GDPR**: customer contact data lawful basis is contract (booking is itself the contract); retention 7 years for tax (NL Boekhoudplicht); right-to-be-forgotten pseudonymizes Booking history (replace name + email + phone with hashes, keep aggregates).
- **NL Boekhoudplicht (Belastingdienst)**: booking + payment records retained 7 years; deletion before this would require Belastingdienst-approved exception.
- **PSD2 / SCA**: deposit and no-show fee charges flow via openconnector to PSD2-compliant providers (Mollie, Stripe), which handle 3D Secure 2 strong customer authentication.
- **iCalendar (RFC 5545)**: confirmation emails include `.ics` attachment so the customer can add to their own calendar; bi-directional sync uses provider-native APIs (Microsoft Graph, Google Calendar) not raw iCal scraping.
- **WCAG 2.1 AA**: public booking portal passes axe-core, keyboard-navigable, screen-reader-friendly (Dutch + English aria-labels), color-contrast against branding tokens.
- **NEN-7510 (deferred)**: healthcare-adjacent bookings (physio, psych, GP) require additional pseudonymization, audit-logging, and access-controls — this spec explicitly defers to a future `healthcare-booking-extension`.

## Cross-app

- **skill-routing**: source of truth for which Resources can perform which Services; this spec consumes the skill-match query and never duplicates skill data.
- **client-management**: Customer entity stores lifetime booking count, no-show count, lifetime value (sum of completed Bookings).
- **email-calendar-sync**: bi-directional sync of staff calendars; transactional confirmation + reminder emails dispatched here.
- **openconnector**: payment-source integration (Mollie, Stripe, Adyen) for deposit and no-show charges; SMS dispatch (Twilio, MessageBird) for reminders.
- **website-lead-widget**: a "request appointment" widget form can pre-fill the booking portal with collected fields.
- **marketing-segmentation-and-blast**: Customer no-show count and last-booking-date are queryable Segment fields (e.g., "customers who haven't booked in 6 months" for retention blast).
- **pipelinq-base**: Customer ↔ Booking relationship is rendered in the standard Customer detail timeline.

## Target Users

- **MKB service businesses** (hairdresser, barbershop, beauty salon, garage, repair shop, physio, tax advisor, consultant) replacing pen-and-paper or Calendly + spreadsheet.
- **Multi-staff service providers** needing resource-level scheduling rather than "first available person."
- **Walk-in-friendly businesses** (barbershops, urgent-repair shops) mixing scheduled and queued service.
- **Municipal front-office teams** offering citizen appointments (paspoort, rijbewijs, attesten) where current solutions are aging Dutch government booking systems with poor UX.
