# consent-in-integriq (delta)

## ADDED Requirements

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
