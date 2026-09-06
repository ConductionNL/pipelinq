---
status: done
---

# OpenRegister Integration Specification

## Purpose

@e2e exclude backend integration foundation — register schema init, Pinia store wiring, and OR API CRUD are PHP/JS infrastructure; covered by PHPUnit and unit tests

Pipelinq stores all data as OpenRegister objects -- it owns no database tables. This specification defines how the register and schemas are initialized, how the frontend and backend interact with the OpenRegister API for CRUD operations, how Pinia stores manage state, how schema validation works, how errors are handled, and how cross-entity references, audit trails, RBAC, pagination, and performance concerns are addressed. OpenRegister is the foundational layer for every Pipelinq feature.

**Standards**: OpenAPI 3.0.0 (schema format), OpenRegister API conventions
**Feature tier**: MVP (foundation for all features)

## Data Model

See [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) for full entity definitions. The following schemas are registered in the `pipelinq` register:

- `client` -- Person or organization (schema:Person / schema:Organization)
- `contact` -- Contact person linked to client (schema:ContactPoint)
- `lead` -- Sales opportunity (schema:Demand)
- `request` -- Service intake/inquiry (schema:Demand)
- `pipeline` -- Kanban board configuration with embedded stages array (schema:ItemList)
- `product` -- Product or service in the catalog (schema:Product)
- `productCategory` -- Category for grouping products (schema:DefinedTermSet)
- `leadProduct` -- Line item linking a product to a lead (schema:Offer)

Note: Stages are stored as an embedded array within the `pipeline` schema (`stages: [{ name, order, probability?, isClosed?, isWon?, color? }]`), not as a separate `stage` schema. This simplifies the data model while maintaining all stage configuration capabilities.
## Requirements

---

### Requirement: Register Configuration File

The system MUST define its register and schemas in a JSON configuration file following the OpenAPI 3.0.0 format, consistent with the pattern used by opencatalogi and softwarecatalog.

**Feature tier**: MVP

#### Scenario: Configuration file exists and is valid

- GIVEN the Pipelinq app source code
- THEN `lib/Settings/pipelinq_register.json` MUST exist
- AND it MUST be valid JSON
- AND it MUST use OpenAPI 3.0.0 format with `openapi: "3.0.0"` at the root
- AND it MUST define a register with name `pipelinq` and slug `pipelinq`

#### Scenario: All entity schemas are defined

- GIVEN the configuration file `lib/Settings/pipelinq_register.json`
- THEN it MUST define exactly 8 schemas: `client`, `contact`, `lead`, `request`, `pipeline`, `product`, `productCategory`, `leadProduct`
- AND each schema MUST include a `@type` annotation referencing the corresponding Schema.org type
- AND each schema's properties MUST match the entity definitions in ARCHITECTURE.md

#### Scenario: Client schema defines Schema.org Person/Organization properties

- GIVEN the `client` schema in `pipelinq_register.json`
- THEN it MUST define:
  - `name` (string, required, max 255) -- schema:name
  - `type` (string, required, enum: person, organization) -- maps to schema:Person / schema:Organization
  - `email` (string, format: email, optional) -- schema:email / vCard EMAIL
  - `phone` (string, optional) -- schema:telephone / vCard TEL
  - `address` (string, optional) -- schema:address
  - `website` (string, format: uri, optional) -- schema:url
  - `industry` (string, optional) -- schema:industry
  - `notes` (string, optional) -- schema:description
  - `contactsUid` (string, optional, visible: false) -- Nextcloud Contacts UID link
- AND it MUST include `@type` annotation: `schema:Person`

#### Scenario: Contact schema defines vCard-aligned contact person properties

- GIVEN the `contact` schema in `pipelinq_register.json`
- THEN it MUST define:
  - `name` (string, required) -- vCard FN
  - `email` (string, format: email, optional) -- vCard EMAIL
  - `phone` (string, optional) -- vCard TEL
  - `role` (string, optional) -- vCard ROLE
  - `client` (string, format: uuid, optional) -- reference to client object
  - `contactsUid` (string, optional, visible: false) -- Nextcloud Contacts UID link

#### Scenario: Lead schema defines opportunity tracking properties

- GIVEN the `lead` schema in `pipelinq_register.json`
- THEN it MUST define:
  - `title` (string, required, max 255) -- schema:name
  - `client` (string, format: uuid, optional) -- reference to client
  - `contact` (string, format: uuid, optional) -- reference to contact
  - `source` (string, optional) -- lead origin (website, referral, cold-call, etc.)
  - `value` (number, optional, default: 0) -- schema:price / estimated deal value
  - `probability` (integer, optional, min: 0, max: 100) -- win probability percentage
  - `expectedCloseDate` (string, format: date, optional)
  - `assignee` (string, optional) -- Nextcloud user UID
  - `priority` (string, enum: low, normal, high, urgent, default: normal)
  - `pipeline` (string, format: uuid, optional) -- reference to pipeline
  - `stage` (string, optional) -- current pipeline stage name
  - `stageOrder` (integer, optional) -- current stage position
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
  - `assignee` (string, optional) -- Nextcloud user UID
  - `requestedAt` (string, format: date-time, optional)
  - `category` (string, optional)
  - `pipeline` (string, format: uuid, optional)
  - `stage` (string, optional)
  - `stageOrder` (integer, optional)
  - `channel` (string, optional) -- intake channel (phone, email, website, counter, post)
  - `caseReference` (string, format: uuid, optional, visible: false) -- reference to converted Procest case

#### Scenario: Pipeline schema defines view-backed stage configuration

- GIVEN the `pipeline` schema in `pipelinq_register.json`
- THEN it MUST define:
  - `title` (string, required, max 255)
  - `description` (string, optional)
  - `viewId` (string, format: uuid, optional) -- reference to OpenRegister View
  - `propertyMappings` (array of objects, optional) -- per-schema column and totals config
  - `totalsLabel` (string, optional) -- display label for column totals (e.g., "EUR")
  - `stages` (array of objects, required) -- each stage: `{ name, order, probability?, isClosed?, isWon?, color? }`
  - `isDefault` (boolean, default: false)
- AND it MUST include `@type` annotation: `schema:ItemList`

#### Scenario: Product schema defines catalog item properties

- GIVEN the `product` schema in `pipelinq_register.json`
- THEN it MUST define:
  - `name` (string, required, max 255) -- schema:name
  - `description` (string, optional) -- schema:description
  - `sku` (string, optional, max 100) -- schema:sku
  - `unitPrice` (number, required, min: 0) -- schema:price
  - `cost` (number, optional, min: 0) -- for margin calculation
  - `category` (string, format: uuid, optional) -- reference to productCategory
  - `type` (string, required, enum: product, service)
  - `status` (string, enum: active, inactive, default: active)
  - `unit` (string, optional) -- unit of measure
  - `taxRate` (number, optional, min: 0, max: 100, default: 21) -- Dutch BTW default
  - `image` (string, format: uri, optional)
- AND it MUST include `@type` annotation: `schema:Product`

#### Scenario: ProductCategory schema defines hierarchical grouping

- GIVEN the `productCategory` schema in `pipelinq_register.json`
- THEN it MUST define:
  - `name` (string, required, max 255) -- schema:name
  - `description` (string, optional)
  - `parent` (string, format: uuid, optional) -- self-reference for hierarchy
  - `order` (integer, optional, default: 0) -- display order within parent level
- AND it MUST include `@type` annotation: `schema:DefinedTermSet`

#### Scenario: LeadProduct schema defines line item properties

- GIVEN the `leadProduct` schema in `pipelinq_register.json`
- THEN it MUST define:
  - `lead` (string, format: uuid, required) -- reference to parent lead
  - `product` (string, format: uuid, required) -- reference to product
  - `quantity` (number, required, min: 0.01, default: 1)
  - `unitPrice` (number, required, min: 0) -- pre-populated from product, can be overridden
  - `discount` (number, optional, min: 0, max: 100, default: 0) -- percentage discount
  - `total` (number, optional, min: 0) -- computed: quantity * unitPrice * (1 - discount/100)
  - `notes` (string, optional)
- AND it MUST include `@type` annotation: `schema:Offer`

#### Scenario: Schema references use OpenRegister conventions

- GIVEN the `contact` schema in the configuration file
- THEN the `client` property MUST be defined as a reference type pointing to the `client` schema
- AND the `lead` schema's `pipeline` property MUST reference the `pipeline` schema
- AND the `lead` schema's `client` and `contact` properties MUST reference their respective schemas
- AND the `leadProduct` schema's `lead` property MUST reference the `lead` schema
- AND the `leadProduct` schema's `product` property MUST reference the `product` schema
- AND the `product` schema's `category` property MUST reference the `productCategory` schema
- AND the `productCategory` schema's `parent` property MUST self-reference `productCategory`

#### Scenario: Facetable properties are annotated

- GIVEN the configuration file `lib/Settings/pipelinq_register.json`
- THEN all enum and categorical properties MUST include `"facetable": true`
- AND specifically: `client.type`, `client.industry`, `contact.role`, `lead.source`, `lead.assignee`, `lead.priority`, `lead.stage`, `lead.status`, `request.status`, `request.priority`, `request.assignee`, `request.category`, `request.stage`, `request.channel`, `product.category`, `product.type`, `product.status` MUST be facetable
- AND non-categorical properties (name, email, notes, etc.) MUST NOT be facetable

---

### Requirement: Register Configuration File Format Compliance

The register JSON file MUST comply with the OpenRegister `importFromApp()` contract so that the configuration service can parse and import it without errors.

**Feature tier**: MVP

#### Scenario: x-openregister metadata block is present

- GIVEN the configuration file `lib/Settings/pipelinq_register.json`
- THEN the root MUST contain an `x-openregister` object with:
  - `type`: `"application"`
  - `app`: `"pipelinq"`
  - `openregister`: version constraint string (e.g., `"^v0.2.10"`)
- AND the `x-openregister` block MUST include a human-readable `description`

#### Scenario: Register definition is under components.registers

- GIVEN the configuration file
- THEN the register MUST be defined at `components.registers.pipelinq`
- AND it MUST include: `slug`, `title`, `version`, `description`, `published` (ISO 8601), `schemas` (array of slug strings)
- AND the `schemas` array MUST list all 8 schema slugs
- AND `tablePrefix` MUST be empty string (no prefix)
- AND `folder` MUST specify the Nextcloud Files folder path for attachments

