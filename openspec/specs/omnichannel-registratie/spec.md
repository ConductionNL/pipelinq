---
status: partial
---

# Omnichannel Registratie Specification

## Purpose

Omnichannel registratie enables KCC agents to register contact moments from any communication channel (telefoon, e-mail, balie, chat, social media, brief) using a unified data model. Regardless of channel, every contact produces a consistent contactmoment record that can be linked to a client and zaak. **54% of klantinteractie-tenders** (28/52) explicitly require omnichannel contact registration with channel-specific metadata.

**Standards**: VNG Klantinteracties (`Contactmoment`, `Kanaal`), Schema.org (`InteractionCounter`, `CommunicateAction`)
**Feature tier**: MVP (phone, email, counter), V1 (chat, social, mail), Enterprise (CTI integration)
**Tender frequency**: 28/52 (54%)

## Data Model

The contactmoment entity is extended with a flexible metadata structure per channel:
- **Core fields** (all channels): timestamp, agent, client reference, zaak reference, subject, summary, outcome
- **Channel envelope**: channel type + channel-specific metadata object
- **Channel metadata examples**: call duration (telefoon), email thread ID (e-mail), ticket number (balie), chat transcript link (chat)

## Requirements

---

### Requirement: Unified Contact Registration Form

The system MUST provide a single contact registration form that adapts its fields based on the selected channel, while maintaining a consistent core data structure.

**Feature tier**: MVP

#### Scenario: Register phone contact
- GIVEN an agent selects channel "Telefoon" in the contact registration form
- WHEN the form is displayed
- THEN the system MUST show core fields: klant, onderwerp, toelichting, resultaat, koppel aan zaak
- AND channel-specific fields: gespreksduur (auto-filled from timer if active), inkomend/uitgaand, doorverbonden (ja/nee)
- AND the contactmoment MUST be stored with `kanaal: "telefoon"` and metadata `{"gespreksduur": "PT4M23S", "richting": "inkomend"}`

#### Scenario: Register email contact
- GIVEN an agent selects channel "E-mail" in the contact registration form
- WHEN the form is displayed
- THEN the system MUST show core fields plus: e-mail onderwerp (auto-filled if linked), afzender, ontvanger, thread-ID
- AND the system SHOULD support attaching the email or linking to the email in Nextcloud Mail
- AND the contactmoment MUST be stored with `kanaal: "email"` and metadata `{"threadId": "msg-123", "afzender": "burger@example.nl"}`

#### Scenario: Register counter (balie) contact
- GIVEN an agent selects channel "Balie" in the contact registration form
- WHEN the form is displayed
- THEN the system MUST show core fields plus: locatie (which office/counter), volgnummer (queue ticket number)
- AND the contactmoment MUST be stored with `kanaal: "balie"` and metadata `{"locatie": "Stadhuis", "volgnummer": "B042"}`

#### Scenario: Register chat contact
- GIVEN an agent selects channel "Chat" in the contact registration form
- WHEN the form is displayed
- THEN the system MUST show core fields plus: chat platform (website/WhatsApp/other), transcript link
- AND the system SHOULD support auto-importing chat transcripts
- AND the contactmoment MUST be stored with `kanaal: "chat"` and metadata `{"platform": "website", "transcriptLink": "/files/chat-2024-001.txt"}`

#### Scenario: Register social media contact
- GIVEN an agent selects channel "Social media" in the contact registration form
- WHEN the form is displayed
- THEN the system MUST show core fields plus: platform (Twitter/Facebook/Instagram), bericht-URL, openbaar (ja/nee)
- AND the contactmoment MUST be stored with `kanaal: "social"` and metadata `{"platform": "twitter", "berichtUrl": "https://...", "openbaar": true}`

---

### Requirement: Channel Configuration

The system MUST allow administrators to configure which channels are available and to define custom metadata fields per channel.

**Feature tier**: V1

#### Scenario: Enable/disable channels
- GIVEN an administrator accessing channel configuration
- WHEN they disable the "Social media" channel
- THEN the channel MUST NOT appear in the contact registration form for agents
- AND existing contactmomenten with that channel MUST remain accessible
- AND the channel MUST be re-enableable at any time

