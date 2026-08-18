# Collaboration Leaves — Deck, Talk & Forms Consumption Delta

**Spec refs**: `email-calendar-sync` (comms-leaf consumption pattern; calendar axis owned by the `calendar-deepening` change), `lead-management`, `client-management`, ADR-022 (apps consume OR abstractions — leaf-first)
**Standards**: Nextcloud integration-leaf widget contract (`{ "type": "integration", "integrationId": "…" }`), OpenRegister schema `linkedTypes` dialect

## ADDED Requirements

### Requirement: Deck leaf widget on LeadDetail

The LeadDetail manifest page SHALL render the deck leaf's integration widget — `{ "id": "lead-deck", "type": "integration", "integrationId": "deck", "title": "Board", "icon": "BulletinBoard" }` in `config.widgets` plus a layout row — so that the Deck cards linked to a deal are visible where the deal is worked. The widget SHALL be a body widget, not a sidebar tab (the sidebar stays audit-only, per the rule recorded in the LeadDetail `_note`). The `lead` schema already declares `deck` in its `linkedTypes` (`lib/Settings/pipelinq_register.json` L358–366); the system SHALL NOT add any pipelinq-local Deck component, store, API call, or schema — the leaf owns all Deck I/O.

**Standards**: Nextcloud Deck app, OpenRegister deck leaf
**Feature tier**: V1 (collaboration-leaf parity)

#### Scenario: Linked Deck cards appear on the lead

- **GIVEN** the Nextcloud Deck app is installed and a lead has a Deck card linked through the deck leaf
- **WHEN** an agent opens the lead detail page
- **THEN** a "Board" widget SHALL render the lead's linked cards through the deck leaf
- **AND** linking or creating a card from that widget SHALL use the leaf's own flow, with no pipelinq code on the write path

#### Scenario: Deck app absent degrades the widget, not the page

- **GIVEN** the Nextcloud Deck app is not installed
- **WHEN** an agent opens a lead detail page
- **THEN** the LeadDetail page SHALL render normally with the deck widget in its integration-unavailable state
- **AND** no error SHALL be raised by pipelinq code

---

### Requirement: Talk rooms on client and lead detail

The system SHALL link per-record Talk conversations to clients and leads through the OpenRegister talk leaf, and nowhere else. The `client` and `lead` schemas SHALL declare `talk` in their `linkedTypes` (`lib/Settings/pipelinq_register.json`), and the ClientDetail and LeadDetail manifest pages SHALL render the talk leaf's integration widget (`client-talk` titled "Client room", `lead-talk` titled "Deal room") as body widgets with layout rows. Conversation content SHALL live in Talk and link state in the leaf; pipelinq SHALL NOT store conversation or room references in its own schemas, SHALL NOT call the Talk API directly, and SHALL NOT add `talk` to the `contact` schema (a conversation belongs to the deal or the account, not to a person card).

**Standards**: Nextcloud Talk app, OpenRegister talk leaf
**Feature tier**: V1 (collaboration-leaf parity)

#### Scenario: Deal room on a lead

- **GIVEN** the Nextcloud Talk app is installed
- **WHEN** an agent opens a lead detail page and creates or links a conversation from the "Deal room" widget
- **THEN** the conversation SHALL be created/linked through the talk leaf's flow and rendered in the widget on subsequent visits
- **AND** no pipelinq object SHALL gain a room or conversation property

#### Scenario: Client room on a client

- **GIVEN** a client with a leaf-linked Talk conversation
- **WHEN** an agent opens the client detail page
- **THEN** the "Client room" widget SHALL render the linked conversation through the talk leaf

#### Scenario: Talk app absent degrades the widgets, not the pages

- **GIVEN** the Nextcloud Talk app is not installed
- **WHEN** an agent opens a client or lead detail page
- **THEN** the page SHALL render normally with the talk widget in its integration-unavailable state

---

### Requirement: Forms leaf widget on LeadDetail

The LeadDetail manifest page SHALL render the forms leaf's integration widget — `{ "id": "lead-forms", "type": "integration", "integrationId": "forms", "title": "Intake forms", "icon": "FormSelect" }` plus a layout row — so that NC Forms and their submissions linked to a lead (its intake context) are visible on the deal. The `lead` schema already declares `forms` in its `linkedTypes`. This requirement consumes the forms leaf on an internal detail page only; it SHALL NOT implement, alter, or claim any part of the separate `public-intake-forms` capability (embeddable external-website forms, currently a draft/unbuilt spec), and pipelinq SHALL NOT add any Forms API call of its own.

**Standards**: Nextcloud Forms app, OpenRegister forms leaf
**Feature tier**: V1 (collaboration-leaf parity)

#### Scenario: Linked intake form visible on the lead

- **GIVEN** the Nextcloud Forms app is installed and a form is linked to a lead through the forms leaf
- **WHEN** an agent opens the lead detail page
- **THEN** the "Intake forms" widget SHALL render the linked form and its submissions through the forms leaf

#### Scenario: Forms app absent degrades the widget, not the page

- **GIVEN** the Nextcloud Forms app is not installed
- **WHEN** an agent opens a lead detail page
- **THEN** the LeadDetail page SHALL render normally with the forms widget in its integration-unavailable state

---

### Requirement: Declared linkedTypes are mounted or recorded as deliberate exclusions

For each of the `client`, `contact`, `lead`, and `ticket` schemas, every entry in `linkedTypes` SHALL either have a corresponding integration widget mounted on that type's detail page in `src/manifest.json`, or be recorded as a deliberate exclusion in the page's `_note`. A unit test SHALL parse `lib/Settings/pipelinq_register.json`, `lib/Settings/register.d/*.json`, and `src/manifest.json` and fail on any declared leaf type that is neither mounted nor recorded — so declared-but-unmounted drift (the state `deck` and `forms` were in before this change) surfaces in review instead of silently shipping. After this change the recorded exclusions SHALL be: `flow` and `time-tracker` (all types), `xwiki` on detail pages (mounted app-level on the knowledge page `xwiki-knowledge` instead), and `deck`/`forms` on `ticket` (deferred pending the ticket-board workflow).

**Standards**: OpenRegister schema `linkedTypes` dialect
**Feature tier**: V1 (conformance)

#### Scenario: Conformance test fails on silent divergence

`@e2e exclude` static conformance between two JSON artifacts (register schemas vs. manifest) with no browser surface — asserted by a PHPUnit test over the parsed files, not by a page.

- **WHEN** a schema gains a `linkedTypes` entry with no mounted widget and no `_note` exclusion for it
- **THEN** the conformance unit test SHALL fail, naming the schema, the leaf type, and the page

#### Scenario: Recorded exclusions pass

`@e2e exclude` same static conformance check — the passing branch of the same PHPUnit test, no browser surface.

- **GIVEN** the `ticket` schema declaring `deck` with a `_note` exclusion recorded on the TicketDetail page
- **WHEN** the conformance test runs
- **THEN** it SHALL pass without requiring a ticket deck widget
