## 1. Backend: xWiki Proxy Service

- [ ] 1.1 Create `lib/Service/XWikiService.php` — wraps xWiki REST API calls. Methods: `search(query, space, tags, limit, offset)`, `getPages(space, limit, offset)`, `getPageContent(wiki, page)`, `getStatus()`. Uses Nextcloud `IClientService` for HTTP. Attempts to load `OCA\Xwiki\SettingsManager` and `Instance` via DI; falls back to direct URL from Pipelinq settings if xWiki app unavailable. Parses XML responses to arrays. Caches responses via `ICacheFactory` with configurable TTL.
- [ ] 1.2 Create `lib/Controller/XWikiController.php` — proxy controller with `@NoAdminRequired @NoCSRFRequired` endpoints: `GET /api/xwiki/search`, `GET /api/xwiki/pages`, `GET /api/xwiki/page/{wiki}/{page}`, `GET /api/xwiki/status`. Delegates to `XWikiService`. Returns `JSONResponse`. Sanitizes HTML content (strip `<script>`, event handlers) before returning page content.
- [ ] 1.3 Add xWiki proxy routes to `appinfo/routes.php` — register the 4 new GET endpoints for the XWikiController.

## 2. Backend: xWiki Settings

- [ ] 2.1 Add xWiki settings keys to `lib/Service/SettingsService.php` — new keys: `xwiki_default_space` (string, default empty), `xwiki_cache_ttl` (int, default 300 seconds), `xwiki_direct_url` (string, default empty — fallback when xWiki NC app not installed).
- [ ] 2.2 Add xWiki settings section to `SettingsController` — extend the existing settings GET/POST handlers to include the xWiki configuration keys.

## 3. Frontend: xWiki Pinia Store

- [ ] 3.1 Create `src/store/modules/xwiki.js` — Pinia store with state: `articles`, `currentArticle`, `spaces`, `searchQuery`, `loading`, `error`, `available`. Actions: `search(params)`, `getPages(space)`, `getPageContent(wiki, page)`, `checkStatus()`. Calls the proxy endpoints via `fetch` with Nextcloud request token.

## 4. Frontend: xWiki Components

- [ ] 4.1 Create `src/components/xwiki/XWikiArticleList.vue` — shared list renderer: accepts `articles` array prop, emits `select` event on click. Renders each article as a list item with title, space badge, and modified date. Shows "Geen artikelen gevonden" when empty.
- [ ] 4.2 Create `src/components/xwiki/XWikiWidget.vue` — widget component with props: `space`, `tags`, `query`, `limit` (default 5), `title` (default "Kennisbank"), `showSearch` (default false). Uses xWiki store to fetch articles on mount. Includes optional debounced search input (300ms). Shows "xWiki integratie niet beschikbaar" when xWiki unreachable. "Meer bekijken" link when results exceed limit.
- [ ] 4.3 Create `src/components/xwiki/XWikiArticleViewer.vue` — inline HTML content viewer. Accepts `wiki` and `page` props. Fetches rendered HTML via store. Displays content in a sanitized `v-html` container. Shows "Open in xWiki" link and back button.
- [ ] 4.4 Create `src/components/xwiki/XWikiSidebarTab.vue` — sidebar panel component with props: `space`, `tags`, `contextQuery`, `limit` (default 10). Has three modes: search (default), space browser, article viewer. Search mode: input + article list. Space browser: list of spaces, click to see pages. Article viewer: inline content with back button.

## 5. Dashboard Integration

- [ ] 5.1 Add xWiki widget to dashboard grid — in the dashboard view, add an `XWikiWidget` instance to the `CnDashboardPage` layout with `showSearch="true"` and the admin-configured default space. Place at 6 columns width.

## 6. Detail View Sidebar Integration

- [ ] 6.1 Add `XWikiSidebarTab` to client detail view — add a "Kennisbank" tab to the client detail sidebar. Pass relevant context (e.g., client industry/category) as `contextQuery`.
- [ ] 6.2 Add `XWikiSidebarTab` to lead detail view — add a "Kennisbank" tab to the lead detail sidebar.
- [ ] 6.3 Add `XWikiSidebarTab` to request detail view — add a "Kennisbank" tab to the request detail sidebar. Pass request type/category as `contextQuery`.

## 7. Kennisbank Deprecation

- [ ] 7.1 Remove kennisbank entry from sidebar navigation — update the navigation config to remove the "Kennisbank" link. The `/kennisbank` routes remain accessible by direct URL.
- [ ] 7.2 Add deprecation banners to kennisbank views — in `KennisbankHome.vue`, `ArticleDetail.vue`, and `KennisbankEditor.vue`, add a prominent banner: "De ingebouwde kennisbank is vervangen door xWiki. Gebruik de xWiki integratie voor nieuwe artikelen." with a link to the xWiki instance.
- [ ] 7.3 Add `@deprecated` annotations to kennisbank code — mark `KennisbankController.php`, `PublicKennisbankController.php`, `KennisbankReviewJob.php`, `src/store/modules/kennisbank.js`, and all `src/views/kennisbank/` components with deprecation comments.

## 8. Admin Settings UI

- [ ] 8.1 Add xWiki settings section to the admin settings view — display fields for default space, cache TTL, and direct URL fallback. Show xWiki connection status indicator (available/unavailable with version). Include a "Test verbinding" button. Show warning when xWiki NC app is not installed.

## 9. i18n

- [ ] 9.1 Add Dutch and English translations for all xWiki integration strings — add translation keys for widget titles, error messages, settings labels, deprecation banners, sidebar tab labels. Minimum: `nl` and `en`.

## 10. Tests

- [ ] 10.1 Add unit tests for `XWikiService` — test XML parsing, caching, fallback behavior, error handling, HTML sanitization.
- [ ] 10.2 Add unit tests for `XWikiController` — test endpoint routing, response format, error responses.
- [ ] 10.3 Add E2E test for xWiki dashboard widget — navigate to dashboard, verify xWiki widget renders (or shows unavailable message).
- [ ] 10.4 Add E2E test for xWiki sidebar tab — navigate to client detail, click Kennisbank tab, verify it renders.
