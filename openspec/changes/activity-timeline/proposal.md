# Proposal: activity-timeline

## Problem

Pipelinq stores rich interaction data across multiple schemas — contactmomenten, tasks, emailLinks, calendarLinks — but exposes no unified REST endpoint to query this activity history. External systems (reporting tools, citizen portals, integration platforms) cannot programmatically retrieve the chronological activity feed for a client, lead, or request. 25 tender evaluations (demand score: 125) explicitly require queryable activity API support.

Additionally, there is no worklog endpoint for logging and querying work effort against CRM entities. Agents working on requests and leads have no standardised way to record time spent, which prevents accurate effort reporting and SLA compliance monitoring. This capability was identified from GitHub issue activity in makeplane/plane (worklog-api-endpoint pattern).

## Solution

Implement two complementary API capabilities:

1. **Activity timeline REST API** — `GET /api/timeline` returns a merged, chronologically sorted activity stream for any CRM entity. Aggregates contactmomenten, tasks, emailLinks, and calendarLinks from OpenRegister using `ObjectService.findObjects()`. Supports filtering by date range and activity type.

2. **Worklog API endpoint** — `GET /api/worklog` and `POST /api/worklog` allow logging and querying work effort. Worklog entries are stored as `contactmoment` objects with `channel = 'worklog'`, reusing the existing schema without new entity definitions.

3. **Activity timeline Vue component** — `ActivityTimeline.vue` embeds in entity detail pages (ClientDetail, LeadDetail, RequestDetail), consuming the new API for a contextual in-app timeline view.

## Scope

- `ActivityTimelineService` — multi-schema aggregation: contactmomenten, tasks, emailLinks, calendarLinks
- `ActivityTimelineController` — REST endpoints: `GET /api/timeline`, `GET /api/worklog`, `POST /api/worklog`
- API request parameters: `entityType`, `entityId`, `from`, `to`, `types[]`, `_page`, `_limit`
- Unified activity item response format: `{type, id, title, description, date, user, entityType, entityId, metadata}`
- `ActivityTimeline.vue` component with type filter bar and chronological display
- Integration in ClientDetail.vue, LeadDetail.vue, RequestDetail.vue detail pages

## Out of scope

- Real-time streaming or WebSocket activity feed (Enterprise)
- Cross-app timeline including Procest cases (V2)
- AI-generated activity summaries (Enterprise)
- Webhook push delivery of activity events (Enterprise)
- Bulk worklog import (V2)
- Activity analytics dashboard (V2)
