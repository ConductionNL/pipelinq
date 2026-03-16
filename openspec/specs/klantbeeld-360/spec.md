# Klantbeeld 360 Specification

## Purpose

Klantbeeld 360 provides a comprehensive, aggregated view of all interactions, cases, documents, and notes for a single person or business across all channels and systems. This "single pane of glass" is essential for KCC agents and case handlers to deliver consistent, informed service. **83% of klantinteractie-tenders** (43/52) require a 360-degree customer view.

**Standards**: VNG Klantinteracties (`Partij`, `Betrokkene`, `Contactmoment`), Haal Centraal BRP API, KVK API, ZGW Zaken API, AVG (doelbinding)
**Feature tier**: MVP (core), V1 (extended), Enterprise (advanced)
**Tender frequency**: 43/52 (83%)

## Data Model

The klantbeeld aggregates data from multiple sources into a unified view per person/business:
- **Client record**: Pipelinq client object (master record)
- **Contactmomenten**: All registered contact moments linked to this client
- **Zaken**: Open and closed cases from ZGW/Procest
- **Documenten**: Documents linked via zaken or directly to the client
- **Notes**: Internal notes from entity-notes spec
- **BRP/KVK data**: Enrichment from base registries

## Requirements

---

### Requirement: Unified Client Profile

The system MUST display a single, consolidated profile page for each client that combines identity data, contact details, and base registry enrichment.

**Feature tier**: MVP

#### Scenario: View person client profile

- GIVEN a person client "Jan de Vries" with BSN linked, email "jan@devries.nl", telephone "+31 6 12345678"
- WHEN the agent opens the klantbeeld for this client
- THEN the system MUST display: name, contact details (email, telephone, address), BSN (masked by default), and a "Verrijk met BRP" button
- AND the profile header MUST show the client type (Persoon) and a photo/avatar placeholder

#### Scenario: View organization client profile

- GIVEN an organization client "Acme B.V." with KVK number "12345678"
- WHEN the agent opens the klantbeeld
- THEN the system MUST display: business name, KVK number, contact details, and linked contact persons
- AND a "Verrijk met KVK" button MUST allow fetching current registration data

#### Scenario: BRP enrichment on demand

- GIVEN a client "Jan de Vries" with BSN "123456789" linked
- WHEN the agent clicks "Verrijk met BRP"
- THEN the system MUST query the BRP via OpenConnector and display: current address (verblijfplaats), nationality, partner information, and registration municipality
- AND the system MUST log this BRP lookup in the audit trail with the agent identity and doelbinding reason

---

### Requirement: Interaction History

The system MUST display a chronological timeline of all interactions (contactmomenten, zaak-events, notes) for a client.

**Feature tier**: MVP

#### Scenario: Display complete interaction history

- GIVEN a client "Jan de Vries" with the following history:
  - 2024-01-15: Contactmoment (telefoon) -- "Vraag over vergunning"
  - 2024-02-01: Zaak "Bouwvergunning" aangemaakt
  - 2024-02-10: Contactmoment (e-mail) -- "Aanvullende documenten verstuurd"
  - 2024-02-15: Interne notitie door behandelaar
  - 2024-03-01: Zaak status "In behandeling" -> "Besluit genomen"
- WHEN the agent views the interaction history
- THEN the system MUST display all 5 events in reverse chronological order
- AND each event MUST show: date, type (icon), channel (if contactmoment), summary, and actor
- AND the timeline MUST support infinite scroll or "Meer laden"

#### Scenario: Filter interaction history by type

- GIVEN a client with 20 interaction history entries of mixed types
- WHEN the agent filters by "Contactmomenten" only
- THEN only contactmoment entries MUST be shown
- AND filter options MUST include: Alle, Contactmomenten, Zaken, Notities, Documenten

#### Scenario: Filter interaction history by date range

- GIVEN a client with interaction history spanning 2 years
- WHEN the agent selects date range "01-01-2024" to "31-03-2024"
- THEN only entries within that range MUST be displayed
- AND the total count for the filtered range MUST be shown

---

### Requirement: Open and Closed Cases Overview

