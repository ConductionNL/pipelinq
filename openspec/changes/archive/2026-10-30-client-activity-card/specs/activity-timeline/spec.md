# activity-timeline

## ADDED Requirements

### Requirement: The timeline lists notes and messages

`GET /api/timeline` SHALL list the notes on the record as kind `note`. Each note shows its text, its time and its author. It SHALL list WhatsApp and SMS messages as kind `message`. A contact's messages are its own. A client's messages are those of its contact persons. `types[]=note` and `types[]=message` SHALL narrow to these kinds.

#### Scenario: an internal note on a client
- **GIVEN** Fatma Yildiz wrote a note on Sanne de Vries's client record
- **THEN** the timeline lists it as a `note` with `userName` "Fatma Yildiz"

### Requirement: The timeline names authors by display name

Every item SHALL carry `userName`: its author's display name, or the user id when that user is unknown.

#### Scenario: a contact moment handled by Fatma
- **GIVEN** a contact moment assigned to user `fatma`
- **THEN** its `userName` is "Fatma Yildiz"

### Requirement: The timeline lists bookings and linked mail

`GET /api/timeline` SHALL list a client's appointment bookings as kind `calendar`. Each booking is dated the day it was made and titled with its subject, else its service's name, and the appointment's time. It SHALL list the emails linked to the record as kind `email`. It reads them through OpenRegister's published `EmailLinkReaderInterface`. Without that reader, the mail kind SHALL be empty, not an error.

#### Scenario: a site visit booked for Sanne
- **GIVEN** a "Locatiebezoek" booking for Sanne de Vries on 8 October at 14:00, booked on 5 October
- **THEN** the timeline lists it on 5 October as "Locatiebezoek, 8 oktober 2026 om 14:00"
