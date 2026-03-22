---
status: draft
---

# Klantbeeld 360 Specification

## Purpose

Klantbeeld 360 provides a comprehensive, aggregated view of all interactions, cases, documents, and notes for a single person or business across all channels and systems. This "single pane of glass" is essential for KCC agents and case handlers to deliver consistent, informed service. **83% of klantinteractie-tenders** (43/52) require a 360-degree customer view.

**Standards**: VNG Klantinteracties (`Partij`, `Betrokkene`, `Contactmoment`), Haal Centraal BRP API, KVK API, ZGW Zaken API, AVG (doelbinding)
**Feature tier**: MVP (core), V1 (extended), Enterprise (advanced)
**Tender frequency**: 43/52 (83%)

## Data Model

The klantbeeld aggregates data from multiple sources into a unified view per person/business:
- **Client record**: Pipelinq client object (master record) with properties: name, type, email, phone, address, website, industry, notes, contactsUid
- **Contactmomenten**: All registered contact moments linked to this client (from kcc-werkplek/contactmomenten specs)
- **Zaken**: Open and closed cases from ZGW/Procest via OpenConnector
- **Documenten**: Documents linked via zaken or directly to the client via Nextcloud Files
- **Notes**: Internal notes via `EntityNotes.vue` component (ICommentsManager)
- **Leads and Requests**: Existing CRM entities linked to this client
- **BRP/KVK data**: Enrichment from base registries via OpenConnector sources

## ADDED Requirements

---

### Requirement: Unified Client Profile

The system MUST display a single, consolidated profile page for each client that combines identity data, contact details, and base registry enrichment.

**Feature tier**: MVP

#### Scenario: View person client profile

- GIVEN a person client "Jan de Vries" with BSN linked, email "jan@devries.nl", telephone "+31 6 12345678"
- WHEN the agent opens the klantbeeld for this client
- THEN the system MUST display: name, contact details (email, telephone, address), BSN (masked as "***456789" by default with a "Toon" toggle), and a "Verrijk met BRP" button
- AND the profile header MUST show the client type (Persoon) with a person avatar icon
- AND the profile MUST extend the existing `ClientDetail.vue` layout with additional klantbeeld tabs/sections

#### Scenario: View organization client profile

- GIVEN an organization client "Acme B.V." with KVK number "12345678"
- WHEN the agent opens the klantbeeld
- THEN the system MUST display: business name, KVK number, contact details, and linked contact persons (using the existing contacts table from `ClientDetail.vue`)
- AND a "Verrijk met KVK" button MUST allow fetching current registration data via `KvkApiClient`
- AND the organization profile MUST show the industry field and website

#### Scenario: BRP enrichment on demand

- GIVEN a client "Jan de Vries" with BSN "123456789" linked
- WHEN the agent clicks "Verrijk met BRP"
- THEN the system MUST query the BRP via OpenConnector and display: current address (verblijfplaats), nationality, partner information, and registration municipality
- AND the system MUST log this BRP lookup in the audit trail with the agent identity and doelbinding reason
- AND the enriched data MUST be displayed in a separate "BRP Gegevens" card below the client information card

#### Scenario: KVK enrichment on demand

- GIVEN an organization client "Acme B.V." with KVK number "12345678"
- WHEN the agent clicks "Verrijk met KVK"
- THEN the system MUST query the KVK API via the existing `KvkApiClient` service and display: legal form, registration date, trading names, SBI codes (activity descriptions), and vestigingen (branches)
- AND the enriched data MUST be displayed in a "KVK Gegevens" card
- AND the agent MUST be able to update the client record with fetched data (e.g., update address)

#### Scenario: Client profile with Nextcloud Contacts sync badge

- GIVEN a client "Jan de Vries" with `contactsUid` set (synced with Nextcloud Contacts)
- WHEN the agent opens the klantbeeld
- THEN the system MUST display the "Synced with Contacts" badge (existing pattern from `ClientDetail.vue`)
- AND the agent MUST be able to navigate to the linked Nextcloud Contact
- AND changes to the Nextcloud Contact MUST be reflected in Pipelinq via `ContactSyncService`