#### Scenario: Schema definitions are under components.schemas

- GIVEN the configuration file
- THEN each schema MUST be defined at `components.schemas.{slug}`
- AND each schema MUST include: `slug`, `title`, `icon`, `version`, `summary`, `description`, `@type`, `required` (array), `properties` (object)
- AND each property MUST include at minimum: `type`, `description`
- AND properties with constraints MUST include: `maxLength`, `minLength`, `minimum`, `maximum`, `enum`, `format`, `default` as appropriate

#### Scenario: View definitions are under components.views (when present)

- GIVEN the configuration file includes views
- THEN views MUST be defined under a `views` key within the `x-openregister` block or `components`
- AND each view MUST include: `slug`, `name`, `description`, `query` (with `registers` and `schemas` arrays)
- AND one view SHOULD be marked with `"isDefault": true`

#### Scenario: ConfigFileLoaderService validates JSON parsing

- GIVEN the `ConfigFileLoaderService` loads the configuration file
- WHEN the file is read from `lib/Settings/pipelinq_register.json`
- THEN `loadConfigurationFile()` MUST resolve the app path via `IAppManager::getAppPath()`
- AND it MUST throw `RuntimeException` if the file does not exist
- AND it MUST throw `RuntimeException` if `file_get_contents()` returns false
- AND it MUST throw `RuntimeException` if `json_decode()` fails
- AND it MUST return the parsed array on success

#### Scenario: ConfigFileLoaderService ensures sourceType metadata

- GIVEN configuration data loaded from the JSON file
- WHEN `ensureSourceType()` is called
- THEN it MUST add `x-openregister.sourceType` = `"local"` if not already present
- AND it MUST NOT overwrite an existing `sourceType` value

---

### Requirement: Auto-Configuration on Install (Repair Step)

The system MUST import the register configuration during app installation and upgrades via a repair step. The repair step MUST be idempotent.

**Feature tier**: MVP

#### Scenario: First install creates register and schemas

- GIVEN Pipelinq is being installed for the first time
- AND no `pipelinq` register exists in OpenRegister
- WHEN the repair step runs
- THEN it MUST call `SettingsService::loadSettings()` which delegates to `SettingsLoadService::loadSettings()`
- AND `SettingsLoadService` MUST load the JSON via `ConfigFileLoaderService::loadConfigurationFile()`
- AND `SettingsLoadService` MUST ensure sourceType via `ConfigFileLoaderService::ensureSourceType()`
- AND `SettingsLoadService` MUST call `ConfigurationService::importFromApp()` with appId `"pipelinq"`, the parsed data, and the current app version
- AND this MUST create the `pipelinq` register in OpenRegister
- AND this MUST create all 8 schemas: client, contact, lead, request, pipeline, product, productCategory, leadProduct
- AND schema IDs MUST be stored in IAppConfig as `{slug}_schema` keys via `SettingsMapBuilder::buildSchemaSlugMap()`
- AND the register ID MUST be stored in IAppConfig as `register` via `SettingsMapBuilder::findRegisterIdBySlug()`
- AND the default view ID MUST be stored in IAppConfig as `default_view` via `SettingsMapBuilder::findDefaultViewId()`
- AND the register MUST be queryable via the OpenRegister API immediately after

#### Scenario: Missing OpenRegister handled gracefully

- GIVEN Pipelinq is installed but OpenRegister is NOT installed
- WHEN the repair step runs
- THEN it MUST check `IAppManager::getInstalledApps()` for `"openregister"`
- AND if not found, it MUST call `$output->warning()` with a descriptive message
- AND it MUST log a warning via `LoggerInterface`
- AND it MUST NOT throw an unhandled exception
- AND it MUST call `$output->finishProgress()` to complete cleanly

#### Scenario: Upgrade with new schemas preserves existing data

- GIVEN Pipelinq is being upgraded from a version with 5 schemas to a version with 8 schemas
- AND the `pipelinq` register already contains 150 client objects
- WHEN the repair step runs
- THEN `ConfigurationService::importFromApp()` MUST create the 3 new schemas (product, productCategory, leadProduct) without error
- AND it MUST NOT delete or modify existing schemas that have data
- AND all 150 existing client objects MUST remain intact and queryable
- AND the new schema IDs MUST be stored in IAppConfig alongside existing ones

#### Scenario: Upgrade with modified schema properties

- GIVEN Pipelinq is being upgraded and the `lead` schema adds a new optional property `lostReason`
- AND 30 existing lead objects exist without this property
- WHEN the repair step runs
- THEN the schema MUST be updated to include the new property
- AND existing lead objects MUST remain valid (new optional property defaults to null)
- AND new leads MUST be able to use the `lostReason` property

#### Scenario: Repair step is idempotent

- GIVEN the repair step has already run successfully
- WHEN the repair step runs again (e.g., during `occ maintenance:repair`)
- THEN it MUST NOT create duplicate registers or schemas
- AND it MUST NOT produce errors
- AND the end state MUST be identical to a single run
- AND `ConfigurationService::importFromApp()` receives the current app version and MUST skip re-import if the version matches the previously imported version (unless `force: true`)

#### Scenario: Repair step creates default pipelines

- GIVEN Pipelinq is being installed for the first time
- WHEN the repair step runs
- THEN it MUST call `SettingsService::createDefaultPipelines()` which delegates to `DefaultPipelineService`
- AND `DefaultPipelineService` MUST check if a "Sales Pipeline" already exists by querying `ObjectService::findAll()` with `title` = `"Sales Pipeline"`, `_rbac: false`, `_multitenancy: false`
- AND if no existing pipeline is found, it MUST create a "Sales Pipeline" with 7 stages (New, Contacted, Qualified, Proposal, Negotiation, Won, Lost) with correct order and probability values
- AND it MUST create a "Service Requests" pipeline with 5 stages (New, In Progress, Completed, Rejected, Converted to Case)
- AND the Sales Pipeline MUST be marked as the default pipeline (`isDefault: true`)
- AND both pipelines MUST include the `viewId` from `default_view` in IAppConfig if available

#### Scenario: Repair step creates default system tags

- GIVEN Pipelinq is being installed for the first time
- WHEN the repair step runs
- THEN it MUST call `SystemTagService::ensureDefaults()` for lead sources with objectType `"pipelinq_lead_source"` and defaults: website, email, phone, referral, partner, campaign, social_media, event, other
- AND it MUST call `SystemTagService::ensureDefaults()` for request channels with objectType `"pipelinq_request_channel"` and defaults: phone, email, website, counter, post

#### Scenario: Repair step progress reporting

- GIVEN the repair step is running
- THEN it MUST call `$output->startProgress(4)` to report 4 steps
- AND step 1 MUST be configuration loading
- AND step 2 MUST be default pipeline creation
- AND step 3 MUST be default system tag creation
- AND it MUST call `$output->advance()` after each step
- AND it MUST call `$output->finishProgress()` on completion
- AND each step failure MUST be caught, logged, and allow subsequent steps to proceed

#### Scenario: Repair step handles ConfigurationService exceptions

- GIVEN the repair step is running
- AND `ConfigurationService::importFromApp()` throws an exception (e.g., database error)
- WHEN the exception is caught
- THEN the repair step MUST call `$output->warning()` with the exception message
- AND it MUST log the error via `LoggerInterface::error()`
- AND it MUST continue to attempt default pipeline creation and tag creation
- AND it MUST NOT propagate the exception to Nextcloud's repair runner

---

### Requirement: Schema-to-IAppConfig Mapping

After importing the register configuration, the system MUST map imported schema and register IDs to IAppConfig keys so the frontend and backend can resolve entity types at runtime.

**Feature tier**: MVP

#### Scenario: SettingsMapBuilder extracts schema IDs from import result

- GIVEN `ConfigurationService::importFromApp()` returns a result with `schemas` array
- WHEN `SettingsMapBuilder::buildSchemaSlugMap()` is called
- THEN it MUST iterate over each schema in the result
- AND for each schema, it MUST normalize it to an array (supporting both objects with `jsonSerialize()` and plain arrays)
- AND it MUST extract the `slug` and `id` (or `uuid`) fields
- AND it MUST return a map of `{slug: id}` for all schemas

#### Scenario: SettingsMapBuilder extracts register ID by slug

- GIVEN the import result contains registers
- WHEN `SettingsMapBuilder::findRegisterIdBySlug()` is called
- THEN it MUST find the register with `slug` = `"pipelinq"`
- AND it MUST return its `id` (or `uuid`)
- AND it MUST return `null` if no matching register is found

#### Scenario: SettingsLoadService stores all 8 schema IDs in IAppConfig

- GIVEN the import completes successfully
- WHEN `SettingsLoadService::updateObjectTypeConfiguration()` runs
- THEN it MUST store the register ID as `register` in IAppConfig
- AND it MUST store each schema ID as `{slug}_schema` for all 8 slugs: `client_schema`, `contact_schema`, `lead_schema`, `request_schema`, `pipeline_schema`, `product_schema`, `productCategory_schema`, `leadProduct_schema`
- AND it MUST store the default view ID as `default_view` in IAppConfig

#### Scenario: SettingsService exposes all config keys to the frontend

- GIVEN the `SettingsService::getSettings()` method
- THEN it MUST read all 9 config keys from IAppConfig: `register`, `client_schema`, `contact_schema`, `lead_schema`, `request_schema`, `pipeline_schema`, `product_schema`, `productCategory_schema`, `leadProduct_schema`
- AND it MUST return them as a key-value map
- AND missing keys MUST default to empty string

---

### Requirement: Store Registration

The frontend MUST register all 8 entity types in the Pinia object store on initialization via `initializeStores()`.

**Feature tier**: MVP

#### Scenario: All entity types registered on app load

- GIVEN the Pipelinq app loads in the browser
- WHEN `initializeStores()` completes
- THEN the object store MUST have registered: `client`, `contact`, `lead`, `request`, `pipeline`, `product`, `productCategory`, `leadProduct`
- AND each type MUST have the correct schema ID and register ID from settings

#### Scenario: Settings are fetched before registration

- GIVEN the app loads for the first time
- WHEN `initializeStores()` is called
- THEN it MUST first call `settingsStore.fetchSettings()` to load config from `/apps/pipelinq/api/settings`
- AND only after the settings response is received MUST it register object types
- AND if the settings request fails, it MUST NOT attempt to register any types

