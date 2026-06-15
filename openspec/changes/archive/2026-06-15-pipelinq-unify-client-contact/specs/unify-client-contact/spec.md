# Delta Spec: pipelinq-unify-client-contact

**Status:** proposed
**Scope:** pipelinq
**Tier:** MVP
**Depends on:** pipelinq `contacts-sync` (implemented, archived `2026-02-26-contacts-sync`), OpenRegister `pluggable-integration-registry` (ADR-019, `contacts-actions` provider), pipelinq `master-data-management` (`masterEntity`/`sourceRecord`)

This delta makes the Nextcloud addressbook the single person/organisation identity store per CROSS-APP INTERFACE CONTRACT #2. It demotes the duplicate identity fields on the existing `client` and `contact` schemas to denormalised mirrors keyed by `contactsUid`, normalises the `contactmoment` party link to `contactsUid`, reuses the existing `ContactVcardService`/`ContactSyncService` sync pattern (no new engine, no new key), and migrates existing objects idempotently and non-destructively. All three nav surfaces (Clients, Contacts, Contactmomenten) are kept; only the duplicate identity STORAGE is removed.

## ADDED Requirements

### Requirement: REQ-PUCC-001 — The system SHALL key the `client` relationship record by a Nextcloud Contact and demote its identity fields to denormalised mirrors

The system SHALL make `contactsUid` a REQUIRED property on the existing `client` schema (identity = the linked Nextcloud Contact) and SHALL flag `name`, `email`, `phone`, `address`, `website` as denormalised read-only mirrors sourced from that contact, via the override fragment `lib/Settings/register.d/15-unify-client-contact.json` targeting the existing `client` slug. It MUST keep the CRM-specific fields (`type`, `industry`, `notes`) authoritative, add the relationship fields (`lifecycleStage`, `segment`, `accountOwner`, `accountStatus`), and MUST NOT create a second register or a new party/customer schema.

@e2e exclude schema-fragment + identity-source change — register.d metadata and read-only mirror flags; identity sync covered by PHPUnit on ContactVcardService and the migration repair.

#### Scenario: Client identity is sourced from the Nextcloud Contact
- GIVEN the existing `client` schema with `name`, `email`, `phone`, `address`, `website`, `industry`, `contactsUid`
- WHEN the `15-unify-client-contact.json` fragment is loaded
- THEN `contactsUid` MUST be required and reference a Nextcloud addressbook contact
- AND `name`, `email`, `phone`, `address`, `website` MUST be flagged as denormalised read-only mirrors (the contact is authoritative)
- AND `type`, `industry`, `notes` MUST remain authoritative CRM fields
- AND no second `client` register and no new party/customer schema MUST exist

#### Scenario: Client gains CRM relationship attributes
- GIVEN a `client` object
- WHEN it is saved after the fragment loads
- THEN it MUST expose `lifecycleStage`, `segment`, `accountOwner`, `accountStatus` as the account/relationship attributes
- AND the pre-existing `relationship` schema (linking `fromContact`/`toContact` by UUID) MUST remain unchanged

### Requirement: REQ-PUCC-002 — The system SHALL key the `contact` person-role record by a Nextcloud Contact and demote its identity fields to denormalised mirrors

The system SHALL make `contactsUid` a REQUIRED property on the existing `contact` schema and SHALL flag `name`, `email`, `phone` as denormalised read-only mirrors sourced from the linked Nextcloud Contact, via the same fragment. It MUST keep `role`, the parent-account link `client`, and the domain flags (`marketingConsent`, `doNotContact`, `verifiedBSN`, `brpPersoonId`, `geheimhouding`) authoritative on `contact`.

@e2e exclude schema-fragment + identity-source change — register.d metadata; covered by PHPUnit on the migration repair.

#### Scenario: Contact identity is sourced from the Nextcloud Contact, governance flags stay local
- GIVEN the existing `contact` schema with `name`, `email`, `phone`, `role`, `client`, `marketingConsent`, `verifiedBSN`
- WHEN the fragment is loaded
- THEN `contactsUid` MUST be required and reference a Nextcloud addressbook contact
- AND `name`, `email`, `phone` MUST be flagged as denormalised read-only mirrors
- AND `role`, `client`, `marketingConsent`, `doNotContact`, `verifiedBSN`, `brpPersoonId`, `geheimhouding` MUST remain authoritative on `contact`

### Requirement: REQ-PUCC-003 — The system SHALL link `contactmoment` interactions to the party by `contactsUid`

The system SHALL add a `contactsUid` property to the existing `contactmoment` schema as the canonical party link, while keeping the existing `client` UUID reference as a soft back-reference. It MUST NOT add any person/organisation identity field to `contactmoment` (it remains a pure interaction log of channel/outcome/agent/contactedAt).

@e2e exclude schema-fragment — additive link metadata on an existing interaction schema; covered by PHPUnit on the re-key migration.

#### Scenario: Interaction references the party by contactsUid
- GIVEN a `contactmoment` with `subject`, `channel`, `agent`, `contactedAt`, and a `client` UUID link
- WHEN the fragment is loaded
- THEN `contactmoment` MUST expose `contactsUid` as the canonical party link
- AND the existing `client` UUID reference MUST remain as a soft back-reference
- AND no `name`/`email`/`phone` identity field MUST be added to `contactmoment`

### Requirement: REQ-PUCC-004 — The system SHALL reuse the existing contact-sync pattern and keep the Nextcloud Contact authoritative