---

### Requirement: Interaction History

The system MUST display a chronological timeline of all interactions (contactmomenten, zaak-events, notes, leads, requests) for a client.

**Feature tier**: MVP

#### Scenario: Display complete interaction history

- GIVEN a client "Jan de Vries" with the following history:
  - 2024-01-15: Contactmoment (telefoon) -- "Vraag over vergunning"
  - 2024-01-20: Lead "Adviesgesprek stedenbouw" aangemaakt
  - 2024-02-01: Request "Bouwvergunning" aangemaakt, converted to zaak
  - 2024-02-10: Contactmoment (e-mail) -- "Aanvullende documenten verstuurd"
  - 2024-02-15: Interne notitie door behandelaar
  - 2024-03-01: Zaak status "In behandeling" -> "Besluit genomen"
- WHEN the agent views the interaction history tab
- THEN the system MUST display all 6 events in reverse chronological order
- AND each event MUST show: date, type (distinct icon per type), channel (if contactmoment), summary, and actor (agent/system)
- AND the timeline MUST support "Meer laden" pagination (20 events per page)

#### Scenario: Filter interaction history by type

- GIVEN a client with 20 interaction history entries of mixed types
- WHEN the agent filters by "Contactmomenten" only
- THEN only contactmoment entries MUST be shown
- AND filter options MUST include: Alle, Contactmomenten, Zaken, Notities, Leads, Verzoeken
- AND the active filter MUST be visually indicated and the count per type shown in parentheses

#### Scenario: Filter interaction history by date range

- GIVEN a client with interaction history spanning 2 years
- WHEN the agent selects date range "01-01-2024" to "31-03-2024"
- THEN only entries within that range MUST be displayed
- AND the total count for the filtered range MUST be shown
- AND quick date presets MUST be available: "Laatste 30 dagen", "Laatste 3 maanden", "Dit jaar"

#### Scenario: View interaction detail inline

- GIVEN the agent is viewing the interaction timeline
- WHEN the agent clicks on a contactmoment entry
- THEN the system MUST expand the entry inline to show the full details: subject, description, channel, duration, agent, and linked zaak
- AND the agent MUST NOT leave the klantbeeld view (no full page navigation)
- AND the expanded entry MUST include a "Ga naar" link to open the full detail page if needed

#### Scenario: Add interaction note from timeline

- GIVEN the agent is viewing the interaction timeline for "Jan de Vries"
- WHEN the agent clicks "Notitie toevoegen" at the top of the timeline
- THEN a note input field MUST appear inline
- AND submitting the note MUST add it to the timeline and store it via `ICommentsManager` (EntityNotes pattern)

---

### Requirement: Open and Closed Cases Overview

The system MUST display all cases (open and closed) for the client, grouped by status, with quick access to case details.

**Feature tier**: MVP

#### Scenario: Display open cases prominently

- GIVEN a client "Jan de Vries" with 2 open and 5 closed zaken
- WHEN the agent views the klantbeeld cases section
- THEN open cases MUST be displayed first in a prominent card section
- AND each case MUST show: zaaktype, identificatie, status, start date, and handler
- AND closed cases MUST be shown below in a collapsible section (collapsed by default)

#### Scenario: View case details from klantbeeld

- GIVEN a client with open zaak "Omgevingsvergunning #2024-001"
- WHEN the agent clicks on the case
- THEN the system MUST display case details in a side panel (matching the `CnDetailPage` sidebar pattern): full status history, linked documents, besluit (if any), and handler
- AND the agent MUST NOT leave the klantbeeld view

#### Scenario: Display case statistics summary

- GIVEN a client "Acme B.V." with 3 open zaken and 12 closed zaken over the past 2 years
- WHEN the agent views the klantbeeld header
- THEN the system MUST display summary statistics in a stats bar: open cases count (3), total cases (15), average case duration, and last case activity date
- AND the stats MUST use `CnStatsBlock` components for consistent display

