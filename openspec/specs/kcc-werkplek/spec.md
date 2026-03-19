# KCC Werkplek Specification

## Purpose

The KCC werkplek (frontoffice workspace) is the unified agent screen for KCC (Klant Contact Centrum) employees. It combines citizen/business identification, open case visibility, contact moment registration, and backoffice routing into a single interface. This is the most demanded capability in Dutch government CRM tenders: **100% of 52 klantinteractie-tenders** require an integrated KCC workspace.

**Standards**: VNG Klantinteracties (`Contactmoment`, `Klant`, `Medewerker`), Haal Centraal BRP API, KVK API, ZGW Zaken API
**Feature tier**: MVP (core), V1 (extended), Enterprise (advanced)
**Tender frequency**: 52/52 (100%)

## Data Model

The KCC werkplek is a composite view that orchestrates data from multiple sources:
- **Klant** (client): OpenRegister object in the `pipelinq` register using the `client` schema
- **Contactmoment**: OpenRegister object in the `pipelinq` register using the `contactmoment` schema
- **Zaak** (case): Retrieved via ZGW Zaken API or OpenRegister procest objects
- **BRP/KVK enrichment**: Retrieved via OpenConnector sources for person/business lookup

## Requirements

---

### Requirement: Agent Dashboard Landing

The system MUST provide a dedicated KCC agent landing screen that shows an overview of the agent's current queue, recent contacts, and quick-action buttons.

**Feature tier**: MVP

#### Scenario: Agent opens KCC werkplek

- GIVEN a KCC agent with appropriate role permissions
- WHEN they navigate to the KCC werkplek
- THEN the system MUST display a dashboard with: active queue count, recent contactmomenten (last 10), quick-action buttons for "Nieuw contact" and "Zoek klant"
- AND the dashboard MUST show the agent's personal statistics for today (number of contacts handled, average handling time)

#### Scenario: Queue overview displays waiting contacts

- GIVEN 5 incoming contacts are waiting in the queue
- WHEN the agent views the KCC werkplek dashboard
- THEN the system MUST display the queue with caller/contact identification (if available), wait time per contact, and channel (telefoon/balie/chat)
- AND contacts MUST be ordered by wait time descending (longest waiting first)

---

### Requirement: Citizen/Business Identification

The system MUST allow agents to quickly identify a citizen or business during a contact, using BSN (via BRP) or KVK-nummer lookups.

**Feature tier**: MVP

#### Scenario: Identify citizen by BSN

- GIVEN an agent handling an incoming phone call
- WHEN the agent enters BSN "123456789" in the identification panel
- THEN the system MUST query the BRP source via OpenConnector
- AND display the citizen's name, address, date of birth, and registered municipality
- AND automatically search for an existing Pipelinq client matching this BSN

#### Scenario: Identify business by KVK number

- GIVEN an agent handling an incoming contact from a business
- WHEN the agent enters KVK number "12345678" in the identification panel
- THEN the system MUST query the KVK source via OpenConnector
- AND display the business name, address, legal form, and authorized signatories
- AND automatically search for an existing Pipelinq client matching this KVK number

#### Scenario: Search by name or phone number

- GIVEN an agent who does not have a BSN or KVK number
- WHEN the agent searches by name "Jansen" or phone number "+31 6 12345678"
- THEN the system MUST search existing Pipelinq clients by name and telephone
- AND display matching results ranked by relevance
- AND allow the agent to select a match or create a new client

#### Scenario: No matching client found

- GIVEN an agent has identified a citizen via BRP with BSN "987654321"
- WHEN no existing Pipelinq client is linked to this BSN
- THEN the system MUST offer a "Nieuwe klant aanmaken" action
- AND pre-populate the new client form with data from the BRP response (name, address)

---

### Requirement: Open Cases View

The system MUST display all open zaken (cases) for the identified citizen/business directly in the KCC werkplek, so the agent can see context without switching applications.

**Feature tier**: MVP

#### Scenario: Display open cases for identified citizen

- GIVEN a citizen "Jan de Vries" has been identified with 3 open zaken
- WHEN the agent views the client context panel
- THEN the system MUST display all 3 open zaken with: zaaktype, status, start date, and assigned handler
- AND each zaak MUST be clickable to view details

#### Scenario: Display case details inline

- GIVEN an agent viewing the open cases for a citizen
- WHEN the agent clicks on zaak "Omgevingsvergunning #2024-001"
- THEN the system MUST display case details in a side panel: zaaktype, status history, documents, and assigned handler
- AND the agent MUST NOT be navigated away from the KCC werkplek

