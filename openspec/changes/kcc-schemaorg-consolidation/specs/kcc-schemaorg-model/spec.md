# KCC Schema.org Model — Delta Spec

## Purpose

@e2e exclude declarative register-configuration spec — schema shape, schema.org markers and seed objects are asserted by PHPUnit register-fragment tests, not by a UI flow

Establish Pipelinq's register as the single canonical data model for the contact centre (KCC): schema.org-typed, English-propertied. Dutch and ZGW/VNG naming is a derived mapping layer, never a stored field. Conforms Pipelinq to ADR-001 (International First, Dutch API Mapping Layer) and retires the parallel Dutch contact-centre model currently declared in Procest.

**Schema.org types**: `CommunicateAction`, `Action`, `Schedule`, `TransferAction`, `Rating`, `Person`, `ItemList`, `DefinedTerm`
**VNG mapping**: Klantinteracties (`Contactmoment`, `InterneTaak`), Awb chapter 9 (`Klacht`) — served by the mapping layer, not stored
**Feature tier**: V1

---

## ADDED Requirements

### Requirement: Contact-centre schemas are schema.org-typed

Every contact-centre schema in the Pipelinq register that represents a schema.org thing MUST declare a schema-level `x-schema-org` marker whose value is a `schema:`-prefixed CURIE. Configuration-only schemas MUST NOT declare one.

The marker MUST be a sibling of `title` and `properties`. It MUST NOT be expressed as `@type` or `x-schema-org-type`: OpenRegister's `JsonLdContextService` reads only `implements[]`, `jsonld.type` and `x-schema-org`, and `@type` inside a schema definition is consumed by `SchemaImport`'s `DialectDetector` for inbound JSON-LD dialect detection.

#### Scenario: Each contact-centre schema carries its ratified type

- **WHEN** the Pipelinq register is loaded
- **THEN** the schema-level `x-schema-org` markers MUST be exactly:
  - `contactMoment` → `schema:CommunicateAction`
  - `complaint` → `schema:CommunicateAction`
  - `callPlan` → `schema:Schedule`
  - `callTransfer` → `schema:TransferAction`
  - `contactSentiment` → `schema:Rating`
  - `quickAction` → `schema:Action`
  - `agentProfile` → `schema:Person`
  - `task` → `schema:Action`
  - `queue` → `schema:ItemList`
  - `skill` → `schema:DefinedTerm`
  - `complaintCategory` → `schema:DefinedTerm`
  - `complaintDisposition` → `schema:Review`
  - `hearing` → `schema:Event`

#### Scenario: Routing rules carry no schema.org type

- **WHEN** the `routingRule` schema is loaded
- **THEN** it MUST NOT declare an `x-schema-org` marker
- **AND** no schema.org type SHALL be forced onto it, because it is configuration rather than a schema.org thing

#### Scenario: A bare or foreign-prefixed marker is rejected

- **WHEN** a contact-centre schema declares `x-schema-org` as a bare name (e.g. `"Person"`) or with a non-`schema:` prefix
- **THEN** the register-fragment test MUST fail
- **AND** the reason MUST be that `expandSchemaOrgMarker()` expands only `schema:`-prefixed CURIEs and absolute IRIs, and silently drops anything else

#### Scenario: No schema definition leaves a schema.org CURIE in @type

- **WHEN** any contact-centre schema definition is loaded
- **THEN** it MUST NOT contain an `@type` key holding a schema.org CURIE
- **AND** the register-fragment test MUST assert this, because `DialectDetector` would misread it as an inbound JSON-LD dialect signal

### Requirement: Contact-centre property names are English

Every property of every contact-centre schema MUST be named in English. Dutch property names MUST NOT be stored. Dutch and ZGW/VNG field names MUST be produced by the mapping layer at the controller boundary and MUST NOT appear as schema properties.

This is ADR-001's rule ("`fn` not `naam`") applied to the contact centre.

#### Scenario: No Dutch property names in the register

