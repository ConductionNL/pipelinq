# OpenRegister Integration Specification

## Purpose

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

Note: Stages are stored as an embedded array within the `pipeline` schema (`stages: [{ name, order, probability? }]`), not as a separate `stage` schema. This simplifies the data model while maintaining all stage configuration capabilities.

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
- THEN it MUST define exactly 5 schemas: `client`, `contact`, `lead`, `request`, `pipeline`
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
- AND it MUST include `@type` annotation: `schema:Person`

#### Scenario: Contact schema defines vCard-aligned contact person properties

- GIVEN the `contact` schema in `pipelinq_register.json`
- THEN it MUST define:
  - `name` (string, required) -- vCard FN
  - `email` (string, format: email, optional) -- vCard EMAIL
  - `phone` (string, optional) -- vCard TEL
  - `role` (string, optional) -- vCard ROLE
  - `client` (string, format: uuid, optional) -- reference to client object

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

#### Scenario: Pipeline schema defines stage configuration

- GIVEN the `pipeline` schema in `pipelinq_register.json`
- THEN it MUST define:
  - `title` (string, required, max 255)
  - `description` (string, optional)
  - `entityType` (string, required, enum: lead, request, both)
  - `stages` (array of objects, required) -- each stage: `{ name, order, probability? }`
  - `isDefault` (boolean, default: false)
- AND it MUST include `@type` annotation: `schema:ItemList`

#### Scenario: Schema references use OpenRegister conventions

- GIVEN the `contact` schema in the configuration file
- THEN the `client` property MUST be defined as a reference type pointing to the `client` schema
- AND the `lead` schema's `pipeline` property MUST reference the `pipeline` schema
- AND the `lead` schema's `client` and `contact` properties MUST reference their respective schemas

---

### Requirement: Auto-Configuration on Install (Repair Step)

The system MUST import the register configuration during app installation and upgrades via a repair step. The repair step MUST be idempotent.

**Feature tier**: MVP

#### Scenario: First install creates register and schemas

- GIVEN Pipelinq is being installed for the first time
- AND no `pipelinq` register exists in OpenRegister
- WHEN the repair step runs
- THEN it MUST call `SettingsService::loadSettings()` which delegates to `ConfigurationService::importFromApp()`
- AND this MUST create the `pipelinq` register in OpenRegister
- AND this MUST create all 5 schemas: client, contact, lead, request, pipeline
- AND schema IDs MUST be stored in IAppConfig as `{slug}_schema` keys
- AND the register ID MUST be stored in IAppConfig as `register`
- AND the register MUST be queryable via the OpenRegister API immediately after

#### Scenario: Missing OpenRegister handled gracefully

- GIVEN Pipelinq is installed but OpenRegister is NOT installed
- WHEN the repair step runs
- THEN it MUST log a warning and skip initialization
- AND it MUST NOT throw an unhandled exception

#### Scenario: Upgrade with new schemas preserves existing data

- GIVEN Pipelinq is being upgraded from a version with 3 schemas to a version with 5 schemas
- AND the `pipelinq` register already contains 150 client objects
- WHEN the repair step runs
- THEN it MUST create the 2 new schemas without error
- AND it MUST NOT delete or modify existing schemas that have data
- AND all 150 existing client objects MUST remain intact and queryable

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

#### Scenario: Repair step creates default pipelines

- GIVEN Pipelinq is being installed for the first time
- WHEN the repair step runs
- THEN it MUST create a default "Sales Pipeline" with 7 stages (New, Contacted, Qualified, Proposal, Negotiation, Won, Lost) with correct order and probability values
- AND it MUST create a default "Service Requests Pipeline" with 5 stages (New, In Progress, Completed, Rejected, Converted to Case)
- AND the Sales Pipeline MUST be marked as the default pipeline (`isDefault: true`)

---

### Requirement: Store Registration

The frontend MUST register all 5 entity types in the Pinia object store on initialization via `initializeStores()`.

**Feature tier**: MVP

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

- GIVEN an existing client with UUID "f47ac10b-58cc-4372-a567-0e02b2c3d479"
- WHEN the frontend navigates to the client detail view
- THEN it MUST call `GET /index.php/apps/openregister/api/objects/pipelinq/client/f47ac10b-58cc-4372-a567-0e02b2c3d479`
- AND the response MUST contain the full client object with all properties

#### Scenario: Update an object

- GIVEN an existing client with UUID "f47ac10b-58cc-4372-a567-0e02b2c3d479"
- WHEN the user updates the email to "info@rivierenland.nl"
- THEN the frontend MUST call `PUT /index.php/apps/openregister/api/objects/pipelinq/client/f47ac10b-58cc-4372-a567-0e02b2c3d479`
- AND the request body MUST contain the updated client object
- AND the response MUST return the updated object

