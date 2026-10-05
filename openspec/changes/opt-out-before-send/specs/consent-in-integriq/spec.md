## ADDED Requirements

### Requirement: pipelinq's consent records are migrated into integriq once (REQ-CII-001)

On upgrade, pipelinq MUST migrate every `messagingConsentRecord` and `consentRecord` into integriq through `OptOutChangeRequestedEvent`, with the pipelinq object UUID as `legacyRef`. It MUST follow the mapping in ConductionNL/hydra `openspec/changes/opt-out-before-send/design.md` section 7. Withdrawals for `bounce-hard` and `bounce-soft-x5` MUST NOT be migrated. A second run MUST write nothing new. pipelinq MUST switch `consent.store` to `integriq` only when no record was refused. Feature tier: V1. This implements hydra REQ-CMO-007. Approved by Ruben on 2026-10-05.

#### Scenario: A STOP from before the migration is honoured by other apps

- **GIVEN** a contact whose latest `messagingConsentRecord` on `sms` is `opted-out`
- **WHEN** the migration runs
- **THEN** integriq holds an `opted-out` row for the contact's number on `sms`
- **AND** a later SMS from integriq to that number is refused
- @e2e exclude repair step path, covered by PHPUnit

#### Scenario: A list consent is migrated as a list consent

- **GIVEN** a `consentRecord` with `listId` `nieuws`, lawful basis `consent`, not withdrawn
- **WHEN** the migration runs
- **THEN** integriq holds an `opted-in` row with scope `list` and ref `nieuws`
- @e2e exclude repair step path, covered by PHPUnit

#### Scenario: A bounce stays in pipelinq

- **GIVEN** a `consentRecord` withdrawn with reason `bounce-hard`
- **WHEN** the migration runs
- **THEN** integriq gains no row for it
- **AND** pipelinq still excludes the contact from email blasts
- @e2e exclude repair step path, covered by PHPUnit

#### Scenario: The migration runs twice

- **GIVEN** the migration ran and the flag reads `integriq`
- **WHEN** it runs again
- **THEN** integriq's table gains no rows
- @e2e exclude repair step path, covered by PHPUnit

#### Scenario: integriq is missing at upgrade

- **GIVEN** an instance without integriq
- **WHEN** pipelinq upgrades
- **THEN** nothing is migrated and `consent.store` stays `pipelinq`
- @e2e exclude needs an instance without integriq, covered by PHPUnit

### Requirement: pipelinq asks integriq before every non-exempt message (REQ-CII-002)

After the cutover, pipelinq MUST decide every SMS, WhatsApp message, blast, journey send, appointment mail and Berichtenbox email fallback through `OutboundSendDecisionRequestedEvent`. A marketing send MUST set `requiresConsent`. A WhatsApp business-initiated send MUST set `requiresConsent`. An appointment mail MUST use category `reminder`. Dunning suppression MUST still apply after integriq allows a promotional send. Without an answer from integriq, pipelinq MUST refuse the message with `authority-unavailable`, except account and security mail.

#### Scenario: A blast skips a contact who opted out elsewhere

- **GIVEN** a contact who followed a dossiq case mail's unsubscribe link with "stop everything"
- **AND** the contact has a marketing consent in integriq
- **WHEN** pipelinq resolves a blast audience that includes the contact
- **THEN** the contact is not in the audience
- @e2e exclude blast resolution, covered by PHPUnit

#### Scenario: An appointment reminder to an opted-out contact is not sent

- **GIVEN** a contact with an instance-wide opt-out in integriq
- **WHEN** the reminder job runs for the contact's booking
- **THEN** no mail is sent
- **AND** the booking shows the reminder as not sent, with the reason
- @e2e exclude background job path, covered by PHPUnit

#### Scenario: A password reset is still sent without integriq

- **GIVEN** an instance without integriq
- **WHEN** a portal user asks for a password reset
- **THEN** the mail is sent
- @e2e exclude needs an instance without integriq, covered by PHPUnit

#### Scenario: A late payer is still suppressed

- **GIVEN** integriq allows a promotional email to a contact
- **AND** the contact is in a suppressing dunning state
- **WHEN** pipelinq checks the send
- **THEN** the send is refused with `suppressed_dunning`
- @e2e exclude backend check, covered by PHPUnit

### Requirement: pipelinq writes every wish to integriq (REQ-CII-003)

After the cutover, every opt-out and consent pipelinq records MUST go to integriq through `OptOutChangeRequestedEvent`. This covers STOP and START keywords, the consent buttons on a contact, a confirmed list subscription, the preference centre and a withdrawal by unsubscribe, complaint or admin removal. pipelinq MUST NOT write `messagingConsentRecord` or `consentRecord` objects after the cutover, except as a fallback when integriq refuses a write. A fallback write MUST be replayed to integriq.

#### Scenario: STOP by SMS reaches integriq

- **WHEN** a contact sends `STOP` to pipelinq's SMS number
- **THEN** integriq holds an `opted-out` row for that number on `sms` with source `keyword-stop`
- **AND** pipelinq wrote no `messagingConsentRecord`
- @e2e exclude inbound webhook path, covered by PHPUnit

#### Scenario: A one-click list unsubscribe reaches integriq

- **WHEN** a mail provider posts to pipelinq's list unsubscribe URL
- **THEN** integriq holds an `opted-out` row with scope `list` for that address and list
- @e2e exclude provider-side POST, covered by PHPUnit

#### Scenario: A STOP is not lost when integriq refuses

- **GIVEN** integriq refuses a write
- **WHEN** a contact sends `STOP`
- **THEN** pipelinq writes the opt-out to its own store
- **AND** a background job replays it to integriq when integriq answers again
- @e2e exclude fault injection, covered by PHPUnit

### Requirement: Every non-exempt pipelinq mail carries an unsubscribe link (REQ-CII-004)

A segment blast, an appointment mail and a fallback mail MUST carry integriq's unsubscribe link in the body. A list blast MUST keep pipelinq's own list link, whose POST writes to integriq. Every such mail MUST carry `List-Unsubscribe` and `List-Unsubscribe-Post` when the transport can set headers.

#### Scenario: A segment blast has a link

- **WHEN** pipelinq sends a segment blast email
- **THEN** `{{unsubscribe_link}}` resolves to integriq's link and is not empty
- @e2e exclude mail rendering, covered by PHPUnit