The system SHALL keep the Nextcloud Contact authoritative for `name`/`email`/`phone`/`address`/`org` and SHALL refresh the pipelinq denormalised mirror fields from it using the EXISTING `ContactVcardService`/`ContactSyncService::syncToContacts()` flow — without adding a new sync service or a new identity key. It SHALL resolve/match an existing Nextcloud Contact through the OpenRegister `contacts-actions` integration provider (ADR-019) before creating one, and MUST NOT hard-code a cross-app HTTP call (ADR-022).

@e2e exclude reuse of an implemented service — verified by PHPUnit asserting the existing ContactVcardService is invoked and no new sync class is introduced.

#### Scenario: Mirror fields refresh from the authoritative contact
- GIVEN a `client` linked to a Nextcloud Contact by `contactsUid`
- WHEN the contact's name or email changes and the object is synced
- THEN the pipelinq `client.name`/`client.email` mirror MUST be refreshed from the contact via the existing `ContactVcardService`
- AND the mirror MUST be read-only in the UI (editing identity deep-links to the addressbook)
- AND no new sync service and no new identity key MUST be introduced

#### Scenario: An existing contact is matched, not duplicated, via the registry
- GIVEN a `client`/`contact` whose email already matches a Nextcloud addressbook contact
- WHEN the sync resolves its identity
- THEN it MUST resolve that existing contact through the `contacts-actions` integration provider and reuse its `contactsUid`
- AND it MUST NOT create a duplicate contact and MUST NOT issue a hard-coded HTTP call

### Requirement: REQ-PUCC-005 — The system SHALL migrate existing `client`/`contact` objects to Nextcloud Contacts idempotently and non-destructively

The system SHALL provide an idempotent repair `lib/Repair/UnifyClientContactIdentity.php` that, for each existing `client`/`contact` object, resolves an existing Nextcloud Contact (matching `email` first, then KvK/ORG for `type==organization`) or creates one from the object's existing identity fields, then stores the resulting `contactsUid` on the object. It MUST keep the existing identity fields in place as the initial mirror value (never delete them), MUST NOT overwrite a non-empty `contactsUid`, MUST write one MDM `sourceRecord` (`sourceSystem: pipelinq-identity-unify`) per resolved object, and MUST be safe to re-run and to resume after a partial run.

@e2e exclude data-migration repair — verified by PHPUnit on the repair (idempotency, match-then-create, non-destructive, resumable).

#### Scenario: Migration resolves an existing client to a Nextcloud Contact by email
- GIVEN a `client` with `email` matching a Nextcloud addressbook contact and no `contactsUid`
- WHEN `UnifyClientContactIdentity` runs
- THEN the matched contact's `contactsUid` MUST be stored on the `client`
- AND the existing `name`/`email`/`phone`/`address` fields MUST be preserved as the mirror (nothing deleted)
- AND a `sourceRecord` with `sourceSystem: pipelinq-identity-unify` MUST be written

#### Scenario: Migration matches an organisation client by KvK/ORG when email is absent
- GIVEN a `client` with `type: organization`, no `email`, and a name matching a contact's ORG
- WHEN the repair runs
- THEN it MUST resolve that contact by KvK/ORG and store its `contactsUid`
- AND if no contact matches it MUST create one from the existing identity fields and store the new `contactsUid`

#### Scenario: Migration is idempotent and non-destructive
- GIVEN a `client`/`contact` that already has a `contactsUid`
- WHEN the repair runs again
- THEN it MUST be a no-op for that object (existing `contactsUid` not overwritten)
- AND no identity field MUST be deleted
- AND a partial earlier run MUST be safely resumable

### Requirement: REQ-PUCC-006 — The system SHALL re-key existing `contactmoment` interactions to `contactsUid`

The migration SHALL, for each existing `contactmoment` linked to a `client` by UUID, resolve the `contactsUid` from that client's freshly-set `contactsUid` and store it on `contactmoment.contactsUid`, while leaving `contactmoment.client` untouched.

@e2e exclude data-migration repair — verified by PHPUnit on the re-key step.

#### Scenario: Interaction is re-keyed to the party's contactsUid
- GIVEN a `contactmoment` whose `client` UUID points at a `client` that now has a `contactsUid`
- WHEN the migration runs
- THEN `contactmoment.contactsUid` MUST be set to that client's `contactsUid`
- AND `contactmoment.client` MUST be left untouched as the soft back-reference

### Requirement: REQ-PUCC-007 — The system SHALL keep all three people-surfaces in the navigation with their routes intact

The system SHALL keep the three top-level nav entries `Clients` (order 20), `Contacts` (order 30), and `Contactmomenten` (order 70) in `src/manifest.json`, with routes `/clients`, `/contacts`, `/contactmomenten` unchanged. They are distinct surfaces (relationship/account, contact-person role, interaction log); only the duplicate identity STORAGE is removed, not any nav entry or route.

@e2e exclude nav-preservation assertion — covered by the existing pipelinq nav e2e; this change removes no route.

#### Scenario: No nav entry or route is removed
- GIVEN the pipelinq nav with `Clients`, `Contacts`, `Contactmomenten`
- WHEN this change is applied
- THEN all three nav entries MUST remain present with routes `/clients`, `/contacts`, `/contactmomenten`
- AND each detail page (`/clients/:id`, `/contacts/:id`, `/contactmomenten/:id`) MUST remain routable for deep links
