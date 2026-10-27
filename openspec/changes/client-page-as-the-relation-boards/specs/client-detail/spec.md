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
