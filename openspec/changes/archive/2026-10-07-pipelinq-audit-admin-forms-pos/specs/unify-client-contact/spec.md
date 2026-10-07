# Unify client and contact: identity edited on the client page

Delta over `specs/unify-client-contact`. Ruben decided on 7 October 2026 that a client's name, email address and phone number are edited on the client page and written back to the Nextcloud Contact, instead of being read-only with a deep link to the addressbook.

## MODIFIED Requirements

### Requirement: REQ-PUCC-004 — The system SHALL reuse the existing contact-sync pattern and keep the Nextcloud Contact authoritative

The system SHALL keep the Nextcloud Contact authoritative for `name`/`email`/`phone`/`address`/`org` and SHALL refresh the pipelinq denormalised mirror fields from it using the EXISTING `ContactVcardService`/`ContactSyncService::syncToContacts()` flow — without adding a new sync service or a new identity key. A user MAY edit `name`, `email` and `phone` on the client or contact edit form; the system SHALL then write the change back to the linked Nextcloud Contact through the same `syncToContacts()` flow (`POST /api/contacts-sync/write-back`), so the contact stays authoritative and the next refresh does not undo the edit. It SHALL resolve/match an existing Nextcloud Contact through the OpenRegister `contacts-actions` integration provider (ADR-019) before creating one, and MUST NOT hard-code a cross-app HTTP call (ADR-022).

@e2e exclude reuse of an implemented service — verified by PHPUnit asserting the existing ContactVcardService is invoked and no new sync class is introduced; the write-back after an edit is asserted by tests/vitest/clientForms.spec.js.

#### Scenario: Mirror fields refresh from the authoritative contact
- GIVEN a `client` linked to a Nextcloud Contact by `contactsUid`
- WHEN the contact's name or email changes and the object is synced
- THEN the pipelinq `client.name`/`client.email` mirror MUST be refreshed from the contact via the existing `ContactVcardService`
- AND no new sync service and no new identity key MUST be introduced

#### Scenario: Identity edited on the client page is written back
- GIVEN a `client` linked to a Nextcloud Contact by `contactsUid`
- WHEN a user changes the client's name, email or phone in the edit form and saves
- THEN the client SHALL carry the new value
- AND the system SHALL write it back to the linked Nextcloud Contact

#### Scenario: An existing contact is matched, not duplicated, via the registry
- GIVEN a `client`/`contact` whose email already matches a Nextcloud addressbook contact
- WHEN the sync resolves its identity
- THEN it MUST resolve that existing contact through the `contacts-actions` integration provider and reuse its `contactsUid`
- AND it MUST NOT create a duplicate contact and MUST NOT issue a hard-coded HTTP call
