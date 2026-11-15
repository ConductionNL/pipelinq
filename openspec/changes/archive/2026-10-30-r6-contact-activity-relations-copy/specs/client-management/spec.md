# Delta: client-management

## ADDED Requirements

### Requirement: Cards on the client page follow a contact added on that page
When a user adds a contact person on a client's Contacts tab, the client page MUST update without a reload. The Messages card MUST offer the new contact, and the Related card MUST list it with the client's other related records. The contact-first create MUST announce the new object the same way the library's own create does (`cn-walkthrough:object-created` with register `pipelinq` and the schema slug).

#### Scenario: The Messages card after a contact is added
@e2e exclude Asserted in tests/vitest/r6ContactActivityRelationsCopy.spec.js: the real ContactAwareObjectListWidget create announces the contact, the real page refresh listener answers it, and the real MessagingConversationSection refetches the client's contacts on that refresh.
- **GIVEN** a client with no contact persons, whose Messages card says no contacts are linked
- **WHEN** the user adds a contact person on the Contacts tab
- **THEN** the Messages card MUST fetch the client's contacts again and offer the new contact

#### Scenario: The Related card after a contact is added
@e2e exclude Asserted in tests/vitest/r6ContactActivityRelationsCopy.spec.js: a new contact sends a refresh on `cn:widget:refresh`, the channel CnRelatedObjectsWidget reloads on. That the contact is in the client's `/used` follows from its `client` reference being in `_relations`.
- **GIVEN** a client page showing "No relations yet" in its Related card
- **WHEN** the user adds a contact person on the Contacts tab
- **THEN** the Related card MUST reload and list the contact

### Requirement: The Messages card empty state reads as plain text
The Messages card on a client with no contact persons MUST say so without an em-dash, in English and Dutch.

#### Scenario: A client without contacts
@e2e exclude Copy check; asserted in tests/vitest/r6ContactActivityRelationsCopy.spec.js against the component source and the en and nl catalogues.
- **GIVEN** a client with no contact persons
- **WHEN** the user opens the client
- **THEN** the Messages card MUST read "No contacts are linked to this client yet. Add a contact to send messages."
