---
status: draft
---

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
- **Kennisartikel**: OpenRegister object in the `pipelinq` register using the `kennisartikel` schema (see kennisbank spec)
- **Taak/Terugbelverzoek**: OpenRegister object for backoffice routing and callback requests

## ADDED Requirements

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

## ADDED Requirements

---

### Requirement: Workspace Layout and Navigation

The KCC werkplek MUST present a structured multi-panel layout that allows agents to identify a client, view context, and register a contact simultaneously without leaving the workspace.

**Feature tier**: MVP

#### Scenario: Three-panel workspace layout

- GIVEN an agent opens the KCC werkplek and begins a new contact
- WHEN the workspace is fully loaded
- THEN the system MUST display three panels: an identification/search panel (left), a client context panel with klantbeeld-360 (center), and an active contact registration panel (right)
- AND each panel MUST be independently scrollable so the agent can browse case history while completing the contact form
- AND the layout MUST be responsive, collapsing to a tabbed view on screens narrower than 1280px

#### Scenario: Panel resizing for focus

- GIVEN an agent is reviewing a lengthy case history in the center panel
- WHEN the agent drags the panel divider to expand the center panel
- THEN the system MUST resize panels proportionally while maintaining a minimum width of 300px per panel
- AND panel size preferences MUST persist across sessions via Nextcloud user preferences

#### Scenario: Keyboard navigation between panels

- GIVEN an agent is working in the identification panel
- WHEN the agent presses Tab or a configurable keyboard shortcut (e.g., Ctrl+1/2/3)
- THEN focus MUST move to the corresponding panel
- AND all interactive elements within each panel MUST be reachable via keyboard (WCAG AA)

---

### Requirement: 360-degree Klantbeeld Integration

The KCC werkplek MUST embed the klantbeeld-360 view (see klantbeeld-360 spec) in the center panel, showing all interactions, cases, documents, and notes for the identified client in a unified timeline.

**Feature tier**: MVP
**Cross-reference**: `klantbeeld-360/spec.md`

#### Scenario: Klantbeeld loads automatically after identification

- GIVEN an agent has identified citizen "Suzanne Moulin" via BSN 999993653
- WHEN the identification is confirmed and a matching Pipelinq client exists
- THEN the center panel MUST automatically load the klantbeeld-360 for this client
- AND the klantbeeld MUST show: contact history (most recent first), open zaken, linked documents, and internal notes
- AND loading MUST complete within 2 seconds to maintain agent flow

#### Scenario: Klantbeeld shows interaction timeline

- GIVEN the klantbeeld is loaded for a client with 15 previous contactmomenten and 4 zaken
- WHEN the agent scrolls the timeline
- THEN the system MUST display all interactions in reverse chronological order, mixing contactmomenten and zaak status changes
- AND each entry MUST show: date/time, channel icon, handling agent, subject, and outcome
- AND the agent MUST be able to filter the timeline by channel type (telefoon/email/balie) or by date range

#### Scenario: Klantbeeld for new (unknown) client

- GIVEN the agent has created a new client during this contact (no prior history)
- WHEN the klantbeeld panel loads
- THEN the system MUST display "Eerste contact" with an empty timeline
- AND the current contact being registered MUST appear as the first entry in the timeline

---

### Requirement: Quick Search Across All Entities

The system MUST provide a universal search bar in the werkplek header that searches across clients, contactmomenten, zaken, and kennisartikelen simultaneously.

**Feature tier**: MVP

#### Scenario: Search by client name returns unified results

- GIVEN an agent types "Jansen" in the universal search bar
- WHEN the search is submitted (after 300ms debounce)
- THEN the system MUST return results grouped by entity type: Klanten, Zaken, Contactmomenten, Kennisartikelen
- AND each result group MUST show a maximum of 5 items with a "Toon alle X resultaten" link
- AND client results MUST show name, client type (persoon/organisatie), and most recent contact date

#### Scenario: Search by zaak number

- GIVEN an agent types "2024-001" in the universal search bar
- WHEN the search is submitted
- THEN the system MUST prioritize zaak results matching the zaak identification number
- AND clicking a zaak result MUST load the linked client's klantbeeld and highlight the zaak in the context panel

#### Scenario: Search by phone number with normalization

