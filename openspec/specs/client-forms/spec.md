# client-forms Specification

## Purpose
Makes creating and editing a client quick and predictable. One client dialog serves every form that opens it and returns there, editing asks for a name and email, a new party never gets conflicting language settings, help text is plain, and errors wait until the user has acted.

## Requirements

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

### Requirement: Errors wait for the user

The New client and New lead forms SHALL NOT show a validation error for a field until the user changed that field or tried to save. Validity itself (and with it the disabled Save button) SHALL not change.

#### Scenario: A fresh form

- GIVEN a user who opens New client or New lead
- WHEN nothing has been typed yet
- THEN no "Name is required", "Title is required" or "Client is required" SHALL show

@e2e exclude asserted with the real forms in tests/vitest/clientForms.spec.js and tests/vitest/leadFormErrorTiming.spec.js.

#### Scenario: A save attempt

- GIVEN a New lead form with no title
- WHEN the user tries to save
- THEN "Title is required" SHALL show and nothing SHALL be saved

@e2e exclude asserted in tests/vitest/leadFormErrorTiming.spec.js.

### Requirement: A client's name, email and phone can be saved from the client page (REQ-R3-001)

The client and contact schemas SHALL NOT mark `name`, `email` or `phone` as
read-only, so OpenRegister accepts a change made in the Edit dialog. The change
SHALL still be written back to the linked Nextcloud Contact.

#### Scenario: Change a client's phone

@e2e exclude The schema half is asserted on the merged register in tests/Unit/Settings/ReviewRound3RegisterTest.php; tests/e2e/workflows/client-crud.spec.ts renames a client through the object API.
- GIVEN a client without a phone number
- WHEN the user enters a phone number in the client Edit dialog and saves
- THEN the client SHALL carry the phone number
- AND OpenRegister SHALL NOT answer "Cannot modify readOnly property"
- AND pipelinq SHALL write the change back to the linked Nextcloud Contact