#### Scenario: Cases fetched from Procest via API

- GIVEN client "Jan de Vries" has cases in the Procest zaaksysteem
- WHEN the klantbeeld loads the cases section
- THEN the system MUST query the Procest/ZGW Zaken API via OpenConnector to find cases linked to this client
- AND the query MUST use the client's BSN (for persons) or KVK number (for organizations) as the search parameter
- AND the results MUST be cached for 5 minutes to avoid excessive API calls

---

### Requirement: Documents Overview

The system MUST display all documents associated with the client, either directly or through linked cases.

**Feature tier**: V1

#### Scenario: Display documents from all linked cases

- GIVEN a client "Jan de Vries" with 2 zaken, each having 3 documents
- WHEN the agent opens the documents tab in the klantbeeld
- THEN the system MUST display all 6 documents with: filename, document type, date, and source case
- AND each document MUST be downloadable or viewable inline (for PDFs) using Nextcloud's viewer

#### Scenario: Display documents from Nextcloud Files

- GIVEN a client "Jan de Vries" has a folder in "Open Registers/Pipelinq/Clients/Jan de Vries/"
- WHEN the agent views the documents tab
- THEN documents from the client's Nextcloud Files folder MUST also be displayed alongside case documents
- AND each document MUST indicate its source: "Zaak" or "Bestanden"

#### Scenario: Search within client documents

- GIVEN a client with 20 documents across multiple cases
- WHEN the agent searches for "vergunning"
- THEN the system MUST filter documents by filename and metadata containing "vergunning"
- AND display matching results with the search term highlighted

#### Scenario: Upload document to client folder

- GIVEN the agent is viewing the documents tab for "Jan de Vries"
- WHEN the agent clicks "Document toevoegen" and uploads a file
- THEN the file MUST be stored in the client's Nextcloud Files folder via `IRootFolder`
- AND the document MUST immediately appear in the documents list
- AND the upload MUST support drag-and-drop

---

### Requirement: Contact Persons Management

The system MUST display and manage contact persons associated with a client organization, with role-based context.

**Feature tier**: MVP

#### Scenario: View contact persons with roles

- GIVEN organization client "Acme B.V." with 3 contact persons: CEO, Accountant, Project Manager
- WHEN the agent views the klantbeeld contact persons section
- THEN the system MUST display all 3 contacts with: name, role, email, phone (extending the existing contacts table from `ClientDetail.vue`)
- AND the primary contact MUST be visually distinguished (star icon or "Primair" badge)

#### Scenario: View contact person interaction history

- GIVEN contact person "Petra Bakker" (CEO) of "Acme B.V."
- WHEN the agent clicks on Petra's name in the contact persons section
- THEN the system MUST show Petra's individual interactions: emails, meetings, and contactmomenten specific to Petra
- AND the view MUST distinguish between interactions with Petra personally versus the organization generally

#### Scenario: Add contact person from klantbeeld

