# Client forms: audit fixes

Delta over `specs/client-management` and `specs/lead-management`.

## ADDED Requirements

### Requirement: One client dialog that returns to the form that opened it

Every place that creates a client SHALL use the New client dialog (`ClientCreateDialog`), including the select-or-create pickers of nextcloud-vue's generic forms. Opened from a picker, the dialog SHALL prefill the typed name, hand the created client back to the picker and leave the form that opened it on screen. Opened from the Clients page, it SHALL open the new client.

#### Scenario: Create a client from the lead form

- GIVEN a user filling in New lead who types "Acme" in the client picker
- WHEN the user picks the create option and saves the New client dialog
- THEN the dialog SHALL have started with the name "Acme"
- AND the lead form SHALL still be open with Acme selected as the client

@e2e exclude asserted with the real ClientCreateDialog in tests/vitest/clientForms.spec.js.

#### Scenario: Create a client from the contact picker

- GIVEN the Create contact form
- WHEN the user creates a client from its client picker
- THEN the New client dialog SHALL open, not a generic schema form

@e2e exclude nextcloud-vue picks the page's createModal when no createOverride is set; asserted on the manifest in tests/vitest/clientForms.spec.js.

### Requirement: Client edit asks for name and email

The client edit form SHALL let the user change the name, email address and phone number. A change to those fields SHALL be written back to the linked Nextcloud Contact, so the next sync does not undo it. The industry field SHALL offer only the listed sectors.

#### Scenario: Rename a client

- GIVEN a client
- WHEN the user edits its name and saves
- THEN the client SHALL carry the new name
- AND pipelinq SHALL write the change back to the linked Nextcloud Contact

@e2e exclude the write-back is a store plugin, asserted in tests/vitest/clientForms.spec.js.

### Requirement: No conflicting language on a new party

A new client or contact person SHALL NOT receive a `language` value by default. `correspondenceLanguage` holds the language choice.

#### Scenario: New client in English

- GIVEN a user who creates a client with correspondence language English
- WHEN the client is saved
- THEN it SHALL NOT carry `language: 'nl'`

@e2e exclude a schema default, asserted by tests/Unit/Settings/PlainFormHelpTest.php.

### Requirement: Forms show plain help

The create and edit forms for clients, contact persons, tasks, products and deal lines SHALL show help a user understands, or none. The technical description SHALL stay with the property for developers (`x-notes`).

#### Scenario: Create contact

- GIVEN the Create contact form
- WHEN it renders the client field
- THEN its help SHALL read "The client this person works for." and not "UUID reference to the parent client object"

@e2e exclude schema text, asserted on the merged register by tests/Unit/Settings/PlainFormHelpTest.php.