#### Scenario: Add custom metadata field to channel
- GIVEN an administrator configuring the "Telefoon" channel
- WHEN they add a custom field "Wachtrij" (type: text, required: no)
- THEN the field MUST appear in the contact registration form when "Telefoon" is selected
- AND the field value MUST be stored in the channel metadata object

#### Scenario: Channel configuration integrates with existing SystemTag infrastructure
- GIVEN the existing RequestChannelController that manages channels via SystemTagService
- AND the existing default channels (phone, email, website, counter, post) initialized in InitializeSettings
- WHEN the administrator manages channels for contactmomenten
- THEN the system MUST extend the existing SystemTag-based channel management
- AND each channel tag MUST support additional metadata configuration (stored as JSON in tag description or as a separate config object)
- AND the existing requestChannels Pinia store MUST be extended to include channel metadata definitions

#### Scenario: Channel icon and color assignment
- GIVEN an administrator configuring channels
- WHEN they edit a channel
- THEN they MUST be able to assign an icon (from the Nextcloud icon set) and a color
- AND the icon and color MUST appear in the contact registration form and in channel statistics views

---

### Requirement: Auto-linking to Client and Case

The system MUST support automatically linking contactmomenten to clients and cases based on context.

**Feature tier**: MVP

#### Scenario: Auto-link when client is already identified
- GIVEN an agent has identified client "Jan de Vries" in the KCC werkplek
- WHEN the agent opens the contact registration form
- THEN the client field MUST be pre-populated with "Jan de Vries"
- AND the agent MUST be able to change the client if needed

#### Scenario: Auto-suggest cases for linking
- GIVEN an agent is registering a contact for client "Jan de Vries" who has 3 open zaken
- WHEN the agent clicks the "Koppel aan zaak" field
- THEN the system MUST display the 3 open zaken as suggestions (queried from Procest if installed)
- AND the system MUST also allow searching for closed zaken
- AND the agent MUST be able to select one or more zaken
- AND if Procest is not installed, the zaak linking field MUST be hidden

#### Scenario: Create contactmoment from email
- GIVEN an email arrives at info@gemeente.nl from burger@example.nl
- WHEN the agent processes the email and clicks "Registreer als contactmoment"
- THEN the system MUST pre-populate: kanaal "E-mail", afzender, onderwerp from email subject
- AND the system MUST search for an existing client by the sender email address (using OpenRegister query on contact schema email field)
- AND if found, auto-populate the client reference
- AND the email body MUST be stored as the contactmoment toelichting

#### Scenario: Auto-link from phone number identification
- GIVEN an incoming phone call from +31612345678
- AND a contact exists in Pipelinq with phone number +31612345678
- WHEN the agent opens the contact registration form with caller ID context
- THEN the system MUST auto-search contacts by the phone number
- AND if a unique match is found, pre-populate the client field
- AND if multiple matches are found, display all matches for agent selection

#### Scenario: Create request from contactmoment
- GIVEN a completed contactmoment registration
- WHEN the agent determines the contact requires follow-up action
- THEN the agent MUST be able to click "Maak verzoek aan" to create a new request
- AND the new request MUST be pre-populated with: client from contactmoment, subject from contactmoment, channel from contactmoment
- AND the contactmoment MUST be linked to the newly created request

---

### Requirement: Contactmoment Schema and Storage

The system MUST define a contactmoment schema in the Pipelinq register for structured storage.

**Feature tier**: MVP

#### Scenario: Contactmoment schema definition
- GIVEN the Pipelinq register in OpenRegister
- THEN a `contactmoment` schema MUST be defined with the following properties:
  - `timestamp` (datetime, required): when the contact occurred
  - `agent` (string, required): the Nextcloud user ID of the agent handling the contact
  - `client` (string, optional): reference to the client entity (Pipelinq client object ID)
  - `contact` (string, optional): reference to the contact person entity
  - `zaak` (string, optional): reference to linked zaak (Procest case object ID)
  - `request` (string, optional): reference to linked Pipelinq request
  - `kanaal` (string, required): channel type (telefoon, email, balie, chat, social, brief)
  - `onderwerp` (string, required): subject/topic of the contact
  - `toelichting` (string, optional): detailed notes/summary
  - `resultaat` (string, optional): outcome of the contact (e.g., "afgehandeld", "doorverwezen", "terugbelverzoek")
  - `metadata` (object, optional): channel-specific metadata (flexible JSON structure)