- **WHEN** the Pipelinq register's contact-centre schemas are loaded
- **THEN** no schema SHALL declare a property named `klachtnummer`, `klager`, `onderwerp`, `omschrijving`, `ontvangstdatum`, `ontvangstkanaal`, `categorie`, `betrokkenMedewerker`, `behandelaar`, `prioriteit`, `verdagingMogelijk`, `bellerIdentificatie`, `kccMedewerkerId`, `geidentificeerdeBurgerId`, `identificatieMethode`, `samenvatting`, `kanaal`, `richting`, `startTijd`, `eindTijd`, `duurSeconden`, `naam`, `volgorde`, `permissies`, `vereisteContext`, `actieType`, `routeringStappen`, `openingstijden`, `terugvalActie`, `medewerkerId`, `expertises`, or `doorverbindingsReden`

### Requirement: Contact-centre schema slugs are English

Every contact-centre schema slug MUST be English. Slugs MUST NOT retain Dutch spellings.

The magic table backing a schema is named `oc_openregister_table_{registerId}_{schemaId}` and objects bind to their schema by numeric id, so a slug is metadata only: renaming it MUST NOT rename a magic table, re-materialise it, or orphan any object.

#### Scenario: Every contact-centre slug is English

- **WHEN** the Pipelinq register is loaded
- **THEN** the contact-centre schema slugs MUST be `contactMoment`, `complaint`, `task`, `agentProfile`, `queue`, `skill`, `callPlan`, `callTransfer`, `contactSentiment`, `quickAction`, `routingRule`, `complaintCategory`, `complaintDisposition` and `hearing`
- **AND** no schema SHALL carry the slug `contactmoment`, `belplan`, `doorverbinding`, `klantSentiment`, `specialistBeschikbaarheid`, `kccQuickAction`, `kccAgent` or `callbackRequest`

#### Scenario: The contactMoment rename preserves the schema row

- **GIVEN** the schema formerly slugged `contactmoment` (id 4476)
- **WHEN** the register is re-imported with the slug `contactMoment`
- **THEN** exactly one schema row MUST exist for the concept, and its id MUST still be 4476
- **AND** a second schema row MUST NOT be inserted
- **AND** the import MUST NOT be run with `force=true`, which is documented to drop schema linkage
- **AND** if a second row appears, the import MUST be rolled back and the rename applied as a slug update on the existing row

#### Scenario: Every site addressing the schema by slug moves atomically

- **WHEN** the `contactmoment` → `contactMoment` rename lands
- **THEN** the register, the `70-cti.json` patch, the `$ref`s from `callTransfer` and `contactSentiment`, `ContactmomentController`, the `contactmoment_schema` settings key, the Vue object-store registration, `src/manifest.json`, the `contactmomenten` / `contactmomenten-rapportage` / `omnichannel-registratie` / `klantbeeld-360` specs, the tests and the postman collections MUST all be updated in the same commit
- **AND** no site SHALL be left addressing the old slug

### Requirement: Contact-centre enum values are English

Every enum value on every contact-centre schema MUST be English. Dutch enum values MUST NOT be stored, and MUST be produced by the mapping layer only when a Dutch-facing API is served.

Where a schema holds no rows, the enum MUST simply be declared in English. Where a schema holds rows, the value change MUST be migrated (see the migration requirement below) — an enum MUST NOT be narrowed while live rows still hold values outside it.

#### Scenario: Newly added enum values are English

- **WHEN** a schema added by this change (`callPlan`, `callTransfer`, `contactSentiment`, `quickAction`, `routingRule`, `complaintDisposition`, `hearing`) declares an enum
- **THEN** every enum value MUST be English (e.g. `positive`, `neutral`, `negative`, `angry`; `none`, `yellow`, `orange`, `red`; `voicemail`, `sms_callback`, `email_callback`)

#### Scenario: Zero-row enums are anglicised directly

