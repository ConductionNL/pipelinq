# Register Configuration — Delta Spec

## Purpose
Define the Pipelinq register configuration file and repair step that initializes 5 schemas in OpenRegister on app install.

**Main spec ref**: [openregister-integration/spec.md](../../../../specs/openregister-integration/spec.md)
**Feature tier**: MVP

---

## Requirements

### REQ-RC-001: JSON Configuration File

The system MUST define its register and all schemas in `lib/Settings/pipelinq_register.json` following the OpenAPI 3.0.0 format.

#### Scenario: Configuration file defines the pipelinq register with 5 schemas

- GIVEN the Pipelinq app source code
- THEN the file `lib/Settings/pipelinq_register.json` MUST exist
- AND it MUST be valid JSON conforming to OpenAPI 3.0.0 format
- AND it MUST define a register with slug `pipelinq`
- AND it MUST define exactly 5 schemas: `client`, `contact`, `lead`, `request`, `pipeline`

#### Scenario: Client schema defines Schema.org Person/Organization properties

- GIVEN the `client` schema in `pipelinq_register.json`
- THEN it MUST define:
  - `name` (string, required, max 255) — schema:name
  - `type` (string, required, enum: person, organization) — maps to schema:Person / schema:Organization
  - `email` (string, format: email, optional) — schema:email / vCard EMAIL
  - `phone` (string, optional) — schema:telephone / vCard TEL
  - `address` (string, optional) — schema:address
  - `website` (string, format: uri, optional) — schema:url
  - `industry` (string, optional) — schema:industry
  - `notes` (string, optional) — schema:description
- AND it MUST include `@type` annotation: `schema:Person` or `schema:Organization`

#### Scenario: Contact schema defines vCard-aligned contact person properties

- GIVEN the `contact` schema in `pipelinq_register.json`
- THEN it MUST define:
  - `name` (string, required) — vCard FN
  - `email` (string, format: email, optional) — vCard EMAIL
  - `phone` (string, optional) — vCard TEL
  - `role` (string, optional) — vCard ROLE
  - `client` (string, format: uuid, optional) — reference to client object

#### Scenario: Lead schema defines opportunity tracking properties

- GIVEN the `lead` schema in `pipelinq_register.json`
- THEN it MUST define:
  - `title` (string, required, max 255) — schema:name
  - `client` (string, format: uuid, optional) — reference to client
  - `contact` (string, format: uuid, optional) — reference to contact
  - `source` (string, optional) — lead origin (website, referral, cold-call, etc.)
  - `value` (number, optional, default: 0) — schema:price / estimated deal value
  - `probability` (integer, optional, min: 0, max: 100) — win probability percentage
  - `expectedCloseDate` (string, format: date, optional)
  - `assignee` (string, optional) — Nextcloud user UID
  - `priority` (string, enum: low, normal, high, urgent, default: normal)
  - `pipeline` (string, format: uuid, optional) — reference to pipeline
  - `stage` (string, optional) — current pipeline stage name
  - `stageOrder` (integer, optional) — current stage position
  - `notes` (string, optional)
  - `status` (string, enum: open, won, lost, default: open)
- AND it MUST include `@type` annotation: `schema:Demand`

#### Scenario: Request schema defines service request properties

- GIVEN the `request` schema in `pipelinq_register.json`
- THEN it MUST define:
  - `title` (string, required, max 255)
  - `description` (string, optional)
  - `client` (string, format: uuid, optional)
  - `contact` (string, format: uuid, optional)
  - `status` (string, enum: new, in_progress, completed, rejected, converted, default: new)
  - `priority` (string, enum: low, normal, high, urgent, default: normal)
  - `assignee` (string, optional) — Nextcloud user UID
  - `requestedAt` (string, format: date-time, optional)
  - `category` (string, optional)
  - `pipeline` (string, format: uuid, optional)
  - `stage` (string, optional)
  - `stageOrder` (integer, optional)

#### Scenario: Pipeline schema defines stage configuration

- GIVEN the `pipeline` schema in `pipelinq_register.json`
- THEN it MUST define:
  - `title` (string, required, max 255)
  - `description` (string, optional)
  - `entityType` (string, required, enum: lead, request, both)
  - `stages` (array of objects, required) — each stage: `{ name, order, probability? }`
  - `isDefault` (boolean, default: false)
- AND it MUST include `@type` annotation: `schema:ItemList`

---

### REQ-RC-002: Repair Step Migration

The repair step MUST use `ConfigurationService::importFromApp('pipelinq')` instead of inline PHP schema arrays.

#### Scenario: First install creates register and all 5 schemas

- GIVEN Pipelinq is installed for the first time with OpenRegister available
- WHEN the repair step runs
- THEN it MUST call `ConfigurationService::importFromApp('pipelinq')`
- AND the `pipelinq` register MUST be created
- AND all 5 schemas MUST be created
- AND schema IDs MUST be stored in IAppConfig

#### Scenario: Upgrade preserves existing data

- GIVEN Pipelinq was previously installed with 3 schemas (client, request, contact)
- AND existing objects exist in those schemas
- WHEN the repair step runs during upgrade
- THEN the 2 new schemas (`lead`, `pipeline`) MUST be created
- AND existing objects MUST NOT be modified or deleted

#### Scenario: Missing OpenRegister handled gracefully

- GIVEN Pipelinq is installed but OpenRegister is NOT installed
- WHEN the repair step runs
- THEN it MUST log a warning and skip initialization
- AND it MUST NOT throw an unhandled exception

---

### REQ-RC-003: Store Registration

The frontend MUST register all 5 entity types in the Pinia object store on initialization.

#### Scenario: All entity types registered on app load

- GIVEN the Pipelinq app loads in the browser
- WHEN `initializeStores()` completes
- THEN the object store MUST have registered: `client`, `contact`, `lead`, `request`, `pipeline`
- AND each type MUST have the correct schema ID and register ID from settings

#### Scenario: Missing schema handled gracefully

- GIVEN the settings endpoint returns a register ID but no `lead_schema`
- WHEN `initializeStores()` runs
- THEN it MUST skip registering the `lead` type
- AND it MUST NOT throw an error
- AND other types (client, request, contact) MUST still be registered