#### Scenario: Missing schema handled gracefully

- GIVEN the settings endpoint returns a register ID but no `lead_schema`
- WHEN `initializeStores()` runs
- THEN it MUST skip registering the `lead` type (guarded by `if (config.register && config.lead_schema)`)
- AND it MUST NOT throw an error
- AND other types (client, request, contact) MUST still be registered

#### Scenario: Missing register ID prevents all registration

- GIVEN the settings endpoint returns schema IDs but no `register` value
- WHEN `initializeStores()` runs
- THEN it MUST skip all type registrations (each guarded by `config.register &&`)
- AND the app MUST display a configuration warning to the user

---

### Requirement: Frontend API Interaction (CRUD)

The frontend MUST interact with OpenRegister's REST API directly for all CRUD operations on Pipelinq entities. All API calls follow the pattern `/index.php/apps/openregister/api/objects/{register}/{schema}[/{id}]`.

**Feature tier**: MVP

#### Scenario: List objects with default pagination

- GIVEN the `client` schema exists in the `pipelinq` register with 45 client objects
- WHEN the frontend requests the client list without pagination parameters
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/pipelinq/client`
- AND the response MUST include a paginated result set (default page size)
- AND the response MUST include a `total` count of 45

#### Scenario: List objects with explicit pagination

- GIVEN 45 client objects exist
- WHEN the frontend requests page 2 with 20 items per page
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/pipelinq/client?_page=2&_limit=20`
- AND the response MUST return clients 21-40
- AND the `total` MUST still be 45

#### Scenario: Create an object

- GIVEN a user fills in the new client form with name "Waterschap Rivierenland" and type "organization"
- WHEN they submit the form
- THEN the frontend MUST call `POST /index.php/apps/openregister/api/objects/pipelinq/client`
- AND the request body MUST contain `{"name": "Waterschap Rivierenland", "type": "organization"}`
- AND the Content-Type MUST be `application/json`
- AND the response MUST return the created object with a generated UUID

#### Scenario: Read a single object

- GIVEN an existing client with UUID "00000000-0000-0000-0000-000000000000"
- WHEN the frontend navigates to the client detail view
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/pipelinq/client/00000000-0000-0000-0000-000000000000`
- AND the response MUST contain the full client object with all properties

#### Scenario: Update an object

- GIVEN an existing client with UUID "00000000-0000-0000-0000-000000000000"
- WHEN the user updates the email to "info@rivierenland.nl"
- THEN the frontend MUST call `PUT /index.php/apps/openregister/api/objects/pipelinq/client/00000000-0000-0000-0000-000000000000`
- AND the request body MUST contain the updated client object
- AND the response MUST return the updated object

#### Scenario: Delete an object

- GIVEN an existing client with UUID "00000000-0000-0000-0000-000000000000"
- WHEN the user confirms deletion
- THEN the frontend MUST call `DELETE /index.php/apps/openregister/api/objects/pipelinq/client/00000000-0000-0000-0000-000000000000`
- AND the response MUST indicate successful deletion
- AND subsequent GET requests for this UUID MUST return 404

#### Scenario: API uses slugs not IDs in URL path

- GIVEN the object store has registered `client` with schema ID `42` and register ID `7`
- WHEN the `createObjectStore` from `@conduction/nextcloud-vue` constructs API URLs
- THEN it MUST resolve schema ID and register ID to their slugs
- AND the URL path MUST use `/api/objects/pipelinq/client` (slugs), not `/api/objects/7/42` (IDs)
- AND the `registerMappingPlugin` MUST handle this slug resolution

---

### Requirement: Search and Filtering via OpenRegister

The system MUST support search and filtering through OpenRegister's query parameters for all entity types.

**Feature tier**: MVP

#### Scenario: Search by text field

- GIVEN clients "Gemeente Utrecht", "Gemeente Amsterdam", "Acme B.V."
- WHEN the frontend searches for "Gemeente"
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/pipelinq/client?_search=Gemeente`
- AND the response MUST return "Gemeente Utrecht" and "Gemeente Amsterdam"
- AND the response MUST NOT return "Acme B.V."

#### Scenario: Filter by property value

- GIVEN clients of type "person" and "organization"
- WHEN the frontend filters by type
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/pipelinq/client?type=organization`
- AND the response MUST return only organization clients

#### Scenario: Sort results

- GIVEN multiple client objects
- WHEN the frontend requests sorted results
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/pipelinq/client?_order[name]=asc`
- AND the response MUST return clients sorted alphabetically by name

#### Scenario: Combined search, filter, sort, and pagination

- GIVEN 100 clients, 60 of which are organizations
- WHEN the frontend searches for "Gemeente" among organizations, sorted by name, page 1 of 20
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/pipelinq/client?_search=Gemeente&type=organization&_order[name]=asc&_page=1&_limit=20`
- AND the response MUST return only matching organization clients, sorted, paginated

#### Scenario: Filter by multiple faceted properties simultaneously

- GIVEN leads with various priorities, sources, and statuses
- WHEN the frontend filters for priority "high" AND source "website" AND status "open"
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/pipelinq/lead?priority=high&source=website&status=open`
- AND the response MUST return only leads matching ALL three criteria

#### Scenario: Pipeline board fetches items filtered by pipeline UUID