#### Scenario: Delete an object

- GIVEN an existing client with UUID "f47ac10b-58cc-4372-a567-0e02b2c3d479"
- WHEN the user confirms deletion
- THEN the frontend MUST call `DELETE /index.php/apps/openregister/api/objects/pipelinq/client/f47ac10b-58cc-4372-a567-0e02b2c3d479`
- AND the response MUST indicate successful deletion
- AND subsequent GET requests for this UUID MUST return 404

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

---

### Requirement: Pinia Store Pattern

The frontend MUST use Pinia stores for state management. Each entity type MUST have a dedicated store that wraps OpenRegister API calls and manages loading states, errors, and pagination.

**Feature tier**: MVP

#### Scenario: Store provides standard CRUD actions

- GIVEN the `clientStore` Pinia store
- THEN it MUST expose the following actions: `fetchClients()`, `fetchClient(id)`, `createClient(data)`, `updateClient(id, data)`, `deleteClient(id)`
- AND each action MUST construct the correct OpenRegister API URL using the pattern `/index.php/apps/openregister/api/objects/pipelinq/client`
- AND each action MUST set a `loading` state to `true` before the request and `false` after completion

#### Scenario: Store manages loading state

- GIVEN the user navigates to the client list
- WHEN `fetchClients()` is called
- THEN `clientStore.loading` MUST be `true` during the API request
- AND the UI SHOULD display a loading indicator
- AND when the request completes, `clientStore.loading` MUST be `false`
- AND `clientStore.clients` MUST contain the fetched data

#### Scenario: Store manages error state

- GIVEN the OpenRegister API returns a 500 error
- WHEN `fetchClients()` is called
- THEN `clientStore.error` MUST be set to an error object with message and status code
- AND `clientStore.loading` MUST be `false`
- AND the UI MUST display an error message to the user

#### Scenario: Store handles pagination state

- GIVEN the client store fetches a paginated list of 45 items
- THEN the store MUST track `total` (45), `page` (current page), and `limit` (page size) in state
- AND it MUST expose a `fetchPage(page)` action to load a specific page
- AND changing the page MUST trigger a new API request and update the items

#### Scenario: Store caches single entity reads

- GIVEN the user fetches client "f47ac10b" via `fetchClient(id)`
- WHEN the user navigates back to the same client detail
- THEN the store SHOULD return the cached object immediately
- AND the store SHOULD still make a background API call to check for updates
- AND if the data has changed, the store MUST update the cached object

#### Scenario: All entity stores follow the same pattern

- GIVEN the app has stores for client, contact, lead, request, and pipeline
- THEN each store MUST follow the same structure: state (items, currentItem, loading, error, total, page, limit), actions (fetchAll, fetchOne, create, update, delete)
- AND only the schema name and URL path segment MUST differ between stores

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

---

### Requirement: Cross-Entity References

Entities in Pipelinq reference each other via UUID fields stored on the OpenRegister objects. The system MUST maintain referential integrity and resolve references for display.

**Feature tier**: MVP

#### Scenario: Lead references a client

- GIVEN a lead object with `client: "f47ac10b-58cc-4372-a567-0e02b2c3d479"`
- WHEN the frontend displays the lead detail view
- THEN it MUST resolve the client UUID to fetch and display the client name
- AND clicking the client name MUST navigate to the client detail view

#### Scenario: Lead references a pipeline and stage

- GIVEN a lead with `pipeline: "pipe-uuid-1"` and `stage: "stage-uuid-3"`
- WHEN the frontend displays the lead detail view
- THEN it MUST resolve both references to display the pipeline name and stage title
- AND the pipeline progress indicator MUST show the current stage in context

#### Scenario: Contact references a client organization

- GIVEN a contact person with `client: "org-uuid-1"`
- WHEN the frontend displays the contact person list
- THEN it MUST resolve the client UUID and display the organization name in the list row

#### Scenario: Stage references a pipeline

- GIVEN a stage object with `pipeline: "pipe-uuid-1"`
- WHEN the frontend lists stages for admin configuration
- THEN it MUST group stages by their pipeline reference
- AND display them in the correct order within each pipeline

#### Scenario: Orphaned reference handling

- GIVEN a lead with `client: "deleted-uuid"` where the client has been deleted
- WHEN the frontend displays the lead
- THEN it MUST NOT crash or show a blank screen
- AND it SHOULD display "[Deleted client]" or similar placeholder text
- AND the lead MUST remain fully functional for editing other fields

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
