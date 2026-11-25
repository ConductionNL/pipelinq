# marketing-events-and-attendees Specification (delta)

## Purpose

A team organises an event with a date, a place and a number of seats, and
takes the registrations from the campaign's landing page form. From pipelinq
matrix row `mkt-events`.

## ADDED Requirements

### Requirement: A campaign can have an event (REQ-MEV-001)

The system SHALL store an event with a title, a start and end, a venue or an
online join link, a number of seats, a closing date for registration and a
status, linked to one campaign. The campaign page SHALL let a marketer create
the event or open it.

#### Scenario: A marketer adds an event to the webinar campaign

- GIVEN the campaign "Webinar AI voor gemeenten"
- WHEN a marketer opens it, chooses Create event and enters 12 November 14:00 to 15:00, online, 100 seats
- THEN the campaign page shows the event with 0 of 100 seats taken

### Requirement: A landing page sign-up registers for the event (REQ-MEV-002)

When a campaign has an open event, a sign-up on its landing page SHALL still
become a contact, a lead and a touchpoint, and SHALL also become a
registration: registered while a seat is free, on the waiting list when none
is. A second sign-up with the same email address for the same event SHALL NOT
take a second seat. After the closing date, or when the event is not open, no
registration SHALL be written.

#### Scenario: A visitor signs up and gets a seat

- GIVEN an open event with 100 seats and 20 registered
- WHEN a visitor signs up on the campaign's landing page with name and email
- THEN the event lists that person as registered
- AND a lead for the campaign exists as before

#### Scenario: A sign-up after the last seat goes on the waiting list

- GIVEN an open event with 2 seats and 2 registered
- WHEN a third visitor signs up
- THEN the event lists that person as waitlisted

#### Scenario: Two sign-ups for the last seat

- GIVEN an open event with one free seat
- WHEN two visitors sign up in the same second
- THEN one is registered and the other is waitlisted
- AND only the registered one receives a calendar file

### Requirement: Attendees get a confirmation, a reminder and a way to cancel (REQ-MEV-003)

The system SHALL mail each registered attendee a confirmation with a calendar
file and a cancel link, and each waitlisted attendee a waiting list notice. It
SHALL mail a reminder 24 hours before the start, with the join link for an
online event. The cancel link SHALL open a page that cancels only after the
attendee presses a button. A cancellation SHALL free the seat for the first
waitlisted attendee, who SHALL receive a mail.

#### Scenario: An attendee cancels and the waiting list moves up

- GIVEN a full event with one person on the waiting list
- WHEN a registered attendee opens the cancel link and presses Cancel my registration
- THEN that attendee is cancelled
- AND the waitlisted person is registered and receives a mail with a calendar file

#### Scenario: Opening the cancel link does not cancel

- GIVEN a registered attendee
- WHEN a mail scanner opens the cancel link
- THEN the registration is still registered

### Requirement: The team keeps the attendee list and who came (REQ-MEV-004)

The event page SHALL list attendees with their status and show the counts of
registered, seats and waiting. A team member SHALL add an attendee by hand and
mark each attendee attended or no-show. Marking attended SHALL add a touchpoint
of kind attend to the campaign, so the campaign report counts it.

#### Scenario: The team records who came

- GIVEN an event that took place with 40 registered attendees
- WHEN a team member marks 32 of them attended
- THEN the event page shows 32 attended
- AND the campaign report shows 32 attend touchpoints
