# Spec: Activity Timeline & Worklog API

## Purpose

Provide a unified REST API for querying CRM activity instances (contactmomenten, tasks, emailLinks, calendarLinks) for any entity, and a worklog endpoint for logging and querying work effort. Enables external integrations and in-app timeline views. Demand score: 125 (25 tender mentions).

---

## REQ-ATL-001: Activity Timeline API Returns Merged, Sorted Activity Stream [V1]

The `GET /api/timeline` endpoint MUST return a single merged list of all CRM activity types for a given entity, sorted by date descending.

### Scenario: Merged timeline for a client entity

- GIVEN a client has 3 contactmomenten, 2 tasks, and 1 emailLink
- WHEN `GET /api/timeline?entityType=client&entityId={uuid}` is called with valid authentication
- THEN the response status MUST be 200
- AND `items` MUST contain all 6 records merged into a single array
- AND `total` MUST equal 6
- AND items MUST be sorted by `date` descending (newest first)
- AND each item MUST include the fields: `type`, `id`, `title`, `date`, `user`, `entityType`, `entityId`

### Scenario: Timeline includes calendarLink and emailLink entries for leads

- GIVEN a lead has 2 calendarLinks and 1 emailLink (linked via `linkedEntityType=lead`)
- WHEN `GET /api/timeline?entityType=lead&entityId={uuid}` is called
- THEN the response MUST include items with `type: "calendar"` and `type: "email"`
- AND item metadata MUST include channel-specific fields from the source schema

### Scenario: Empty timeline returns valid empty response

- GIVEN a contact entity has no linked activity records of any type
- WHEN `GET /api/timeline?entityType=contact&entityId={uuid}` is called
- THEN the response status MUST be 200
- AND `items` MUST be an empty array
- AND `total` MUST be 0

---

## REQ-ATL-002: Activity Timeline API Supports Date Range Filtering [V1]

The timeline endpoint MUST support `from` and `to` date filters that restrict results to a specific time window.

### Scenario: Date range filter limits results

- GIVEN a client has activities from January 2026, February 2026, and March 2026
- WHEN `GET /api/timeline?entityType=client&entityId={uuid}&from=2026-02-01&to=2026-02-28` is called
- THEN ONLY activities with a date between 2026-02-01 and 2026-02-28 (inclusive) MUST be returned
- AND activities outside this range MUST NOT appear in the response

### Scenario: Open-ended date filter

- GIVEN a request has 5 activities, 3 created after 2026-03-01
- WHEN `GET /api/timeline?entityType=request&entityId={uuid}&from=2026-03-01` is called
- THEN ONLY the 3 activities from 2026-03-01 onwards MUST be returned

---

## REQ-ATL-003: Activity Timeline API Supports Type Filtering [V1]

The timeline endpoint MUST support a `types[]` query parameter that restricts results to specific activity types.

### Scenario: Filter to single activity type

- GIVEN a client has contactmomenten, tasks, and emailLinks
- WHEN `GET /api/timeline?entityType=client&entityId={uuid}&types[]=contactmoment` is called
- THEN ONLY items with `type: "contactmoment"` MUST be returned
- AND task and email items MUST NOT appear

### Scenario: Filter to multiple activity types

- GIVEN a request has contactmomenten, tasks, and calendarLinks
- WHEN `GET /api/timeline?entityType=request&entityId={uuid}&types[]=contactmoment&types[]=task` is called
- THEN ONLY contactmoment and task items MUST be returned
- AND calendarLink items MUST NOT appear

---

## REQ-ATL-004: Activity Timeline API Supports Pagination [V1]

The timeline endpoint MUST support `_page` and `_limit` parameters and return `total`, `page`, and `pages` in the response.

### Scenario: Paginated response

- GIVEN a client has 45 total activity items
- WHEN `GET /api/timeline?entityType=client&entityId={uuid}&_limit=20&_page=1` is called
- THEN `total` MUST be 45
- AND `pages` MUST be 3
- AND `page` MUST be 1
- AND `items` MUST contain exactly 20 records

### Scenario: Last page returns remaining items

- GIVEN a client has 45 total activity items
- WHEN `GET /api/timeline?entityType=client&entityId={uuid}&_limit=20&_page=3` is called
- THEN `items` MUST contain exactly 5 records
- AND `page` MUST be 3

---

## REQ-ATL-005: Activity Timeline API Enforces Authentication [V1]

The timeline endpoint MUST reject unauthenticated requests.

### Scenario: Unauthenticated request is rejected

- GIVEN no Nextcloud session or credentials are provided
- WHEN `GET /api/timeline` is called
- THEN the response status MUST be 401
- AND no activity data MUST be returned

