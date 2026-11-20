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

### Requirement: The client page opens on the boards' figures and tabs

The client page SHALL show one row of six figures, each a label and a value: open matters, new requests, term passed, term at risk, open leads and last activity (as a date). Under it the tab strip SHALL read Overview, Tickets, Contact persons (an organisation only), Contact moments, Sales (a resident only), Documents and History.

#### Scenario: Sanne de Vries

- **GIVEN** the resident Sanne de Vries
- **THEN** the figures read Openstaande zaken, Nieuwe verzoeken, Termijn verstreken, Termijn in gevaar, Open leads and Laatste activiteit ("4 okt")
- **AND** the tabs read Overzicht, Tickets, Contactmomenten, Verkoop, Documenten, Geschiedenis

### Requirement: The overview is the relation screen's overview

The overview SHALL show the resident's card (name, given names, surname, date of birth, home address, description) or the organisation's card (trade name, KVK number, legal form, address), the client's tickets as ruled rows with their status and date, and the three newest contact moments.

#### Scenario: Buurtvereniging Oost

- **GIVEN** the organisation Buurtvereniging Oost
- **THEN** the overview opens on Organisatie (Handelsnaam, KVK-nummer, Rechtsvorm, Adres), then Tickets, then Laatste contactmomenten

### Requirement: New request starts from the client

The header SHALL offer Log contact and New request; New request SHALL open the request dialog with the client already chosen.

#### Scenario: New request from Sanne de Vries

- **GIVEN** the client page of Sanne de Vries
- **WHEN** the user clicks New request in the header
- **THEN** the request dialog opens with Sanne de Vries already chosen as the client
- **AND** the header also offers Log contact

### Requirement: An organisation shows its main contact

The side column of an organisation SHALL show, under KvK and address, the contact person flagged as main contact (else the first contact person) with initials, name, role and e-mail, and a link to the Contact persons tab with the number of contact persons.

#### Scenario: Buurtvereniging Oost

- **GIVEN** Buurtvereniging Oost with Sanne de Vries (Secretaris, main contact) and Marja Verbeek (Voorzitter)
- **THEN** the Hoofdcontact card shows SV, Sanne de Vries, Secretaris, bestuur@buurtoost.nl and "Alle 2 contactpersonen"
- **WHEN** the user follows that link
- **THEN** the Contact persons tab opens

### Requirement: The client page lists the client's cases

The client page SHALL list the client's cases, which live in dossiq, read by the client's master entity through dossiq's case leaf (`GET /api/clients/{id}/cases`): three in the overview's Zaken card with a link to all of them, all of them in the Zaken tab after Overzicht, and on an organisation the open case due first as Open zaken under the main contact. The client SHALL be read with the caller's rights first (403 when the caller may not read it), and dossiq SHALL read the cases with the caller's rights. Without dossiq, or without a master entity, the client has no cases. A case row SHALL open the case in dossiq.

#### Scenario: Buurtvereniging Oost

- **GIVEN** Buurtvereniging Oost, whose dossiq organisation requested one open and two closed cases
- **THEN** the Zaken card shows the open case first with its status pill and "Uiterlijk" date, then the closed cases with "Klaar" and their end date, and "Alle 3 zaken bekijken"
- **AND** Open zaken shows the open case
- **WHEN** the user opens a case row
- **THEN** the case opens in dossiq
