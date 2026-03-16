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

---

### Current Implementation Status

**Partially implemented.** The client detail view provides a basic customer profile, but the full 360-degree view with interaction history, cases, documents, and BRP/KVK enrichment is NOT implemented.

Implemented (partial, via existing client detail view):
- **Client profile**: `src/views/clients/ClientDetail.vue` displays: name, type, email, phone, website, address, notes. Shows "Synced with Contacts" badge when linked to Nextcloud Contacts.
- **Linked contacts**: Contact persons list with name, role, email on client detail.
- **Linked leads**: Leads list with title, stage, value on client detail.
- **Linked requests**: Requests list with title, status on client detail.
- **Delete with warnings**: Delete dialog warns about linked contacts/leads/requests.
- **Sidebar**: `CnDetailPage` renders OpenRegister's sidebar with audit log (basic change history).
- **Entity notes**: `EntityNotes.vue` component provides notes functionality via `ICommentsManager` (see entity-notes spec).
- **KVK API client**: `lib/Service/KvkApiClient.php` exists for prospect discovery but could be repurposed for KVK enrichment.

NOT implemented:
- **Unified interaction history/timeline** -- no chronological timeline aggregating contactmomenten, zaak events, notes, and documents. The sidebar shows OpenRegister audit log but not a CRM-level timeline.
- **Open/closed cases overview** -- no ZGW Zaken API integration to display open and closed zaken for a client.
- **Documents overview** -- no document listing from linked cases or direct client attachments.
- **BRP enrichment** -- no BSN field on client schema, no BRP API integration, no "Verrijk met BRP" button.
- **KVK enrichment** -- the KVK API client exists but is not integrated into the client detail view for on-demand enrichment.
- **Summary statistics** -- no aggregated stats panel (open leads count/value, won value, case duration, last activity).
- **Privacy/access control** -- no doelbinding logging, no AVG audit trail for data access, no role-based visibility filtering.
- **Pinned notes** -- no pin/unpin functionality for important notes.
- **Filter by type/date** on interaction history -- not applicable since timeline does not exist.
- **BSN field** -- not in the client schema (`pipelinq_register.json`). The schema has: name, type, email, phone, address, website, industry, notes, contactsUid -- but no BSN, KVK number, or taxID field.

**Mock Registers (dependency):** This spec depends on mock BRP and KVK registers being available in OpenRegister for development and testing. These registers are available as JSON files that can be loaded on demand from `openregister/lib/Settings/`. Production deployments should connect to the actual Haal Centraal BRP API and KVK Handelsregister API via OpenConnector.

### Using Mock Register Data

This spec depends on the **BRP** and **KVK** mock registers.

**Loading the registers:**
```bash
# Load BRP register (35 persons, register slug: "brp", schema: "ingeschreven-persoon")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/brp_register.json

# Load KVK register (16 businesses + 14 branches, register slug: "kvk", schemas: "maatschappelijke-activiteit", "vestiging")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/kvk_register.json
```

**Test data for this spec's use cases:**
- **BRP enrichment**: BSN `999993653` (Suzanne Moulin, French national in Rotterdam) -- test "Verrijk met BRP" button to display address, nationality, partner info
- **BRP enrichment**: BSN `999990627` (Stephan Janssen, father with 2 children) -- test family relationships display
- **BRP enrichment**: BSN `999999655` (Astrid Abels, deceased) -- test edge case handling
- **KVK enrichment**: KVK `69599084` (Test EMZ Dagobert, Eenmanszaak Amsterdam) -- test "Verrijk met KVK" button
- **KVK enrichment**: KVK `68750110` (Test BV Donald, BV Lollum) -- test business data display with vestiging

**Querying mock data:**
```bash
# Find person by BSN
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{brp_register_id}/{person_schema_id}?_search=999993653" -u admin:admin

# Find business by KVK number
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{kvk_register_id}/{business_schema_id}?_search=69599084" -u admin:admin
```

**In Vue frontend stores:**
```javascript
const brpRegisterId = store.getters.getRegisterBySlug('brp')?.id
const personSchemaId = store.getters.getSchemaBySlug('ingeschreven-persoon')?.id
const response = await fetch(`/index.php/apps/openregister/api/objects/${brpRegisterId}/${personSchemaId}?_search=${bsn}`)
```

### Standards & References
- VNG Klantinteracties API -- `Partij`, `Betrokkene`, `Contactmoment` entities for unified customer view
- Haal Centraal BRP Personen Bevragen API v2 -- for BSN-based citizen data enrichment
- KVK API (Basisregistratie Handelsregister) -- for KVK-based business data enrichment
- ZGW Zaken API -- for retrieving cases linked to a citizen/business
- AVG (Algemene Verordening Gegevensbescherming) -- doelbinding requirements for personal data access
- Common Ground -- federated data access principles
- WCAG AA -- accessibility for government-facing interfaces

### Specificity Assessment
- The spec is comprehensive and covers the full 360-degree customer view with detailed scenarios.
- **NOT fully implementable as-is** due to significant external dependencies:
- **Missing**: The client schema needs BSN and KVK number fields to enable identification and enrichment. These are not in the current data model.
- **Missing**: No specification of how BRP/KVK data is cached or stored -- should enrichment data be saved on the client object or fetched on demand each time?
- **Missing**: No specification of the ZGW Zaken API integration -- which endpoint, authentication, and how to discover cases by BSN/KVK.
- **Missing**: No specification of the audit log format for AVG compliance -- what is logged, where, and how is it made immutable?
- **Missing**: No specification of role definitions (frontoffice/backoffice) -- where are roles managed? Nextcloud groups? Custom role system?
- **Open question**: Should the klantbeeld be a separate route/view or an enhanced version of the existing client detail view?
- **Open question**: How does the doelbinding requirement work in practice? Is it a modal dialog before each BRP query, or a one-time declaration per session?
- **Dependency**: BRP API access requires government certificates and secure connections -- not available in standard development environments.
