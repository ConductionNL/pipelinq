## Context

Pipelinq is a thin-client CRM app for Nextcloud that stores all data in OpenRegister. It currently manages clients, contact persons, leads, requests, and pipelines. The `omnichannel-registratie` spec defines the channel-aware registration form, and `contactmomenten-rapportage` defines reporting KPIs. This change adds the missing core layer: the Contactmoment entity itself, its CRUD operations, list/detail views, and integration into client and request detail pages.

All data is stored in OpenRegister via its REST API. The frontend (Vue 2.7 + Pinia) queries the OpenRegister API directly — Pipelinq has no own backend CRUD controllers. The register schema is defined in `lib/Settings/pipelinq_register.json` (OpenAPI 3.0.0 format) and imported via `ConfigurationService::importFromApp()` during the repair step.

## Goals / Non-Goals

**Goals:**
- Define the Contactmoment entity in the register schema with VNG Klantinteracties and Schema.org alignment
- Provide a contactmomenten list view with search, filter, sort, and pagination
- Provide a contactmoment detail view with full record display and linked entity navigation
- Provide a quick-log form that can be opened from client detail, request detail, or the list view
- Integrate contactmomenten into the client detail timeline and request detail view
- Create a Pinia store for contactmomenten CRUD via OpenRegister API

**Non-Goals:**
- Channel-specific form adaptation (covered by `omnichannel-registratie` spec)
- Reporting dashboards and KPIs (covered by `contactmomenten-rapportage` spec)
- Real-time notifications or push updates for new contactmomenten
- CTI (computer-telephony integration) or automatic call logging
- Attachment storage (files are managed by Nextcloud Files, only references stored)

## Decisions

### 1. Contactmoment as OpenRegister object (not Nextcloud Activity)

**Decision**: Store contactmomenten as first-class OpenRegister objects in the `pipelinq` register, not as Nextcloud Activity events.

**Rationale**: Contactmomenten need structured queryable fields (channel, duration, outcome, client reference) that Activity events cannot provide. OpenRegister gives us schema validation, search, filtering, and the same data access patterns used by clients/requests/leads.

**Alternative considered**: Using Nextcloud Activity API — rejected because it is append-only, unstructured, and cannot be queried by arbitrary fields.

### 2. Direct OpenRegister API access from frontend

**Decision**: The Pinia store calls OpenRegister's REST API directly (same pattern as clients, requests, leads). No Pipelinq backend controller needed for CRUD.

**Rationale**: Consistent with existing architecture. All other Pipelinq entities follow this pattern. Adding a backend proxy would add complexity without benefit.

### 3. Quick-log form as reusable Vue component

**Decision**: The quick-log form is a single Vue component (`ContactmomentQuickLog.vue`) that accepts optional `clientId` and `requestId` props for pre-filling context. It is used from the contactmomenten list view, client detail, and request detail.

**Rationale**: Avoids duplicating form logic across views. Pre-fill props make it contextual without separate form variants.

### 4. Client timeline integration via aggregated query

**Decision**: The client detail timeline queries OpenRegister for contactmomenten where `client` matches the current client ID, then merges results with other timeline events (leads, requests, notes) client-side.

**Rationale**: OpenRegister supports filtering by reference field. Client-side merge is simpler than a backend aggregation endpoint and consistent with how the existing timeline already works.

### 5. Navigation as top-level menu item

**Decision**: Add "Contactmomenten" as a top-level navigation item in the Pipelinq sidebar, between "Requests" and "Products".

**Rationale**: Contactmomenten are a primary CRM entity, not a sub-feature of clients or requests. KCC agents need direct access without navigating through a client first.

## Risks / Trade-offs

- **[Performance]** Client timeline merges multiple entity types client-side. For clients with hundreds of contactmomenten plus leads/requests, this could be slow. **Mitigation**: Paginate each entity type independently (20 per type), lazy-load on scroll.
- **[Data consistency]** Client or request deletion leaves orphaned contactmoment references. **Mitigation**: Reference fields store UUIDs; UI handles missing references gracefully (shows "[Deleted client]" placeholder).
- **[Schema migration]** Adding the Contactmoment schema to the register requires a repair step run. **Mitigation**: Standard pattern — `ConfigurationService::importFromApp()` already handles this for all entity types on app update.