- **GIVEN** `contactmoment` and `complaint` hold no objects
- **WHEN** their enums are anglicised
- **THEN** `contactmoment.channel` MUST offer `phone`, `email`, `counter`, `chat`, `social`, `letter`, `sms` — not `telefoon`, `balie` or `brief`
- **AND** `contactmoment.outcome` MUST offer `handled`, `transferred`, `callback_requested`, `follow_up`, `resolved`, `referred` — not `afgehandeld`, `doorverbonden` or `terugbelverzoek`
- **AND** `complaint.status`, `complaint.priority` and `complaint.channel` MUST offer English values only
- **AND** no object migration SHALL be required, because no objects exist

#### Scenario: Task enums are anglicised with a migration

- **GIVEN** `task` holds live objects, some carrying Dutch enum values
- **WHEN** `task.type`, `task.status` and `task.priority` are anglicised
- **THEN** the mapping MUST be `terugbelverzoek`→`callback_request`, `opvolgtaak`→`follow_up`, `informatievraag`→`information_request`; `laag`/`normaal`/`hoog`→`low`/`normal`/`high`; `in_behandeling`→`in_progress`, `afgerond`→`completed`, `verlopen`→`expired`
- **AND** the change MUST proceed as widen (accept both) → rewrite rows → narrow (drop Dutch)
- **AND** the enum MUST NOT be narrowed before every live row holds an English value

### Requirement: Object-value migrations are register-scoped and fail safe

Any migration that rewrites stored object values MUST be performed by an app Repair step driving OpenRegister's bulk writer (`SaveObjects::saveObjects()`). It MUST NOT hand-roll SQL and MUST NOT introduce a service class.

#### Scenario: Migration is scoped to Pipelinq's register

- **GIVEN** the `task` schema (id 74) is shared by the Pipelinq, Procest and Planix registers
- **WHEN** the enum-value migration runs
- **THEN** it MUST rewrite only rows where `_register` is Pipelinq's register
- **AND** it MUST NOT modify rows belonging to the Procest or Planix registers

#### Scenario: An unmapped value stops the migration

- **WHEN** the migration encounters a stored value that is not in the old→new mapping table
- **THEN** it MUST abort, reporting the offending object `_uuid` and value
- **AND** it MUST leave the enum in its widened state
- **AND** it MUST NOT guess a value, MUST NOT null the field, and MUST NOT drop the object

#### Scenario: Pre-existing drift is converged, not ignored

- **GIVEN** some live `task` rows already carry values outside the declared enum
- **WHEN** the migration runs
- **THEN** it MUST converge every row in Pipelinq's register onto a declared English value
- **AND** the enum narrow at the end MUST leave zero rows outside the enum

### Requirement: Merges into data-bearing schemas are additive

`agentProfile`, `task`, `queue` and `skill` hold live objects. Absorbing Procest's `kccAgent`, `specialistBeschikbaarheid` and `callbackRequest` into them MUST NOT remove, rename or re-type any property that existing objects populate.

#### Scenario: No property is dropped from a data-bearing schema

- **WHEN** `agentProfile` or `task` is extended
- **THEN** every property those schemas declare today MUST still be declared afterwards
- **AND** no existing property's `type` SHALL change

#### Scenario: isAvailable is retained alongside availabilityStatus

- **WHEN** `agentProfile` gains the richer `availabilityStatus` enum
- **THEN** the boolean `isAvailable` MUST be retained and marked deprecated
- **AND** it MUST NOT be removed in this change, because live objects populate it and `skill-routing` reads it

### Requirement: One contact-centre model, in Pipelinq

The contact-centre data model MUST be declared once, in the Pipelinq register. No other app's register SHALL declare a competing contact-centre schema, and no schema slug SHALL be declared by two apps' registers.

#### Scenario: The contactmoment slug collision is retired

- **WHEN** both the Pipelinq and Procest registers are installed
- **THEN** exactly one schema for the contact-moment concept SHALL exist across both registers, it SHALL be Pipelinq's, and its slug SHALL be `contactMoment`
- **AND** Procest SHALL declare no schema named `contactmoment`, `customerContact`, `belplan`, `doorverbinding`, `klantSentiment`, `specialistBeschikbaarheid`, `kccQuickAction`, `kccAgent`, `callbackRequest` or `routingRule`

#### Scenario: New contact-centre concepts extend existing schemas

