## ADDED Requirements

### Requirement: Pipelinq's registers are closed to everybody outside the staff

The `pipelinq`, `pipelinq-portal` and `sla` registers SHALL grant read, create, update, delete and export only to the staff group `sales` and the system account `user:pipelinq-system` (administrators bypass OpenRegister's checks). No schema rule SHALL name `authenticated`, except the read and export of the shared vocabularies partyKind, partyFieldSet, partyIndicator, product and productCategory. The service-account grants (`pipelinq-messaging-service`, `pipelinq-portal-service`) and the `public` create on `lead` SHALL be kept.

#### Scenario: A signed-in account outside the staff reads no tickets or clients
- **GIVEN** a Nextcloud account in no group, or only in the resident group
- **WHEN** it lists `/apps/openregister/api/objects/pipelinq/ticket` or `client`
- **THEN** OpenRegister returns no objects
- @e2e exclude enforced by OpenRegister from the shipped register; PHPUnit `RegisterAccessDeclaredTest` sweeps the merged register, and the live probe in the PR body checks the instance

#### Scenario: The website form and the service accounts keep working
- **GIVEN** the closed registers
- **WHEN** an anonymous visitor submits the website form, a provider webhook writes as the messaging account, or the portal writes as the portal account
- **THEN** their grants are unchanged, and the web form intake, which writes as `pipelinq-system`, is granted through `user:pipelinq-system`
- @e2e exclude a property of the shipped register; PHPUnit `RegisterAccessDeclaredTest::testServiceAccountsAndTheWebFormKeepTheirGrants`

#### Scenario: The shared vocabularies stay readable
- **GIVEN** a signed-in account in another app (a dossiq handler, a shillinq user)
- **WHEN** that app reads party kinds or products as the account
- **THEN** it gets them, and it cannot write them
- @e2e exclude a property of the shipped register; PHPUnit `RegisterAccessDeclaredTest::testTheVocabulariesAreReadableButWrittenByTheStaff`

### Requirement: The configured CRM group is granted wherever the staff group is

When the app config `crm_group` names a group, pipelinq SHALL grant it on every register and schema verb that grants `sales`, after every import and when the setting is saved; a group that is replaced SHALL be taken out. A setting naming `authenticated`, `public`, `admin` or nothing SHALL grant nothing.

#### Scenario: The KCC group reads the tickets
- **GIVEN** `crm_group` = `zuiddrecht-kcc`
- **WHEN** the register is imported
- **THEN** every verb that grants `sales` also grants `zuiddrecht-kcc`, and the portal schemas, which grant no staff, are untouched
- @e2e exclude a write to OpenRegister's schema store; PHPUnit `RegisterAccessSyncTest::testTheSyncWritesTheRegistersAndTheirSchemas`

#### Scenario: Changing the group moves the grant
- **GIVEN** `crm_group` changes from `kcc-oud` to `kcc-nieuw`
- **WHEN** the setting is saved
- **THEN** `kcc-oud` is taken out and `kcc-nieuw` granted
- @e2e exclude PHPUnit `RegisterAccessSyncTest::testAChangedSettingReplacesThePreviousGroup`, `SettingsServiceRegisterAccessTest`

### Requirement: A configured read group reads the parties and contact moments and writes nothing

When the app config `crm_read_group` names a group, pipelinq SHALL grant it `read` and `export`, and never create, update or delete, on exactly the schemas other apps read as the signed-in user for the party panel and the contact moments: client, contact, ticket and partyIndicatorValue. A group the setting no longer names SHALL be taken out, however the setting changed (the settings screen, an example set, `occ config:app:set`). A setting naming `authenticated`, `public`, `admin`, `sales` or nothing SHALL grant nothing. The Zuiddrecht example set SHALL set it to `dossiq-medewerkers`.

#### Scenario: A case handler reads a party and cannot change it
- **GIVEN** `crm_read_group` = `dossiq-medewerkers` and a handler in that group only
- **WHEN** the registers are synced and the handler reads a client, then tries to update it
- **THEN** OpenRegister's permission check grants the read and refuses the update, create and delete
- @e2e exclude evaluated with OpenRegister's rule semantics (a group in a verb's list grants that verb, a verb that does not list the caller refuses it, admin aside) on the block the real sync writes, in PHPUnit `RegisterAccessSyncTest::testAReadGroupMemberReadsAClientAndCannotUpdateIt`; the live probe after re-import checks the instance

#### Scenario: The read group reaches only the party and contact-moment schemas
- **GIVEN** the same setting
- **WHEN** the registers are synced
- **THEN** client, contact, ticket and partyIndicatorValue grant the group read and export, and no register and no other schema names it
- @e2e exclude PHPUnit `RegisterAccessSyncTest::testTheSyncWritesTheRegistersAndTheirSchemas`

#### Scenario: Changing the setting removes the old group
- **GIVEN** `dossiq-medewerkers` was applied
- **WHEN** the setting changes to `zaakbehandelaars`, written straight to app config as an example set does
- **THEN** the next sync (the background job, the settings save or the next import) takes `dossiq-medewerkers` out and grants `zaakbehandelaars`; while nothing changed, the job reads nothing
- @e2e exclude PHPUnit `RegisterAccessSyncTest::testAChangedReadGroupIsTakenOutWhicheverWayItChanged`, `::testNothingChangedReadsNothing`