- AND the schema MUST be added to `lib/Settings/pipelinq_register.json`
- AND the schema MUST be imported during the InitializeSettings repair step

#### Scenario: Contactmoment is separate from request
- GIVEN the existing `request` entity type in Pipelinq
- WHEN a contactmoment is registered
- THEN it MUST be stored as a separate entity type (not merged with request)
- AND a contactmoment MAY be linked to zero or more requests
- AND a request MAY be linked to zero or more contactmomenten
- AND the relationship MUST be stored as array references on both entities

#### Scenario: VNG Klantinteracties alignment
- GIVEN the VNG Klantinteracties API standard
- THEN the contactmoment schema MUST be mappable to the VNG `Contactmoment` entity
- AND the kanaal values MUST align with VNG `Kanaal` enum values
- AND the contactmoment MUST include a `registratiedatum` (auto-set to creation timestamp)
- AND the contactmoment MUST include an `initiatiefnemer` field: "klant" or "medewerker"

---

### Requirement: Channel Statistics

The system MUST track contact volume and trends per channel, feeding into the contactmomenten-rapportage spec.

**Feature tier**: MVP

#### Scenario: Count contacts per channel
- GIVEN 50 contactmomenten registered today: 30 telefoon, 10 email, 5 balie, 3 chat, 2 social
- WHEN the statistics are queried
- THEN the system MUST return accurate counts per channel
- AND the data MUST be available for the rapportage dashboard in real-time
- AND the counts MUST be queryable via the OpenRegister API using faceted search on the `kanaal` field

#### Scenario: Track channel trends over time
- GIVEN contactmomenten data spanning 3 months
- WHEN the system aggregates channel data per week
- THEN the system MUST produce time series per channel showing volume trends
- AND the data MUST support identifying channel shifts (e.g., phone declining, chat increasing)
- AND the aggregation MUST be queryable by date range (from/to)

#### Scenario: Agent workload per channel
- GIVEN contactmomenten data for the current week
- WHEN the agent workload statistics are queried
- THEN the system MUST return per agent: total contacts, contacts per channel, average handling time (for phone contacts with gespreksduur)
- AND the data MUST be sortable by total contacts and by channel

#### Scenario: Peak hour analysis
- GIVEN contactmomenten data for the last 30 days
- WHEN peak hour analysis is requested
- THEN the system MUST return contact volume per hour of the day, aggregated by channel
- AND the data MUST identify the busiest hours per channel (e.g., phone peaks at 10:00-11:00)

---

### Requirement: Bulk Registration

The system MUST support registering multiple contactmomenten at once for batch processing scenarios.

**Feature tier**: V1

#### Scenario: Import email batch
- GIVEN 15 emails processed by a KCC team member over the morning
- WHEN the agent uses "Bulkregistratie" to register all 15 as contactmomenten
- THEN the system MUST allow setting common fields (kanaal: email, agent) once
- AND allow per-contact fields (client, subject, summary) per row
- AND create all 15 contactmomenten in a single operation
- AND report how many were created successfully and how many failed validation

#### Scenario: CSV import for historical contactmomenten
- GIVEN a municipality migrating from a legacy KCC system
- WHEN an administrator uploads a CSV file with historical contactmomenten
- THEN the system MUST validate each row against the contactmoment schema
- AND map CSV columns to contactmoment fields (configurable column mapping)
- AND report validation errors per row without aborting the entire import
- AND successfully imported contactmomenten MUST be fully searchable and reportable

#### Scenario: Bulk registration UI
- GIVEN the bulk registration interface
- THEN it MUST display a spreadsheet-like table with one row per contactmoment
- AND the agent MUST be able to add/remove rows
- AND common fields (channel, agent, date) MUST be settable as defaults for all rows
- AND each row MUST have inline validation feedback

---

### Requirement: Unified Inbox

