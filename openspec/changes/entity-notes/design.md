# Design: entity-notes

## Architecture

### Data Model

No new OpenRegister schemas are introduced by this change. All data is stored using:

- **OpenRegister built-in `notes` field** — available on every object; managed by OpenRegister's object layer. Accessed via `CnObjectSidebar` Notes tab.
- **`contactmoment` schema** (defined in omnichannel-registratie) — existing entity. Communication history is a read-only view of `contactmoment` objects linked to the current entity via the `relationsPlugin` (`fetchUsed`).

### Reuse Analysis

| Capability needed | OpenRegister / @conduction/nextcloud-vue component | Action |
|---|---|---|
| Notes on entities | `CnObjectSidebar` → Notes tab via `notesPlugin` | Configure — no custom code |
| Notes display | `CnNotesCard` (embedded in `CnObjectSidebar`) | Reuse — no custom code |
| Linked contactmomenten lookup | `relationsPlugin` → `fetchUsed(entityType, entityId)` | Configure with entity type |
| Communication history display | `CnDetailCard` + `CnDataTable` | Compose — new `CommunicationHistory.vue` |
| Pagination of activity | `CnPagination` | Reuse |
| REST API query | `ObjectService.findObjects($register, $schema, $params)` | Wrap in `ActivityController` |
| CRUD forms | Not needed — notes handled by platform | N/A |
| Full-text search | `IndexService` (already active on contactmomenten) | No change needed |

No overlap with existing custom controllers. `omnichannel-registratie` creates contactmomenten; this change reads them in entity context.

### Backend

#### ActivityController (`lib/Controller/ActivityController.php`)

Single controller exposing the activity query API. `@NoAdminRequired`.

| Method | URL | Description |
|--------|-----|-------------|
| GET | `/api/activity/{entityType}/{entityId}` | List activity instances for an entity |

**Query parameters:**
- `_page` (integer, default: 1) — page number
- `_limit` (integer, default: 20) — page size
- `type` (string, enum: `notes|contactmomenten|all`, default: `all`) — filter by activity type

**Response shape:**
```json
{
  "total": 12,
  "page": 1,
  "pages": 1,
  "results": [
    {
      "type": "contactmoment",
      "id": "uuid",
      "subject": "Vraag over vergunning",
      "channel": "telefoon",
      "agent": "jdvries",
      "timestamp": "2026-04-10T09:15:00Z",
      "summary": "Burger belde over status aanvraag..."
    }
  ]
}
```

Valid `entityType` values: `client`, `contact`, `lead`, `request`.

**Error handling:**
- Unknown `entityType` → `400 Bad Request` with `{"message": "Invalid entity type"}`
- Entity not found → `404 Not Found` with `{"message": "Entity not found"}`
- Auth failure → Nextcloud handles (401/403)

#### ActivityService (`lib/Service/ActivityService.php`)

Business logic layer. Stateless.

- `getActivity(string $entityType, string $entityId, string $type, int $page, int $limit): array`
  — Queries OpenRegister for contactmomenten linked to the entity via `ObjectService::findObjects()`.
  Returns merged, sorted (reverse-chronological) result set with pagination metadata.

**Implementation notes:**
- Uses `ObjectService::findObjects($register, $schema, ['client' => $entityId, '_page' => $page, '_limit' => $limit])` for contactmomenten.
- Notes are read from the entity's built-in `notes` field via `ObjectService::findObject()`.
- NEVER calls mappers directly (ADR-003-backend).
- NEVER returns `$e->getMessage()` to the API response.

### Frontend

#### CommunicationHistory.vue (`src/components/CommunicationHistory.vue`)

Reusable component embedded in entity detail pages. Shows linked contactmomenten in a `CnDetailCard`.

**Props:**
- `entityType` (string, required) — e.g. `client`, `contact`
- `entityId` (string, required) — entity UUID

**Data:**
- `items: []` — fetched activity records
- `loading: false` — loading state
- `page: 1`, `total: 0` — pagination state

**Methods:**
- `fetchHistory()` — GET `/api/activity/{entityType}/{entityId}?type=contactmomenten&_page={page}&_limit=10`
  Wrapped in `try/catch` with `this.$toast.error(...)` on failure (ADR-004-frontend).
- `goToContactmoment(id)` — `$router.push({ name: 'ContactmomentDetail', params: { id } })`

**Template structure:**
```
<CnDetailCard :title="t('pipelinq', 'Communication History')">
  <template #header-actions>
    <NcButton @click="fetchHistory">{{ t('pipelinq', 'Refresh') }}</NcButton>
  </template>
  <div v-if="loading"><NcLoadingIcon /></div>
  <div v-else-if="items.length === 0">
    <CnEmptyState :description="t('pipelinq', 'No communication history')" />
  </div>
  <CnDataTable v-else :columns="columns" :rows="items" @row-click="goToContactmoment" />
  <CnPagination :total="total" :page="page" @change="page = $event; fetchHistory()" />
</CnDetailCard>
```

**Columns:** Channel icon | Subject | Agent | Date/time

**i18n keys:** `Communication History`, `Refresh`, `No communication history`, `Channel`, `Subject`, `Agent`, `Date`.