### Scenario: Missing required parameters returns validation error

- GIVEN an authenticated user
- WHEN `GET /api/timeline` is called without `entityType` or `entityId`
- THEN the response status MUST be 400
- AND the response MUST contain a `message` field with a user-readable error string
- AND the response MUST NOT contain stack traces or internal path information

---

## REQ-ATL-006: Worklog API Creates Effort Records Against CRM Entities [V1]

The `POST /api/worklog` endpoint MUST create a worklog entry stored as a `contactmoment` with `channel = 'worklog'`.

### Scenario: Create a worklog entry for a request

- GIVEN an authenticated agent
- WHEN `POST /api/worklog` is called with `{ entityType: "request", entityId: "{uuid}", duration: "PT2H30M", description: "Verwerking aanvraag en opstellen correspondentie", date: "2026-04-15T14:00:00+02:00" }`
- THEN the response status MUST be 201
- AND a `contactmoment` record MUST be created in OpenRegister with `channel = 'worklog'`
- AND the contactmoment's `request` field MUST reference the provided `entityId`
- AND `duration` MUST be stored as the provided ISO 8601 string
- AND `summary` MUST contain the provided description
- AND `agent` MUST be set to the authenticated user's UID (derived from IUserSession — NEVER from request body)

### Scenario: Create a worklog entry for a client

- GIVEN an authenticated agent
- WHEN `POST /api/worklog` is called with `{ entityType: "client", entityId: "{uuid}", duration: "PT45M", description: "Klantgesprek", date: "2026-04-14T09:00:00+02:00" }`
- THEN a `contactmoment` MUST be created with `channel = 'worklog'` and `client` referencing the provided `entityId`

### Scenario: Missing required fields return 400

- GIVEN an authenticated user
- WHEN `POST /api/worklog` is called with a missing `entityType` or `entityId`
- THEN the response status MUST be 400
- AND the response MUST contain a `message` field

### Scenario: Unauthenticated worklog creation is rejected

- GIVEN no valid Nextcloud session
- WHEN `POST /api/worklog` is called
- THEN the response status MUST be 401

---

## REQ-ATL-007: Worklog API Queries Effort Records for an Entity [V1]

The `GET /api/worklog` endpoint MUST return worklog entries (contactmomenten with `channel = 'worklog'`) for a given entity.

### Scenario: Query worklog for a request

- GIVEN a request has 4 worklog entries logged by different agents
- WHEN `GET /api/worklog?entityType=request&entityId={uuid}` is called
- THEN `items` MUST contain exactly 4 worklog entries
- AND each item MUST include `duration`, `description`, `date`, `user`
- AND regular (non-worklog) contactmomenten for the same request MUST NOT appear

### Scenario: Worklog response includes total duration

- GIVEN a request has worklog entries: PT1H, PT30M, PT2H
- WHEN `GET /api/worklog?entityType=request&entityId={uuid}` is called
- THEN the response MUST include a `totalDuration` field summing all durations

---

## REQ-ATL-008: Frontend Activity Timeline Component Displays In-App [V1]

An `ActivityTimeline.vue` component MUST be embedded in the ClientDetail, LeadDetail, and RequestDetail views and display a chronological feed of activity items.

### Scenario: Activity timeline visible in client detail

- GIVEN a client detail page is open for a client with linked activities
- WHEN the user scrolls to the "Activity" section
- THEN a chronological list of contactmomenten, tasks, emailLinks, and calendarLinks MUST be displayed
- AND each item MUST show a type icon, title, relative date, and the handling user

### Scenario: Activity type icons distinguish item types

- GIVEN the activity timeline is displayed with mixed item types
- WHEN the user views the timeline
- THEN each activity type MUST be visually distinguished by a different icon
- AND the distinction MUST NOT rely solely on colour (WCAG AA compliance)

### Scenario: Filter by activity type in the UI

- GIVEN the activity timeline is displayed with multiple activity types
- WHEN the user selects the "Taken" (Tasks) filter
- THEN ONLY task items MUST be shown
- AND all other activity types MUST be hidden

### Scenario: Empty state shown when no activities exist

- GIVEN an entity has no linked activity records
- WHEN the activity timeline component renders
- THEN an empty state indicator MUST be shown (CnEmptyState)
- AND no error state MUST be shown (empty is not an error)

### Scenario: Timeline is keyboard-navigable

- GIVEN the activity timeline is rendered
- WHEN the user navigates using keyboard only (Tab, Arrow keys)
- THEN all interactive controls (filter buttons, load more) MUST be reachable and activatable
- (WCAG AA compliance per ADR-010)
