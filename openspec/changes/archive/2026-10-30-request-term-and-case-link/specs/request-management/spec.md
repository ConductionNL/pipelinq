## ADDED Requirements

### Requirement: A request's term can be set from its Termijn card

The Termijn card on a request MUST offer "Termijn instellen" to a user who may update the ticket. It MUST save the chosen date and time as the ticket's `slaDeadline` through OpenRegister, so OpenRegister's object rights decide, and the card MUST show the new term after saving.

#### Scenario: Pieter sets the term of Sanne's request
- GIVEN request PQ-2026-0417 with term 11 oktober 2026, 17:00
- WHEN Pieter chooses "Termijn instellen" and saves 14 oktober 2026, 17:00
- THEN the ticket's slaDeadline is 2026-10-14T17:00
- AND the Termijn card reads "14 oktober 2026, 17:00"

#### Scenario: A reader cannot set the term
- GIVEN a user who may read but not update the ticket
- THEN the Termijn card shows no "Termijn instellen"

### Requirement: A request can be linked to an existing case

While a request has no `caseReference`, the Gekoppelde zaak card MUST offer "Bestaande zaak koppelen". It MUST let the user pick one of the cases the case app lists and save that case as the ticket's `caseReference`. Linking MUST NOT change the ticket's status.

#### Scenario: Pieter links the request to the case that already exists
- GIVEN request PQ-2026-0417 with no case linked, and case 2026-4890 in dossiq
- WHEN Pieter chooses "Bestaande zaak koppelen" and picks 2026-4890
- THEN the ticket's caseReference names case 2026-4890
- AND the Gekoppelde zaak card shows the case by its title
- AND the request keeps its status

#### Scenario: Without a case app there is nothing to link
- GIVEN no case app answers the case search
- THEN the Gekoppelde zaak card shows "Nog geen zaak gekoppeld." without the link

### Requirement: The Termijn card writes the term as its figure

The Termijn card MUST draw the term as the card's figure, the date and time written out, with Prioriteit and Behandelaar as rows under it.

#### Scenario: Board PqTicket's Termijn card
- GIVEN request PQ-2026-0417 with term 11 oktober 2026, 17:00
- THEN the Termijn card's figure reads "11 oktober 2026, 17:00" at 26px
