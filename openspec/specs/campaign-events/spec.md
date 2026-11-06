# campaign-events Specification

## Purpose
Campaign events: registration, reminders to everyone with a confirmed place, check-in at the door, the event's form, and the results that count who came.

## Requirements

### Requirement: An event's reminder reaches everyone with a confirmed place

"Send reminder" on an event SHALL mail every registration with status confirmed and a valid e-mail address, naming the event, when it starts and where. The event SHALL be stamped with `reminderSentAt` as the caller before any mail is sent. A caller who may not change the event SHALL be refused, and nothing SHALL be sent. The answer SHALL say how many mails went out and how many registrations were skipped.

#### Scenario: three confirmed, one without an address
- GIVEN an event with three confirmed registrations, one of them without an e-mail address
- WHEN the organiser sends the reminder
- THEN two mails go out, one registration is reported as skipped, and the event has reminderSentAt

#### Scenario: a colleague who may only read the event
- GIVEN a user who may read the event but not change it
- WHEN that user sends the reminder
- THEN the request is refused and no mail goes out

### Requirement: Registrations are checked in at the door

The event SHALL offer a check-in list of its confirmed registrations, the ones not yet checked in first. Checking someone in SHALL stamp `checkedInAt` with the moment, and it SHALL be possible to undo it.

#### Scenario: someone arrives
- GIVEN Sanne de Vries has a confirmed place and has not checked in
- WHEN the colleague at the door presses Check in on her row
- THEN her checkedInAt is the moment, and she moves to Checked in

### Requirement: The event's results count who came

The event's Results tab SHALL count the confirmed registrations that checked in and those that did not, and list who checked in, with the time.

#### Scenario: after the evening
- GIVEN 64 confirmed registrations, 51 of them checked in
- WHEN the organiser opens Results
- THEN it reads 51 came and 13 did not come

### Requirement: The registration form card edits the form in portaliq

An event MAY name the portaliq form binding its sign-up form is published with. When it does, the registration form card SHALL offer "Formulier bewerken", opening that form binding in portaliq. When it does not, the card SHALL NOT show the button.

#### Scenario: an event with a published form
- GIVEN the event "Informatieavond warmtenet" names its portaliq form binding
- WHEN the organiser opens the event
- THEN the form card shows "Formulier bewerken", which opens that form binding in portaliq

#### Scenario: an event without a form binding
- GIVEN an event that names no form binding
- WHEN the organiser opens the event
- THEN the form card shows no "Formulier bewerken" button