#### Scenario: No open cases

- GIVEN a citizen "Maria Garcia" has been identified but has no open zaken
- WHEN the agent views the client context panel
- THEN the system MUST display "Geen openstaande zaken" with an option to create a new zaak

---

### Requirement: Contact Moment Registration

The system MUST allow agents to register a contactmoment during or immediately after a citizen interaction, capturing channel, subject, and outcome.

**Feature tier**: MVP

#### Scenario: Register a phone contact

- GIVEN an agent has identified citizen "Jan de Vries" during a phone call
- WHEN the agent fills in the contactmoment form with kanaal "telefoon", onderwerp "Vraag over vergunning", and toelichting "Burger belt over status bouwvergunning Keizersgracht 100"
- THEN the system MUST create an OpenRegister contactmoment object linked to the client
- AND the contactmoment MUST record the agent identity, timestamp, channel, and duration
- AND the contactmoment MUST appear in the client's interaction history

#### Scenario: Link contact to existing case

- GIVEN an agent is registering a contactmoment for citizen "Jan de Vries" who has open zaak "Bouwvergunning #2024-001"
- WHEN the agent selects the zaak in the "Koppel aan zaak" field
- THEN the contactmoment MUST store a reference to the zaak UUID
- AND the contactmoment MUST appear in both the client history and the zaak history

#### Scenario: Register contact without identification

- GIVEN a caller who refuses to identify themselves
- WHEN the agent registers the contactmoment with kanaal "telefoon" and onderwerp "Anonieme melding overlast"
- THEN the system MUST allow creating a contactmoment without a linked client
- AND the contactmoment MUST still record agent, timestamp, channel, and content

---

### Requirement: Backoffice Routing

The system MUST allow agents to route a contact to a backoffice department or specialist when the question cannot be resolved at the frontoffice.

**Feature tier**: MVP

#### Scenario: Route to backoffice department

- GIVEN an agent handling a complex question about "bestemmingsplan wijziging"
- WHEN the agent clicks "Doorsturen naar backoffice" and selects department "Ruimtelijke Ordening"
- THEN the system MUST create a task/terugbelverzoek assigned to the selected department
- AND the task MUST include the contactmoment summary, client reference, and priority
- AND the agent MUST receive confirmation that the routing was successful

#### Scenario: Route to specific colleague

- GIVEN an agent handling a follow-up question that was previously handled by colleague "Petra Bakker"
- WHEN the agent routes to "Petra Bakker" specifically
- THEN the system MUST create a task assigned to that specific user
- AND Petra Bakker MUST receive a notification about the new task

---

### Requirement: Contact Timer

The system MUST display a running timer during active contacts, helping agents track handling time for SLA and reporting purposes.

**Feature tier**: V1

#### Scenario: Timer starts on contact initiation

- GIVEN an agent starts a new contact in the KCC werkplek
- WHEN the "Nieuw contact" action is triggered
- THEN a visible timer MUST start counting from 00:00
- AND the timer MUST be prominently displayed in the werkplek header

#### Scenario: Timer color coding for SLA

- GIVEN an agent is handling a phone contact with a 5-minute SLA target
- WHEN the timer reaches 4:00 (80% of SLA)
- THEN the timer MUST change to an orange/warning color
- AND when the timer exceeds 5:00, it MUST change to red
- AND the SLA target MUST be configurable per channel type

#### Scenario: Timer stops and records duration

- GIVEN an agent has been handling a contact for 3 minutes 42 seconds
- WHEN the agent completes the contactmoment registration and clicks "Afronden"
- THEN the timer MUST stop
- AND the duration (3:42) MUST be stored on the contactmoment object
- AND the duration MUST be available for reporting

---

### Requirement: Quick Actions

The system MUST provide quick-action buttons for common KCC operations to minimize clicks and handling time.

**Feature tier**: V1

#### Scenario: Quick action to create a new case

- GIVEN an agent has identified a citizen and determined a new zaak is needed
- WHEN the agent clicks the "Nieuwe zaak" quick action
- THEN a zaak creation form MUST open pre-populated with the client reference
- AND the agent MUST be able to select a zaaktype from a categorized list

#### Scenario: Quick action to send status update

- GIVEN a citizen calls about the status of zaak "Paspoort aanvraag #2024-050"
- WHEN the agent clicks "Status mededelen" on the zaak
- THEN the system MUST display the current status in a citizen-friendly format
- AND the agent MUST be able to mark the contactmoment as "Status informatieverzoek - afgehandeld"

---

