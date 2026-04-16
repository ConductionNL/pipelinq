# Design: activity-timeline

## Architecture

### Data Model

No new OpenRegister schemas are required. The activity timeline aggregates from existing Pipelinq schemas defined in ADR-000:

| Source schema | Entity relation field | Activity type label | Date field |
|---|---|---|---|
| `contactmoment` | `client` (UUID) or `request` (UUID) | `contactmoment` | `contactedAt` |
| `contactmoment` (channel=worklog) | `client` or `request` | `worklog` | `contactedAt` |
| `task` | `clientId` (UUID) or `requestId` (UUID) | `task` | `deadline` / `createdAt` |
| `emailLink` | `linkedEntityType` + `linkedEntityId` | `email` | `date` |
| `calendarLink` | `linkedEntityType` + `linkedEntityId` | `calendar` | `startDate` |

Worklog entries are stored as `contactmoment` objects with `channel = 'worklog'`. This reuses the existing schema's `duration` (ISO 8601), `summary`, `agent`, `contactedAt`, `client`, and `request` fields. No new schema definitions are needed.

Entity type → query strategy mapping:

| entityType | contactmoment filter | task filter | emailLink filter | calendarLink filter |
|---|---|---|---|---|
| `client` | `client = entityId` | `clientId = entityId` | `linkedEntityType=client, linkedEntityId=entityId` | `linkedEntityType=client, linkedEntityId=entityId` |
| `request` | `request = entityId` | `requestId = entityId` | `linkedEntityType=request, linkedEntityId=entityId` | `linkedEntityType=request, linkedEntityId=entityId` |
| `lead` | (none — no direct contactmoment.lead field) | (none) | `linkedEntityType=lead, linkedEntityId=entityId` | `linkedEntityType=lead, linkedEntityId=entityId` |
| `contact` | (none — contactmomenten link to client) | (none) | `linkedEntityType=contact, linkedEntityId=entityId` | `linkedEntityType=contact, linkedEntityId=entityId` |

### Reuse Analysis

The following existing OpenRegister services are leveraged directly — no custom implementations needed:

| Existing service / component | Usage in this change |
|---|---|
| `ObjectService.findObjects($register, $schema, $params)` | Multi-schema queries with field filters for each activity type. 3-arg signature used throughout — no single-arg shortcuts. |
| `contactmoment` schema | Reused for worklog entries (channel='worklog'). No new schema; existing duration, summary, agent, contactedAt fields cover all worklog properties. |
| `ObjectService.findObject($register, $schema, $id)` | Used in worklog GET to fetch single entry by ID for 404 checking. |
| `IAppConfig` | Read register + schema IDs (`contactmoment_schema`, `task_schema`, etc.) to pass to ObjectService — same pattern as existing SettingsService. |

Explicitly NOT duplicated:

- **CnAuditTrailTab / AuditTrailService** — tracks OpenRegister object-level property changes (before/after snapshots). The activity timeline tracks CRM-level interactions (calls, emails, meetings). Different data, different purpose.
- **ActivityService (notifications-activity)** — publishes events to the Nextcloud Activity app (IManager). This change exposes a REST API for external query access. No overlap.
- **CnObjectSidebar Notes/Tasks tabs** — sidebar tabs display attached notes/tasks from OpenRegister's built-in relation system. The timeline component shows CRM activity items with type discrimination. Different scope.

### Backend

#### ActivityTimelineService (`lib/Service/ActivityTimelineService.php`)

Methods:

- `getTimeline(string $entityType, string $entityId, array $params): array` — Queries each applicable schema for the entity, normalises results, merges, sorts by date descending, applies pagination. Returns `{items, total, page, pages}`.
- `normalizeActivity(string $type, array $object): array` — Maps each schema's fields to unified format: `{type, id, title, description, date, user, entityType, entityId, metadata}`.
- `resolveEntityQueryParams(string $entityType, string $entityId): array` — Returns per-schema filter arrays keyed by schema type.
- `createWorklog(string $entityType, string $entityId, array $data): array` — Saves a `contactmoment` with `channel = 'worklog'`, `client` or `request` reference from entityType/entityId, and provided `duration`, `summary`, `contactedAt`.
- `getWorklog(string $entityType, string $entityId, array $params): array` — Queries contactmomenten filtered by `channel=worklog` for the entity, with pagination and summed `totalDuration`.

