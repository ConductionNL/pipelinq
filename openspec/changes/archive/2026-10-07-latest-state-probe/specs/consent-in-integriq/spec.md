## ADDED Requirements

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
