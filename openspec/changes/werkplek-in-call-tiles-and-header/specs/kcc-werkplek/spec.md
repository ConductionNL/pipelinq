# kcc-werkplek Delta

## ADDED Requirements

### Requirement: The in-call page offers the next steps as tiles

While a call is on, the contact centre page SHALL draw a row of six tiles above the in-call cards: new request, call
back, book an appointment, to the back office, register a complaint and another channel. Each tile SHALL open its form
or dialog with the client on the line filled in.

#### Scenario: Board PqWerkplekKlant

- **WHEN** the agent takes a call from a known client and clicks "Terugbellen"
- **THEN** the callback form opens with the client filled in

### Requirement: The in-call header marks the call

While a call is on, the header SHALL show "In gesprek" as a menu that sets the agent's availability, the call's running
time as a separate chip, and the pills "Telefoon, inkomend" and "Geïdentificeerd" at the start of the line under the
title. Ending the call SHALL remove the menu state, the chip and the pills.

#### Scenario: Board PqWerkplekKlant

- **WHEN** the agent takes a call from a known client
- **THEN** the header reads "In gesprek" with a timer chip beside it
- **AND** the line under the title opens with "Telefoon, inkomend" and "Geïdentificeerd"

### Requirement: The in-call cards carry the board's titles

While a call is on, the contact form SHALL be titled "Dit contactmoment", the client's requests "Openstaande zaken" and
the earlier contact moments "Eerder contact". Without a call the cards SHALL keep their dashboard titles.

#### Scenario: Board PqWerkplekKlant

- **WHEN** the agent takes a call
- **THEN** the middle column opens with "Dit contactmoment" above "Openstaande zaken"