- GIVEN a pipeline with UUID "pipe-uuid-1" containing 80 leads
- WHEN the pipeline board view loads
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/pipelinq/lead?pipeline=pipe-uuid-1&_limit=200`
- AND the response MUST return only leads assigned to that specific pipeline
- AND the frontend MUST group them by their `stage` property for column placement

---

### Requirement: Faceted Search

The system MUST support faceted search where properties marked `"facetable": true` in the schema can be used to filter and aggregate results.

**Feature tier**: MVP

#### Scenario: Faceted properties are available as filter options

- GIVEN the `client` schema has `type` and `industry` marked as facetable
- WHEN the frontend loads the client list view
- THEN it MUST be able to query distinct values for `type` (person, organization) and `industry`
- AND it MUST display these as filter options in the sidebar or filter bar

#### Scenario: Facet counts reflect current filter state

- GIVEN 100 clients: 60 organizations and 40 persons
- WHEN the frontend displays faceted filters
- THEN the `type` facet MUST show "organization (60)" and "person (40)"
- AND applying the "organization" filter MUST update other facet counts to reflect only organization clients

#### Scenario: Lead facets support pipeline workflow

- GIVEN leads with `source`, `assignee`, `priority`, `stage`, and `status` as facetable properties
- WHEN the frontend displays the lead list
- THEN all 5 facets MUST be available as filter dimensions
- AND selecting a stage facet value MUST filter leads to only those in that stage

---

### Requirement: Pinia Store Pattern

The frontend MUST use Pinia stores for state management. The centralized `createObjectStore('object')` from `@conduction/nextcloud-vue` MUST serve as the single store with per-type registration.

**Feature tier**: MVP

#### Scenario: Centralized store with plugins

- GIVEN the object store is created via `createObjectStore('object')`
- THEN it MUST be configured with 4 plugins: `filesPlugin`, `auditTrailsPlugin`, `relationsPlugin`, `registerMappingPlugin`
- AND the store MUST use the Pinia store ID `"object"` for backward compatibility with all views
- AND all entity types MUST share this single store instance

#### Scenario: Store provides standard CRUD actions per type

- GIVEN the object store has registered `client` as an object type
- THEN it MUST expose actions to: list clients (with pagination), fetch a single client by ID, create a client, update a client, delete a client
- AND each action MUST construct the correct OpenRegister API URL using the registered schema and register slugs
- AND each action MUST manage loading state during the request

#### Scenario: Store manages loading state

- GIVEN the user navigates to the client list
- WHEN a fetch action is called
- THEN `objectStore.loading` MUST be `true` during the API request
- AND the UI SHOULD display a loading indicator
- AND when the request completes, loading MUST be `false`

#### Scenario: Store manages error state

- GIVEN the OpenRegister API returns a 500 error
- WHEN a fetch action is called
- THEN the error MUST be captured in the store state
- AND loading MUST be `false`
- AND the UI MUST display an error message to the user

#### Scenario: Store handles pagination state

- GIVEN the client store fetches a paginated list of 45 items
- THEN the store MUST track `total` (45), `page` (current page), and `limit` (page size) in state
- AND changing the page MUST trigger a new API request and update the items

#### Scenario: Store caches single entity reads

- GIVEN the user fetches client "f47ac10b" via a detail fetch
- WHEN the user navigates back to the same client detail
- THEN the store SHOULD return the cached object immediately
- AND the store SHOULD still make a background API call to check for updates
- AND if the data has changed, the store MUST update the cached object

#### Scenario: All 8 entity types follow the same pattern

- GIVEN the app has registered client, contact, lead, request, pipeline, product, productCategory, and leadProduct
- THEN each type MUST share the same CRUD interface via the centralized object store
- AND only the schema slug, register slug, and schema ID MUST differ between types

---

### Requirement: Schema Validation

OpenRegister MUST validate objects against their schema on create and update operations. Pipelinq MUST handle validation errors gracefully in the frontend.

**Feature tier**: MVP

#### Scenario: Server-side validation rejects missing required field

- GIVEN the `client` schema requires `name` and `type`
- WHEN the frontend sends a POST request with `{"type": "person"}` (missing name)
- THEN OpenRegister MUST return a 422 response with a validation error
- AND the error response MUST identify the `name` field as missing
- AND the frontend MUST display the validation error inline next to the name field

#### Scenario: Server-side validation rejects invalid enum value

- GIVEN the `client` schema defines `type` as enum `["person", "organization"]`
- WHEN the frontend sends a POST request with `{"name": "Test", "type": "government"}`
- THEN OpenRegister MUST return a 422 response
- AND the error MUST indicate that "government" is not a valid value for `type`

#### Scenario: Server-side validation rejects invalid email format

- GIVEN the `client` schema defines `email` with `format: "email"`
- WHEN the frontend sends `{"name": "Test", "type": "person", "email": "not-valid"}`
- THEN OpenRegister MUST return a 422 response
- AND the error MUST indicate invalid email format

#### Scenario: Server-side validation enforces numeric constraints

- GIVEN the `lead` schema defines `probability` with `minimum: 0` and `maximum: 100`
- WHEN the frontend sends a lead with `probability: 150`
- THEN OpenRegister MUST return a 422 response
- AND the error MUST indicate that the value exceeds the maximum of 100

#### Scenario: Server-side validation enforces string length constraints

- GIVEN the `client` schema defines `name` with `maxLength: 255` and `minLength: 1`
- WHEN the frontend sends a client with an empty `name` (zero-length string)
- THEN OpenRegister MUST return a 422 response
- AND the error MUST indicate that the name is below minimum length

#### Scenario: Client-side validation before submission

- GIVEN the client creation form
- WHEN the user leaves the `name` field empty and clicks "Save"
- THEN the frontend MUST validate required fields before sending the API request
- AND the frontend MUST display inline validation errors without making an API call
- AND the "Save" button SHOULD be disabled while required fields are empty

---

### Requirement: Error Handling

The system MUST handle API errors, network errors, and unexpected failures gracefully, providing meaningful feedback to the user.

**Feature tier**: MVP

#### Scenario: Handle 404 Not Found

- GIVEN a client with UUID "abc-123" has been deleted
- WHEN the frontend requests `GET /api/objects/pipelinq/client/abc-123`
- THEN the API MUST return a 404 response
- AND the frontend MUST display a "not found" message
- AND the frontend MUST offer navigation back to the client list

#### Scenario: Handle 403 Forbidden (RBAC)

- GIVEN a user without permission to delete clients
- WHEN they attempt to delete a client
- THEN the API MUST return a 403 response
- AND the frontend MUST display an "insufficient permissions" message
- AND the object MUST remain unchanged

#### Scenario: Handle 500 Internal Server Error

- GIVEN an unexpected server error occurs during client creation
- WHEN the frontend receives a 500 response
- THEN the frontend MUST display a generic error message such as "An unexpected error occurred. Please try again."
- AND the frontend MUST log the error details to the browser console
- AND the form data MUST be preserved so the user can retry without re-entering data

#### Scenario: Handle network timeout

- GIVEN the network connection is slow or interrupted
- WHEN an API request exceeds the timeout threshold
- THEN the frontend MUST display a "network error" message
- AND the frontend MUST offer a "Retry" action
- AND the original request data MUST be preserved for retry

#### Scenario: Handle concurrent modification conflict

- GIVEN user A and user B both open the same client detail view
- WHEN user A saves a change and then user B saves a different change
- THEN the system SHOULD detect the conflict (e.g., via ETag or version field)
- AND user B SHOULD be informed that the record was modified
- AND user B SHOULD be offered the option to reload and re-apply their changes

#### Scenario: Handle settings fetch failure on app load

- GIVEN the settings endpoint `/apps/pipelinq/api/settings` is unreachable
- WHEN `settingsStore.fetchSettings()` fails
- THEN `settingsStore.error` MUST be set to the error message
- AND `settingsStore.initialized` MUST remain `false`
- AND `initializeStores()` MUST return without registering any object types
- AND the app SHOULD display a clear "configuration unavailable" state

---

### Requirement: Cross-Entity References

Entities in Pipelinq reference each other via UUID fields stored on the OpenRegister objects. The system MUST maintain referential integrity and resolve references for display.

**Feature tier**: MVP

#### Scenario: Lead references a client

- GIVEN a lead object with `client: "00000000-0000-0000-0000-000000000000"`
- WHEN the frontend displays the lead detail view
- THEN it MUST resolve the client UUID to fetch and display the client name
- AND clicking the client name MUST navigate to the client detail view

#### Scenario: Lead references a pipeline and stage

- GIVEN a lead with `pipeline: "pipe-uuid-1"` and `stage: "Qualified"`
- WHEN the frontend displays the lead detail view
- THEN it MUST resolve the pipeline reference to display the pipeline name
- AND the pipeline progress indicator MUST show the current stage in context

#### Scenario: Contact references a client organization

- GIVEN a contact person with `client: "org-uuid-1"`
- WHEN the frontend displays the contact person list
- THEN it MUST resolve the client UUID and display the organization name in the list row

#### Scenario: LeadProduct references lead and product

- GIVEN a leadProduct object with `lead: "lead-uuid-1"` and `product: "prod-uuid-1"`
- WHEN the frontend displays the lead detail with line items
- THEN it MUST resolve the product UUID to display the product name
- AND it MUST display the quantity, unit price, discount, and computed total

#### Scenario: Product references a category

- GIVEN a product with `category: "cat-uuid-1"`
- WHEN the frontend displays the product list
- THEN it MUST resolve the category UUID and display the category name

#### Scenario: ProductCategory self-references for hierarchy

- GIVEN a productCategory with `parent: "parent-cat-uuid-1"`
- WHEN the frontend displays the category tree
- THEN it MUST resolve the parent reference to build the hierarchical tree structure
- AND it MUST handle circular references gracefully (stop recursion)

#### Scenario: Orphaned reference handling

- GIVEN a lead with `client: "deleted-uuid"` where the client has been deleted
- WHEN the frontend displays the lead
- THEN it MUST NOT crash or show a blank screen
- AND it SHOULD display "[Deleted client]" or similar placeholder text
- AND the lead MUST remain fully functional for editing other fields

#### Scenario: relationsPlugin resolves references automatically

- GIVEN the object store is configured with `relationsPlugin()`
- WHEN an object with UUID reference properties is loaded
- THEN the `relationsPlugin` MUST automatically resolve references based on the schema definition
- AND resolved objects MUST be cached to avoid redundant API calls
- AND the resolution MUST be lazy (triggered on access, not on load)

---

### Requirement: Audit Trail

The system MUST maintain an audit trail of all create, update, and delete operations on all entities, recording who made the change and when.

**Feature tier**: MVP

#### Scenario: Record creation audit entry

- GIVEN user "jan" creates a new client "Gemeente Amersfoort"
- THEN the system MUST record an audit entry with: action "create", entity type "client", entity UUID, user "jan", and timestamp

#### Scenario: Record update audit entry with field changes

- GIVEN user "maria" updates client "Gemeente Amersfoort" email from null to "info@amersfoort.nl"
- THEN the system MUST record an audit entry with: action "update", entity type "client", entity UUID, user "maria", timestamp, and changed fields (email: null -> "info@amersfoort.nl")

#### Scenario: Record deletion audit entry

- GIVEN user "admin" deletes client "Test B.V."
- THEN the system MUST record an audit entry with: action "delete", entity type "client", entity UUID, user "admin", and timestamp
- AND the audit entry SHOULD preserve a snapshot of the deleted object's data

#### Scenario: View audit history for an entity

- GIVEN a client "Gemeente Utrecht" with 5 audit entries
- WHEN the user views the client's activity timeline
- THEN all 5 audit entries MUST be displayed in reverse chronological order
- AND each entry MUST show the action, user, timestamp, and changed fields

#### Scenario: auditTrailsPlugin exposes audit trail in the store

- GIVEN the object store is configured with `auditTrailsPlugin()`
- WHEN the frontend loads an object's detail view
- THEN the plugin MUST provide an action to fetch the audit trail for that object
- AND the audit entries MUST be accessible as part of the store's state for the current object

---

### Requirement: RBAC Integration

The system MUST integrate with OpenRegister's role-based access control (RBAC) system to enforce permissions on all operations.

**Feature tier**: MVP

#### Scenario: User with read-only access

- GIVEN a user with read-only CRM access
- WHEN they view the client list and client details
- THEN the system MUST display the data normally
- AND create, edit, and delete buttons MUST NOT be visible
- AND direct API calls to POST/PUT/DELETE MUST return 403

#### Scenario: User with full CRM access

- GIVEN a user with full CRM access
- WHEN they navigate to any entity view
- THEN they MUST be able to create, read, update, and delete entities
- AND all CRUD buttons MUST be visible and functional

#### Scenario: Organisation-scoped access

- GIVEN user "jan" belongs to organisation "Gemeente Utrecht"
- AND the RBAC system scopes data by organisation
- WHEN "jan" views the client list
- THEN the system MUST only show clients belonging to "Gemeente Utrecht"
- AND API requests MUST be filtered by the user's active organisation

#### Scenario: Admin access to all data

- GIVEN an admin user
- WHEN they view the client list
- THEN the system MUST show all clients across all organisations
- AND the admin MUST be able to manage pipelines and stages in admin settings

---

### Requirement: Object Event Handling

The system MUST listen for OpenRegister object events (create, update) and trigger Pipelinq-specific business logic such as notifications and data transformations.

**Feature tier**: MVP

#### Scenario: SchemaMapService resolves entity types from schema IDs

- GIVEN `SchemaMapService` is initialized with settings containing schema IDs
- WHEN `resolveEntityType()` is called with a schema ID
- THEN it MUST return the corresponding entity type string (e.g., "lead", "request")
- AND it MUST build the schema map lazily on first call (cached for subsequent calls)
- AND it MUST return `null` for unrecognized schema IDs

#### Scenario: ObjectEventHandlerService processes created objects

- GIVEN an OpenRegister object is created with a schema ID matching `lead_schema`
- WHEN `handleCreated()` is called with the object entity
- THEN it MUST resolve the entity type via `SchemaMapService::resolveEntityType()`
- AND if the type is `"lead"` or `"request"`, it MUST dispatch a creation notification via `ObjectEventDispatcher`
- AND if the type is not `"lead"` or `"request"` (e.g., "client"), it MUST return without dispatching

#### Scenario: ObjectEventHandlerService processes updated objects

- GIVEN an existing lead object is updated
- WHEN `handleUpdated()` is called with the new and old object entities
- THEN it MUST check for assignee changes via `ObjectUpdateDiffService::dispatchAssigneeChangeIfNeeded()`
- AND for lead entities, it MUST check for stage changes via `dispatchStageChangeIfNeeded()`
- AND for request entities, it MUST check for status changes via `dispatchStatusChangeIfNeeded()`

#### Scenario: Event handling ignores irrelevant entity types

- GIVEN an object is created with a schema ID matching `pipeline_schema`
- WHEN `handleCreated()` is called
- THEN `isRelevantEntityType()` MUST return `false` for "pipeline"
- AND no notifications or events MUST be dispatched

---

### Requirement: Performance Considerations

The system MUST handle data volumes typical of a municipal CRM (thousands of clients, hundreds of leads) without degraded user experience.

**Feature tier**: MVP

#### Scenario: Client list loads within acceptable time

- GIVEN 5,000 clients exist in the register
- WHEN the user navigates to the client list
- THEN the first page MUST load within 2 seconds
- AND the system MUST use server-side pagination (not load all 5,000 to the client)

#### Scenario: Pipeline board loads with many cards

- GIVEN a pipeline with 6 stages and 200 total leads across all stages
- WHEN the user navigates to the pipeline kanban view
- THEN the board MUST load within 3 seconds
- AND each stage column SHOULD initially display a limited number of cards (e.g., 20)
- AND a "Load more" action SHOULD be available per column

#### Scenario: Reference resolution is batched

- GIVEN a lead list showing 20 leads, each referencing a client and a pipeline/stage
- WHEN the frontend needs to resolve client names and stage titles
- THEN it MUST batch the reference resolution (e.g., fetch all referenced clients in one call)
- AND it MUST NOT make 20 individual API calls for client names

#### Scenario: Pinia store caches entity data

- GIVEN the user navigates from client list to client detail and back to client list
- WHEN returning to the client list
- THEN the store SHOULD serve the cached list immediately
- AND the store MAY make a background request to check for updates
- AND the user MUST NOT see a loading spinner for cached data

#### Scenario: Search uses server-side filtering

- GIVEN 5,000 clients and the user types "Gemeente" in the search box
- WHEN the search executes
- THEN the frontend MUST send the search query to the server via `_search` parameter
- AND the frontend MUST NOT download all 5,000 clients for client-side filtering
- AND the frontend SHOULD debounce search input (e.g., 300ms delay) to avoid excessive API calls

#### Scenario: Pipeline board uses high limit for single-request loading

- GIVEN the pipeline board needs all items for a pipeline
- WHEN it fetches leads for a specific pipeline
- THEN it MUST use `_limit=200` to load items in a single request where possible
- AND for pipelines with more than 200 items, it MUST paginate and load additional pages

---

### Requirement: OpenRegister Availability Health Check

The system MUST detect whether OpenRegister is available and functional, and degrade gracefully when it is not.

**Feature tier**: MVP

#### Scenario: Settings endpoint reports OpenRegister availability

- GIVEN the frontend calls `GET /apps/pipelinq/api/settings`
- THEN the response MUST include an `openRegisters` boolean field
- AND if OpenRegister is installed and functional, `openRegisters` MUST be `true`
- AND if OpenRegister is not installed, `openRegisters` MUST be `false`

#### Scenario: Frontend detects OpenRegister unavailability

- GIVEN `settingsStore.fetchSettings()` returns `openRegisters: false`
- WHEN the app initializes
- THEN `settingsStore.openRegisters` MUST be `false`
- AND the app SHOULD display a prominent warning: "OpenRegister is required for Pipelinq to function"
- AND CRUD operations MUST NOT be attempted

#### Scenario: Backend repair step checks OpenRegister presence

- GIVEN Pipelinq's repair step runs
- WHEN it checks `IAppManager::getInstalledApps()` and OpenRegister is absent
- THEN it MUST skip all configuration import
- AND it MUST log a warning
- AND it MUST complete without errors

---

### Requirement: Multi-Tenancy via OpenRegister

The system MUST support multi-tenancy through OpenRegister's built-in multi-tenancy support, ensuring data isolation between organizations.

**Feature tier**: Enterprise

#### Scenario: Default pipelines created without multi-tenancy

- GIVEN the repair step creates default pipelines
- WHEN `DefaultPipelineService::createDefaultPipelines()` calls `ObjectService::findAll()` and `saveObject()`
- THEN it MUST pass `_multitenancy: false` to bypass tenant filtering
- AND this ensures default pipelines are visible to all tenants as system-level data

#### Scenario: User data operations respect multi-tenancy

- GIVEN a user belongs to organization "Gemeente Utrecht"
- WHEN the user creates, reads, updates, or deletes CRM objects via the API
- THEN OpenRegister MUST apply tenant-scoped filtering based on the user's organization
- AND the user MUST only see objects belonging to their organization
- AND objects created by the user MUST be tagged with their organization's tenant ID

#### Scenario: Admin overrides multi-tenancy for system operations

- GIVEN an admin user manages pipelines or system configuration
- WHEN backend service operations run (e.g., default pipeline creation)
- THEN they MUST use `_rbac: false` and `_multitenancy: false` to bypass tenant restrictions
- AND user-facing operations MUST still respect tenant boundaries

---

### Requirement: Schema Migration on Version Changes

When the app is upgraded with schema changes (new properties, new schemas, modified constraints), the system MUST migrate gracefully without data loss.

**Feature tier**: MVP

#### Scenario: New optional property added to existing schema

- GIVEN the `lead` schema v1.0.0 has 14 properties
- AND 200 existing lead objects exist
- WHEN the app is upgraded and the schema gains `lostReason` (string, optional)
- THEN `ConfigurationService::importFromApp()` MUST update the schema definition
- AND all 200 existing lead objects MUST remain queryable
- AND existing objects MUST return `null` for the new `lostReason` property
- AND new objects MAY include the `lostReason` property

#### Scenario: New required property added to existing schema

- GIVEN the schema gains a new required property with a `default` value
- WHEN the schema is updated via the repair step
- THEN existing objects that lack the property MUST be treated as valid (using the default)
- AND new objects MUST include the required property

#### Scenario: New schema added in upgrade

- GIVEN the app v1.0.0 has 5 schemas and v2.0.0 has 8 schemas
- WHEN the repair step runs after upgrade
- THEN all 3 new schemas (product, productCategory, leadProduct) MUST be created
- AND their IDs MUST be stored in IAppConfig
- AND the frontend MUST register them in `initializeStores()` after upgrade

#### Scenario: Schema version tracked in register configuration

- GIVEN each schema in `pipelinq_register.json` has a `version` field
- WHEN `ConfigurationService::importFromApp()` compares versions
- THEN it MUST only re-import schemas whose version has changed
- AND unchanged schemas MUST be left untouched

---

### Requirement: Default Pipeline Data Integrity

The default pipeline creation MUST produce complete, consistent pipeline objects that match the pipeline schema definition.

**Feature tier**: MVP

#### Scenario: Sales pipeline has correct stage structure

- GIVEN the `DefaultPipelineService` creates the Sales Pipeline
- THEN it MUST contain exactly 7 stages with these properties:
  - New (order: 0, probability: 10, isClosed: false, isWon: false, color: #3b82f6)
  - Contacted (order: 1, probability: 20, isClosed: false, isWon: false, color: #8b5cf6)
  - Qualified (order: 2, probability: 40, isClosed: false, isWon: false, color: #f59e0b)
  - Proposal (order: 3, probability: 60, isClosed: false, isWon: false, color: #f97316)
  - Negotiation (order: 4, probability: 80, isClosed: false, isWon: false, color: #ef4444)
  - Won (order: 5, probability: 100, isClosed: true, isWon: true, color: #22c55e)
  - Lost (order: 6, probability: 0, isClosed: true, isWon: false, color: #6b7280)
- AND `isDefault` MUST be `true`
- AND `totalsLabel` MUST be `"EUR"`

#### Scenario: Sales pipeline includes property mappings

- GIVEN the Sales Pipeline is created
- THEN `propertyMappings` MUST contain exactly 2 entries:
  - `{ schemaSlug: "lead", columnProperty: "stage", totalsProperty: "value" }`
  - `{ schemaSlug: "request", columnProperty: "stage", totalsProperty: null }`
- AND these mappings define how leads and requests are placed in pipeline columns

#### Scenario: Service Requests pipeline has correct stage structure

- GIVEN the `DefaultPipelineService` creates the Service Requests pipeline
- THEN it MUST contain exactly 5 stages:
  - New (order: 0, isClosed: false, isWon: false, color: #3b82f6)
  - In Progress (order: 1, isClosed: false, isWon: false, color: #f59e0b)
  - Completed (order: 2, isClosed: true, isWon: true, color: #22c55e)
  - Rejected (order: 3, isClosed: true, isWon: false, color: #ef4444)
  - Converted to Case (order: 4, isClosed: true, isWon: false, color: #8b5cf6)
- AND `isDefault` MUST be `false`
- AND `propertyMappings` MUST contain 1 entry: `{ schemaSlug: "request", columnProperty: "status", totalsProperty: null }`

#### Scenario: Default pipelines saved with bypass flags

- GIVEN the default pipelines are being created
- WHEN `ObjectService::saveObject()` is called
- THEN it MUST pass `_rbac: false` to bypass role-based access control
- AND it MUST pass `_multitenancy: false` to bypass tenant filtering
- AND this ensures default pipelines are created as system-level objects regardless of the current user context

---

### Requirement: filesPlugin Integration

The object store MUST support file attachments on OpenRegister objects via the `filesPlugin`.

**Feature tier**: MVP

#### Scenario: filesPlugin enables file operations on objects

- GIVEN the object store is configured with `filesPlugin()`
- WHEN an object is loaded
- THEN the plugin MUST provide actions to list, upload, and delete files attached to the object
- AND files MUST be stored in the Nextcloud Files folder specified by the register's `folder` property (`"Open Registers/Pipelinq"`)

---

### Requirement: Application shell — documented operations

The application shell navigation implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `onPipelineSidebarSave`, `permissions`, `provide`, `translateForApp`). Each listed method realises an observable part of application shell navigation and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

- GIVEN the frontend component/store is loaded
- WHEN a caller invokes one of the documented operations for application shell navigation
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Application shell — results derived from current CRM state

Operations for application shell navigation MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

- GIVEN CRM data backing application shell navigation
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Application shell — defensive handling of absent or invalid input

Operations for application shell navigation MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

- GIVEN an operation for application shell navigation is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception

### Requirement: Frontend service layer — documented operations

The frontend API service helpers implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `getAllowedTransitions`, `getCategoryLabel`, `getChannelLabel`, `getPriorityColor`, `getPriorityLabel`, `getSlaColor`). Each listed method realises an observable part of frontend API service helpers and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

- GIVEN the frontend component/store is loaded
- WHEN a caller invokes one of the documented operations for frontend API service helpers
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Frontend service layer — results derived from current CRM state

Operations for frontend API service helpers MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

- GIVEN CRM data backing frontend API service helpers
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Frontend service layer — defensive handling of absent or invalid input

Operations for frontend API service helpers MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

- GIVEN an operation for frontend API service helpers is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception

> UPDATED 2026-06-01: The "Frontend state stores" requirement below originally
> enumerated the store actions of `src/store/modules/kennisbank.js` (~22 methods)
> and the automation store alongside the still-present modules. The
> `kennisbank.js` module has been **removed** (knowledge management migrated to
> the XWiki leaf — see `migrate-kennisbank-to-xwiki-leaf` and the `kennisbank`
> spec), and automation migrated to the Flow leaf. The deleted-module methods no
> longer apply; only the store modules that still exist are in scope.

### Requirement: Frontend state stores — documented operations

The Pinia store actions and getters implemented in this app MUST provide the
operations of the store modules that currently exist in `src/store/modules/`
(`agentProfiles.js`, `leadSources.js`, `object.js`, `product.js`, `prospect.js`,
`requestChannels.js`, `settings.js`, `skills.js`, `survey.js` — for
example `_countOpenRequests`, `deleteProfile`, `fetchProfiles`, `getWorkload`,
`saveProfile`, `_addToRecentlyViewed`). Each listed method realises an observable
part of Pinia store actions and getters and MUST behave as implemented in the
current codebase. Methods of removed modules (`kennisbank.js`, automation) are
out of scope.

**Feature tier**: V1

#### Scenario: Documented operations are available

- GIVEN the frontend component/store is loaded
- WHEN a caller invokes one of the documented operations for Pinia store actions and getters
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Frontend state stores — results derived from current CRM state

Operations for Pinia store actions and getters MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

- GIVEN CRM data backing Pinia store actions and getters
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Frontend state stores — defensive handling of absent or invalid input

Operations for Pinia store actions and getters MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

- GIVEN an operation for Pinia store actions and getters is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception

### Requirement: Server-Side Query Pushdown (Count / Sort / Paginate)

Backend services MUST push counting, sorting, and pagination down into OpenRegister's query engine
rather than fetching every matching object and counting / sorting / slicing in PHP. A service that
needs only a count MUST call `ObjectService::count(['filters' => …])`; a service that needs the most
recent or a page of objects MUST use `findAll`'s `sort`, `limit`, and `offset` config keys. Services
MUST call `ObjectService` with its real single-`$config`-array signature
(`findAll(config: […])` / `count(config: […])`), never the obsolete
`findAll(register: …, schema: …, limit: …)` positional/named form.

A predicate MAY remain in PHP **only** when OpenRegister's query engine cannot express it — namely
`SUM`/`AVG` aggregation, `NOT IN`, sorting on a computed/coalesced value, or `DISTINCT` over the
simple filter call path. Such a leg MUST carry an inline comment stating why it stays in PHP.

**Feature tier**: MVP

#### Scenario: Active-staff-for-role count pushes the role filter down

- GIVEN POS staff rows, some on the target role and some on other roles
- WHEN `PosRoleService::countActiveStaffForRole()` is called
- THEN the `posRole` equality MUST be applied server-side via `findAll(config:)`
- AND staff on other roles MUST NOT be fetched into PHP
- AND a staff row with a missing `isActive` field MUST still count as active (the `isActive` default stays in PHP)

#### Scenario: Latest forecast snapshot is selected by server-side sort + limit

- GIVEN multiple forecast snapshots for an owner / period / level
- WHEN `ForecastService::latestSnapshot()` resolves the most recent snapshot
- THEN it MUST request `sort` by `as_of_date` descending with `limit` 1
- AND it MUST NOT fetch every snapshot and sort them in PHP

#### Scenario: Paginated list uses a server-side total and page window

- GIVEN more matching objects than one page holds
- WHEN a paginated list (e.g. `BlastService::listBlasts`, `ForecastExportService::exportSnapshots`) is built
- THEN the total MUST come from `ObjectService::count()`
- AND the page MUST come from a `findAll` with `limit` and `offset`
- AND the service MUST NOT fetch the full result set and `array_slice` it in PHP

#### Scenario: An OR-unexpressible predicate stays in PHP with a stated reason

- GIVEN a predicate OpenRegister cannot express (e.g. a `NOT IN` over terminal statuses, or a `SUM` with a per-row floor)
- WHEN the service computes it
- THEN that specific leg MAY remain a PHP loop
- AND the code MUST carry an inline comment explaining why it cannot be pushed down

### Requirement: Declarative Object Lifecycle State Machines

Backend services that gate object `status` transitions MUST declare the transition graph in the
schema's `configuration.x-openregister-lifecycle` annotation (ADR-031), rather than maintaining a
hardcoded PHP adjacency map as the source of truth. OpenRegister's `LifecycleValidationListener`
enforces the declared graph automatically on every `ObjectService::saveObject()`.

A service MAY keep a thin PHP transition guard, but that guard MUST derive its allowed-transition
set from the schema declaration (not from a duplicate hardcoded constant). A guard is retained ONLY
to preserve an existing error contract (specific exception type, message, or HTTP status) or to
validate before the object reaches `saveObject()`.

A predicate MAY remain in a PHP guard **only** when the declarative lifecycle grammar cannot express
it — namely cross-object business invariants such as date-range coherence, "at least one related
rule exists", or "at least one redemption option exists". Such a predicate MUST carry an inline
comment stating why it stays in PHP.

**Feature tier**: MVP

#### Scenario: Callback task transitions are sourced from the schema

- GIVEN the Task schema declares `x-openregister-lifecycle` (open→in_behandeling→afgerond/verlopen, reopen)
- WHEN `CallbackService::validateStatusTransition('open', 'in_behandeling')` is called
- THEN it MUST return valid, having read the allowed set from the schema declaration
- AND `validateStatusTransition('open', 'afgerond')` MUST return invalid with a "not allowed" reason
- AND the controller MUST still surface an illegal transition as HTTP 400

#### Scenario: Walk-in ticket lifecycle is declared and enforced

- GIVEN the walkInTicket schema declares `x-openregister-lifecycle` (waiting→called/abandoned, called→served/abandoned; served/abandoned terminal)
- WHEN `WalkInQueueService::assertTransitionAllowed('waiting', 'called')` is called
- THEN it MUST pass, having derived the graph from the schema declaration
- AND `assertTransitionAllowed('served', 'called')` MUST throw `InvalidArgumentException` with the existing message
- AND a save that flips the ticket status to a value no transition allows MUST be rejected by OpenRegister's listener

#### Scenario: Loyalty activation moves the graph edge to the schema but keeps business guards in PHP

- GIVEN the loyaltyProgramme schema declares `x-openregister-lifecycle` including concept→actief
- WHEN `LoyaltyProgrammeService::activate()` is called on a `concept` programme whose business guards pass
- THEN the concept→actief edge MUST be validated against the schema declaration
- AND the activation MUST succeed and persist `status = actief`
- WHEN activation is attempted on a programme that fails a business guard (no rules, no redemption options, or incoherent dates)
- THEN it MUST still raise the existing `RuntimeException` with the same message, because that guard cannot be expressed declaratively

### Requirement: Money-Path Object Lifecycle State Machines Are Schema-Declared

Revenue and booking services that gate an object's status transitions MUST declare the transition
graph in the schema's `configuration.x-openregister-lifecycle` annotation (ADR-031) as the single
source of truth, rather than a hardcoded PHP adjacency map. OpenRegister's
`LifecycleValidationListener` enforces the declared graph automatically on every
`ObjectService::saveObject()`. A thin PHP guard MAY be retained, but it MUST derive its
allowed-transition set (and terminal set) from the schema declaration, and it is retained ONLY to
preserve an existing error contract (exception type, message, HTTP status) or pre-save validation.

The migration MUST be strictly behavior-preserving: no transition legal before the change becomes
illegal, and none illegal before becomes legal. A conditional predicate MAY remain in a PHP guard
ONLY when the declarative grammar cannot express it (engine-only origin, "requires a related won
lead", "requires a non-empty reason", date-range coherence), and MUST carry an inline comment.

When a schema needs a **second** independent state machine on a different field, and OpenRegister's
single enforced lifecycle field is already claimed by another field, that second machine MUST NOT be
declared as a second `x-openregister-lifecycle` (OpenRegister enforces only one lifecycle field per
schema). It MUST instead be declared under an app-namespaced `configuration` key and enforced by the
app, with the schema annotation remaining the source of truth for its state partition.

**Feature tier**: MVP

#### Scenario: Contract transitions derive terminal set and graph from the schema

- GIVEN the contract schema declares `x-openregister-lifecycle` on `status` with
  `terminal: [renewed, churned, cancelled]` and transitions covering every reachable
  non-terminal→target edge the service permits today
- WHEN `ContractService::assertTransitionAllowed(['status' => 'active'], 'expiring', byEngine: true)` is called
- THEN it MUST pass, having read the terminal set + adjacency from the schema declaration
- AND `assertTransitionAllowed(['status' => 'churned'], 'active')` MUST throw `InvalidArgumentException`
  reporting the terminal state (terminal set sourced from the schema)
- AND `assertTransitionAllowed(['status' => 'active'], 'renewed')` without a won renewal lead MUST still
  throw `InvalidArgumentException` with the existing "requires a won renewal lead" message (a conditional
  predicate kept in PHP)
- AND a `saveObject()` that flips a `renewed` (terminal) contract's status back to `active` MUST be
  rejected by OpenRegister's `LifecycleValidationListener` as defense-in-depth

#### Scenario: Booking state machine is sourced from the schema and preserves its messages

- GIVEN the booking schema declares `x-openregister-lifecycle` on `status` mirroring the prior
  `allowedTransitions()` map (pending-deposit/confirmed sources; completed/no-show/cancelled-*/rescheduled terminal)
- WHEN `BookingService::allowedTransitions()` is read
- THEN it MUST equal the prior hardcoded map, having been derived from the schema declaration
- AND `BookingService::assertTransitionAllowed('pending-deposit', 'confirmed')` MUST pass
- AND `assertTransitionAllowed('confirmed', 'pending-deposit')` MUST throw `InvalidArgumentException`
  with the existing "Invalid status transition" message
- AND `assertTransitionAllowed('completed', 'confirmed')` MUST be rejected (terminal state has no outgoing edge)

#### Scenario: Forecast-category partition moves to the schema but enforcement stays in PHP

- GIVEN the lead schema already declares `x-openregister-lifecycle` on `status`, and OpenRegister enforces
  only one lifecycle field per schema
- AND the forecast-category open/closed partition and default are declared under the app-namespaced
  `configuration.x-pipelinq-forecast-lifecycle` key on the lead schema
- WHEN `ForecastDealService::validateTransition()` moves a `closed_won` deal to an open category
- THEN it MUST be rejected with `forecast.error.closed_deal_locked`, having read the open/closed partition
  from the schema annotation (not a hardcoded constant)
- AND the `DealUpdatedListener` revert-after-write behavior MUST be unchanged (OpenRegister's listener does
  NOT enforce this second field)
- AND a new deal created without a `forecast_category` MUST default to `pipeline` via the schema property
  `default` (`defaultBehavior: "falsy"`), with `DealCreatedListener` retained as an idempotent backstop

### Requirement: Server-Side Aggregation Pushdown (Sum / Count / Group)

Backend money / reporting services MUST push `SUM` and grouped `COUNT` work down into OpenRegister's
ad-hoc aggregation runner rather than hydrating every matching object and reducing in PHP. A service
that needs a total over a column MUST call
`AggregationRunner::runAdhocByRef($registerRef, $schemaRef, AggregationQuery::create(metric: 'sum',
field: …, filter: …))`; a service that needs per-key counts MUST add `groupBy: ['field' => …]`. The
runner MUST be resolved from the DI container the same way `ObjectService` is. A pushed-down call
MUST degrade to the prior fallback (`0` / empty / the original PHP reduce) when OpenRegister is
unavailable, so the report still renders.

A computation MAY remain a PHP loop **only** when the OpenRegister aggregation contract cannot
express it — namely a per-row transformation before the aggregate (e.g. a `max(0, …)` floor or a
per-row sign that is not reducible to a status split), an aggregate over a value nested inside a
row's array property rather than a top-level column, a date window that is string-compared against a
differently-formatted stored timestamp, a coalesced/defaulted/derived bucket key, or a case-folded
`NOT IN`. Such a leg MUST carry an inline comment stating why it stays in PHP, and its numeric result
MUST NOT change.

**Feature tier**: MVP

#### Scenario: Confirmed-sales total is summed server-side over a date window

- GIVEN POS transactions with mixed statuses and confirmation timestamps
- WHEN `CashShiftService::sumConfirmedSales(from, to)` computes the in-window sales total
- THEN it MUST request `SUM(total)` via `runAdhocByRef` with `status IN (confirmed, settled)` and a `confirmedAt` `gte`/`lte` window
- AND it MUST NOT fetch every transaction and sum them in PHP
- AND the returned total MUST equal the prior PHP window-sum to the cent

#### Scenario: Account balance is summed server-side over the full ledger

- GIVEN a points ledger with credit, debit, and expiry entries for an account
- WHEN `PointsLedgerService::getAccountBalance(accountId)` computes the live balance
- THEN it MUST request `SUM(aantal)` via `runAdhocByRef` filtered by `accountId`
- AND an account with no ledger entries MUST balance to 0 (the runner's `null` result casts to 0)
- AND the returned balance MUST equal the prior sum over the full ledger history

#### Scenario: Tier distribution is counted server-side with a default bucket preserved

- GIVEN loyalty accounts for a programme, some with no `currentTierId`
- WHEN `LoyaltyReportingService::getTierReport(programmeId)` builds the per-tier counts
- THEN it MUST request a grouped `COUNT` by `currentTierId` via `runAdhocByRef`
- AND accounts with a missing or empty `currentTierId` MUST be folded into the `unassigned` bucket
- AND the per-tier counts MUST equal the prior PHP bucketing

#### Scenario: Per-staff sales reproduce refund netting via a signed split sum

- GIVEN final-status POS transactions (confirmed / settled / refunded) for several staff members
- WHEN `PosStaffReportService::staffSalesReport()` aggregates per staff member
- THEN the `transactionCount` MUST come from a grouped `COUNT` over all three final statuses
- AND each staff total MUST be `SUM(total | confirmed,settled) − SUM(total | refunded)` (and likewise for tax), reconstructing the per-row refund sign
- AND a transaction with an empty `staffMemberId` MUST be excluded
- AND the per-staff `transactionCount` / `total` / `totalTax` MUST equal the prior PHP reduce

#### Scenario: A per-row-floored or windowed-by-string sum stays in PHP with a stated reason

- GIVEN an outstanding-points liability sum that applies a per-account `max(0, …)` floor, or a ledger
  window that string-compares a stored timestamp OpenRegister normalises to a different format
- WHEN the service computes it
- THEN that specific leg MAY remain a PHP loop
- AND the code MUST carry an inline comment explaining why it cannot be pushed down
- AND its numeric result MUST be unchanged from before the refactor

### Requirement: Server-Side COUNT / Facet and Filtered-List Pushdown (non-GDPR)

Backend non-GDPR read services MUST push grouped `COUNT` work down into OpenRegister's ad-hoc
aggregation runner, and filtered-list work into `ObjectService::findAll` filters, rather than
hydrating every matching object and filtering / grouping / counting in PHP. A service that needs
per-key counts MUST call `AggregationRunner::runAdhocByRef($registerRef, $schemaRef,
AggregationQuery::create(metric: 'count', filter: …, groupBy: ['field' => …]))`; a service that needs
a filtered subset MUST pass the equality / `IN` filters to `findAll(['filters' => …])`. The runner
MUST be resolved from the DI container the same way `ObjectService` is. A pushed-down call MUST
degrade to the prior fallback (empty list / empty counts / partial payload) when OpenRegister is
unavailable, so the page still renders.

A computation MAY remain a PHP loop **only** when the OpenRegister contract cannot express it —
namely a **computed bucket key** (a group key derived in PHP, e.g. resolving a stored ref to a slug),
a **cross-source merge** (combining separate schemas or an array property on a row), a date window
that is **string-compared with an ISO-`T` / end-of-day boundary** or a **timezone-aware
`DateTimeImmutable`** against a differently-formatted stored timestamp, a sort/paginate that MUST run
**after a per-row IDOR filter**, an aggregate over a value that must be **computed per row** (e.g. an
ISO-8601 duration sum), or a **case-folded** match. Such a leg MUST carry an inline comment stating
why it stays in PHP, and its result MUST NOT change.

The GDPR-scoped services (`AvgRequestService`, `DpiaDetectionService`, `ConsentService`,
`OptOutService`, `RedactionService`, `EvidenceCollectionService`) are out of scope for this delta and
MUST NOT be modified here.

**Feature tier**: MVP

#### Scenario: A user's open requests are filtered server-side

- GIVEN requests with various assignees and statuses
- WHEN `KccWerkplekService::getWorkspaceState(userId)` builds the agent's assigned-and-open list
- THEN it MUST request the subset via `findAll` with `assignee = userId` and `status IN (new, in_progress)`
- AND it MUST NOT fetch every request and filter them in PHP for this list
- AND the returned `assignedRequests` MUST equal the prior PHP-filtered list (same rows, same order)

#### Scenario: A cross-source, computed-bucket, ISO-T-windowed, or post-IDOR leg stays in PHP with a stated reason

- GIVEN a builder that buckets by a PHP-computed week key over pre-fetched rows that also drive an
  empty-result guard, OR a timeline that merges several schemas with an end-of-day ISO-`T` date window,
  OR a portal facade that sorts and paginates only after a per-row IDOR scope filter, OR a cache lookup
  that selects the most-recent unexpired row via a timezone-aware `DateTimeImmutable` compare
- WHEN the service computes it
- THEN that specific leg MAY remain a PHP loop
- AND the code MUST carry an inline comment explaining why it cannot be pushed down
- AND its result MUST be unchanged from before the refactor

### Requirement: AVG Request Status Lifecycle Is Schema-Declared

The `avgVerzoek` schema MUST declare its `status` transition graph in
`configuration.x-openregister-lifecycle` (ADR-031): `field: status`,
`initial: ingediend`, `final: [afgerond, gearchiveerd]`, and a transition map in
which each of the seven working states (ingediend, in-behandeling,
bewijs-verzamelen, redactie, bundle-genereren, wachten-op-verzoeker,
weigering-opgesteld) may move to any declared status, and the two terminal states
have no outgoing transitions. The declared graph MUST mirror the permit set the
service enforced before this change — no edge opened or closed.

`AvgRequestService::update()` MUST derive its status-transition validation from
this schema declaration (via `SchemaLifecycleGraph`), not from a hardcoded copy.
An illegal status move MUST be rejected with `OCSBadRequestException` (HTTP 400),
preserving the existing exception type and message contract. OpenRegister's
`LifecycleValidationListener` enforces the same declared graph on
`ObjectService::saveObject()` as defense-in-depth.

The side-effecting legal computations MUST remain in PHP because the declarative
lifecycle grammar cannot express them: the intake reference + legal-deadline
computation, the archive 5-year `retentieTot` stamp, the retention-guarded delete
with FG/DPO override, and the allowed-FIELDS enforcement on update. Per-transition
RBAC `authorization` MUST NOT be added, because no individual edge is role-gated
today — *who* may edit is gated once by `AvgAccessService` (handler / team lead /
FG-DPO / admin), and per-edge authorization would change that contract.

**Feature tier**: MVP

#### Scenario: avgVerzoek lifecycle is declared and resolves from the schema

- GIVEN the avgVerzoek schema declares `x-openregister-lifecycle` (field `status`, initial `ingediend`, final `afgerond`/`gearchiveerd`)
- WHEN `SchemaLifecycleGraph::fullAdjacencyFor('avgVerzoek')` resolves the adjacency map
- THEN each of the seven working states MUST reach all nine declared states
- AND the two terminal states (`afgerond`, `gearchiveerd`) MUST be present as keys with empty target lists
- `@e2e exclude` backend schema-resolution invariant; covered by AvgRequestServiceTest unit test

#### Scenario: A legal status transition succeeds with preserved behaviour

- GIVEN a request the acting handler may edit, in a working state (e.g. `redactie`)
- WHEN `update()` patches `status` to a declared target (e.g. `bundle-genereren`)
- THEN the transition MUST be validated against the schema-derived graph and persist the new status
- `@e2e exclude` backend service-layer transition; covered by the unit transition-matrix test

#### Scenario: An illegal status transition is rejected with the same error contract

- GIVEN a request in a terminal state (`afgerond` or `gearchiveerd`)
- WHEN `update()` is called with any status patch
- THEN it MUST raise `OCSBadRequestException` with the message "Een afgerond verzoek kan niet meer worden gewijzigd."
- AND WHEN `update()` is called with a status value not in the avgVerzoek enum
- THEN it MUST raise `OCSBadRequestException` with an "Onbekende AVG-status" message
- AND a `saveObject()` that flips the status to a value no declared transition allows MUST be rejected by OpenRegister's `LifecycleValidationListener`
- `@e2e exclude` backend error-contract invariant; covered by the unit transition-matrix test + OR listener (defense-in-depth)

#### Scenario: Legal computations stay in PHP

- GIVEN an AVG request lifecycle action with a legal side-effect
- WHEN intake computes the reference + `wettelijkeTermijnVerloopt`, or archive stamps `retentieTot`, or delete enforces the retention guard with DPO override
- THEN these computations MUST run in `AvgRequestService` PHP, unchanged, because the declarative lifecycle grammar cannot express them
- `@e2e exclude` backend legal-computation invariant; covered by existing AvgRequestServiceTest scenarios

## Requirements

---

### Requirement: Register Configuration Visibility Annotations

Schema properties MUST use the `visible` flag to control which properties appear in list views versus detail views.

**Feature tier**: MVP

#### Scenario: Visible properties appear in list views

- GIVEN a schema property does NOT have `"visible": false`
- WHEN the frontend renders a list or table view
- THEN the property MUST be included as a column

#### Scenario: Hidden properties only appear in detail views

- GIVEN the `client` schema has `address`, `website`, `notes`, and `contactsUid` marked with `"visible": false`
- WHEN the frontend renders the client list view
- THEN those properties MUST NOT be displayed as columns
- AND they MUST still be editable in the client detail/edit view

#### Scenario: Lead schema hides secondary properties

- GIVEN the `lead` schema has `probability`, `pipeline`, `stageOrder`, and `notes` marked with `"visible": false`
- WHEN the frontend renders the lead list
- THEN those properties MUST be omitted from the list columns
- AND `title`, `client`, `source`, `value`, `expectedCloseDate`, `assignee`, `priority`, `stage`, `status` MUST be visible

---

### Requirement: Property Title Annotations for Display Labels

Schema properties MAY include a `title` field that overrides the property key as the display label in the UI.

**Feature tier**: MVP

#### Scenario: Properties with titles use custom labels

- GIVEN the `client.type` property has `"title": "Client type"`
- WHEN the frontend renders a column header or form label for `type`
- THEN it MUST display "Client type" instead of "type"

#### Scenario: Properties without titles use the property key

- GIVEN the `client.name` property has no `title` field
- WHEN the frontend renders a column header or form label
- THEN it MUST display "name" (or a title-cased version: "Name")

---

### Requirement: Error Recovery for Partial Import Failures

When the repair step encounters failures during one phase of initialization, it MUST continue with subsequent phases and report all failures.

**Feature tier**: MVP

#### Scenario: Configuration import fails but default pipelines succeed

- GIVEN `ConfigurationService::importFromApp()` throws a database exception
- WHEN the repair step continues
- THEN it MUST still attempt to create default pipelines
- AND it MUST still attempt to create default system tags
- AND each failure MUST be individually logged with `$output->warning()` and `LoggerInterface::error()`

#### Scenario: Default pipeline creation fails but tags succeed

- GIVEN default pipeline creation throws an exception (e.g., ObjectService unavailable)
- WHEN the repair step continues
- THEN it MUST still attempt to create default system tags
- AND the progress bar MUST still advance correctly
- AND `$output->finishProgress()` MUST be called

#### Scenario: All phases fail gracefully

- GIVEN all three phases (config import, pipelines, tags) throw exceptions
- WHEN the repair step completes
- THEN it MUST have logged 3 warnings and 3 errors
- AND it MUST have called `$output->finishProgress()`
- AND it MUST NOT have thrown an unhandled exception to the Nextcloud repair runner

---

### Requirement: Settings API Authentication and Admin Detection

The settings endpoint MUST distinguish between regular users and admin users, and the frontend MUST use this to control admin-only features.

**Feature tier**: MVP

#### Scenario: Settings response includes admin flag

- GIVEN an admin user requests `GET /apps/pipelinq/api/settings`
- THEN the response MUST include `"isAdmin": true`
- AND the settings store MUST set `settingsStore.isAdmin = true`

#### Scenario: Non-admin user settings response

- GIVEN a regular user requests `GET /apps/pipelinq/api/settings`
- THEN the response MUST include `"isAdmin": false`
- AND admin-only features (pipeline management, system configuration) MUST be hidden in the UI

#### Scenario: Settings request uses Nextcloud authentication

- GIVEN the frontend makes a settings request
- THEN it MUST include the `requesttoken` header with `OC.requestToken`
- AND it MUST include `OCS-APIREQUEST: true`
- AND it MUST use `Content-Type: application/json`

---

### Current Implementation Status

**Implemented:**
- **Register Configuration File:** `lib/Settings/pipelinq_register.json` exists and is valid JSON with `openapi: "3.0.0"`. It defines the `pipelinq` register with 8 schemas: `client`, `contact`, `lead`, `request`, `pipeline`, `product`, `productCategory`, `leadProduct`. Each has `@type` annotations. A default view (`default-pipeline-view`) is defined.
- **Register Configuration Format Compliance:** Full compliance with the `importFromApp()` contract. `x-openregister` metadata block, `components.registers`, `components.schemas`, and view definitions are all present. `ConfigFileLoaderService` handles JSON loading with proper error handling. `ensureSourceType()` adds `sourceType: "local"`.
- **Auto-Configuration (Repair Step):** `lib/Repair/InitializeSettings.php` implements a full repair step with 4-step progress reporting. It checks for OpenRegister availability, imports configuration via `SettingsLoadService`, creates default pipelines via `DefaultPipelineService`, and creates default system tags via `SystemTagService`. Each step has independent error handling.
- **Schema-to-IAppConfig Mapping:** `SettingsLoadService` and `SettingsMapBuilder` extract schema IDs, register IDs, and view IDs from import results and store them in IAppConfig. All 8 schema slugs are mapped.
- **Default Pipelines:** `DefaultPipelineService` creates "Sales Pipeline" (7 stages with probability values, isClosed/isWon flags, colors, propertyMappings, totalsLabel, viewId) and "Service Requests" pipeline (5 stages). Uses `PipelineStageData` for stage definitions. Checks for existing "Sales Pipeline" by title before creating. Saves with `_rbac: false` and `_multitenancy: false`.
- **Default System Tags:** Repair step creates default lead sources (9 values) and request channels (5 values) via `SystemTagService::ensureDefaults()`.
- **Store Registration:** `src/store/store.js` `initializeStores()` registers all 8 entity types with conditional checks for both register ID and schema ID.
- **Frontend API Interaction (CRUD):** Uses `@conduction/nextcloud-vue`'s `createObjectStore('object')` with 4 plugins (`filesPlugin`, `auditTrailsPlugin`, `relationsPlugin`, `registerMappingPlugin`). All CRUD operations follow the `/apps/openregister/api/objects/{register}/{schema}` pattern.
- **Search and Filtering:** Used throughout views via `_search`, `_order`, `_page`, `_limit` query parameters.
- **Pinia Store Pattern:** Centralized via `createObjectStore('object')` from `@conduction/nextcloud-vue`. All entity types share the same store instance with per-type registration.
- **Schema Validation:** Handled server-side by OpenRegister. Frontend displays validation errors from API responses.
- **Cross-Entity References:** Lead references client, contact, pipeline. Contact references client. LeadProduct references lead and product. Product references productCategory. All resolved by the `relationsPlugin`.
- **Audit Trail:** Enabled via the `auditTrailsPlugin` in the object store.
- **Object Event Handling:** `ObjectEventHandlerService` processes created/updated objects for leads and requests. Uses `SchemaMapService` for entity type resolution and `ObjectUpdateDiffService` for change detection.
- **Performance:** Server-side pagination used throughout. Pipeline board fetches with `_limit=200`.
- **OpenRegister Health Check:** Settings endpoint returns `openRegisters` boolean. Repair step checks `IAppManager::getInstalledApps()`.
- **Settings API:** Includes `config`, `openRegisters`, and `isAdmin` in response. Uses Nextcloud authentication headers.
- **Visibility and Title Annotations:** Properties marked with `visible: false` and `title` overrides throughout the register JSON.
- **Facetable Properties:** All categorical/enum properties annotated with `facetable: true`.

**Not yet implemented:**
- **RBAC Integration:** No frontend visibility/permission checks based on user roles. No 403 handling in the UI for insufficient permissions. No organisation-scoped data filtering.
- **Concurrent modification conflict detection:** No ETag or version-based conflict detection.
- **Batched reference resolution:** The `relationsPlugin` from `@conduction/nextcloud-vue` handles this, but the implementation details depend on the library version.
- **Client-side validation before submission:** Minimal -- most validation is server-side.
- **Missing schema handling:** The graceful skip for missing schemas in `initializeStores()` is implemented (conditional checks), but no user-facing warning is shown.
- **Multi-tenancy:** OpenRegister supports it, but Pipelinq does not yet configure tenant-scoped data access for regular users.

**Partial implementations:**
- Error handling exists but is minimal in some views -- generic `showError()` calls without detailed field-level validation display.
- Settings fetch failure is handled (error state set, returns null) but no "configuration unavailable" UI state is rendered.

### Standards & References
- **OpenAPI 3.0.0:** Used as the format for `pipelinq_register.json`.
- **Schema.org:** Type annotations on all schemas (Person, ContactPoint, Demand, ItemList, Product, DefinedTermSet, Offer).
- **vCard (RFC 6350):** Contact schema field conventions.
- **OpenRegister API conventions:** REST patterns for CRUD, pagination (`_page`, `_limit`), search (`_search`), sorting (`_order`), filtering by property value, faceted search.
- **OpenRegister importFromApp() contract:** `x-openregister` metadata, `components.registers`, `components.schemas`, version-based re-import, idempotent import.
