## ADDED Requirements

---

### Requirement: xWiki Proxy Search

The system MUST provide a server-side proxy endpoint that searches xWiki pages and returns JSON results. This avoids CORS issues and centralizes auth handling.

**Feature tier**: V1

#### Scenario: Search xWiki pages by query

- GIVEN the xWiki instance is configured and reachable
- WHEN a user sends `GET /api/xwiki/search?q=paspoort`
- THEN the proxy MUST query xWiki's `/rest/wikis/query?q=paspoort`
- AND parse the XML response into JSON objects with fields: `id`, `title`, `space`, `modified`, `url`
- AND return a JSON response with `results` array, `total`, `limit`, `offset`

#### Scenario: Search filtered by space

- GIVEN the xWiki instance has pages in spaces "Kennisbank" and "Procedures"
- WHEN a user sends `GET /api/xwiki/search?q=klacht&space=Kennisbank`
- THEN the proxy MUST only return results from the "Kennisbank" space

#### Scenario: Search with pagination

- GIVEN xWiki contains 25 matching pages
- WHEN a user sends `GET /api/xwiki/search?q=aanvragen&limit=10&offset=10`
- THEN the proxy MUST return results 11-20
- AND the response MUST include `total: 25`, `limit: 10`, `offset: 10`

---

### Requirement: xWiki Proxy Page List

The system MUST provide an endpoint to list pages within a specific xWiki space.

**Feature tier**: V1

#### Scenario: List pages in a space

- GIVEN the xWiki space "Kennisbank" contains pages "Paspoort", "Verhuizing", "Afvalkalender"
- WHEN a user sends `GET /api/xwiki/pages?space=Kennisbank`
- THEN the proxy MUST query xWiki's REST API for the space page list
- AND return a JSON array of page objects with `id`, `title`, `url`

#### Scenario: List pages with limit

- GIVEN the "Kennisbank" space contains 20 pages
- WHEN a user sends `GET /api/xwiki/pages?space=Kennisbank&limit=5`
- THEN the proxy MUST return at most 5 page objects

---

### Requirement: xWiki Proxy Page Content

The system MUST provide an endpoint to retrieve a single xWiki page's rendered HTML content and metadata.

**Feature tier**: V1

#### Scenario: Get page content

- GIVEN the xWiki page "xwiki:Kennisbank.Paspoort.WebHome" exists
- WHEN a user sends `GET /api/xwiki/page/xwiki/Kennisbank.Paspoort.WebHome`
- THEN the proxy MUST return the page's rendered HTML content (from `#xwikicontent` div)
- AND include metadata: `title`, `id`, `space`, `modified`, `url`
- AND rewrite relative links and image sources to use the xWiki instance URL

#### Scenario: Page not found

- GIVEN the xWiki page "xwiki:Nonexistent.Page" does not exist
- WHEN a user sends `GET /api/xwiki/page/xwiki/Nonexistent.Page`
- THEN the proxy MUST return HTTP 404 with a JSON error message

---

### Requirement: xWiki Proxy HTML Sanitization

The system MUST sanitize HTML content from xWiki before returning it to the frontend to prevent XSS attacks.

**Feature tier**: V1

#### Scenario: Strip script tags from xWiki content

- GIVEN an xWiki page contains `<script>alert('xss')</script>` in its content
- WHEN the proxy fetches and returns this page
- THEN all `<script>` tags MUST be removed from the response
- AND all inline event handlers (onclick, onerror, etc.) MUST be removed from HTML elements

#### Scenario: Preserve safe HTML elements

- GIVEN an xWiki page contains headings, paragraphs, links, images, tables, and lists
- WHEN the proxy fetches and returns this page
- THEN all safe HTML elements MUST be preserved in the response
- AND image `src` attributes MUST be rewritten to absolute URLs pointing to the xWiki instance

---

### Requirement: xWiki Proxy Status Check

The system MUST provide a status endpoint to check xWiki availability.

**Feature tier**: V1

#### Scenario: xWiki is available

- GIVEN the xWiki instance is running and reachable
- WHEN a user sends `GET /api/xwiki/status`
- THEN the proxy MUST return `{ "available": true, "version": "16.x.x", "url": "http://localhost:8088" }`

#### Scenario: xWiki is unavailable

- GIVEN the xWiki instance is not running
- WHEN a user sends `GET /api/xwiki/status`
- THEN the proxy MUST return `{ "available": false, "error": "Could not reach xWiki instance" }`

---

### Requirement: xWiki Proxy Response Caching

The system MUST cache xWiki API responses server-side to reduce load on the xWiki instance.

**Feature tier**: V1

#### Scenario: Cache search results

- GIVEN a search for "paspoort" was executed 2 minutes ago
- WHEN the same search is requested again
- THEN the proxy MUST return the cached result without calling xWiki
- AND the response MUST include a `X-Cache: HIT` header

#### Scenario: Cache expiry

- GIVEN a cached search result is older than the configured TTL (default 5 minutes)
- WHEN the same search is requested
- THEN the proxy MUST fetch fresh results from xWiki
- AND update the cache with the new results

---

### Requirement: xWiki Proxy Authentication

The system MUST authenticate proxy requests using the Nextcloud user's xWiki token from the `nextcloud/xwiki` app settings.

**Feature tier**: V1

#### Scenario: Authenticated proxy request

- GIVEN a Nextcloud user has configured an xWiki token in the xWiki NC app
- WHEN they make a proxy request
- THEN the proxy MUST forward the request to xWiki with the user's Bearer token
- AND xWiki results MUST respect the user's xWiki permissions

#### Scenario: No xWiki token configured

- GIVEN a Nextcloud user has NOT configured an xWiki token
- WHEN they make a proxy request
- THEN the proxy MUST make an unauthenticated request to xWiki
- AND only publicly visible xWiki pages MUST be returned
