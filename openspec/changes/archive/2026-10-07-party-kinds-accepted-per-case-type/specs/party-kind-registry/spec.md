# party-kind-registry

## ADDED Requirements

### Requirement: pipelinq SHALL hold the vocabulary of party kinds (REQ-PKR-001)

pipelinq SHALL provide a `partyKind` schema declaring one kind of party: a code, a
label, the identity shapes it may take (`naturalPerson`, `organization` or `none`),
the `partyFieldSet` it carries, `maxPerRecord` of 1 or unbounded, and an `active`
flag.

A consuming app SHALL NOT declare a party kind of its own, and SHALL NOT ship a party
kind list. A kind set inactive SHALL keep resolving on links that already carry it.

Candidate C-configuration-4 (`configuration.tsv:56`), relevance `must`, **marked a
matrix hole**: a `must` for a municipality with two or more driven passers and no row
in the corpus to hold it. Number 25 of the sweep's loudest twenty-five. Driven
passers opencase and xxllnc-zaken; xxllnc's evidence: Case type, Relaties
(`case-type-editor-anatomy.md`).

#### Scenario: One vocabulary, held once
- **WHEN** the fleet's registers are inspected for a party kind list
- **THEN** it exists in pipelinq, and no consuming app declares one
- @e2e exclude a fleet-wide inspection; covered by the register fragment and PHPUnit on the vocabulary

#### Scenario: A kind that needs no account is expressible
- **GIVEN** a kind `melder` declared with identity shape `none`
- **WHEN** it is read
- **THEN** it states that a party of this kind needs no account of any kind
- @e2e exclude covered by PHPUnit on `SeedPartyKinds`

#### Scenario: A retired kind keeps old links readable
- **GIVEN** party links carrying a kind later set inactive
- **WHEN** those links are read
- **THEN** they still resolve the kind and its label, and the kind is absent from
  the picker
- @e2e exclude covered by PHPUnit on `PartyKindRegistryService::vocabulary()`

### Requirement: A consuming app SHALL declare which kinds a record type accepts (REQ-PKR-002)

pipelinq SHALL provide a `partyKindAcceptance` binding an ordered set of party kinds
to one record type of one consuming app, named as `<app>:<schema>:<type>`. pipelinq
SHALL store and match that name as an opaque string and SHALL NOT interpret it.

The declaration is the consuming app's policy. The object is pipelinq's. pipelinq
SHALL hold no case type, no record type definition and no editor for one.

#### Scenario: A subsidy and a Woo request declare different sets
- **GIVEN** a consuming app declaring `aanvrager` and `gemachtigde` for its subsidy
  type, and `verzoeker` for its Woo type
- **WHEN** each declaration is read
- **THEN** each names only its own kinds, in the order declared
- e2e: `tests/e2e/party-kinds.spec.ts`

#### Scenario: pipelinq does not learn what a case type is
- **WHEN** pipelinq's register is inspected
- **THEN** it holds no case type object, and the acceptance target is a string
- @e2e exclude a register inspection; covered by the fragment and PHPUnit on the opaque match

### Requirement: The picker SHALL offer only the declared kinds, in the declared order (REQ-PKR-003)

The party picker SHALL read the acceptance for the record type it was opened on and
SHALL offer exactly those kinds, in the declared order. Where no acceptance exists
for that record type, the picker SHALL offer every active kind, so an app that has
declared nothing keeps working.

The first kind in the declared order is what most handlers will take, so the order is
part of the declaration and SHALL NOT be re-sorted for display.

#### Scenario: A subsidy case offers two kinds and not eleven
- **GIVEN** a record type declaring `aanvrager` and `gemachtigde`
- **WHEN** the picker opens on a record of that type
- **THEN** it offers those two, in that order
- e2e: `tests/e2e/party-kinds.spec.ts`

#### Scenario: An undeclared record type still works
- **GIVEN** a record type with no acceptance declared
- **WHEN** the picker opens on it
- **THEN** every active kind is offered
- e2e: `tests/e2e/party-kinds.spec.ts`

### Requirement: A party link with an unaccepted kind SHALL be refused on the write (REQ-PKR-004)

Linking a party to a record under a kind the record's type does not accept SHALL be
refused, naming the kind and the record type. The refusal SHALL apply to every write
path: the picker, an import, an API call and a flow.

Where no acceptance is declared for a record type, no kind SHALL be refused for it.

#### Scenario: An API caller cannot go around the picker
- **GIVEN** a record type accepting only `aanvrager` and `gemachtigde`
- **WHEN** a `vergunninghouder` link is written through the API
- **THEN** the write is refused, naming `vergunninghouder` and the record type
- e2e: `tests/e2e/party-kinds.spec.ts`

#### Scenario: An import is judged by the same rule
- **GIVEN** the same record type and a bulk import containing an unaccepted kind
- **WHEN** the import runs
- **THEN** that row is refused with the same message, and the accepted rows land
- @e2e exclude covered by PHPUnit on `PartyLinkService::import()`

### Requirement: A kind declared single SHALL refuse a second holder on one record (REQ-PKR-005)

A `partyKind` with `maxPerRecord` of 1 SHALL refuse a second party link of that kind
on one record, naming the party that already holds it. Replacing SHALL be an explicit
act: end the existing link, then write the new one.

A kind with unbounded cardinality SHALL accept as many as are written.

#### Scenario: One aanvrager
- **GIVEN** a record already holding an `aanvrager`
- **WHEN** a second `aanvrager` is linked
- **THEN** the write is refused and names the party already holding it
- e2e: `tests/e2e/party-kinds.spec.ts`

#### Scenario: Many belanghebbenden
- **GIVEN** a kind `belanghebbende` with unbounded cardinality
- **WHEN** four are linked to one record
- **THEN** all four are accepted
- @e2e exclude covered by PHPUnit on the unbounded kind

### Requirement: The account-less party SHALL be declarable here and built elsewhere (REQ-PKR-006)

A `partyKind` with identity shape `none` SHALL declare that a party of that kind
needs no account. Making such a party notifiable is not this capability's: it belongs
to the notification dialect openregister owns.

This is recorded as a requirement rather than left out, so the boundary is findable.
Candidate C-parties-and-contacts-3 (`parties-and-contacts.tsv:10`) is a separate
`must` and a separate matrix hole, driven passers glpi (anonymous actors,
`src/CommonITILActor.php:68-76`, `users_id = 0` plus `alternative_email`) and otobo.

#### Scenario: The declaration exists and the notification does not live here
- **GIVEN** a kind with identity shape `none`
- **WHEN** pipelinq is inspected for a notification path to a party with no account
- **THEN** none exists in pipelinq, and the kind still declares that no account is
  needed
- @e2e exclude a code inspection; pipelinq ships no notification path to an account-less party