The system MUST provide a unified inbox view that aggregates incoming contacts across channels.

**Feature tier**: V1

#### Scenario: Unified inbox displays pending contacts
- GIVEN 5 unprocessed emails, 3 callback requests, and 2 social media mentions
- WHEN the agent opens the unified inbox
- THEN all 10 items MUST be displayed in a single chronological list
- AND each item MUST show: channel icon, timestamp, sender/caller, subject preview
- AND unprocessed items MUST be visually distinct from processed items

#### Scenario: Process inbox item creates contactmoment
- GIVEN an unprocessed email in the unified inbox
- WHEN the agent clicks "Verwerken" on the email
- THEN the contact registration form MUST open pre-populated with channel "E-mail" and email details
- AND upon saving the contactmoment, the inbox item MUST be marked as processed
- AND the inbox count badge MUST decrement

#### Scenario: Inbox filtering by channel
- GIVEN the unified inbox with items from multiple channels
- WHEN the agent filters by "Telefoon"
- THEN only phone-related items MUST be displayed
- AND the filter MUST support multi-select (e.g., show both phone and email)

#### Scenario: Inbox assignment
- GIVEN an unprocessed inbox item
- WHEN a supervisor assigns the item to agent "Maria"
- THEN the item MUST appear in Maria's personal inbox
- AND it MUST be removed from the unassigned inbox view
- AND Maria MUST receive a Nextcloud notification about the assignment

---

### Requirement: Contact Registration Timer

The system MUST provide an integrated timer for tracking call duration during phone contact registration.

**Feature tier**: MVP

#### Scenario: Start timer on phone call
- GIVEN an agent selects channel "Telefoon" in the contact registration form
- THEN a call timer MUST be displayed showing elapsed time (MM:SS format)
- AND the timer MUST auto-start when the form opens with channel "Telefoon"
- AND the agent MUST be able to manually start/stop/reset the timer

#### Scenario: Timer auto-fills duration
- GIVEN the call timer shows 04:23 when the agent stops the timer
- WHEN the agent submits the contactmoment
- THEN the `gespreksduur` field MUST be auto-filled with "PT4M23S" (ISO 8601 duration)
- AND the agent MUST be able to manually override the duration before submission

#### Scenario: Timer persists during form editing
- GIVEN the call timer is running at 02:15
- WHEN the agent navigates to search for a client (leaving the form temporarily)
- AND returns to the contact registration form
- THEN the timer MUST still be running and show the correct elapsed time
- AND the form fields MUST be preserved

---

### Requirement: Letter (Brief) Registration

The system MUST support registering incoming and outgoing physical letters with document scanning integration.

**Feature tier**: V1

#### Scenario: Register incoming letter
- GIVEN an agent selects channel "Brief" in the contact registration form
- WHEN the form is displayed
- THEN the system MUST show core fields plus: ontvangstdatum, kenmerk, richting (inkomend/uitgaand), scan (file upload)
- AND the uploaded scan MUST be stored in Nextcloud Files under a configurable folder path (default: `/Pipelinq/Contactmomenten/Brieven/`)
- AND the contactmoment MUST be stored with `kanaal: "brief"` and metadata `{"ontvangstdatum": "2024-03-01", "kenmerk": "REF-2024-001", "scanPath": "/Pipelinq/Contactmomenten/Brieven/REF-2024-001.pdf"}`

#### Scenario: Register outgoing letter
- GIVEN an agent selects channel "Brief" and richting "Uitgaand"
- WHEN the form is displayed
- THEN the system MUST show additional fields: verzenddatum, tracking number (optional), copy upload
- AND the contactmoment MUST be stored with metadata `{"richting": "uitgaand", "verzenddatum": "2024-03-05"}`

#### Scenario: Link scan to existing Nextcloud file
- GIVEN an agent registering a letter that has already been scanned and uploaded to Nextcloud Files
- WHEN the agent uses the "Koppel bestand" option instead of uploading
- THEN a Nextcloud file picker MUST open allowing selection of existing files
- AND the selected file MUST be linked (not copied) to the contactmoment

---

### Requirement: Contactmoment Activity Integration

