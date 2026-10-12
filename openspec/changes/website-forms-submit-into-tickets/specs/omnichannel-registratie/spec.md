# omnichannel-registratie Delta: website-forms-submit-into-tickets

**Status**: draft
**Scope**: a note that inbound messages without a form keep their inbox (decision 181, Q5).

## MODIFIED Requirements

### Requirement: Unified Inbox

The system MUST provide a unified inbox view that aggregates incoming contacts across channels.

Messages that arrive without a form (mail, chat, social media, post) keep this inbox: nobody knows their destination until a person reads them, so they are outside decision 179 (decision 181, Q5). A website or Nextcloud Forms submit does not pass through this inbox; it creates the object its form names (see `website-enquiry-intake`).

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
