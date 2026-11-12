# example-data

## ADDED Requirements

### Requirement: The set fills the list boards

The Zuiddrecht set SHALL seed 23 bookings in the seven days from the install
day, 4 of them handled by Pieter and 3 awaiting a deposit, with the board's
rows first in time. It SHALL seed 5 journeys, 14 products (Club-app, basis as
a draft), 10 templates and 5 cash shifts.

#### Scenario: This week's bookings

- **GIVEN** the Zuiddrecht set installed today
- **WHEN** a user opens Appointments on This week
- **THEN** the count reads 23 and the first page shows the board's rows from today's 09:00 onward