- GIVEN an agent types "0612345678" in the universal search bar
- WHEN the search is submitted
- THEN the system MUST normalize the phone number (to "+31 6 12345678" format) before searching
- AND match against client telephone numbers, contact person telephone numbers, and contactmoment metadata

---

### Requirement: Call Logging Workflow

The system MUST support a structured call logging workflow that guides agents through intake, handling, and wrap-up phases of a phone contact.

**Feature tier**: MVP

#### Scenario: Intake phase captures initial information

- GIVEN an agent clicks "Nieuw telefoongesprek" to start a phone contact
- WHEN the contact registration panel opens
- THEN the system MUST start the contact timer, set channel to "telefoon" and direction to "inkomend"
- AND the identification panel MUST gain focus so the agent can immediately search for the caller
- AND the system MUST auto-generate a contact reference number (e.g., "CM-2024-00042")

#### Scenario: Handling phase allows notes and actions

- GIVEN an agent has identified the caller and is in the handling phase
- WHEN the agent types notes in the "Toelichting" field
- THEN the system MUST auto-save the draft every 10 seconds to prevent data loss
- AND the agent MUST be able to add structured tags (e.g., "status-vraag", "klacht", "informatievraag") from a predefined tag list
- AND the agent MUST be able to open the kennisbank in a side panel without losing the current contact form state

#### Scenario: Wrap-up phase completes the contact

- GIVEN an agent has finished speaking with the caller
- WHEN the agent clicks "Afronden gesprek"
- THEN the system MUST display a wrap-up form with: onderwerp (required), resultaat dropdown (afgehandeld/doorverwezen/terugbelverzoek/niet bereikbaar), toelichting (required if resultaat is "doorverwezen" or "terugbelverzoek")
- AND the system MUST stop the timer and record the total duration
- AND after submission, the werkplek MUST reset to the ready state for the next contact

---

### Requirement: Email Contact Registration

The system MUST allow agents to register email-based contacts, linking them to Nextcloud Mail messages where possible.

**Feature tier**: V1
**Cross-reference**: `omnichannel-registratie/spec.md`

#### Scenario: Register email from Nextcloud Mail link

- GIVEN an agent has received a citizen email in Nextcloud Mail about zaak "Paspoort aanvraag"
- WHEN the agent clicks "Registreer als contactmoment" from the KCC werkplek and pastes the mail message ID or selects from recent emails
- THEN the system MUST create a contactmoment with kanaal "email", auto-fill the subject from the email subject line, and store the email thread ID as metadata
- AND the system MUST link the email as a reference on the contactmoment object

#### Scenario: Email contact with attachment handling

- GIVEN a citizen has sent an email with 2 PDF attachments (identity document, proof of address)
- WHEN the agent registers this email as a contactmoment
- THEN the system MUST offer to save the attachments to the client's Nextcloud Files folder
- AND if the agent selects a linked zaak, the attachments MUST also be registerable as zaak documents

---

### Requirement: Walk-in (Balie) Registration

The system MUST support registering walk-in contacts at a physical service counter, including queue ticket tracking.

**Feature tier**: V1
**Cross-reference**: `omnichannel-registratie/spec.md`

#### Scenario: Register walk-in contact with queue number

- GIVEN a citizen arrives at the service counter with queue ticket "B042" at location "Stadhuis Centrum"
- WHEN the agent selects channel "Balie" and enters the queue ticket number
- THEN the system MUST create a contactmoment with kanaal "balie" and metadata containing location and queue number
- AND the timer MUST start from the moment the agent begins the interaction (not from when the citizen took the ticket)

#### Scenario: Walk-in identification via ID document

- GIVEN a citizen at the counter presents a physical identity document
- WHEN the agent manually enters the BSN from the document into the identification panel
- THEN the system MUST perform the same BRP lookup as for phone contacts
- AND the system MUST log that identification was performed via "fysiek identiteitsdocument" in the contactmoment metadata

---

### Requirement: Channel Switching During Contact

The system MUST allow agents to switch or escalate a contact from one channel to another (e.g., phone to email, phone to in-person appointment) while maintaining a single contactmoment record.

**Feature tier**: V1

#### Scenario: Phone contact results in email follow-up

- GIVEN an agent is handling a phone contact with citizen "Jan de Vries" about a document request
- WHEN the agent determines that documents need to be sent via email
- THEN the agent MUST be able to add a secondary channel "email" to the contactmoment
- AND the contactmoment MUST record both channels with timestamps: "telefoon: 14:00-14:05, email: 14:06"
- AND the contactmoment summary MUST indicate the channel transition