- GIVEN the agent is viewing the klantbeeld for "Acme B.V."
- WHEN the agent clicks "Contactpersoon toevoegen"
- THEN the system MUST display the contact creation form (reusing `ClientDetail.vue`'s existing pattern)
- AND the new contact MUST be automatically linked to "Acme B.V." via the `client` UUID property

---

### Requirement: Linked CRM Entities

The system MUST display all leads, requests, and tasks linked to the client in dedicated sections.

**Feature tier**: MVP

#### Scenario: Display linked leads with pipeline context

- GIVEN client "Acme B.V." has 2 open leads and 3 won leads
- WHEN the agent views the klantbeeld leads section
- THEN the system MUST display open leads first with: title, pipeline stage, value, probability, and assignee
- AND won/lost leads MUST be shown in a collapsible "Gesloten" section
- AND the total pipeline value for open leads MUST be displayed as a summary metric

#### Scenario: Display linked requests

- GIVEN client "Jan de Vries" has 1 open request and 4 completed requests
- WHEN the agent views the klantbeeld requests section
- THEN the system MUST display the open request prominently with status, category, and assignee
- AND completed requests MUST be shown below with completion date and outcome

#### Scenario: Quick-create from klantbeeld

- GIVEN the agent is viewing the klantbeeld for "Jan de Vries"
- WHEN the agent clicks "Nieuw verzoek" in the requests section header
- THEN the system MUST open the request creation form with the client pre-filled
- AND after saving, the new request MUST immediately appear in the klantbeeld requests section

---

### Requirement: Privacy and Access Control (Doelbinding)

The system MUST enforce AVG-compliant access to the klantbeeld, logging all data access with a purpose (doelbinding) and ensuring agents only see data relevant to their role.

**Feature tier**: MVP

#### Scenario: Log access to klantbeeld

- GIVEN agent "Medewerker A" opens the klantbeeld for client "Jan de Vries"
- WHEN the klantbeeld loads
- THEN the system MUST create an audit log entry with: agent identity (Nextcloud UID), client identity (UUID), timestamp, and accessed data categories (profile/interactions/cases/documents)
- AND the log entry MUST be immutable and available for AVG audits via OpenRegister's audit trail

#### Scenario: Require doelbinding for BRP access

- GIVEN an agent clicks "Verrijk met BRP" for a client
- WHEN the enrichment request is initiated
- THEN the system MUST display a modal dialog requiring the agent to select a doelbinding reason from a predefined list (e.g., "Afhandeling vergunningaanvraag", "Klantidentificatie bij contact", "Bezwaarschrift behandeling")
- AND the selected reason MUST be stored in the audit trail alongside the BRP query
- AND the dialog MUST include a checkbox confirming the agent understands the data will be logged

#### Scenario: Role-based data visibility

- GIVEN a KCC agent with Nextcloud group "frontoffice" and a case handler with group "backoffice"
- WHEN the KCC agent views the klantbeeld
- THEN the system MUST hide sensitive case details that are restricted to backoffice roles (e.g., internal case notes, financial details)
- AND the agent MUST see an indication that restricted information exists: "[3 items verborgen - onvoldoende rechten]"
- AND role-based visibility rules MUST be configurable per data category via admin settings

#### Scenario: BSN masking and access logging

- GIVEN a client has BSN "123456789" stored
- WHEN any agent views the klantbeeld
- THEN the BSN MUST be displayed as "***456789" by default
- AND clicking "Toon volledige BSN" MUST require confirmation and log the access
- AND the full BSN MUST be hidden again after 30 seconds or when the agent navigates away

#### Scenario: Data access report for citizen

- GIVEN a citizen "Jan de Vries" requests an AVG inzageverzoek (data access request)
- WHEN an administrator generates the access report
- THEN the system MUST produce a list of all agents who accessed Jan's klantbeeld, with dates, times, and doelbinding reasons
- AND the report MUST include which data categories were accessed (profile, BRP, cases, documents)

---

### Requirement: Notes and Internal Communication

The system MUST support adding internal notes to the klantbeeld that are visible to colleagues but not to the citizen.

**Feature tier**: V1

#### Scenario: Add internal note to client

- GIVEN an agent viewing the klantbeeld for "Jan de Vries"
- WHEN the agent adds a note "Let op: burger is slechthorend, communicatie bij voorkeur schriftelijk"
- THEN the note MUST be stored via `ICommentsManager` linked to the client object (reusing the `EntityNotes.vue` pattern)
- AND the note MUST be visible to all agents who open this client's klantbeeld
- AND the note MUST appear in the interaction timeline with type "Notitie"

#### Scenario: Pin important note

- GIVEN a client "Jan de Vries" with 5 notes, including one marked as important
- WHEN any agent opens the klantbeeld for this client
- THEN the pinned note MUST be displayed prominently at the top of the klantbeeld in a yellow warning banner
- AND the pinned note MUST have a visual distinction (warning icon, contrasting background)
- AND only one note can be pinned at a time per client

#### Scenario: Note mentions and notifications

- GIVEN an agent adds a note "@petra.bakker Kun je deze casus oppakken?"
- WHEN the note is saved
- THEN the system MUST detect the @-mention and send a Nextcloud notification to Petra Bakker
- AND the notification MUST include the client name, note text, and a link to the klantbeeld

---

### Requirement: Summary Statistics Panel

The system MUST display an at-a-glance summary panel at the top of the klantbeeld with key metrics.

**Feature tier**: MVP

#### Scenario: Display client summary statistics

- GIVEN client "Acme B.V." with various linked entities
- WHEN the agent opens the klantbeeld
- THEN the system MUST display a summary bar showing: Open leads (2, totaal EUR 45.000), Open requests (1), Open cases (3), Contactmomenten (15 dit jaar), Laatste contact (3 dagen geleden)
- AND each metric MUST be clickable to scroll to the relevant section
- AND the summary MUST use the `CnStatsBlock` component pattern from `Dashboard.vue`

#### Scenario: Empty client summary

- GIVEN a newly created client "Nieuw Bedrijf B.V." with no linked entities
- WHEN the agent opens the klantbeeld
- THEN the summary bar MUST show all metrics as "0" or "Geen"
- AND the system MUST suggest next actions: "Voeg een contactpersoon toe", "Maak een lead aan"

---

## Appendix

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
- **KVK API client**: `lib/Service/KvkApiClient.php` + `KvkResultMapper.php` exist for prospect discovery but could be repurposed for KVK enrichment.
- **Contact sync**: `ContactSyncService.php` syncs Pipelinq clients with Nextcloud Contacts app.

NOT implemented:
- **Unified interaction history/timeline** -- no chronological timeline aggregating contactmomenten, zaak events, notes, and documents. The sidebar shows OpenRegister audit log but not a CRM-level timeline.
- **Open/closed cases overview** -- no ZGW Zaken API integration to display open and closed zaken for a client.
- **Documents overview** -- no document listing from linked cases or direct client attachments.
- **BRP enrichment** -- no BSN field on client schema, no BRP API integration, no "Verrijk met BRP" button.
- **KVK enrichment** -- the KVK API client exists but is not integrated into the client detail view for on-demand enrichment.
- **Summary statistics** -- no aggregated stats panel (open leads count/value, case count, last contact date).
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

### Competitor Comparison

- **EspoCRM**: Record detail view with stream (activity feed), related panels (contacts, opportunities, cases, documents), and profile data. Strong side-panel navigation. No BRP/KVK integration (not government-focused).
- **Twenty**: Record detail page with timeline, tasks, notes, and related records. Rich field-level customization. Auto-linking via email/calendar sync. No government registry integration.
- **Krayin**: Contact detail with activities, notes, and linked leads. Basic profile view without aggregated statistics or document management.
- **KISS (VNG reference)**: Purpose-built for 360-degree klantbeeld with BRP/KVK integration, contactmomenten timeline, and zaak overview. Closest competitor for Dutch government use case but not CRM-native.
- **Pipelinq advantage**: Combines CRM capabilities (leads, pipeline, requests) with government klantbeeld requirements (BRP/KVK, doelbinding, zaak integration) in a single Nextcloud-native app.

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
- **Implementable incrementally**: Start with interaction timeline (MVP), then add case integration (V1), then BRP/KVK enrichment (V1).
- **Resolved design decisions:**
  - The klantbeeld is an **enhanced version of the existing `ClientDetail.vue`** (not a separate route), adding tabs for timeline, cases, documents.
  - BRP/KVK enrichment data is **fetched on demand** (not cached on the client object) to ensure freshness and comply with data minimization principles.
  - BSN and KVK number need to be added as new fields to the `client` schema in `pipelinq_register.json`.
  - Role-based visibility uses **Nextcloud groups** (frontoffice/backoffice) matched against configurable access rules.
- **Open questions:**
  - How does the doelbinding requirement work in practice? A modal dialog before each BRP query requiring reason selection (resolved in spec).
  - What is the data retention policy for audit logs? Recommendation: 5 years minimum per AVG guidelines.