### Current Implementation Status

**NOT implemented.** No KCC werkplek (agent workspace) exists in the codebase.

- No dedicated KCC agent landing screen or route.
- No `contactmoment` schema in `lib/Settings/pipelinq_register.json` -- the register does not include this entity.
- No citizen/business identification UI (BSN/KVK lookup).
- No BRP or KVK API integration for identity verification (though `lib/Service/KvkApiClient.php` exists for prospect discovery, it is not used for KCC identification).
- No open cases/zaken view integrated into the workspace.
- No contact moment registration form.
- No backoffice routing mechanism.
- No contact timer component.
- No quick-action buttons for KCC operations.
- No queue management or display.
- The existing `request` entity with `channel` property provides basic intake channel tracking, but is not the same as a VNG `Contactmoment`.
- The existing client search functionality (`ClientSearchWidget`) could serve as a foundation for citizen identification, but lacks BSN/KVK lookup.

**Mock Registers (dependency):** This spec depends on mock BRP and KVK registers being available in OpenRegister for development and testing. These registers are available as JSON files that can be loaded on demand from `openregister/lib/Settings/`. Production deployments should connect to the actual Haal Centraal BRP API and KVK Handelsregister API via OpenConnector.

### Using Mock Register Data

This spec depends on the **BRP** and **KVK** mock registers for citizen/business identification.

**Loading the registers:**
```bash
# Load BRP register (35 persons, register slug: "brp", schema: "ingeschreven-persoon")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/brp_register.json

# Load KVK register (16 businesses + 14 branches, register slug: "kvk", schemas: "maatschappelijke-activiteit", "vestiging")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/kvk_register.json
```

**Test data for this spec's use cases:**
- **Citizen identification by BSN**: BSN `999993653` (Suzanne Moulin) -- test BSN lookup in identification panel, verify name/address/municipality display
- **Citizen identification by BSN**: BSN `999992570` (Albert Vogel, man with partner and child) -- test person with partner info
- **Business identification by KVK**: KVK `69599084` (Test EMZ Dagobert, Amsterdam) -- test KVK lookup, verify business name/address/legal form display
- **Business identification by KVK**: KVK `68727720` (Test NV Katrien, Veendam) -- test NV legal form display
- **No match scenario**: BSN `000000000` -- test "no matching client" flow, verify "Nieuwe klant aanmaken" pre-population

**Querying mock data:**
```bash
# Identify citizen by BSN
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{brp_register_id}/{person_schema_id}?_search=999993653" -u admin:admin

# Identify business by KVK number
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{kvk_register_id}/{business_schema_id}?_search=69599084" -u admin:admin
```

### Standards & References
- VNG Klantinteracties API -- defines `Contactmoment`, `Klant`, `Medewerker`, `Organisatorische eenheid` entities
- Haal Centraal BRP Personen Bevragen API v2 -- for BSN-based citizen lookup (requires DigiD/PKI certificate)
- KVK API (Basisregistratie Handelsregister) -- for KVK number-based business lookup
- ZGW Zaken API (Zaak-Documentservices) -- for retrieving open cases per citizen/business
- Common Ground -- architectural principles for cross-system data access
- AVG/GDPR -- doelbinding requirements for accessing personal data
- WCAG AA -- accessibility for government-facing interfaces
- NEN-ISO 18295 -- Customer contact centres service requirements

### Specificity Assessment
- The spec is comprehensive and well-structured, covering the full KCC agent workflow.
- **NOT implementable as-is** due to significant external dependencies:
- **Missing**: No specification of how BRP/KVK sources are configured in OpenConnector. This requires OpenConnector source definitions and likely API keys/certificates.
- **Missing**: No specification of the `contactmoment` schema (entity definition, properties, required fields). This needs to be added to `pipelinq_register.json`.
- **Missing**: No specification of how ZGW Zaken API is called -- is it via OpenConnector, direct HTTP, or via the Procest app?
- **Missing**: No specification of queue management -- does Pipelinq manage the phone queue, or integrate with a telephony system (e.g., Asterisk, Microsoft Teams)?
- **Missing**: No specification of user roles/permissions for KCC agents vs regular CRM users.
- **Missing**: No specification of the "departments" list for backoffice routing -- where are departments configured?
- **Open question**: Should the KCC werkplek be a separate Nextcloud app or a module within Pipelinq?
- **Open question**: How does the KCC werkplek relate to the existing client/request management? Should contactmomenten be a separate entity or an extension of requests?
- **Significant dependency**: BRP API access requires government certificates and agreements -- not available in development environments.
