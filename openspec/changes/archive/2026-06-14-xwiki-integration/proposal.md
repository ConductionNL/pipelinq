## Why

Pipelinq has a built-in kennisbank (knowledge base) with article management, categories, search, and feedback — but knowledge management is not a CRM core competency. xWiki is already our go-to knowledge management platform, running in the docker environment on port 8088, with a Nextcloud integration app (`nextcloud/xwiki`) that provides search and content display. By integrating xWiki instead of maintaining a parallel knowledge system, we get professional wiki features (versioning, permissions, structured content, collaboration) without duplicating effort. The existing NC xWiki app only supports up to NC 31 — an upstream PR to fix NC 32 compatibility is a prerequisite (to be submitted manually).

## What Changes

- **Add an `XWikiWidget` Vue component** — embeddable card/list that can be placed on any dashboard or detail page. Configurable filters: xWiki space, tags/labels, search query. Shows article titles, summaries, and links to full content.
- **Add an `XWikiSidebarTab` Vue component** — sidebar panel with search input and inline article viewer. Can be mounted in any app's detail view sidebar (clients, leads, requests).
- **Add an `XWikiController` PHP proxy** — thin backend proxy that forwards requests to the xWiki REST API (`/rest/`) with Nextcloud authentication passthrough. Handles CORS (browser cannot call xWiki directly cross-origin) and caches responses.
- **Add xWiki admin settings** — configure xWiki base URL, default space, authentication method, and cache TTL in Pipelinq's settings page.
- **Wire widget into Pipelinq dashboard** — add an "XWiki Kennisbank" widget to the dashboard grid showing recent/relevant articles.
- **Wire sidebar tab into detail views** — add xWiki tab to client, lead, and request detail sidebars with context-aware filtering (e.g., filter by zaaktype tag when viewing a request).
- **Deprecate built-in kennisbank** — mark existing kennisbank routes, store, controllers, and components as deprecated. They remain functional but the UI navigation shifts to xWiki. **BREAKING**: kennisbank sidebar nav entry replaced by xWiki integration.

## Capabilities

### New Capabilities
- `xwiki-widget`: Reusable Vue component for displaying xWiki content (articles list, search, filtering by space/tag) — embeddable in dashboards and detail pages across any Conduction app
- `xwiki-sidebar`: Sidebar tab component with inline search and article viewer for context-aware knowledge access from detail views
- `xwiki-proxy`: PHP proxy controller for xWiki REST API access with auth passthrough, CORS handling, and response caching
- `xwiki-settings`: Admin configuration for xWiki connection (base URL, default space, auth, cache TTL)

### Modified Capabilities
- `kennisbank`: Deprecate built-in kennisbank in favor of xWiki integration. Navigation entry changes from internal kennisbank to xWiki-powered knowledge base. Existing data and endpoints remain available but are no longer the primary knowledge path.
- `dashboard`: Add xWiki kennisbank widget slot to the dashboard grid layout.

## Impact

- **Dependency**: Requires `nextcloud/xwiki` app to be compatible with NC 32 (upstream PR pending — manual submission). Until merged, the integration works with a forked version or direct xWiki REST API access.
- **Frontend**: New Vue components in `src/components/xwiki/`, new Pinia store module `src/store/modules/xwiki.js`, dashboard layout update.
- **Backend**: New `XWikiController` in `lib/Controller/`, new `XWikiService` in `lib/Service/`, new routes in `appinfo/routes.php`.
- **Settings**: New admin settings keys for xWiki configuration in `SettingsService`.
- **Cross-app reuse**: Widget and sidebar components designed for extraction to `@conduction/nextcloud-vue` shared library in the future, so Procest and other apps can also embed xWiki content.
- **Procest**: Can adopt the same widget/sidebar pattern once components are proven in Pipelinq.
- **Docker**: xWiki already available via `--profile xwiki` or `--profile integrations` on port 8088.