#### ActivityTimelineController (`lib/Controller/ActivityTimelineController.php`)

All endpoints require `@NoAdminRequired`. No `@PublicPage` — authenticated users only. No `@NoCSRFRequired` on POST.

| Method | URL | Action |
|--------|-----|--------|
| GET | `/api/timeline` | Merged activity timeline for an entity |
| GET | `/api/worklog` | Worklog entries for an entity |
| POST | `/api/worklog` | Create a worklog entry |

**GET `/api/timeline`** query parameters:
- `entityType` (required) — `client`, `request`, `lead`, `contact`
- `entityId` (required) — UUID
- `from` — ISO date, filter start (inclusive)
- `to` — ISO date, filter end (inclusive)
- `types[]` — Filter to specific activity types: `contactmoment`, `task`, `email`, `calendar`
- `_page` (default: 1), `_limit` (default: 20, max: 100)

Response shape:
```json
{
  "items": [
    {
      "type": "contactmoment",
      "id": "b3a1c2d4-...",
      "title": "Telefonisch contact over vergunningsvraag",
      "description": "Burger belde over status aanvraag omgevingsvergunning...",
      "date": "2026-04-15T10:30:00+02:00",
      "user": "j.bakker",
      "entityType": "client",
      "entityId": "a1b2c3d4-...",
      "metadata": { "channel": "phone", "duration": "PT15M", "outcome": "afgerond" }
    }
  ],
  "total": 42,
  "page": 1,
  "pages": 3
}
```

**POST `/api/worklog`** request body:
```json
{
  "entityType": "request",
  "entityId": "a1b2c3d4-...",
  "duration": "PT2H30M",
  "description": "Verwerking aanvraag en opstellen correspondentie",
  "date": "2026-04-15T14:00:00+02:00"
}
```

Error handling: all catch blocks return static strings only (`{ "message": "Operation failed" }`). Full exception logged via `$this->logger->error()`. Never expose `$e->getMessage()` in responses.

### Frontend

#### ActivityTimeline.vue (`src/components/ActivityTimeline.vue`)

Props: `entityType` (String, required), `entityId` (String, required).

Layout:
- Filter bar at top: "All" | "Contactmomenten" | "Taken" | "Email" | "Agenda" — updates `types[]` query param
- Timeline items list: each item shows type icon (`CnIcon` MDI), title, truncated description, date (relative via `formatDateFromNow`), user display name
- Empty state using `CnEmptyState` when no activities exist
- Load more button (not infinite scroll) for additional pages

Imports: from `@conduction/nextcloud-vue` — never `@nextcloud/vue` directly.

All user-visible strings via `this.t('pipelinq', 'key')`. Entries required in `l10n/en.json` and `l10n/nl.json`.

API calls via `axios` from `@nextcloud/axios`. Wrapped in `try/catch` with `NcDialog` error feedback.

#### Integration in detail pages

Add `<ActivityTimeline>` inside a `CnDetailCard` section (per ADR-017, `CnTimelineStages` renders its own card but `ActivityTimeline` is a custom component that does not):

```vue
<CnDetailCard :title="t('pipelinq', 'Activity')">
  <ActivityTimeline :entity-type="'client'" :entity-id="client.id" />
</CnDetailCard>
```

Add to: `ClientDetail.vue`, `LeadDetail.vue`, `RequestDetail.vue`.

## Files Changed

### New Files
- `lib/Service/ActivityTimelineService.php`
- `lib/Controller/ActivityTimelineController.php`
- `src/components/ActivityTimeline.vue`

### Modified Files
- `appinfo/routes.php` — Add 3 activity timeline routes
- `src/views/clients/ClientDetail.vue` — Add `<ActivityTimeline>` section
- `src/views/leads/LeadDetail.vue` — Add `<ActivityTimeline>` section
- `src/views/requests/RequestDetail.vue` — Add `<ActivityTimeline>` section