The system MUST integrate contactmomenten with the existing Pipelinq activity stream.

**Feature tier**: MVP

#### Scenario: Contactmoment appears in client activity timeline
- GIVEN a contactmoment registered for client "Jan de Vries" via channel "Telefoon"
- WHEN viewing the client detail page for "Jan de Vries"
- THEN the contactmoment MUST appear in the activity timeline
- AND it MUST show: channel icon, timestamp, agent name, subject, outcome
- AND clicking the entry MUST expand to show the full toelichting and channel metadata

#### Scenario: Contactmoment appears in request activity timeline
- GIVEN a contactmoment linked to request "Verzoek parkeervergunning"
- WHEN viewing the request detail page
- THEN the contactmoment MUST appear in the request's activity timeline
- AND it MUST show the same details as in the client timeline

#### Scenario: Activity publishing uses existing ActivityService
- GIVEN a new contactmoment is created
- THEN the system MUST publish a `contactmoment_created` activity event via the existing ActivityService
- AND the activity MUST include: agent user, channel type, client reference, subject
- AND the activity MUST appear in the Nextcloud activity app for the agent and any linked assignees

#### Scenario: Notification for contactmoment on assigned entities
- GIVEN a contactmoment is registered for a client who has an open lead assigned to "Maria"
- WHEN the contactmoment is saved
- THEN Maria MUST receive a Nextcloud notification: "Nieuw contactmoment voor [client name]: [subject]"
- AND the notification preference MUST respect the existing `notify_notes` user setting in SettingsService

---

### Requirement: KCC Integration Points

The system MUST provide integration hooks for KCC-specific workflows and external systems.

**Feature tier**: Enterprise

#### Scenario: CTI (Computer Telephony Integration) screen pop
- GIVEN an incoming phone call routed through the organization's PBX system
- AND the PBX provides the caller's phone number via a webhook or SIP header
- WHEN Pipelinq receives the caller ID
- THEN the system MUST auto-search contacts by phone number
- AND if found, open the contact registration form with the client pre-populated (screen pop)
- AND if not found, open the contact registration form with the phone number pre-filled

#### Scenario: Nextcloud Talk integration for chat channel
- GIVEN a chat conversation in Nextcloud Talk between an agent and a citizen
- WHEN the agent clicks "Registreer als contactmoment" in the Talk interface
- THEN a contactmoment registration form MUST open with: kanaal "chat", platform "Nextcloud Talk", transcript auto-linked
- AND the chat history MUST be stored as a linked file or inline in the toelichting field

#### Scenario: Webhook for external channel integration
- GIVEN an external channel system (e.g., a website chatbot or social media management tool)
- WHEN the external system sends a POST request to `/api/pipelinq/contactmomenten/webhook`
- THEN the system MUST validate the payload against the contactmoment schema
- AND create a new contactmoment with the provided data
- AND return the created contactmoment ID in the response
- AND the webhook MUST require API key authentication

---

### Requirement: Search and Filter Contactmomenten

The system MUST provide search and filter capabilities for contactmomenten.

**Feature tier**: MVP

#### Scenario: Search contactmomenten by keyword
- GIVEN 200 contactmomenten in the system
- WHEN the agent searches for "parkeervergunning"
- THEN the system MUST return all contactmomenten where the onderwerp or toelichting contains "parkeervergunning"
- AND results MUST be sorted by timestamp (newest first)

#### Scenario: Filter contactmomenten by channel and date range
- GIVEN the contactmomenten list view
- WHEN the agent filters by kanaal "Telefoon" and date range "01-03-2024 to 31-03-2024"
- THEN only phone contactmomenten from March 2024 MUST be displayed
- AND filters MUST be combinable (channel + date + agent + client)

#### Scenario: Filter by outcome
- GIVEN the contactmomenten list view
- WHEN the agent filters by resultaat "terugbelverzoek"
- THEN only contactmomenten with outcome "terugbelverzoek" MUST be displayed
- AND this view feeds directly into the terugbel-taakbeheer spec

