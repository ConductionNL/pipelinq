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
