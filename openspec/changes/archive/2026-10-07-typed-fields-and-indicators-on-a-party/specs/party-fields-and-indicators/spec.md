# party-fields-and-indicators

## ADDED Requirements

### Requirement: A party kind SHALL carry administered typed fields (REQ-PFI-001)

pipelinq SHALL provide a `partyFieldSet` declaring, per party kind, the properties
that kind carries: a key, a label, a kind (text, number, date, boolean, choice from a
code list, or a reference), whether it is required, and its order. Values SHALL be
stored on the party's own relationship record, beside the denormalised identity
mirrors `unify-client-contact` defines, and SHALL be typed and validated by
OpenRegister rather than by an engine of pipelinq's own.

An administrator SHALL be able to add a field without a schema release. Identity
fields SHALL stay on the Nextcloud Contact, unchanged by this requirement.

Candidate C-configuration-105 (`cross-area.tsv:7`), relevance `must`, **marked a
matrix hole**: a `must` for a municipality with two or more driven passers and no row
in the corpus to hold it. Number 14 of the sweep's loudest twenty-five. Driven
passers freescout and osticket; osTicket's evidence: forms attach to more than the
case (`ost_help_topic_form`, form types U for user, O for organisation, A for asset,
L1 for a list item).

#### Scenario: A field is added without a release
- **GIVEN** an administrator who needs "vestigingsnummer" on organisations
- **WHEN** they add it to the organisation field set as a text field
- **THEN** it is offered on organisation records, and no schema file was edited to
  allow it
- @e2e exclude an administered declaration; covered by PHPUnit on `PartyLeafProvider::describe()`

#### Scenario: A typed field is validated as its type
- **GIVEN** a field declared as a date
- **WHEN** a value that is not a date is written
- **THEN** the write is refused by OpenRegister's validation, not by an app-side
  check of pipelinq's
- @e2e exclude OpenRegister's own validation; covered by the register import

#### Scenario: Identity stays on the Contact
- **WHEN** a party's name, e-mail and phone are read
- **THEN** they resolve from the Nextcloud Contact, and no field set declares them
- @e2e exclude no field set declares an identity field; covered by PHPUnit on the panel shape

### Requirement: An indicator SHALL be a declared vocabulary with dated values (REQ-PFI-002)

pipelinq SHALL provide a `partyIndicator` declaring an indicator: a code, a label, a
severity, and the effects it asserts. It SHALL provide a `partyIndicatorValue`
holding one indicator on one party, with a `validFrom`, an optional `validUntil`, and
the source that set it.

A value whose `validUntil` has passed SHALL stop applying on its own date, with no
edit. An indicator SHALL NOT be modelled as a boolean property per concern, and SHALL
NOT be modelled as a tag.

Candidate C-parties-and-contacts-9 (`parties-and-contacts.tsv:8`), relevance `must`,
**marked a matrix hole**. Number 22 of the sweep's loudest twenty-five. Driven
passers dimpact-zac (Betrokkenen, `docs/user-manual-features.md`) and itop.

#### Scenario: The source of a flag is recorded
- **GIVEN** an "overleden" value set from a BRP lookup and an
  "agressie-registratie" value set by a KCC supervisor
- **WHEN** both are read
- **THEN** each names the source that set it
- @e2e exclude covered by PHPUnit on `PartyIndicatorService::resolve()`

#### Scenario: A lifted indicator stops applying on its own date
- **GIVEN** an indicator value with a `validUntil` of yesterday
- **WHEN** the party's indicators are resolved today
- **THEN** it does not apply, and nobody edited it
- e2e: `tests/e2e/party-fields-and-indicators.spec.ts`

#### Scenario: A new concern needs no schema change
- **GIVEN** an organisation that needs "bewindvoering"
- **WHEN** an administrator declares it as an indicator
- **THEN** values can be set against it, and no property was added to the party
  schema
- @e2e exclude a declaration, not a surface; covered by PHPUnit on the vocabulary read

### Requirement: A party's indicators SHALL resolve live on every surface showing that party (REQ-PFI-003)

Every surface that shows a party SHALL resolve that party's indicators at read time.
No case, contact moment, task or message SHALL hold a copy of an indicator.

An indicator set today SHALL therefore show on work opened at any earlier date, and
an indicator lifted today SHALL stop showing everywhere at once.

#### Scenario: A flag set today reaches last year's case
- **GIVEN** a case opened in 2025 for a party
- **WHEN** an "overleden" indicator is set on that party today
- **THEN** the case surface shows it on the next read
- @e2e exclude covered by PHPUnit on the live resolution

#### Scenario: A lifted flag leaves every surface at once
- **GIVEN** a party with an indicator shown on twenty cases
- **WHEN** the indicator value is ended
- **THEN** none of the twenty shows it on the next read, and no sweep ran
- @e2e exclude covered by PHPUnit on the dated value

#### Scenario: No copies exist to go stale
- **WHEN** the register is inspected for stored indicator values
- **THEN** they exist only as `partyIndicatorValue` against a party
- @e2e exclude a storage assertion; covered by the register fragment and PHPUnit

### Requirement: pipelinq SHALL answer whether an indicator blocks an act, and SHALL NOT intercept it (REQ-PFI-004)

An indicator MAY declare `blocksOutbound`, `blocksAddressPublication` or
`requiresAcknowledgement`. pipelinq SHALL answer, for a given party and a given act,
whether the act is blocked and by which indicator. pipelinq SHALL NOT intercept a
send, a publication or a render performed by another app.

The answer SHALL name the indicator, its label and its severity, so a caller can
show a person why an act was refused.

