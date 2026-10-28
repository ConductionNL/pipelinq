# client-detail Delta

**Status**: proposed
**Scope**: pipelinq

## Purpose

The client page reads as PqKlant and PqOrganisatie, the same relation screen dossiq draws.

## ADDED Requirements

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
