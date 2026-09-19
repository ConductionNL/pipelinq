# Contact moments: one record, several cases

Delta over `specs/contactmomenten`. Closes the contact moment half of ledger row 6.25,
"One contact moment or document filed onto several cases without copying". It modifies
REQ-CMD-002, which `contact-moments-on-pipelinq-schema` writes as a single ADR-048 semantic
reference, and widens it to a set. The header is kept identical so the two deltas merge
in order rather than leaving two requirements about the same property.

## MODIFIED Requirements

### Requirement: A contact moment references a case semantically (REQ-CMD-002)

A contact moment MUST hold an ordered set of ADR-048 semantic references to the `case`
type. The set MUST hold at least one entry and MUST NOT hold the same reference twice.
An existing single value MUST migrate to a one-element set without loss, and the
migration MUST be idempotent. The `request` facet MUST keep the single reference.

The set is carried by `ticket.caseReferences` rather than by widening
`ticket.caseReference` in place. `caseReference` is ONE property on ONE schema that
three facets share, and `request` must keep it a single string, so a property that is
a string for one facet and an array for another cannot be declared. On a contact
moment `caseReference` therefore stays readable as the primary reference and is kept
in step with it on every write, so every existing reader keeps working and no
consumer has to learn two shapes at once.

**Feature tier**: V1

#### Scenario: One call about three cases is one record

- **GIVEN** a contact moment recording one telephone call
- **WHEN** it is filed on three cases
- **THEN** one contact moment exists
- **AND** its `caseReferences` set holds the three references.
- e2e: `tests/e2e/contact-moment-several-cases.spec.ts`

#### Scenario: An existing contact moment migrates without loss

- **GIVEN** a contact moment whose `caseReference` is a single reference
- **WHEN** the migration runs
- **THEN** its `caseReferences` is a set holding that one reference
- **AND** running the migration again changes nothing.
- @e2e exclude repair step; covered by PHPUnit on `WidenContactMomentCaseReference`

#### Scenario: A duplicate reference is refused

- **GIVEN** a contact moment already filed on a case
- **WHEN** a write adds the same reference again
- **THEN** the write is refused
- **AND** the set is unchanged.
- @e2e exclude server validation; covered by PHPUnit on `TicketService::save()`

## ADDED Requirements

### Requirement: One reference is the primary one, and it is named (REQ-CMS-002)

A contact moment MUST carry `primaryCaseReference`, and its value MUST be a member of
its `caseReferences` set. A surface that can show only one case MUST show the primary.
The app MUST NOT answer that question by position in the set.

**Feature tier**: V1

#### Scenario: The primary survives a reorder

- **GIVEN** a contact moment on three cases with the second as primary
- **WHEN** the set is reordered
- **THEN** the primary is still the same case.
- @e2e exclude a reorder is a write shape, not a surface; covered by PHPUnit on `ContactMomentFilingService::primaryOf()`

#### Scenario: A primary outside the set is refused

- **GIVEN** a contact moment on cases A and B
- **WHEN** a write sets the primary to case C
- **THEN** the write is refused.
- @e2e exclude server validation; covered by PHPUnit on `TicketService::save()`

### Requirement: A case lists the contact moments it is a member of (REQ-CMS-003)

The contact moments leaf MUST answer, for a host case, every contact moment whose
`caseReferences` set contains that case. The query MUST be bounded per ADR-058 and MUST
NOT scan every ticket.

**Feature tier**: V1

#### Scenario: Each of three cases sees the one call

- **GIVEN** one contact moment filed on cases A, B and C
- **WHEN** the leaf renders on case B
- **THEN** it lists that contact moment once.
- e2e: `tests/e2e/contact-moment-several-cases.spec.ts`

### Requirement: Filing onto a further case is an act, not a copy (REQ-CMS-004)

The app MUST offer an act that appends a case reference to an existing contact moment,
and it MUST record who performed it and when. The act MUST NOT create a second contact
moment and MUST NOT alter the contact moment's content.

**Feature tier**: V1

#### Scenario: Filing a call onto a second case creates no second record

- **GIVEN** a contact moment on case A
- **WHEN** a handler files it onto case B
- **THEN** the number of contact moments is unchanged
- **AND** the set holds A and B
- **AND** the append names the handler and the moment it happened.
- e2e: `tests/e2e/contact-moment-several-cases.spec.ts`

### Requirement: A shared contact moment says so before it is edited (REQ-CMS-005)

Where a contact moment is on more than one case, the surface rendering it MUST say so
and MUST name the other cases. A case the reader is not permitted to see MUST be
reported as a count rather than by title.

**Feature tier**: V1

#### Scenario: The reader is warned before editing

- **GIVEN** a contact moment on cases A and B
- **WHEN** a handler opens it from case A
- **THEN** the surface says it is also on case B.
- e2e: `tests/e2e/contact-moment-several-cases.spec.ts`

#### Scenario: A case the reader may not see is counted, not named

- **GIVEN** a contact moment on case A and on case B, which the reader may not see
- **WHEN** the handler opens it from case A
- **THEN** the surface says it is also on one other case
- **AND** it does not render case B's title.
- @e2e exclude a second reader is needed; covered by PHPUnit on `ContactMomentFilingService::sharedMarker()`

### Requirement: A contact moment cannot be left with no case (REQ-CMS-006)

The act that removes a case reference MUST refuse when it would empty the set, and MUST
refuse to remove the primary unless the same call names a new primary from the
remaining members.

**Feature tier**: V1

#### Scenario: The last reference cannot be removed

- **GIVEN** a contact moment on one case
- **WHEN** a handler tries to unfile it
- **THEN** the act is refused
- **AND** the refusal says a contact moment has to stay on at least one case.
- e2e: `tests/e2e/contact-moment-several-cases.spec.ts`

#### Scenario: Removing the primary requires naming the next one

- **GIVEN** a contact moment on cases A and B with A as primary
- **WHEN** a handler unfiles A without naming a new primary
- **THEN** the act is refused
- **AND** the same call naming B as primary succeeds.
- @e2e exclude two acts in one call; covered by PHPUnit on `ContactMomentFilingService::unfileFromCase()`
