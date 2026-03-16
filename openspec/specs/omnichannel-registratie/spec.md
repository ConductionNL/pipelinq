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

#### Scenario: Register letter (brief) contact

- GIVEN an agent selects channel "Brief" in the contact registration form
- WHEN the form is displayed
- THEN the system MUST show core fields plus: ontvangstdatum, kenmerk, scan (file upload)
- AND the uploaded scan MUST be stored in Nextcloud Files and linked to the contactmoment
- AND the contactmoment MUST be stored with `kanaal: "brief"` and metadata `{"ontvangstdatum": "2024-03-01", "kenmerk": "REF-2024-001"}`

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
- THEN the system MUST display the 3 open zaken as suggestions
- AND the system MUST also allow searching for closed zaken
- AND the agent MUST be able to select one or more zaken

#### Scenario: Create contactmoment from email

- GIVEN an email arrives at info@gemeente.nl from burger@example.nl
- WHEN the agent processes the email and clicks "Registreer als contactmoment"
- THEN the system MUST pre-populate: kanaal "E-mail", afzender, onderwerp from email subject
- AND the system MUST search for an existing client by the sender email address
- AND if found, auto-populate the client reference

---

### Requirement: Channel Statistics

The system MUST track contact volume and trends per channel, feeding into the contactmomenten-rapportage spec.

**Feature tier**: MVP

#### Scenario: Count contacts per channel

- GIVEN 50 contactmomenten registered today: 30 telefoon, 10 email, 5 balie, 3 chat, 2 social
- WHEN the statistics are queried
- THEN the system MUST return accurate counts per channel
- AND the data MUST be available for the rapportage dashboard in real-time

#### Scenario: Track channel trends over time

- GIVEN contactmomenten data spanning 3 months
- WHEN the system aggregates channel data per week
- THEN the system MUST produce time series per channel showing volume trends
- AND the data MUST support identifying channel shifts (e.g., phone declining, chat increasing)

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
