# Public events

## ADDED Requirements

### Requirement: An event collects registrations

An event MUST be an OpenRegister object of the `event` schema with a name, a start and end, a place and a number of places. Every person or household that signs up MUST be an `eventRegistration` object whose `eventId` names the event. The event page MUST count and list the registrations rather than store a counter.

#### Scenario: The event page counts its registrations

- GIVEN an event with 64 confirmed registrations and 9 on the waiting list
- WHEN a marketer opens the event page
- THEN the page shows 64 registered and 9 on the waiting list
- AND the Registrations tab lists the registrations of this event only

### Requirement: A reminder goes to everyone with a place

The event page MUST offer "Send reminder". It MUST mail every registration of the event whose status is `confirmed` and that has a valid e-mail address, through the app's service mail path, which asks integriq before each send. It MUST NOT mail a registration on the waiting list or a cancelled one. It MUST answer how many were sent, refused, skipped for having no address and failed, and stamp `reminderSentAt` on the event. The endpoint MUST refuse a caller without the CRM privilege.

#### Scenario: Only confirmed registrations are reminded

- GIVEN an event with two confirmed registrations, one without an e-mail address, and one on the waiting list
- WHEN the marketer sends the reminder
- THEN one mail is sent, one registration is skipped, and the waiting list is not mailed

#### Scenario: An unknown event is not found

- GIVEN no event with the given id
- WHEN the reminder is requested
- THEN the endpoint answers 404 and nothing is sent

### Requirement: A registration is checked in at the door

The event page MUST offer "Check in at the door", where a host checks a registration in. Checking in MUST stamp `checkedInAt` on the registration. A cancelled registration MUST NOT be checked in, and checking in twice MUST keep the first moment. The endpoint MUST refuse a caller without the CRM privilege.

#### Scenario: A confirmed registration is checked in once

- GIVEN a confirmed registration that has not been checked in
- WHEN the host checks it in twice
- THEN `checkedInAt` holds the moment of the first check-in

#### Scenario: A cancelled registration is refused

- GIVEN a cancelled registration
- WHEN the host checks it in
- THEN the endpoint answers 409 and the registration is unchanged
