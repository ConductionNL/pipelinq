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
