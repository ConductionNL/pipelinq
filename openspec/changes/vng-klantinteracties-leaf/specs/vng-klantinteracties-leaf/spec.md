## ADDED Requirements

### Requirement: VNG ↔ canonical Klantinteracties mapping contract over international storage

Pipelinq SHALL bind the VNG Klantinteracties API (OpenKlant 2.x, OAS v0.8.0) to
its canonical English schema.org storage schemas as a declarative mapping layer,
without adding any VNG-shaped field to storage. The binding SHALL be shipped as a
`lib/Settings/register.d/82-vng-klantinteracties.json` fragment that references the
OpenConnector Endpoint / Mapping / Rule slugs from the `vng-klantinteracties-adapter`
change; the field-level contract SHALL use pipelinq's REAL canonical field names:
`klantcontact` ↔ `ticket` (ticketType=contactmoment: `onderwerp`→`title`,
`tekst`→`description`, `kanaal`→`channel`, `plaatsgevondenOp`→`occurredAt`);
`partij` ↔ `client` (`soortPartij`→`type` person/organization); `betrokkene` ↔
`contact`; `digitaalAdres` ↔ `contact.email` / `contact.phone` (by
`soortDigitaalAdres`); `internetaak` ↔ `task` (terugbelverzoek ↔
`task.type=terugbelverzoek` + `task.callbackPhoneNumber`); `onderwerpobject` ↔
`ticket.caseReference` / `ticket.parentTicket`;
`partij.indicatieGeheimhouding` ↔ `contact.geheimhouding`. This realises the
pipelinq principle that data storage uses international standards while Dutch
government standards are an API mapping layer (ADR-001).

@e2e exclude declarative config fragment + cross-repo mapping contract — no pipelinq UI surface; verified via the OpenConnector adapter's Newman/PHPUnit tests

#### Scenario: klantcontact maps onto the contactmoment ticket subtype
- **WHEN** a VNG `klantcontact` is created through the OpenConnector Klantinteracties endpoint
- **THEN** it is stored as a `ticket` with `ticketType=contactmoment`, `onderwerp`→`title`, `tekst`→`description`, `kanaal`→`channel`, `plaatsgevondenOp`→`occurredAt`
- **AND** no VNG-named field is added to the `ticket` schema

#### Scenario: partij maps onto client with type translation
- **WHEN** a VNG `partij` with `soortPartij=persoon` is registered
- **THEN** it is stored as a `client` with `type=person`
- **AND** `partij.indicatieGeheimhouding=true` maps to `contact.geheimhouding=true`

#### Scenario: digitaalAdres maps onto the correct contact field
- **WHEN** a `digitaalAdres` with `soortDigitaalAdres=telefoon` is attached to a betrokkene
- **THEN** its value is stored in `contact.phone` (and an email digitaalAdres in `contact.email`)

#### Scenario: terugbelverzoek internetaak maps onto a callback task
- **WHEN** a VNG `internetaak` of kind terugbelverzoek is created
- **THEN** it is stored as a `task` with `type=terugbelverzoek` and the callback number in `task.callbackPhoneNumber`

#### Scenario: the fragment references the OpenConnector adapter slugs
- **WHEN** the `82-vng-klantinteracties.json` fragment is installed
- **THEN** its binding config references the OpenConnector Endpoint / Mapping / Rule slugs (`vng-klantcontacten`, `vng-partijen`, `vng-maak-klantcontact`, `vng-avg-bsn-policy`, `vng-referentienummer`) defined by the `vng-klantinteracties-adapter` change

### Requirement: AVG BSN policy — hash-only, never raw

The leaf SHALL mandate that BSNs arriving as a VNG `partijIdentificator` are
validated (11-proef) and SHA-256-hashed via pipelinq's existing BRP flow
(`brpPersoon.bsnHash`, `bsnValidatie`) before any storage, and that a raw BSN is
NEVER persisted or reconstructed outbound. `contact.verifiedBSN` remains a boolean
flag and `contact.brpPersoonId` links to the hashed `brpPersoon`; the raw BSN value
SHALL NOT appear in any pipelinq object or log. This is a documented, intentional
deviation from VNG's raw-BSN expectation.

@e2e exclude AVG policy contract — enforced by the OpenConnector adapter's AVG Rule; verified via PHPUnit, no pipelinq UI

#### Scenario: inbound partijIdentificator BSN is hashed, never stored raw
- **WHEN** a `partij` arrives with a `partijIdentificator` of soort BSN
- **THEN** the BSN passes 11-proef, is SHA-256-hashed via the BRP flow, and only `brpPersoon.bsnHash` + `contact.verifiedBSN=true` are persisted
- **AND** no pipelinq object or log contains the raw BSN

#### Scenario: outbound partij never reconstructs a raw BSN
- **WHEN** a stored partij backed by a hashed BSN is served outbound through the VNG endpoint
- **THEN** the raw BSN is not reconstructed (the identifier is omitted or hash-backed), deviating intentionally from VNG's raw-BSN expectation

### Requirement: Actor-registry bridge maps a VNG actor to a Nextcloud UID

The leaf SHALL add a declarative `vngActor` schema (config only, no service class)
mapping a VNG `actor` UUID to a Nextcloud `userId`, with an `actorType` covering
medewerker, geautomatiseerdeActor and organisatorischeEenheid, so that VNG
`actor` references resolve to a pipelinq assignee (`ticket.assignee` /
`task.assigneeUserId`). Seed `vngActor` objects SHALL ship with the fragment.

@e2e exclude declarative schema + seeds — no dedicated UI surface

#### Scenario: a VNG actor resolves to a Nextcloud assignee
- **WHEN** a VNG `actor` UUID is referenced on an internetaak
- **THEN** the `vngActor` bridge resolves it to the mapped Nextcloud `userId` and the `task.assigneeUserId` is set accordingly

#### Scenario: all three VNG actor types are representable
- **WHEN** actors of type medewerker, geautomatiseerdeActor and organisatorischeEenheid are seeded
- **THEN** each is stored as a `vngActor` object with the correct `actorType` and `nextcloudUserId`

## Non-Functional Requirements

- **Performance:** actor resolution is a single OpenRegister object read; no added query fan-out.
- **Accessibility:** N/A — declarative config fragment, no browser UI.
- **Internationalization:** Dutch and English MUST be supported (hydra ADR-007); VNG field names are Dutch by contract, but canonical storage and any surfaced labels stay English-keyed.

## Acceptance Criteria

- The `82-vng-klantinteracties.json` fragment installs and references the OpenConnector adapter slugs.
- The VNG ↔ canonical mapping contract uses only real pipelinq field names and adds no VNG-shaped storage field.
- No raw BSN is ever stored or reconstructed; inbound BSNs are 11-proef-validated and hashed via the BRP flow.
- `vngActor` seeds cover all three VNG actor types and resolve to Nextcloud user ids.

## Notes

- Cross-repo dependency (not via `depends_on`): the OpenConnector change `vng-klantinteracties-adapter` owns the Endpoints/Mappings/Rules/Consumers and the generic gateway features, and MUST land first; this leaf references its frozen slugs.
- Realises the VNG-mapping claims of `omnichannel-registratie` and `contactmomenten`, and consumes the 11-proef + `bsnHash` flow of `brp-lookup`; those specs carry an OpenSpec-changes note pointing here.
- Standards: VNG Klantinteracties (OpenKlant 2.x, OAS v0.8.0), Schema.org (`CommunicateAction`, `Person`/`Organization`), AVG (doelbinding, geheimhouding, no raw BSN), pipelinq ADR-001 (international-first, Dutch mapping layer).