#### Scenario: Export contactmomenten to CSV
- GIVEN a filtered set of contactmomenten
- WHEN the agent clicks "Exporteren"
- THEN a CSV file MUST be generated containing all visible columns plus channel metadata
- AND the CSV MUST include headers in Dutch: Datum, Kanaal, Medewerker, Klant, Onderwerp, Resultaat, Toelichting

---

## Appendix

### Current Implementation Status

**Implemented:**
- Request channels are defined as SystemTag-based configuration in the repair step (`lib/Repair/InitializeSettings.php`, lines 74-80): phone, email, website, counter, post.
- `lib/Controller/RequestChannelController.php` provides CRUD for managing request channels via SystemTags.
- `src/store/modules/requestChannels.js` provides frontend state management for channel options.
- The `request` schema in `lib/Settings/pipelinq_register.json` does NOT currently include a `channel` field (noted as a gap in the request-management spec).
- Request forms include a channel dropdown sourced from SystemTag-based admin settings (visible in `RequestForm.vue`).
- Activity publishing exists via `ActivityService.php` for leads and requests (reusable for contactmomenten).
- Notification system exists via `NotificationService.php` with per-user preferences (reusable for contactmomenten).

**Not yet implemented:**
- **Contactmoment entity:** No `contactmoment` schema exists in `pipelinq_register.json`. The entire omnichannel contact registration concept is not implemented.
- **Unified Contact Registration Form:** No adaptive form based on channel selection.
- **Channel-specific metadata:** No channel metadata configuration (gespreksduur, thread-ID, etc.).
- **Channel Configuration admin UI:** No admin panel for channel metadata field definitions (beyond basic channel name management).
- **Auto-linking to Client and Case:** No auto-population of client from identified caller, no case suggestion system.
- **Channel Statistics:** No per-channel contact volume tracking or trend analysis.
- **Bulk Registration:** No batch processing for contactmomenten.
- **Unified Inbox:** No aggregated inbox view.
- **Contact Registration Timer:** No call timer component.
- **Letter Registration:** No physical letter scanning/file upload integration.
- **Activity Integration:** Activity stream exists but does not include contactmoment events.
- **KCC Integration:** No CTI, Talk integration, or external webhook endpoints.
- **Search/Filter:** No contactmoment-specific search or export.
- **VNG Klantinteracties integration:** No `Contactmoment` or `Kanaal` entity alignment.

**Partial implementations:**
- The existing request channel infrastructure (SystemTags + controller + store) provides a foundation for channel management but does not support channel-specific metadata or the contactmoment concept.
- The existing request form channel dropdown (RequestForm.vue line 42-49) demonstrates the pattern for channel selection that can be extended.

### Standards & References
- **VNG Klantinteracties API:** `Contactmoment` entity with `Kanaal` enum (telefoon, email, balie, chat, social, brief). This is the primary standard for Dutch municipal contact registration.
- **Schema.org:** `InteractionCounter`, `CommunicateAction` for contact event modeling.
- **Common Ground:** Contact registration is a core component of the Common Ground architecture for municipalities.
- **WCAG AA:** All forms must be accessible.
- **ISO 8601:** Duration format (PT4M23S) for gespreksduur, datetime for timestamps.
- **Nextcloud Talk (OCA\Talk\IBroker):** Integration point for chat channel.
- **Nextcloud Mail (OCA\Mail):** Integration point for email channel auto-population.

### Specificity Assessment
- The spec now defines 12 requirements with 3-5 scenarios each, covering the registration form, channels, auto-linking, schema, statistics, bulk registration, unified inbox, timer, letters, activity integration, KCC integration, and search/filter.
- **Implementable incrementally:** MVP covers the registration form (phone, email, counter), auto-linking, schema, statistics, timer, activity integration, and search. V1 adds channel configuration, bulk registration, unified inbox, and letter registration. Enterprise adds CTI and Talk integration.
- **Resolved:** Contactmoment is a separate entity from request, with explicit schema definition.
- **Resolved:** Channel configuration extends existing SystemTag infrastructure.
- **Resolved:** Integration with existing ActivityService and NotificationService is specified.
- **Dependency:** Requires `kcc-werkplek` and `klantbeeld-360` specs for full KCC workflow context. Procest integration for zaak linking is optional (gracefully degrades when not installed).
