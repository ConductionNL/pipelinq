## Context

Pipelinq has a built-in kennisbank (knowledge base) with article CRUD, categories, search, and feedback. However, xWiki is the organization's standard knowledge management platform, already running in the docker environment (port 8088, `xwiki:lts-postgres-tomcat`).

The `nextcloud/xwiki` app (v1.0.0) provides Nextcloud integration with xWiki:
- **Search provider** — queries `/rest/wikis/query?q=<term>` and returns XML `<searchResult>` elements in Nextcloud unified search
- **Integrated mode** — renders xWiki page HTML inside Nextcloud by scraping `#xwikicontent` div, rewriting links/images to Nextcloud routes
- **Page list** — `/rest/wikis/{wiki}/spaces/{space}/.../pages` returns `<pageSummary>` XML
- **Auth** — Bearer token via `Authorization` header or xWiki OIDC token flow
- **PDF export** — saves xWiki pages as PDF to Nextcloud Files

**Problem**: The xWiki NC app only supports NC 27-31 (`max-version="31"` in info.xml). An upstream PR is needed for NC 32 compatibility — this will be submitted manually to `nextcloud/xwiki`.

## Goals / Non-Goals

**Goals:**
- Provide two reusable Vue components (`XWikiWidget`, `XWikiSidebarTab`) that any Conduction app can embed
- Build a thin PHP proxy that reuses the `nextcloud/xwiki` app's `Instance` class and `SettingsManager` for auth/connection
- Wire xWiki content into Pipelinq's dashboard and detail view sidebars with context-aware filtering
- Deprecate the built-in kennisbank in favor of xWiki as the knowledge backend

**Non-Goals:**
- Building our own xWiki REST client from scratch — reuse the existing NC xWiki app's services
- Migrating existing kennisbank articles to xWiki — that's a manual/operational task
- Replacing xWiki's editing UI — users create/edit articles in xWiki directly
- Building a generic xWiki admin UI — use the existing NC xWiki app's admin settings
- WYSIWYG editing of xWiki content inside Nextcloud

## Decisions

### Decision 1: Reuse the `nextcloud/xwiki` app as a dependency

**Choice**: Declare `nextcloud/xwiki` as a dependency in `info.xml` and use its PHP classes (`Instance`, `SettingsManager`) via DI rather than building our own xWiki client.

**Alternatives considered**:
- **Direct REST API calls from Pipelinq** — duplicates connection management, auth, URL building logic already in the xWiki app
- **Shared PHP library** — over-engineering; the xWiki app is already a well-scoped NC app with clean DI

**Rationale**: The xWiki app already handles instance management, token auth, OIDC, URL rewriting, and CSP. Reusing it avoids duplication and ensures our integration stays compatible with xWiki app updates.

### Decision 2: PHP proxy controller (not direct browser-to-xWiki calls)

**Choice**: Frontend calls Pipelinq's `XWikiController`, which proxies to xWiki REST API server-side.

**Alternatives considered**:
- **Direct browser → xWiki REST API** — blocked by CORS (xWiki runs on port 8088, Nextcloud on 8080). Would require configuring CORS headers on xWiki's Tomcat, which is fragile and not standard.
- **Nextcloud external API middleware** — no existing NC pattern for this

**Rationale**: Server-side proxy naturally handles CORS, can leverage the xWiki app's auth tokens, and allows us to cache responses and transform XML→JSON for the frontend.

### Decision 3: Two embeddable components with filter props

**Choice**: `XWikiWidget` (card/list) and `XWikiSidebarTab` (sidebar panel) accept filter props:
- `space` — xWiki space name (e.g., "Kennisbank", "Procedures")
- `tags` — array of xWiki tags to filter by
- `query` — search query string
- `limit` — max results (default 5 for widget, 10 for sidebar)

**Alternatives considered**:
- **Single component with mode prop** — less clear API, harder to style independently
- **Renderless composable** — Vue 2.7 doesn't have full Composition API; Options API mixins are the equivalent but less clean

**Rationale**: Two components with clear responsibilities. Widget is compact (title + list), sidebar is full-featured (search + viewer). Both share a `useXWiki` composable (or mixin) for the API layer.

### Decision 4: XML→JSON transformation in the proxy

**Choice**: The PHP proxy parses xWiki's XML REST responses and returns JSON to the frontend.

**Rationale**: xWiki REST API returns XML by default. The existing xWiki app parses XML server-side (`xml_parse_into_struct`). Our proxy does the same and returns clean JSON objects: `{ id, title, summary, space, tags, url, modified }`.

### Decision 5: Graceful degradation when xWiki app is unavailable

**Choice**: If the `nextcloud/xwiki` app is not installed/enabled, the components show an informative message ("xWiki integration niet beschikbaar") rather than crashing. The built-in kennisbank remains accessible as fallback.

**Rationale**: The NC 32 compatibility fix hasn't landed yet. The integration should not break Pipelinq if xWiki is temporarily unavailable.

### Decision 6: Deprecate kennisbank, don't remove

**Choice**: Mark kennisbank routes, components, and store as `@deprecated`. Remove the sidebar navigation entry. Keep the code functional for existing deployments.

**Rationale**: Some deployments may have kennisbank articles. Hard removal would break them. Deprecation gives a migration path.

## API Design