- **WHEN** a contact-centre concept already has a schema.org-typed home in the Pipelinq register
- **THEN** the concept MUST be expressed by extending that schema rather than by declaring a second one
- **AND** specifically: agent availability, skills and workload MUST live on `agentProfile`; callback requests MUST live on `task` with `type: callback_request`; contact moments MUST live on `contactMoment`; complaints MUST live on `complaint`

### Requirement: Contact-centre schemas declared in a register fragment

Contact-centre schemas that Pipelinq does not already declare MUST be added in a dedicated register fragment under `lib/Settings/register.d/`, following the modular-config-fragment pattern already used by `70-cti.json` and `80-berichtenbox.json`.

#### Scenario: New schemas land in the contact-centre fragment

- **WHEN** the register fragment `lib/Settings/register.d/71-kcc-contactcentre.json` is loaded
- **THEN** it MUST declare `callPlan`, `callTransfer`, `contactSentiment`, `quickAction`, `routingRule`, `complaintCategory`, `complaintDisposition` and `hearing`
- **AND** it MUST list each of them under `components.registers.pipelinq.schemas`
- **AND** the fragment MUST be valid JSON that parses without error

#### Scenario: Existing schemas are patched, not redeclared

- **WHEN** `contactmoment`, `complaint`, `task` or `agentProfile` gains a contact-centre property
- **THEN** the property MUST be added to the existing schema definition in `lib/Settings/pipelinq_register.json`
- **AND** the schema's `version` MUST be bumped so OpenRegister's register-import version gate fires

### Requirement: Contact-centre behaviour is declarative where the engine allows

Per ADR-031, contact-centre lifecycle, notification, aggregation and processing behaviour MUST be declared as `x-openregister-*` schema metadata rather than implemented as a PHP service class, wherever OpenRegister provides a fitting extension. This change MUST NOT introduce a new service class.

#### Scenario: Complaint lifecycle is declarative

- **WHEN** the `complaint` schema is loaded
- **THEN** its Awb chapter-9 state machine MUST be declared as `x-openregister-lifecycle` transitions
- **AND** no PHP state-machine service SHALL be introduced in Pipelinq to enforce it

#### Scenario: Contact-moment read logging is declarative

- **WHEN** the `contactmoment` schema is loaded
- **THEN** AVG read-logging and attribution MUST be declared as `x-openregister-processing` (`logReads`, `attribution`, `subjectIdFields`)
- **AND** `subjectIdFields` MUST point at the English `contact` reference

#### Scenario: Notifications use the canonical dialect

- **WHEN** a contact-centre schema declares notification behaviour
- **THEN** it MUST use the canonical `x-openregister-notifications` dialect
- **AND** it MUST NOT use the obsolete legacy notification dialect

#### Scenario: Imperative behaviour is named, not smuggled

- **WHEN** contact-centre behaviour has no declarative analogue — sentiment classification over a transcript, and call-plan routing evaluation
- **THEN** it MUST remain imperative
- **AND** it MUST be out of scope for this configuration change, deferred to a chained `kind: code` change

### Requirement: Contact-centre demo data is seeded

The register MUST ship a realistic municipal contact-centre demo dataset, so that the contact centre is explorable on a fresh install and the seven demo rows currently held by Procest are not lost.

#### Scenario: Seed objects cover every contact-centre schema

- **WHEN** the Pipelinq register is imported on a fresh install
- **THEN** seed objects MUST be created for `routingRule` (3), `agentProfile` (3), `callPlan` (2), `quickAction` (5), `complaintCategory` (4), `task` of type `terugbelverzoek` (2), `contactmoment` (2), `callTransfer` (1), `contactSentiment` (1) and `complaint` (1)
- **AND** the data MUST be realistic general-organisation data for a municipal contact centre
- **AND** it MUST contain no personal data of real people and no live BSN values

#### Scenario: Re-import is idempotent

- **WHEN** the register is imported a second time
- **THEN** each seed object MUST be matched by its stable `@self.slug`
- **AND** no duplicate seed objects SHALL be created
