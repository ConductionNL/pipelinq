## ADDED Requirements

### Requirement: Write-back maps channel arrays to typed vCard properties

When a client or contact carries a non-empty `emails[]` or `phones[]` array, the write-back sync SHALL build the vCard's `EMAIL`/`TEL` properties from that array as multiple typed values (one vCard property per entry, with a `TYPE` parameter derived from the entry's `kind`) rather than from the legacy single `email`/`phone` field. When `socialProfiles[]` is non-empty, the write-back sync SHALL also write an `X-SOCIALPROFILE` property per entry, with `TYPE` set to the entry's `network` and the value set to its `url` (falling back to `handle` when no URL is set). An object whose `emails[]`/`phones[]` array is absent or empty SHALL fall back to the legacy scalar `email`/`phone` field, unchanged from prior behaviour.

#### Scenario: Multiple typed emails write multiple vCard EMAIL properties

- **GIVEN** a client with `emails: [{kind: "work", value: "a@x.test", primary: true}, {kind: "private", value: "b@x.test", primary: false}]`
- **WHEN** the object is synced to Nextcloud Contacts
- **THEN** the vCard SHALL contain two `EMAIL` properties, `a@x.test` with `TYPE=WORK` and `b@x.test` with `TYPE=HOME`

#### Scenario: Social profiles write X-SOCIALPROFILE properties

- **GIVEN** a contact with `socialProfiles: [{network: "linkedin", url: "https://linkedin.com/in/jane", handle: "jane"}]`
- **WHEN** the object is synced to Nextcloud Contacts
- **THEN** the vCard SHALL contain an `X-SOCIALPROFILE` property with value `https://linkedin.com/in/jane` and `TYPE=linkedin`

#### Scenario: Empty channel arrays fall back to the legacy scalar field

- **GIVEN** a client with `emails: []` and a non-empty legacy `email` field
- **WHEN** the object is synced to Nextcloud Contacts
- **THEN** the vCard's `EMAIL` property SHALL be built from the legacy `email` field, matching pre-existing behaviour

@e2e exclude vCard property construction is a PHP service method with no direct UI surface — the resulting vCard is written to Nextcloud Contacts via IManager, not rendered in Pipelinq; covered by PHPUnit (ContactVcardPropertyBuilderTest)

### Requirement: Import maps typed vCard properties to channel arrays

When a Nextcloud contact's `EMAIL`/`TEL` properties carry `TYPE` information (the IManager search `types` option), importing that contact into a Pipelinq client or contact SHALL build `emails[]`/`phones[]` with one entry per vCard property, `kind` derived from the vCard `TYPE` (falling back to `other` when no mapping applies), and the first entry marked `primary`. The legacy scalar `email`/`phone` field SHALL be set from the primary entry's value, matching the array. An `X-SOCIALPROFILE` property SHALL be imported into `socialProfiles[]`, with `network` matched case-insensitively against the network enum (falling back to `other`) and the value stored as `url` when it looks like a URL, else as `handle`.

#### Scenario: Multiple typed EMAIL/TEL import as typed arrays

- **GIVEN** a Nextcloud contact with `EMAIL: [{type: "WORK", value: "a@x.test"}, {type: "HOME", value: "b@x.test"}]`
- **WHEN** the contact is imported into Pipelinq
- **THEN** the created object's `emails[]` SHALL be `[{kind: "work", value: "a@x.test", primary: true, verified: false}, {kind: "private", value: "b@x.test", primary: false, verified: false}]`
- **AND** the legacy `email` field SHALL be `"a@x.test"`

#### Scenario: An untyped or unmapped TYPE imports as kind "other"

- **GIVEN** a Nextcloud contact with a `TEL` property carrying no `TYPE` parameter
- **WHEN** the contact is imported into Pipelinq
- **THEN** the imported `phones[]` entry's `kind` SHALL be `"other"`

@e2e exclude vCard property parsing is a PHP service method (ContactDataBuilder) consumed by the import API, not directly rendered; covered by PHPUnit

### Requirement: Existing records backfill channel arrays on upgrade

On upgrade, every existing `client` and `contact` object whose `emails[]`/`phones[]` array is missing or empty SHALL have that array seeded from its legacy scalar `email`/`phone` field, as a single entry with `kind: "work"`, `primary: true` and `verified: false`. A record whose array is already non-empty SHALL NOT be modified. The backfill SHALL be idempotent: running it again SHALL make no further change.

#### Scenario: A legacy record gets its arrays seeded

- **GIVEN** a client with `email: "info@acme.example"` and no `emails` property
- **WHEN** the backfill repair step runs
- **THEN** the client's `emails[]` SHALL become `[{kind: "work", value: "info@acme.example", primary: true, verified: false}]`

#### Scenario: A record with existing arrays is left untouched

- **GIVEN** a client whose `emails[]` already has one or more entries
- **WHEN** the backfill repair step runs
- **THEN** the client's `emails[]` SHALL remain exactly as it was, and no save SHALL occur for that field

@e2e exclude an upgrade repair step (IRepairStep) runs during app install/update, outside any user-driven flow a browser session can trigger; covered by PHPUnit (BackfillContactChannelArraysTest)
