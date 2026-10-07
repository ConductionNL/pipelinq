# appointment-booking Specification (delta)

## ADDED Requirements

### Requirement: The booking timeline is the library timeline widget (REQ-RP2-001)

The booking page SHALL show its timeline through the nextcloud-vue `timeline`
widget. The timeline SHALL list the moments the booking holds: created,
deposit cleared, confirmation mail sent, reminder sent, starts, ends,
cancelled and no-show fee charged, each only when set. It SHALL include the
booking's audit trail, so a status change shows as a dated change with who
made it. A moment in the future SHALL be marked as upcoming.

#### Scenario: A confirmed booking

- GIVEN a booking created on 1 May, deposit cleared on 2 May and starting on 10 May
- WHEN the user opens the Timeline tab on 5 May
- THEN the timeline lists created, deposit cleared and starts in that order
- AND starts is marked upcoming

#### Scenario: A status change

- GIVEN a user cancels a booking
- WHEN the user opens the Timeline tab
- THEN the change shows with its time and the user who made it
