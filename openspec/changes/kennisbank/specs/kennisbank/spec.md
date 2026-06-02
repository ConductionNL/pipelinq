# Spec: kennisbank — REST API for Search and Document Management

**Change:** kennisbank
**App:** Pipelinq
**Platform:** Nextcloud + OpenRegister

---

## Requirements

### REQ-KB-001: Public Full-Text Article Search

The system MUST expose a public REST endpoint for searching published knowledge base articles so that external systems can retrieve relevant content without authentication.

#### Scenario: Search returns matching articles

- GIVEN the knowledge base contains articles "Paspoort aanvragen" (gepubliceerd, openbaar) and "Interne procedure nieuw medewerker" (gepubliceerd, intern)
- WHEN an external system calls `GET /api/kennisbank/public/search?q=paspoort`
- THEN the response MUST return HTTP 200 with `results` containing "Paspoort aanvragen"
- AND "Interne procedure nieuw medewerker" MUST NOT appear in the results (visibility=intern)
- AND each result MUST include: id, title, summary, categories, tags, publishedAt, snippet

#### Scenario: Search excludes concept and archived articles

- GIVEN articles with status "concept" and "gearchiveerd" matching the query term
- WHEN a public search is performed
- THEN concept and archived articles MUST NOT appear in results regardless of visibility

#### Scenario: Empty search returns paginated article list

- GIVEN 25 published public articles exist
- WHEN `GET /api/kennisbank/public/search` is called with no `q` parameter and `_limit=10`
- THEN the response MUST include `total: 25`, `page: 1`, `pages: 3`, and 10 `results`

#### Scenario: Search endpoint is accessible without authentication

- GIVEN no authentication headers are sent
- WHEN `GET /api/kennisbank/public/search?q=rijbewijs` is called
- THEN the response MUST return HTTP 200 (not 401)
- AND CORS headers MUST be present in the response

#### Scenario: Internal fields are excluded from public response

- GIVEN an article with author UID, zaaktypeLinks, and usefulnessScore
- WHEN retrieved via the public search endpoint
- THEN the response MUST NOT include: author, lastUpdatedBy, zaaktypeLinks, usefulnessScore, body

---

### REQ-KB-002: Public Collections Endpoint

The system MUST expose a public endpoint returning the full category hierarchy with article counts so that external systems can present a browsable knowledge base navigation.

#### Scenario: Category tree with article counts

- GIVEN categories "Burgerzaken" (parent: none), "Reisdocumenten" (parent: Burgerzaken), and "Rijbewijzen" (parent: Burgerzaken)
- AND "Burgerzaken" has 0 direct articles, "Reisdocumenten" has 3, "Rijbewijzen" has 2
- WHEN `GET /api/kennisbank/public/collections` is called
- THEN the response MUST return a nested tree with `articleCount` per node
- AND counts MUST only include articles with status=gepubliceerd AND visibility=openbaar

#### Scenario: Per-category article listing

- GIVEN category "Burgerzaken" with slug "burgerzaken" containing 8 published public articles
- WHEN `GET /api/kennisbank/public/collections/burgerzaken/articles?_limit=5&_page=1` is called
- THEN the response MUST return 5 articles and `total: 8`, `pages: 2`

#### Scenario: Unknown category returns 404

- GIVEN no category with slug "onbekend" exists
- WHEN `GET /api/kennisbank/public/collections/onbekend/articles` is called
- THEN the response MUST return HTTP 404 with a `message` field
- AND no stack trace, SQL, or internal path MUST appear in the response

---

### REQ-KB-003: Export Endpoint

The system MUST expose an authenticated export endpoint allowing admins to download all knowledge base articles in JSON or CSV format.

#### Scenario: Admin exports articles as JSON

- GIVEN 50 articles in the knowledge base across all statuses
- WHEN an admin calls `GET /api/kennisbank/articles/export?format=json`
- THEN the response MUST return HTTP 200 with `Content-Type: application/json`
- AND all articles MUST be included (not filtered by status/visibility)
- AND each article MUST include all schema properties

#### Scenario: Admin exports articles as CSV

- GIVEN articles exist with Dutch characters in the title and body
- WHEN an admin calls `GET /api/kennisbank/articles/export?format=csv`
- THEN the response MUST return HTTP 200 with `Content-Type: text/csv`
- AND the CSV MUST use UTF-8 encoding with a header row

#### Scenario: Non-admin is denied access to export

- GIVEN a regular authenticated user (not admin)
- WHEN `GET /api/kennisbank/articles/export` is called
- THEN the response MUST return HTTP 403 with a static `message` field
- AND no internal data MUST be exposed

#### Scenario: Unauthenticated request is denied

- GIVEN no authentication
- WHEN `GET /api/kennisbank/articles/export` is called
- THEN the response MUST return HTTP 401

---

### REQ-KB-004: Version History Endpoint

The system MUST expose an endpoint returning the version history of a knowledge base article so that editors can review editorial changes over time.

#### Scenario: Article version list