The clause this rests on: writing to a deceased person, or publishing a protected
address, is the failure the indicator prevents. The corpus row asks only about
address protection, which is why the sweep marks the candidate a matrix hole.

#### Scenario: A send to a deceased party is refused with a reason
- **GIVEN** a party carrying an indicator asserting `blocksOutbound`
- **WHEN** a consuming app asks whether it may send to that party
- **THEN** the answer is blocked, and it names the indicator and its label
- e2e: `tests/e2e/party-fields-and-indicators.spec.ts`

#### Scenario: Publication is answered separately from sending
- **GIVEN** a party carrying only `blocksAddressPublication`
- **WHEN** a consuming app asks whether it may send to that party
- **THEN** the answer is not blocked, and a question about publishing the address is
- e2e: `tests/e2e/party-fields-and-indicators.spec.ts`

#### Scenario: pipelinq stays out of the send path
- **WHEN** pipelinq's code is inspected for outbound interception
- **THEN** no listener, middleware or hook of pipelinq's sits on another app's send
  path, and the contract is a question a caller asks
- @e2e exclude a code inspection; covered by the absence of any listener in `lib/Listener`

### Requirement: Organisations SHALL nest as a guarded tree carrying their own fields (REQ-PFI-005)

An organisation party SHALL be able to name a parent organisation. pipelinq SHALL
hold a materialised path per node, SHALL refuse a parent that would create a cycle,
and SHALL refuse a depth past an administered cap. Moving a node SHALL move its
subtree's paths in the same act.

Each node SHALL carry its own field values from the organisation field set. This is
the customer organisation. The internal organisation stays what it is, and this
requirement does not merge the two.

Candidate C-parties-and-contacts-12 (`parties-and-contacts.tsv:21`), relevance
`should`, driven passer zammad: hierarchical groups at `app/models/group.rb:27`
carrying parent, path and cycle and depth guards, and `HasObjectManagerAttributes` at
`:12`.

#### Scenario: A regional office sits under its parent and carries its own number
- **GIVEN** a customer organisation with three regional offices
- **WHEN** each office is given its own "vestigingsnummer"
- **THEN** the three values are held per node, and the parent keeps its own
- @e2e exclude covered by PHPUnit on the tree service

#### Scenario: A cycle is refused on the write
- **GIVEN** organisation A with child B
- **WHEN** A is given B as its parent
- **THEN** the write is refused
- e2e: `tests/e2e/party-fields-and-indicators.spec.ts`

#### Scenario: Moving a node moves its subtree
- **GIVEN** a node with two descendants
- **WHEN** the node is given a new parent
- **THEN** all three paths are updated in one act, and no descendant points at a
  parent that no longer holds it
- @e2e exclude covered by PHPUnit on `PartyOrganisationTreeService::setParent()`

### Requirement: A merge SHALL survive field values and SHALL union indicators (REQ-PFI-006)

When two parties are merged, field values SHALL follow the existing survivorship
rules, keeping the losing value for reversal. Indicators SHALL be **unioned**: an
indicator held by either record SHALL be held by the merged party.

An indicator SHALL NOT be resolved by picking a winner. A safety flag dropped by a
survivorship rule is silent, and silence is the failure this requirement exists to
prevent. The merge SHALL stay reversible, indicators included.

#### Scenario: A deceased flag survives a merge with a record that lacks it
- **GIVEN** two party records for one person, one carrying "overleden"
- **WHEN** they are merged
- **THEN** the merged party carries "overleden"
- @e2e exclude covered by PHPUnit on `PartyIndicatorService::unionForMerge()`

#### Scenario: Field values still follow trust tiers
- **GIVEN** the same two records with different values for one custom field
- **WHEN** they are merged
- **THEN** the winning value is chosen by source trust tier and the loser is retained
- @e2e exclude the survivorship rules are master-data-management's; unchanged here

#### Scenario: Reversing the merge restores both sides
- **GIVEN** a completed merge
- **WHEN** it is reversed
- **THEN** each record holds the field values and indicators it held before
- @e2e exclude the merge is master-data-management's; this change only requires it carries indicators

### Requirement: A consuming app SHALL read party fields and indicators through a leaf (REQ-PFI-007)

pipelinq SHALL register an OpenRegister integration leaf rendering a party's typed
fields and its indicators on a host object. A consuming app SHALL place the leaf
rather than query pipelinq's register, SHALL declare no party field of its own, and
SHALL hold no indicator.

When pipelinq is absent the leaf SHALL NOT be registered, so a host renders no party
panel rather than an empty one.

A consuming app's existing lookup against an external register MAY set an indicator
value. It SHALL NOT become a second indicator model.

#### Scenario: A case page shows the party's flags without reading the pipelinq register
- **WHEN** a consuming app places the leaf on a case detail page
- **THEN** the widget shows the party's fields and indicators, and the consuming
  app's manifest contains no query against the pipelinq register
- e2e: `tests/e2e/party-fields-and-indicators.spec.ts`

#### Scenario: A lookup sets a value and owns no vocabulary
- **GIVEN** a consuming app's BRP lookup returning that a person has died
- **WHEN** it records that fact
- **THEN** it writes a `partyIndicatorValue` against pipelinq's declared indicator,
  and declares no indicator of its own
- @e2e exclude a consuming app's write; covered on the dossiq side

#### Scenario: The surface is absent when pipelinq is
- **WHEN** the consuming app is installed and pipelinq is not
- **THEN** no party leaf is registered, and the host renders no party panel
- @e2e exclude pipelinq cannot observe its own absence; covered on the consuming side