#### Scenario: Phone contact escalated to balie appointment

- GIVEN an agent cannot resolve a question by phone and the citizen needs to visit in person
- WHEN the agent clicks "Plan balieafspraak"
- THEN the system MUST open an appointment creation form (integrated with Nextcloud Calendar) pre-populated with the client reference and contact subject
- AND the original contactmoment MUST record the outcome as "Doorverwezen naar balie" with a link to the created appointment

---

### Requirement: Knowledge Base Integration in Workspace

The system MUST integrate the kennisbank (see kennisbank spec) directly into the KCC werkplek so agents can search for answers without leaving the workspace.

**Feature tier**: V1
**Cross-reference**: `kennisbank/spec.md`

#### Scenario: Context-aware knowledge base search

- GIVEN an agent is handling a contact about "paspoort verlengen" and has filled in onderwerp "Paspoort"
- WHEN the agent clicks the kennisbank icon or presses a keyboard shortcut (e.g., Ctrl+K)
- THEN a knowledge base search panel MUST open within the werkplek (overlay or side panel)
- AND the search field MUST be pre-populated with the current onderwerp text
- AND results MUST be ranked by relevance, showing article title, excerpt, and last-updated date

#### Scenario: Insert FAQ answer into contact notes

- GIVEN the agent has found knowledge article "Paspoort aanvragen - procedure en kosten"
- WHEN the agent clicks "Gebruik antwoord" on the article
- THEN the system MUST insert the article's summary text into the contactmoment toelichting field
- AND the system MUST add a reference to the article on the contactmoment (for tracking which articles are used most)
- AND the agent MUST be able to edit the inserted text before completing the contact

#### Scenario: No relevant article found

- GIVEN an agent searches the kennisbank for "vluchtelingenopvang procedure" and no matching articles exist
- WHEN zero results are returned
- THEN the system MUST display "Geen artikelen gevonden" with a "Suggestie indienen" button
- AND clicking "Suggestie indienen" MUST create a kennisbank suggestion tagged with the search query and linked contactmoment

---

### Requirement: Escalation to Backoffice with SLA Tracking

The system MUST support escalation workflows that transfer contacts to backoffice with defined SLA targets and status tracking visible to the originating KCC agent.

**Feature tier**: V1

#### Scenario: Escalation with priority and SLA assignment

- GIVEN an agent determines a contact requires specialist handling and selects "Escaleren"
- WHEN the agent fills in the escalation form with department "Juridische Zaken", priority "Hoog", and reason "Burger dreigt met bezwaarschrift"
- THEN the system MUST create an escalation task with an SLA deadline based on the priority (Hoog = 4 uur, Normaal = 24 uur, Laag = 72 uur)
- AND the task MUST include the full contactmoment content, client reference, and any linked zaken
- AND the originating agent MUST receive a Nextcloud notification when the escalation is picked up by backoffice

#### Scenario: View escalation status from werkplek

- GIVEN an agent previously escalated a contact for "Jan de Vries" to Juridische Zaken 2 hours ago
- WHEN the agent opens the KCC werkplek and searches for "Jan de Vries"
- THEN the klantbeeld MUST show the escalation with current status (Nieuw/In behandeling/Afgerond), assigned backoffice handler, and remaining SLA time
- AND if the SLA deadline is within 1 hour, the escalation MUST be highlighted with a warning indicator

#### Scenario: Citizen calls back about escalated issue

- GIVEN citizen "Jan de Vries" calls back asking about the escalated issue
- WHEN the agent identifies the citizen and sees the open escalation in the klantbeeld
- THEN the system MUST display the escalation details including any backoffice notes added since escalation
- AND the agent MUST be able to register a new contactmoment linked to the same escalation
- AND the new contactmoment MUST have a tag "Terugkoppeling escalatie"

---

### Requirement: SLA Timer Display

The system MUST display SLA-related timers and deadlines for active contacts, open tasks, and pending escalations to help agents prioritize their work.

**Feature tier**: V1

#### Scenario: Active contact SLA timer in header

- GIVEN an agent is handling a phone contact with a configured SLA of 5 minutes
- WHEN the contact has been active for 3 minutes
- THEN the werkplek header MUST display: elapsed time "03:00", SLA target "05:00", and a progress bar at 60% (green)
- AND the progress bar MUST turn orange at 80% (04:00) and red at 100% (05:00)

