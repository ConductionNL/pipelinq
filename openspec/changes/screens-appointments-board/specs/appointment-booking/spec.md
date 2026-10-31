## ADDED Requirements

### Requirement: The appointments list reads as its board (REQ-APT-BOARD-001)
The appointments list MUST be titled Appointments and MUST show a count line, the chips Today, This week, Mine and Unconfirmed with their counts, and the columns When (the start and end time of day), With whom (the customer's name), About (the subject over how it was booked), Location or desk, Handler and Status (the status label).

#### Scenario: The list shows what an appointment is about
@e2e exclude The formatter is asserted in tests/vitest/bookingTime.spec.js; the e2e instance has no bookings with a subject until the register is imported again.
- **GIVEN** a booking from 14:00 to 15:00 with a subject and a handler
- **WHEN** an employee opens the appointments list
- **THEN** the When cell MUST read "14:00 to 15:00" in the reader's language
- **AND** the About cell MUST show the subject with how it was booked beneath it

#### Scenario: Mine narrows to the reader's own appointments
@e2e exclude Declarative quick filter on the assignee field, asserted on the built manifest; no e2e user owns a booking.
- **GIVEN** bookings handled by several employees
- **WHEN** the employee picks the Mine chip
- **THEN** the list MUST show only the bookings whose handler is that employee

### Requirement: The appointment page keeps its actions in view (REQ-APT-BOARD-002)
The appointment page MUST show Confirm deposit, Mark completed, Mark no-show, Reschedule and Cancel as a row of buttons under the title, each disabled while it does not apply to the booking, and Send reminder beside them when a reminder can be sent.

#### Scenario: A finished booking keeps the row
@e2e exclude Component behaviour of BookingHeaderActions; the time-window rules are the ones the booking endpoints enforce.
- **GIVEN** a booking whose status is completed
- **WHEN** an employee opens it
- **THEN** the five status actions MUST be shown and disabled

### Requirement: The reschedule and cancel dialogs say what happens (REQ-APT-BOARD-003)
The reschedule dialog MUST show the current slot and the new slot, just as long, and say the customer gets a new confirmation. The cancel dialog MUST name the appointment, the cancellation charge rule, the refunded deposit when one was paid, and that the booking stays in the list as cancelled.

#### Scenario: Rescheduling keeps the length
@e2e exclude Slot text is asserted in tests/vitest/bookingTime.spec.js.
- **GIVEN** a booking from 14:00 to 15:00
- **WHEN** the employee picks 10:00 on another day
- **THEN** the dialog MUST show the new slot as 10:00 to 11:00, same length
