# contact-moments-list-board Delta

## ADDED Requirements

### Requirement: The contact moments list logs a contact

The tickets list opened as contact moments SHALL offer Log contact as its primary header button, opening the ticket
form for a contact moment. The requests list SHALL keep its own buttons and Actions menu.

#### Scenario: Board PqContactmomenten

- **WHEN** an agent opens Contactmomenten
- **THEN** the primary button reads "Contact vastleggen" and opens the contact moment form

### Requirement: A contact moment row reads as the board draws it

A row SHALL show the subject with its reference beneath it, the channel with its direction in words, and when it
happened as a day and a time; subject, client and outcome SHALL wrap rather than be cut off.

#### Scenario: A phone call this morning

- **GIVEN** an inbound phone contact moment PQ-2026-0423 at 09:12 today
- **THEN** its row reads "PQ-2026-0423" under the subject, "Telefoon · inkomend" and "vandaag, 09.12"
