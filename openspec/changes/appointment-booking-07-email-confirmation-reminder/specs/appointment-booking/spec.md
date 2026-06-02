# Appointment Booking — Email Confirmation & Reminder (Member 07) Delta Spec

## Purpose

Send confirmation and 24-hour reminder emails (with `.ics` and signed links) via the
email leaf, driven by the ReminderDispatchJob.

**Leaf-first boundary (ADR-022).** Email transport is delegated to
`email-calendar-sync`; pipelinq composes content only.

---

## ADDED Requirements

### Requirement: REQ-APT-006 Booking Confirmation Email

The system MUST send a confirmation email immediately after a Booking is created or a
deposit is paid, including an `.ics` calendar attachment and signed reschedule/cancel
links.

**Feature tier**: V1

#### Scenario: Confirmation email sent on booking creation

- **GIVEN** a Booking is created with `status: "confirmed"`
- **WHEN** the create action completes
- **THEN** a confirmation email MUST be dispatched within 1 minute
- **AND** `confirmationSentAt` MUST be set on the Booking
- **AND** the email MUST include service name, date/time, resource name, price, and signed deep-links for reschedule and cancel

#### Scenario: Email includes `.ics` attachment

- **GIVEN** the confirmation email is being composed
- **WHEN** the content is built
- **THEN** it MUST include an `.ics` (iCalendar) attachment per RFC 5545

### Requirement: REQ-APT-007 Reminder Email and SMS

The system MUST send a 24-hour reminder email and optional SMS before each
appointment.

**Feature tier**: V1

#### Scenario: Reminder sent 24 hours before appointment

- **GIVEN** a confirmed Booking with `startAt: 2026-05-25T14:00:00Z`
- **WHEN** the ReminderDispatchJob runs on 2026-05-24 14:00
- **THEN** a reminder email MUST be dispatched
- **AND** `reminderSentAt` MUST be set on the Booking
- **AND** if SMS is configured, an SMS reminder MUST also be sent

#### Scenario: Reminder includes reschedule/cancel links

- **GIVEN** the reminder is being composed
- **WHEN** the content is built
- **THEN** it MUST include the same signed deep-links for reschedule and cancel as the confirmation email
