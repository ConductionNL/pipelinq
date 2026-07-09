# Request Management — Semantic Case Handoff Delta

**Spec refs**: ADR-051 + hydra change `semantic-object-handoff` (cross-app contract — verify against HEAD at apply time), ADR-048 (`ns#Vendor` declaration precedent), OR `SemanticTypeResolver` (origin/development)
**Standards**: Schema.org-style semantic kind URIs (`https://openregister.app/ns#Case`)

## MODIFIED Requirements

### Requirement: Request-to-Case Conversion [V1]

The system MUST support converting a request into a case via OpenRegister's semantic handoff engine: the "Convert to case" action resolves the installed implementer of `https://openregister.app/ns#Case` through OR's `SemanticTypeResolver` and emits the request per the `x-openregister-handoff` dialect (field mapping governed by the hydra `semantic-object-handoff` contract). The emit path MUST be kind-addressed — no hard-coded target app id. On success the request status changes to `converted` with provenance (target object UUID + implementing app id) stored in `caseReference`; on target-creation failure the request is untouched. When no installed app implements `ns#Case`, the action MUST be hidden and the endpoint MUST refuse cleanly. This requirement was previously specced as a direct Procest call with zero implementing code; the semantic mechanism is now the required implementation and finally backs the advertised "Request-to-Case Bridge".

#### Scenario: Convert request to case via semantic resolution

- **GIVEN** an installed app implementing `https://openregister.app/ns#Case` (e.g. procest)
- **WHEN** user clicks "Convert to case" on a request with status `in_progress`
- **THEN** the system MUST create the target case through OR's handoff engine with the mapped fields (title→name, description, client→subject, contact→applicant, priority, channel)
- **AND** the request status MUST change to `converted` with `caseReference = {targetUuid, implementerAppId}`
- **AND** if case creation fails, the request status MUST NOT change

#### Scenario: Action hidden without an implementer

- **GIVEN** no installed app implements `ns#Case`
- **WHEN** user opens the detail view of an `in_progress` request
- **THEN** the "Convert to case" action MUST NOT be rendered
- **AND** a direct call to the conversion endpoint MUST be refused with a not-available error, leaving the request unchanged

#### Scenario: Conversion displays case link

- **WHEN** user views a converted request
- **THEN** the system MUST display a cross-app link to the target case resolved from `caseReference`
- **AND** the status MUST show as "Converted to case"

#### Scenario: Convert from invalid status

- **WHEN** user attempts to convert a request with status `new`
- **THEN** the system MUST prevent the action indicating conversion is only available from `in_progress`
- `@e2e exclude` backend state validation; covered by PHPUnit

#### Scenario: Converted request is read-only

- **WHEN** user attempts to edit a request with status `converted`
- **THEN** the system MUST prevent modification of core fields
- **AND** the system MUST display a notice that the request has been converted