### Proxy Endpoints (Pipelinq)

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/api/xwiki/search` | Search xWiki pages. Query params: `q`, `space`, `tags`, `limit`, `offset` |
| GET | `/api/xwiki/pages` | List pages in a space. Query params: `space`, `limit`, `offset` |
| GET | `/api/xwiki/page/{wiki}/{page}` | Get single page content (rendered HTML + metadata) |
| GET | `/api/xwiki/status` | Check xWiki availability and connection status |

### Response Format (JSON)

```json
{
  "results": [
    {
      "id": "xwiki:Kennisbank.Paspoort.WebHome",
      "title": "Hoe vraag ik een paspoort aan?",
      "space": "Kennisbank",
      "modified": "2026-03-20T10:30:00Z",
      "url": "http://localhost:8088/xwiki/bin/view/Kennisbank/Paspoort"
    }
  ],
  "total": 42,
  "limit": 10,
  "offset": 0
}
```

### xWiki REST Endpoints Used

| xWiki Endpoint | Purpose |
|----------------|---------|
| `/rest/wikis/query?q=<term>` | Full-text search (returns XML `<searchResult>`) |
| `/rest/wikis/{wiki}/spaces/{space}/.../pages` | List pages in a space (returns XML `<pageSummary>`) |
| `/rest/wikis/{wiki}/spaces/{space}/.../pages/{page}` | Get page metadata (XML) |
| `/rest` | Health check (returns version XML) |

Page content is fetched via the HTML view URL and parsed for `#xwikicontent` (same pattern as the existing xWiki NC app's integrated mode).

## Component Architecture

```
src/components/xwiki/
  XWikiWidget.vue        — Compact article list (dashboard, detail page cards)
  XWikiSidebarTab.vue    — Full sidebar panel with search + article viewer
  XWikiArticleList.vue   — Shared list renderer (used by both)
  XWikiArticleViewer.vue — Inline HTML content viewer with link rewriting

src/store/modules/xwiki.js — Pinia store for xWiki API calls + caching

lib/Controller/XWikiController.php — Proxy controller
lib/Service/XWikiService.php       — xWiki API client (wraps Instance class)
```

### Widget Props

```javascript
// XWikiWidget.vue
props: {
  space: { type: String, default: '' },      // Filter by xWiki space
  tags: { type: Array, default: () => [] },  // Filter by tags
  query: { type: String, default: '' },      // Search query
  limit: { type: Number, default: 5 },       // Max results
  title: { type: String, default: 'Kennisbank' }, // Widget title
  showSearch: { type: Boolean, default: false }    // Show search input
}

// XWikiSidebarTab.vue
props: {
  space: { type: String, default: '' },
  tags: { type: Array, default: () => [] },
  contextQuery: { type: String, default: '' }, // Pre-filled from parent context
  limit: { type: Number, default: 10 }
}
```

## Seed Data

No OpenRegister schemas are introduced by this change. The integration reads from xWiki, not from OpenRegister.

For **development/testing**, the xWiki instance should be seeded with sample pages:
- Space: `Kennisbank` with subpages: "Paspoort aanvragen", "Verhuizing doorgeven", "Afvalkalender"
- Space: `Procedures` with subpages: "Klachtenprocedure", "Bezwaarprocedure"
- Tags: `burgerzaken`, `afval`, `klachten`, `bezwaar`

This seeding is manual via the xWiki UI at `http://localhost:8088`.

## Risks / Trade-offs

**[NC 32 compatibility not yet available]** → The upstream PR for `nextcloud/xwiki` NC 32 support must land before this integration can fully work. Mitigation: graceful degradation + fallback to built-in kennisbank. The proxy endpoints can also call xWiki REST API directly (without the NC app) as a temporary measure.

**[xWiki XML API is verbose and slow for large result sets]** → Search returns max 1000 items per request. Mitigation: server-side caching (5-minute TTL) in `XWikiService` using Nextcloud's `ICacheFactory`.

**[HTML content rendering security]** → xWiki page HTML is injected into Nextcloud. Mitigation: sanitize HTML server-side (strip `<script>`, event handlers), set CSP headers to allow xWiki domain for images/media (same approach as existing xWiki NC app).

**[Coupled to xWiki app internals]** → Using `Instance` and `SettingsManager` from the xWiki app. If they refactor, our code breaks. Mitigation: wrap in our own `XWikiService` so the coupling is in one place.

**[Built-in kennisbank data orphaned]** → Existing kennisbank articles in OpenRegister won't automatically appear in xWiki. Mitigation: deprecation notice in admin settings with link to export instructions.

## Migration Plan

1. **Phase 1 (this change)**: Add proxy, widget, sidebar tab. Wire into dashboard and detail views. Deprecate kennisbank nav entry.
2. **Phase 2 (manual)**: Submit NC 32 compatibility PR to `nextcloud/xwiki`. Once merged, enable the xWiki NC app.
3. **Phase 3 (future)**: Extract `XWikiWidget` and `XWikiSidebarTab` to `@conduction/nextcloud-vue` for use by Procest, OpenCatalogi, etc.
4. **Phase 4 (future)**: Remove deprecated kennisbank code after migration period.

Rollback: Restore kennisbank nav entry, disable xWiki proxy routes. No data loss — xWiki content is external, kennisbank data remains in OpenRegister.

## Open Questions

1. **Should the proxy also support xWiki's XWQL query language?** — Would allow more complex filtering (e.g., "articles modified in last 7 days in space X with tag Y"). Deferring for now — simple search + space + tag filtering covers the initial use cases.
2. **How should we handle xWiki authentication for public/unauthenticated access?** — The existing xWiki app uses per-user tokens. For public pages (e.g., citizen-facing), we may need a service account token configured in admin settings.
3. **Should the sidebar tab support inline editing?** — Decided: no. Users click through to xWiki for editing. Inline editing would require xWiki's editor JS, which is heavy and complex.