#### Scenario: Pending task SLA countdown

- GIVEN the agent's task queue contains 3 terugbelverzoeken with different deadlines
- WHEN the agent views the task queue widget on the dashboard
- THEN each task MUST show a countdown to its SLA deadline (e.g., "nog 2u 15m")
- AND tasks MUST be sorted by SLA urgency (nearest deadline first)
- AND overdue tasks MUST be visually highlighted in red with "Verlopen" label

---

### Requirement: Call Wrap-up Form

The system MUST provide a structured wrap-up form at the end of each contact that captures outcome, follow-up actions, and quality data.

**Feature tier**: V1

#### Scenario: Mandatory wrap-up fields

- GIVEN an agent clicks "Afronden" to complete a phone contact
- WHEN the wrap-up form is displayed
- THEN the system MUST require: resultaat (afgehandeld/doorverwezen/terugbelverzoek/escalatie), onderwerp category (from taxonomy), and a brief summary
- AND optional fields MUST include: follow-up date, linked zaak, internal notes, and client satisfaction score (1-5 if surveyed)
- AND the form MUST NOT allow submission without the required fields

#### Scenario: Quick wrap-up for simple status inquiries

- GIVEN an agent has handled a simple status inquiry lasting under 2 minutes
- WHEN the agent clicks "Snel afronden"
- THEN the system MUST offer a simplified one-click wrap-up with preset: resultaat "Afgehandeld", category "Status informatieverzoek"
- AND the agent MUST be able to override these defaults if needed

#### Scenario: Wrap-up triggers follow-up task creation

- GIVEN an agent selects resultaat "Terugbelverzoek" in the wrap-up form
- WHEN the agent fills in follow-up date "morgen 10:00" and target "Afdeling Burgerzaken"
- THEN the system MUST automatically create a terugbelverzoek task linked to the contactmoment and client
- AND the task MUST appear in the target department's task queue with the specified deadline
- AND the originating agent MUST receive a notification when the callback is completed

---

### Requirement: Agent Performance Metrics

The system MUST provide real-time and historical performance metrics for individual agents and the KCC team as a whole.

**Feature tier**: V1
**Tender frequency**: 51/52 (98%) require contactmoment reporting

#### Scenario: Agent personal dashboard statistics

- GIVEN agent "Lisa van Dam" has handled 23 contacts today across phone (18), email (3), and balie (2)
- WHEN she views her personal statistics panel on the KCC werkplek
- THEN the system MUST display: total contacts today (23), breakdown by channel, average handling time, first-call resolution rate (% of contacts with resultaat "Afgehandeld"), and comparison to her 30-day average

#### Scenario: Team overview for supervisor

- GIVEN a KCC supervisor with the "kcc-supervisor" role
- WHEN they open the team performance view
- THEN the system MUST display a dashboard with: agents currently online, contacts waiting in queue, average wait time, contacts handled today (per agent), current SLA compliance percentage
- AND the supervisor MUST be able to drill down into individual agent statistics

#### Scenario: Historical reporting export

- GIVEN a KCC manager wants to report on last month's performance
- WHEN they access the reporting view and select date range "1 feb 2025 - 28 feb 2025"
- THEN the system MUST generate a report with: total contacts per channel, average handling time per channel, first-call resolution rate, escalation rate, busiest hours/days, and top 10 contact subjects
- AND the report MUST be exportable as CSV and PDF

---

### Requirement: Queue Management

The system MUST provide queue management capabilities for distributing incoming contacts across available KCC agents.

**Feature tier**: V1

#### Scenario: View current queue status

- GIVEN 8 contacts are waiting in the queue and 5 agents are online
- WHEN any agent views the queue management panel
- THEN the system MUST display: total items in queue (8), average wait time, longest waiting contact, and available agent count
- AND queue items MUST show: channel, wait time, client name (if identified), and subject (if known)

#### Scenario: Agent picks up next contact from queue

- GIVEN an agent has finished their previous contact and is ready for the next
- WHEN the agent clicks "Volgende contact" or the queue auto-assigns
- THEN the system MUST assign the longest-waiting contact to this agent
- AND the werkplek MUST automatically open the contact with the timer started and identification panel focused
- AND the queue item MUST be removed from other agents' queue views in real time

