# Parties: the language we write in

Delta over `specs/parties`. Closes ledger row 5.17, "Preferred correspondence
language per party, honoured by templates".

## ADDED Requirements

### Requirement: A party carries the language it asked to be written in (REQ-PCL-001)

A party record MUST be able to hold a `correspondenceLanguage`, a BCP 47 language tag,
administered as one of the party's typed fields. It MUST be optional and unset by
default. The app MUST NOT infer it from a name, an address, a nationality or any other
attribute.

**Feature tier**: V1

#### Scenario: A party states a preference

- **GIVEN** a party with no correspondence language
- **WHEN** an administrator sets it to `en`
- **THEN** the party record holds `correspondenceLanguage` = `en`.

#### Scenario: Nothing is guessed

- **GIVEN** a party with a Dutch name, a Dutch address and no correspondence language
- **WHEN** the party is read
- **THEN** `correspondenceLanguage` is absent
- **AND** no rule has filled it in.

### Requirement: The selectable languages are the ones the instance can render (REQ-PCL-002)

The set of values offered for `correspondenceLanguage` MUST be derived from the locales
the instance ships, not written into the schema. A write naming a tag the instance
cannot render MUST be refused, and the refusal MUST name the tag and list what is
available.

**Feature tier**: V1

#### Scenario: The picker offers only what exists

- **GIVEN** an instance shipping `nl` and `en`
- **WHEN** the correspondence language picker is opened
- **THEN** it offers `nl` and `en` and nothing else.

#### Scenario: An unrenderable tag is refused

- **GIVEN** an instance shipping `nl` and `en`
- **WHEN** a write sets `correspondenceLanguage` to `fy`
- **THEN** the write is refused
- **AND** the refusal names `fy` and lists `nl` and `en`.

### Requirement: The language to write in resolves with its reason (REQ-PCL-003)

The app MUST publish a resolver that, given a party, answers the language tag to write
in and the rule that produced it. The rules MUST be applied in this order: the party's
own preference, then the instance default, then `en`. The answer MUST always name which
of the three answered.

**Feature tier**: V1

#### Scenario: The party's own preference answers

- **GIVEN** a party with `correspondenceLanguage` = `en` on an instance defaulting to `nl`
- **WHEN** the resolver is called
- **THEN** it answers `en`
- **AND** it names the party's preference as the rule.

#### Scenario: The instance default answers, and says so

- **GIVEN** a party with no preference on an instance defaulting to `nl`
- **WHEN** the resolver is called
- **THEN** it answers `nl`
- **AND** it names the instance default as the rule, not the party.

#### Scenario: English is the floor

- **GIVEN** a party with no preference on an instance with no default
- **WHEN** the resolver is called
- **THEN** it answers `en`
- **AND** it names the fallback as the rule.

### Requirement: The preference is visible wherever the party is (REQ-PCL-004)

The correspondence language MUST be shown on the party detail surface and through the
party leaf any host object renders. An unset preference MUST render as unset, naming
what would be used instead, rather than rendering the fallback as though the party had
chosen it.

**Feature tier**: V1

#### Scenario: Unset is not dressed up as chosen

- **GIVEN** a party with no preference on an instance defaulting to `nl`
- **WHEN** the party leaf renders on a host object
- **THEN** it says no preference is recorded
- **AND** it says `nl` would be used.

### Requirement: The resolver is published and no caller reads the property directly (REQ-PCL-005)

Consuming apps MUST reach the resolver through the party's semantic type per ADR-048.
The app MUST NOT require a caller to name pipelinq, and a caller MUST NOT read
`correspondenceLanguage` off the record to answer the question itself.

**Feature tier**: V1

#### Scenario: A consumer resolves without naming the app

- **GIVEN** a document generator holding a semantic reference to a party
- **WHEN** it asks which language to render in
- **THEN** it receives the resolved tag and its reason
- **AND** the call names the semantic type, not an app id.

### Requirement: A merge does not pick a language silently (REQ-PCL-006)

When two parties merge and both carry a correspondence language, the merge MUST surface
both values and MUST require a choice before it completes. When only one side carries
one, that value MUST survive.

**Feature tier**: V1

#### Scenario: Two preferences stop the merge

- **GIVEN** two parties with `nl` and `en`
- **WHEN** they are merged
- **THEN** the merge asks which to keep
- **AND** it does not complete until one is chosen.

#### Scenario: One preference survives

- **GIVEN** two parties, one with `en` and one with none
- **WHEN** they are merged
- **THEN** the merged party holds `en`
- **AND** the merge does not ask.
