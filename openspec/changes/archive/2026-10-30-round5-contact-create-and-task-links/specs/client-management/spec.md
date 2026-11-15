# Delta: client-management

## ADDED Requirements

### Requirement: A contact person added on a client page is created
"Add contact person" on the Contacts tab of a client page MUST create the
contact through the contact-first path (`POST /api/contacts-sync/create`), so
the required `contactsUid` is provisioned, and the new contact MUST be linked
to that client. A failed create MUST show the reason and MUST NOT report the
contact as saved. A client or contact list that cannot create through that
path MUST NOT offer an Add button.

#### Scenario: Add a contact person
@e2e exclude Asserted in tests/vitest/round5ContactCreateAndTaskLinks.spec.js and live on :8099 (lane R5-PQ-A hand-back).
- **GIVEN** a user opens a client and the Contacts tab
- **WHEN** they press "Add contact person", fill in a name and save
- **THEN** the contact MUST be created with a `contactsUid`
- **AND** it MUST show in the client's Contacts list

#### Scenario: The create is refused
@e2e exclude Asserted in tests/vitest/round5ContactCreateAndTaskLinks.spec.js.
- **GIVEN** the backend refuses the create
- **WHEN** the user saves the dialog
- **THEN** the dialog MUST show the backend's message
- **AND** it MUST NOT report success

#### Scenario: The contacts of a lead
@e2e exclude Asserted in tests/vitest/round5ContactCreateAndTaskLinks.spec.js on the manifest.
- **GIVEN** a user opens a lead
- **WHEN** they look at its Contacts list
- **THEN** the list MUST NOT offer an Add button