#### Scenario: Priority queue for returning citizens

- GIVEN a citizen "Jan de Vries" calls back within 30 minutes of a previous contact that was not resolved
- WHEN the system detects the phone number matches a recent unresolved contactmoment
- THEN the queue MUST flag this contact as "Terugkerende beller" with elevated priority
- AND the system SHOULD attempt to route to the same agent who handled the previous contact

---

### Requirement: Multi-language Support for Citizen Interaction

The system MUST support agents serving citizens in multiple languages, with the workspace UI in Dutch (primary) and English, and tools to assist with non-Dutch-speaking citizens.

**Feature tier**: Enterprise

#### Scenario: Workspace language switching

- GIVEN an agent's Nextcloud account is configured with locale "nl"
- WHEN the agent opens the KCC werkplek
- THEN the workspace MUST render in Dutch (all labels, buttons, system messages)
- AND the agent MUST be able to switch the workspace language to English via a language selector
- AND all user-entered content (contactmoment notes, subject) MUST remain in the language the agent typed

#### Scenario: Multi-language contact registration

- GIVEN an agent is helping an English-speaking citizen
- WHEN the agent registers the contactmoment
- THEN the agent MUST be able to tag the contact with language "Engels"
- AND the language tag MUST be stored on the contactmoment for reporting on non-Dutch contacts
- AND the kennisbank search MUST prioritize articles in the tagged language if available

#### Scenario: Language statistics in reporting

- GIVEN the KCC has served citizens in 4 languages this month (Nederlands, Engels, Arabisch, Turks)
- WHEN a supervisor views the reporting dashboard
- THEN the system MUST show contact volume per language
- AND the report MUST highlight languages without kennisbank coverage so content gaps can be addressed

---

### Requirement: Contextual Contact History

The system MUST display the full contact history for the current client inline within the workspace, enabling agents to reference previous interactions without opening separate screens.

**Feature tier**: MVP

#### Scenario: Recent contact summary on identification

- GIVEN an agent identifies citizen "Suzanne Moulin" who had 3 contacts in the last 7 days
- WHEN the client is loaded in the workspace
- THEN the system MUST immediately show a "Recente contacten" banner with the 3 most recent contacts: date, channel, subject, and handling agent
- AND the agent MUST be able to expand any contact to see the full toelichting and outcome

#### Scenario: Filter contact history by subject or channel

- GIVEN a client "Jan de Vries" has 50+ contactmomenten over the past year
- WHEN the agent wants to find previous contacts about "bouwvergunning"
- THEN the agent MUST be able to filter the contact history by keyword, channel, date range, or handling agent
- AND filtered results MUST highlight the matching terms within the contact summaries

#### Scenario: Contact history shows linked documents and tasks

- GIVEN a previous contactmoment for this client resulted in a terugbelverzoek and a document upload
- WHEN the agent expands that contactmoment in the history
- THEN the system MUST show the linked terugbelverzoek with its current status (Nieuw/In behandeling/Afgerond)
- AND the system MUST show the linked document with a click-to-open link (opening in Nextcloud Files)

---

### Requirement: Agent Availability and Status

The system MUST allow agents to set their availability status, which affects queue assignment and team visibility.

**Feature tier**: V1

#### Scenario: Agent sets status to available

- GIVEN an agent has logged into the KCC werkplek
- WHEN the agent sets their status to "Beschikbaar"
- THEN the system MUST include this agent in the queue assignment rotation
- AND the agent's status MUST be visible to supervisors in the team overview
- AND the status change MUST be logged for workforce management reporting

#### Scenario: Agent sets status to wrap-up

- GIVEN an agent has just finished a complex contact and needs administrative time
- WHEN the agent sets their status to "Nawerk"
- THEN the system MUST exclude this agent from new queue assignments for a configurable duration (default: 3 minutes)
- AND the nawerk timer MUST be visible in the werkplek header
- AND after the nawerk period expires, status MUST automatically revert to "Beschikbaar" (with agent override option)

#### Scenario: Agent goes on break

- GIVEN an agent selects status "Pauze"
- WHEN the status changes
- THEN the system MUST exclude the agent from queue assignment
- AND if the agent has any pending unfinished contacts, the system MUST warn "U heeft nog een open contact" before allowing the status change
- AND break duration MUST be tracked for workforce reporting

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