- GIVEN article "Paspoort aanvragen" has been edited 3 times (versions 1, 2, 3)
- WHEN an authenticated user calls `GET /api/kennisbank/articles/{id}/versions`
- THEN the response MUST return a list of 3 entries
- AND each entry MUST include: version number, editedAt timestamp, editedBy user UID, changeType

#### Scenario: Article with no edits returns single version

- GIVEN a newly created article that has never been edited
- WHEN `GET /api/kennisbank/articles/{id}/versions` is called
- THEN the response MUST return a list with exactly 1 entry (the creation event)

#### Scenario: Unknown article returns 404

- GIVEN no article with the provided UUID exists
- WHEN `GET /api/kennisbank/articles/{unknown-uuid}/versions` is called
- THEN the response MUST return HTTP 404 with a static `message` field

---

### REQ-KB-005: Version Comparison Endpoint

The system MUST expose an endpoint that returns a field-level diff between two article versions so that editors can see exactly what changed.

#### Scenario: Diff shows changed fields only

- GIVEN article version 2 changed `title` from "Paspoort aanvragen" to "Paspoort aanvragen — gemeente loket"
- AND version 2 also changed `status` from "concept" to "gepubliceerd"
- AND `body` was unchanged between versions 1 and 2
- WHEN an authenticated user calls `GET /api/kennisbank/articles/{id}/versions/1/2`
- THEN the response MUST include `diff` with entries for `title` and `status`
- AND `body` MUST NOT appear in `diff` (unchanged)
- AND each diff entry MUST include `field`, `before`, and `after`

#### Scenario: Same version comparison returns empty diff

- GIVEN any existing article
- WHEN `GET /api/kennisbank/articles/{id}/versions/2/2` is called
- THEN the response MUST return HTTP 200 with `diff: []`

#### Scenario: Non-existent version returns 400

- GIVEN article has only 3 versions
- WHEN `GET /api/kennisbank/articles/{id}/versions/1/99` is called
- THEN the response MUST return HTTP 400 with a static `message` field

---

### REQ-KB-006: Data Audit Endpoint

The system MUST expose an admin-only audit log endpoint covering all knowledge base entity changes for compliance reporting.

#### Scenario: Audit log returns change events

- GIVEN 10 knowledge base change events (creates, updates, deletes) across kennisartikel and kenniscategorie
- WHEN an admin calls `GET /api/kennisbank/audit`
- THEN the response MUST return events with: objectId, schema, action (created/updated/deleted), actor UID, timestamp, before, after

#### Scenario: Audit log supports date range filter

- GIVEN audit events from 2026-01-01 through 2026-04-16
- WHEN an admin calls `GET /api/kennisbank/audit?dateFrom=2026-04-01&dateTo=2026-04-16`
- THEN only events within that range MUST be returned

#### Scenario: Audit log supports schema filter

- GIVEN events for kennisartikel and kennisfeedback
- WHEN an admin calls `GET /api/kennisbank/audit?schema=kennisartikel`
- THEN only kennisartikel events MUST be returned

#### Scenario: Non-admin is denied access to audit log

- GIVEN a regular authenticated user
- WHEN `GET /api/kennisbank/audit` is called
- THEN the response MUST return HTTP 403 with a static `message` field

---

### REQ-KB-007: Access Control and Security

The system MUST enforce authentication and authorization on all non-public endpoints.

#### Scenario: Public endpoints require no authentication

- GIVEN: `GET /api/kennisbank/public/search`, `GET /api/kennisbank/public/collections`, `GET /api/kennisbank/public/collections/{slug}/articles`
- WHEN called without any authentication headers
- THEN each MUST return HTTP 200 with valid JSON
- AND responses MUST NOT include any PII (author UIDs, internal notes, zaaktypeLinks)

#### Scenario: Admin check uses backend IGroupManager

- GIVEN an admin-only endpoint (export or audit)
- WHEN authorization is checked
- THEN `IGroupManager::isAdmin()` MUST be called on the backend
- AND frontend-sent user IDs or roles MUST NOT be trusted for authorization decisions

#### Scenario: Error responses contain no internal detail

- GIVEN any error condition (missing param, auth failure, not found)
- WHEN an error response is returned
- THEN the response MUST use a static `message` string
- AND MUST NOT include stack traces, SQL queries, or internal file paths

---

### REQ-KB-008: API Structure and Pagination

All list endpoints MUST support consistent pagination following ADR-002.

#### Scenario: Pagination parameters

- GIVEN a search or collection listing endpoint
- WHEN `_page` and `_limit` query parameters are provided
- THEN the response MUST include `total`, `page`, and `pages` fields
- AND `results` MUST contain at most `_limit` items

#### Scenario: Default pagination

- GIVEN no `_limit` parameter is provided
- WHEN any list endpoint is called
- THEN the default page size MUST be 20 items

#### Scenario: CORS preflight for public endpoints

- GIVEN an external browser application
- WHEN an `OPTIONS` request is sent to `/api/kennisbank/public/search`
- THEN the response MUST return HTTP 200 with appropriate CORS headers
- AND the endpoint MUST be registered as `#[PublicPage] #[NoCSRFRequired]`
