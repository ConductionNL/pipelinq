# Time entry core

## ADDED Requirements

### Requirement: The hours of a billing line live on its humaniq booking (REQ-TEC-032)

A `billingTimeEntry` SHALL NOT carry its own `hours`, `date`, `user` or `description`; it SHALL
read them from the humaniq `TimeEntry` its `timeEntry` reference names. The billing category
widget SHALL declare `requiredApp: humaniq` and SHALL sum the hours of the bookings its billing
lines name.

#### Scenario: The widget sums the booked hours

- **GIVEN** two billing lines in one billing category, naming humaniq bookings of 2 and 3 hours
- **WHEN** the billing category widget renders
- **THEN** it shows 5 hours for that category.

#### Scenario: Without humaniq the widget sends no request

- **GIVEN** an install without humaniq
- **WHEN** the dashboard renders
- **THEN** the billing category widget shows its set-up state and sends no request.

### Requirement: Existing billing lines are moved onto bookings (REQ-TEC-033)

A repair step SHALL write one humaniq `TimeEntry` per existing billing line that names none,
carrying the line's hours, date, user and description, and SHALL point the line's `timeEntry` at
it. It SHALL be idempotent: a line that already names a booking is left alone.

#### Scenario: A line without a booking gets one

- **GIVEN** a billing line of 4 hours with no `timeEntry`
- **WHEN** the repair step runs
- **THEN** a humaniq `TimeEntry` of 4 hours exists and the line's `timeEntry` names it.

#### Scenario: A second run changes nothing

- **GIVEN** the repair step ran once
- **WHEN** it runs again
- **THEN** no booking is written and no line changes.
