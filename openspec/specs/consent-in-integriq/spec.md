# consent-in-integriq Specification

## Purpose
Keeps integriq's consent log a record of real checks. When pipelinq only shows a contact's consent state it asks integriq as a probe, which writes no log row; an ask that comes before a send is never a probe.

## Requirements

### Requirement: latestState() asks integriq as a probe (REQ-CII-007)

pipelinq MUST ask integriq with `probe: true` when it only shows a consent state, as `ConsentService::latestState()` does. pipelinq MUST NOT pass `probe` on any ask that comes before a send.

#### Scenario: Showing a contact's SMS consent writes no log row

- **GIVEN** the consent store is integriq and the contact opted out of SMS
- **WHEN** a user opens the contact's messaging consent
- **THEN** pipelinq shows `opted-out`
- **AND** integriq's opt-out log has no new entry
- @e2e exclude cross-app event path, covered by PHPUnit and the live proof

#### Scenario: A send is still logged

- **GIVEN** the same contact
- **WHEN** pipelinq checks whether it may send an SMS
- **THEN** the ask is not a probe
- **AND** integriq's opt-out log gains a `suppressed` entry
- @e2e exclude cross-app event path, covered by PHPUnit and the live proof

### Requirement: A soft delete erases the contact in integriq (REQ-CII-008)

When OpenRegister moves a contact or client into the trash, pipelinq MUST run the erasure of REQ-CII-005 at that moment, not only when the trash is purged. OpenRegister reports the soft delete as an `ObjectUpdatedEvent` whose new object carries deletion metadata and whose old object does not. The purge MUST still run the erasure and MUST change nothing more when the soft delete already did. An ordinary edit, a restore and an edit inside the trash MUST NOT erase. A restore does not bring back the contact link or the evidence. Approved by Ruben on 2026-10-07.

#### Scenario: A soft delete erases the contact link and the evidence

- **GIVEN** a contact who opted out of email, with a contact link and evidence in integriq
- **WHEN** the contact is deleted through the OpenRegister API
- **THEN** integriq keeps the opt-out and clears the contact link and the evidence
- **AND** pipelinq's consent history for the contact is deleted
- @e2e exclude event path, covered by PHPUnit and the live check in the PR

#### Scenario: The purge after a soft delete changes nothing more

- **GIVEN** a contact that was soft deleted and erased
- **WHEN** the trash is purged
- **THEN** no integriq row and no pipelinq record changes
- @e2e exclude event path, covered by PHPUnit and the live check in the PR

#### Scenario: A restore does not erase and does not bring the evidence back

- **GIVEN** a soft deleted contact
- **WHEN** it is restored
- **THEN** pipelinq erases nothing more and integriq still holds the opt-out without a contact link or evidence
- @e2e exclude event path, covered by PHPUnit
