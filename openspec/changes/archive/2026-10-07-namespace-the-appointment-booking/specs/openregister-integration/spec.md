# OpenRegister integration

## ADDED Requirements

### Requirement: The appointment booking is namespaced (REQ-ORI-045)

The customer appointment schema SHALL be `appointmentBooking` and SHALL NOT be
`booking`. shillinq's bookings subsystem is the larger claimant and keeps the
bare slug.

The rename SHALL NOT touch `booking` where it is a log-context key, a template
context key in `AppointmentEmailService`, or the `booking-deposit` widget id.

A repair step SHALL rename the row IN PLACE before the register import, scoped
to this app's own rows.

The config key SHALL remain `booking_schema`.

#### Scenario: The slug is renamed in place

- **GIVEN** an install carrying a pipelinq-owned `booking` schema
- **WHEN** the repair step runs
- **THEN** the row keeps its schema id, and so its shard table and objects.

#### Scenario: The email template context is untouched

- **WHEN** an appointment email is rendered
- **THEN** it still reads `$context['booking']['startAt']`.