#### CnObjectSidebar configuration

`CnObjectSidebar` is already rendered in `App.vue` at `NcContent` level (per ADR-017-component-composition). The Notes tab is activated by passing the entity's `uuid` and schema slug as props to `CnObjectSidebar`.

No new sidebar component code is required. The existing entity detail views must pass the `objectSidebarState` correctly so the sidebar opens with the Notes tab available. Verify that each detail view calls `provide('objectSidebarState', ...)` or passes it as a prop to `CnDetailPage`.

#### Detail View Integration

Add `<CommunicationHistory>` to the four entity detail views, inside the view-mode section (not edit mode, not loading, not new record):

```vue
<!-- ClientDetail.vue, ContactDetail.vue, LeadDetail.vue, RequestDetail.vue -->
<CommunicationHistory
  v-if="!isNew && !loading && !editing"
  entity-type="client"
  :entity-id="entityId" />
```

Each view uses its own `entity-type` value: `client`, `contact`, `lead`, `request`.

## Seed Data

> **Scope:** The `contactmoment` schema is defined by omnichannel-registratie. The seed data below provides realistic linked contactmomenten for testing the communication history view. All objects use the `@self` envelope per ADR-001-data-layer.

Five contactmoment seed objects (Dutch municipality / non-profit context):

**contactmoment-001**
```json
{
  "@self": { "register": "pipelinq", "schema": "contactmoment", "slug": "contactmoment-001" },
  "subject": "Vraag over status vergunningsaanvraag",
  "summary": "Mevrouw Bakker belde in over de status van haar omgevingsvergunning. Verwachte afhandeling binnen 3 werkdagen toegezegd.",
  "channel": "telefoon",
  "outcome": "Informatie verstrekt",
  "agent": "mvanderberg",
  "contactedAt": "2026-04-14T09:12:00Z",
  "duration": "PT7M30S",
  "channelMetadata": { "direction": "inbound", "phoneNumber": "085-0123456" }
}
```

**contactmoment-002**
```json
{
  "@self": { "register": "pipelinq", "schema": "contactmoment", "slug": "contactmoment-002" },
  "subject": "Klacht over aanslagbiljet gemeentelijke belasting",
  "summary": "De heer Jansen stuurde een e-mail met bezwaar tegen het aanslagbiljet. Doorverwezen naar de afdeling Belastingen.",
  "channel": "e-mail",
  "outcome": "Doorverwezen",
  "agent": "sdejong",
  "contactedAt": "2026-04-11T14:30:00Z",
  "channelMetadata": { "emailThreadId": "MSG-20260411-4492", "direction": "inbound" }
}
```

**contactmoment-003**
```json
{
  "@self": { "register": "pipelinq", "schema": "contactmoment", "slug": "contactmoment-003" },
  "subject": "Baliebezoch — aanvraag parkeervergunning",
  "summary": "Meneer Ahmed Hassan bezocht de balie voor een parkeervergunning bewonerszone C. Aanvraagformulier ingevuld en ingediend.",
  "channel": "balie",
  "outcome": "Aanvraag ingediend",
  "agent": "lvanhouten",
  "contactedAt": "2026-04-10T10:45:00Z",
  "channelMetadata": { "counterLocation": "Balie 3 — Stadhuis" }
}
```

**contactmoment-004**
```json
{
  "@self": { "register": "pipelinq", "schema": "contactmoment", "slug": "contactmoment-004" },
  "subject": "Chatgesprek — informatie over WMO-aanvraag",
  "summary": "Mevrouw Pieterse vroeg via de chat naar de benodigde documenten voor een WMO-aanvraag. Documentenlijst per e-mail verstuurd.",
  "channel": "chat",
  "outcome": "Informatie verstrekt",
  "agent": "kbosma",
  "contactedAt": "2026-04-09T11:20:00Z",
  "channelMetadata": { "chatSessionId": "CS-20260409-8831" }
}
```

**contactmoment-005**
```json
{
  "@self": { "register": "pipelinq", "schema": "contactmoment", "slug": "contactmoment-005" },
  "subject": "Brief — bezwaar bestemmingsplan",
  "summary": "De stichting Groen Amsterdam diende een formeel bezwaarschrift in per brief tegen het herziene bestemmingsplan Noord. Ontvangst bevestigd.",
  "channel": "brief",
  "outcome": "In behandeling",
  "agent": "mvanderberg",
  "contactedAt": "2026-04-07T08:00:00Z",
  "channelMetadata": { "letterReference": "BZW-2026-0042" }
}
```

## Files Changed

### New Files
- `lib/Controller/ActivityController.php`
- `lib/Service/ActivityService.php`
- `src/components/CommunicationHistory.vue`

### Modified Files
- `appinfo/routes.php` — Add activity API route
- `src/views/clients/ClientDetail.vue` — Add `CommunicationHistory` section
- `src/views/contacts/ContactDetail.vue` — Add `CommunicationHistory` section
- `src/views/leads/LeadDetail.vue` — Add `CommunicationHistory` section
- `src/views/requests/RequestDetail.vue` — Add `CommunicationHistory` section
- `lib/Settings/pipelinq_register.json` — Add 5 contactmoment seed objects
