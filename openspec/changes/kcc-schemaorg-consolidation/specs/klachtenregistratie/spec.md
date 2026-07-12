# Klachtenregistratie — Delta Spec (kcc-schemaorg-consolidation)

The `complaint` schema is re-typed to `schema:CommunicateAction` and absorbs the Awb chapter-9 lifecycle, deadline and hearing fields that currently live only in Procest's Dutch-fielded `complaint` — with English property names, per ADR-001.

---

## MODIFIED Requirements

### Requirement: Complaint Schema in Register

The system MUST define a `complaint` schema in the Pipelinq register configuration with all required fields for complaint registration.

The schema MUST carry the schema-level marker `"x-schema-org": "schema:CommunicateAction"`. It MUST NOT carry `schema:Message` (the value declared today) and MUST NOT reference `schema:ComplainAction` (which the current schema description and this spec previously claimed, and which schema.org does not define). A complaint is an inbound communication with a subject and a handler — the same schema.org family as `contactmoment`, differing in what it is `about`.

All property names MUST be English. The Dutch property names carried by Procest's `complaint` (`klachtnummer`, `klager`, `onderwerp`, `omschrijving`, `ontvangstdatum`, `ontvangstkanaal`, `categorie`, `betrokkenMedewerker`, `behandelaar`, `prioriteit`, `ontvangstbevestigingDeadline`, `afhandelDeadline`, `verdagingMogelijk`, `verdagingJustificatie`, `geescaleerdeZaak`, `hoorgespreksWaiver`) MUST NOT be stored. Dutch and Awb-facing field names MUST be produced by the mapping layer at the controller boundary.

#### Scenario: Schema includes all required properties

- GIVEN the Pipelinq register configuration
- WHEN the complaint schema is loaded
- THEN it MUST include:
  - `title` (string, required, max 255 chars)
  - `description` (string, detailed complaint text)
  - `complaintNumber` (string, readOnly, auto-generated as `KL-{year}-{sequence}`)
  - `category` (uuid, **reference to `complaintCategory`**, required; facetable)
  - `priority` (enum: low, normal, high, urgent; default: normal; facetable)
  - `status` (enum: new, acknowledged, in_progress, hearing_scheduled, hearing_completed, resolved, rejected, withdrawn; default: new; facetable)
  - `channel` (enum: phone, email, web, counter, letter, social, other; facetable)
  - `client` (uuid, reference to client)
  - `contact` (uuid, reference to contact person — the complainant; replaces the embedded `klager` object)
  - `assignedTo` (string, Nextcloud user ID of assigned agent)
  - `receivedAt` (date, date the complaint was received; the Awb deadline-calculation basis)
  - `acknowledgementDeadline` (date, Awb acknowledgement deadline — 5 working days after `receivedAt`)
  - `slaDeadline` (date-time, Awb resolution deadline — 6 weeks after `receivedAt`, or the category SLA override)
  - `extensionAvailable` (boolean, default true — whether the single permitted 4-week Awb extension is still available)
  - `extensionJustification` (string, written justification when the extension is taken)
  - `subjectEmployee` (string, Nextcloud user ID of the employee the complaint concerns)
  - `subjectDepartment` (string, department the complaint concerns)
  - `escalatedCase` (uuid, reference to the formal case created on escalation)
  - `hearingWaiver` (object: `date`, `method` (enum: email, letter, phone), `confirmation`)
  - `resolvedAt` (date-time, set when status moves to resolved/rejected)
  - `resolution` (string, explanation of resolution)

#### Scenario: Schema declares the CommunicateAction type

- GIVEN the Pipelinq register configuration
- WHEN the complaint schema is loaded
- THEN the schema-level `x-schema-org` marker MUST equal `schema:CommunicateAction`
- AND the schema MUST NOT declare `schema:Message` or `schema:ComplainAction`
- AND the marker MUST be a sibling of `title` and `properties`, not nested inside `@type` or `x-schema-org-type`
- AND an object of type `complaint` MUST resolve to JSON-LD `@type: CommunicateAction`

#### Scenario: Awb lifecycle is declared, not coded

- GIVEN the Pipelinq register configuration
- WHEN the complaint schema is loaded
- THEN it MUST declare `x-openregister-lifecycle` transitions covering: new → acknowledged → in_progress → hearing_scheduled → hearing_completed → resolved, the direct in_progress → resolved path, and `* → withdrawn`
- AND no PHP state-machine service SHALL be introduced in Pipelinq to enforce these transitions

#### Scenario: Complaint categories are objects, not an enum

- GIVEN a complaint is being categorised
- WHEN `category` is set
- THEN it MUST reference a `complaintCategory` object by uuid
- AND `complaintCategory` MUST carry `name`, `description`, `defaultHandler`, `slaOverride` (working days) and `isActive`
- AND the five previously-enumerated values (service, product, communication, billing, other) MUST be seeded as `complaintCategory` objects with matching slugs, so existing complaint rows resolve

#### Scenario: Store initialization registers complaint type

- GIVEN the app settings include a `complaint_schema` config key
- WHEN `initializeStores()` runs
- THEN the object store MUST register `complaint` as a known type
- AND CRUD operations MUST work via `objectStore.saveObject('complaint', data)`

## ADDED Requirements

### Requirement: Complaint disposition and hearing are first-class objects

The formal disposition (`oordeel`) that closes a complaint under Awb chapter 9, and the hearing (`hoorgesprek`) held before it, MUST be modelled as their own schema.org-typed schemas in the Pipelinq register with English property names.

#### Scenario: Disposition schema exists

- **WHEN** the Pipelinq register is loaded
- **THEN** a `complaintDisposition` schema MUST exist, typed `schema:Review`
- **AND** it MUST carry `complaint` (uuid reference, `onDelete: CASCADE`), `judgment` (enum: upheld, partially_upheld, dismissed, withdrawn, inadmissible), `explanation` (required when judgment is upheld or partially_upheld), `measures` (array of `{description, responsible}`), `closedAt`, `closingLetter`, `approvedBy` and `approvalStatus` (enum: pending_approval, approved, rejected)

#### Scenario: Hearing schema exists

- **WHEN** the Pipelinq register is loaded
- **THEN** a `hearing` schema MUST exist, typed `schema:Event`
- **AND** it MUST reference the parent `complaint`

#### Scenario: Dutch Awb field names are never stored

- **WHEN** a disposition is persisted
- **THEN** no property named `oordeel`, `toelichting`, `maatregelen`, `afsluitdatum`, `afsluitbrief`, `goedkeurder` or `goedkeuringStatus` SHALL exist on the stored object
- **AND** those names MUST be produced by the mapping layer only when an Awb-facing API is served
