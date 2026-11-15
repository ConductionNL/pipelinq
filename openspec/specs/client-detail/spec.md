# client-detail Specification

## Purpose
The client page side column: appointments, what the client does business on, and knowledge base articles for the client's latest subject.

## Requirements

### Requirement: The client page shows appointments, business and the knowledge base beside it
The client page's side column SHALL show, after Relations, a resident's coming appointments (subject and time linking to the booking, status and handler under it), the business card (Projects and invoicing for a resident, Sales, contracts and invoicing for an organisation), Record history, and a Knowledge base search.

#### Scenario: A resident's appointment
- GIVEN Sanne de Vries has a confirmed site visit "Locatiebezoek Parkstraat" handled by Pieter Jansen
- WHEN her client page opens
- THEN the Appointments card reads "Locatiebezoek Parkstraat, <day>, 14:00" with "Confirmed · with Pieter Jansen" under it

### Requirement: The client page says what the client does business on
GET /api/clients/{id}/business SHALL read the client with the caller's rights and answer sentences for its open leads, running contracts, projects in planninq and invoices in shillinq, leaving out the sentence of an app that is not installed, and the address of shillinq when it is. The invoice sentence SHALL read shillinq's invoices with the caller's rights. A caller who may not read shillinq's invoices gets no invoice sentence.

#### Scenario: A resident with a planned project and no invoices
- GIVEN a resident with one planned project and no invoices in shillinq
- WHEN the business card loads
- THEN it reads "1 project: <title>, planned. No invoices for this resident." with a link Further in Shillinq

#### Scenario: A reader without the right to read invoices
@e2e exclude needs a second account without shillinq roles, which the shared e2e instance does not have; ClientBusinessInvoiceRightsTest asserts it in PHPUnit through the controller
- GIVEN a resident with two invoices in shillinq, and a reader who may not read shillinq's invoices
- WHEN the business card loads for that reader
- THEN it has no invoice sentence

### Requirement: The knowledge base card suggests articles for the client's latest subject
The Knowledge base card SHALL show, under the empty search, the two best knowledge base articles for the subject of the client's most recent open ticket or contact moment, and nothing when there is no such subject or no match.

#### Scenario: Sanne's open question
- GIVEN Sanne de Vries's most recent open ticket is "Vraag over vergunning straatfeest Parkstraat"
- WHEN her client page opens
- THEN the Knowledge base card lists the two best matching articles under the search

### Requirement: The overview ends with the activity card

The client overview SHALL end with an "Activity" card, the library's timeline over `/api/timeline`, newest first, as feed rows. It SHALL contain:
- the meta line: moment, kind, author;
- chips: Contact moments, Notes, Tasks, Email, Calendar, Messages;
- a box that adds an internal note to the client;
- "Link email" and "Compose in Mail" in its header.

#### Scenario: a note from the card
- **WHEN** the user types a note and presses "Add note"
- **THEN** the note is saved on the client and heads the feed as a note

### Requirement: The contact strip sends a message

The contact strip SHALL end with "Send a message". It opens the Messages section's composer for that client. A sent message SHALL make the client's activity card read again.

#### Scenario: Send a message
- **WHEN** the user presses "Send a message" on Anna de Vries's page
- **THEN** the message composer opens for her contact

### Requirement: The client page's figures stand in one compact row

The ClientDetail band SHALL be compact, so its six figures stand in one row above the tabs as the client boards draw
them.

#### Scenario: PqKlant figures

- **THEN** "Openstaande zaken" through "Laatste activiteit" stand side by side in one row of 67px tiles

### Requirement: The client page's log form and messages card are board forms

In the board look the client page's "Contactmoment registreren" and "Berichten" cards SHALL draw their labels 14/600
above 40px fields and their labelled buttons 40px, and "Berichten" SHALL be titled as a 17/700 card title.

#### Scenario: PqKlant Contactmoment registreren

- **THEN** "Onderwerp", "Kanaal" and "Samenvatting" stand above their fields
- **AND** "Annuleren" and "Opslaan" are 40px

#### Scenario: PqKlant Berichten

- **THEN** "Berichten" is 17/700 and "Bericht versturen" is 40px

### Requirement: The client page's tab strip is as tall as the open tab

The ClientDetail tab strip SHALL take the height of the open tab, so no card in it is cut off and the body sections
start right under it.

#### Scenario: PqKlant Activiteit

- **THEN** Sanne de Vries's Activity card shows its entries and its bottom edge
- **AND** Berichten and Contactmoment registreren start 20px under it