The system MUST display all cases (open and closed) for the client, grouped by status, with quick access to case details.

**Feature tier**: MVP

#### Scenario: Display open cases prominently

- GIVEN a client "Jan de Vries" with 2 open and 5 closed zaken
- WHEN the agent views the klantbeeld cases section
- THEN open cases MUST be displayed first, prominently highlighted
- AND each case MUST show: zaaktype, identificatie, status, start date, and handler
- AND closed cases MUST be shown below in a collapsible section

#### Scenario: View case details from klantbeeld

- GIVEN a client with open zaak "Omgevingsvergunning #2024-001"
- WHEN the agent clicks on the case
- THEN the system MUST display case details in a side panel or modal: full status history, linked documents, besluit (if any), and handler
- AND the agent MUST NOT leave the klantbeeld view

#### Scenario: Display case statistics summary

- GIVEN a client "Acme B.V." with 3 open zaken and 12 closed zaken over the past 2 years
- WHEN the agent views the klantbeeld header
- THEN the system MUST display summary statistics: open cases count (3), closed cases count (12), average case duration, and last case activity date

---

### Requirement: Documents Overview

The system MUST display all documents associated with the client, either directly or through linked cases.

**Feature tier**: V1

#### Scenario: Display documents from all linked cases

- GIVEN a client "Jan de Vries" with 2 zaken, each having 3 documents
- WHEN the agent opens the documents tab in the klantbeeld
- THEN the system MUST display all 6 documents with: filename, document type, date, and source case
- AND each document MUST be downloadable or viewable inline (for PDFs)

#### Scenario: Search within client documents

- GIVEN a client with 20 documents across multiple cases
- WHEN the agent searches for "vergunning"
- THEN the system MUST filter documents by filename and metadata containing "vergunning"
- AND display matching results with the search term highlighted

---

### Requirement: Privacy and Access Control (Doelbinding)

The system MUST enforce AVG-compliant access to the klantbeeld, logging all data access with a purpose (doelbinding) and ensuring agents only see data relevant to their role.

**Feature tier**: MVP

#### Scenario: Log access to klantbeeld

- GIVEN agent "Medewerker A" opens the klantbeeld for client "Jan de Vries"
- WHEN the klantbeeld loads
- THEN the system MUST create an audit log entry with: agent identity, client identity, timestamp, and accessed data categories
- AND the log entry MUST be immutable and available for AVG audits

#### Scenario: Require doelbinding for BRP access

- GIVEN an agent clicks "Verrijk met BRP" for a client
- WHEN the enrichment request is initiated
- THEN the system MUST require the agent to select a doelbinding reason (e.g., "Afhandeling vergunningaanvraag", "Klantidentificatie bij contact")
- AND the selected reason MUST be stored in the audit trail alongside the BRP query

#### Scenario: Role-based data visibility

- GIVEN a KCC agent with role "frontoffice" and a case handler with role "backoffice"
- WHEN the KCC agent views the klantbeeld
- THEN the system MUST hide sensitive case details that are restricted to backoffice roles
- AND the agent MUST see an indication that restricted information exists but is not accessible
- AND role-based visibility rules MUST be configurable per data category

---

### Requirement: Notes and Internal Communication

The system MUST support adding internal notes to the klantbeeld that are visible to colleagues but not to the citizen.

**Feature tier**: V1

#### Scenario: Add internal note to client

- GIVEN an agent viewing the klantbeeld for "Jan de Vries"
- WHEN the agent adds a note "Let op: burger is slechthorend, communicatie bij voorkeur schriftelijk"
- THEN the note MUST be stored linked to the client with agent identity and timestamp
- AND the note MUST be visible to all agents who open this client's klantbeeld
- AND the note MUST appear in the interaction timeline

#### Scenario: Pin important note

- GIVEN a client "Jan de Vries" with 5 notes, including one marked as important
- WHEN any agent opens the klantbeeld for this client
- THEN the pinned note MUST be displayed prominently at the top of the klantbeeld
- AND the pinned note MUST have a visual distinction (e.g., warning banner)
