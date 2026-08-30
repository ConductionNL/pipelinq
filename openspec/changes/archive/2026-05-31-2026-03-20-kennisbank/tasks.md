# Tasks: kennisbank

## 0. Deduplication Check

- [x] 0.1 Verify full-text search is covered by OpenRegister `_search` param — `KennisbankController` MUST NOT implement a custom search engine. Document in PR description: "search delegated to OpenRegister IndexService".
- [x] 0.2 Verify CRUD for agents uses `objectStore` directly (no custom CRUD controller for kennisartikel/kenniscategorie). `KennisbankController` covers only the public read endpoint and feedback submission.
- [x] 0.3 Confirm `NotificationService` is available in the existing app (check `lib/Service/`) — do NOT create a parallel notification service; reuse the existing one.
- [x] 0.4 Search `openspec/specs/` for any existing knowledge base or article spec. If found, reference rather than duplicate.
- [x] 0.5 Verify `marked` npm package is not already imported under a different name — check `package.json` for existing Markdown rendering libs before adding.

## 1. Data Model

- [x] 1.1 Add `kennisartikel` schema to `lib/Settings/pipelinq_register.json` with all properties per design:
  - Required: `title` (string), `body` (string), `status` (enum: concept/gepubliceerd/gearchiveerd; facetable), `visibility` (enum: intern/openbaar; facetable), `author` (string)
  - Optional: `summary`, `categories` (array), `tags` (array), `zaaktypeLinks` (array), `lastUpdatedBy`, `version` (integer, default 1), `publishedAt` (date-time), `archivedAt` (date-time), `usefulnessScore` (number, default 0)
  - MUST NOT redefine OpenRegister built-ins (id, uuid, uri, createdAt, updatedAt, auditTrail, status, tags)
- [x] 1.2 Add `kenniscategorie` schema to `pipelinq_register.json`:
  - Required: `name` (string)
  - Optional: `slug`, `parent` (uuid), `description`, `order` (integer, default 0), `icon`
- [x] 1.3 Add `kennisfeedback` schema to `pipelinq_register.json`:
  - Required: `article` (uuid), `rating` (enum: nuttig/niet_nuttig), `agent` (string)
  - Optional: `comment`, `status` (enum: nieuw/in_behandeling/verwerkt, default nieuw)
- [x] 1.4 Update the register's `schemas` list in `pipelinq_register.json` to include `kennisartikel`, `kenniscategorie`, `kennisfeedback`

## 2. Seed Data

- [x] 2.1 Add 5 kenniscategorie seed objects to `lib/Settings/pipelinq_register.json` under `components.objects[]` using `@self` envelope:
  - `kenniscat-burgerzaken` — name: "Burgerzaken", slug: "burgerzaken", order: 1, icon: "account-group"
  - `kenniscat-vergunningen` — name: "Vergunningen", slug: "vergunningen", order: 2, icon: "file-certificate"
  - `kenniscat-belastingen` — name: "Belastingen en heffingen", slug: "belastingen", order: 3, icon: "currency-eur"
  - `kenniscat-paspoort-id` — name: "Paspoort en ID-kaart", slug: "paspoort-id", parent: "kenniscat-burgerzaken", order: 1
  - `kenniscat-omgevingsvergunning` — name: "Omgevingsvergunning", slug: "omgevingsvergunning", parent: "kenniscat-vergunningen", order: 1
- [x] 2.2 Add 4 kennisartikel seed objects (Dutch realistic content, mix of status/visibility):
  - `artikel-paspoort-aanvragen` — status: gepubliceerd, visibility: openbaar, usefulnessScore: 88
  - `artikel-omgevingsvergunning-aanvragen` — status: gepubliceerd, visibility: openbaar, usefulnessScore: 75
  - `artikel-kwijtschelding-belasting` — status: gepubliceerd, visibility: intern, usefulnessScore: 62
  - `artikel-rijbewijs-verlengen` — status: concept, visibility: intern, usefulnessScore: 0
  - Use `@self`: `{ "register": "pipelinq", "schema": "kennisartikel", "slug": "artikel-..." }`
- [x] 2.3 Add 3 kennisfeedback seed objects:
  - `feedback-paspoort-001` — article: artikel-paspoort-aanvragen, rating: nuttig, status: verwerkt
  - `feedback-omgevingsvergunning-001` — article: artikel-omgevingsvergunning-aanvragen, rating: niet_nuttig, comment: (tarief ontbreekt), status: nieuw
  - `feedback-kwijtschelding-001` — article: artikel-kwijtschelding-belasting, rating: nuttig, comment: (doorkiesnummer update), status: in_behandeling
- [x] 2.4 Verify idempotency: re-importing `pipelinq_register.json` MUST NOT create duplicate objects (match by slug via existing `ImportHandler` logic)

## 3. Backend Service

- [x] 3.1 Create `lib/Service/KennisbankService.php`
  - SPDX header: `// SPDX-License-Identifier: EUPL-1.2`
  - Constructor: inject `ObjectService`, `IAppConfig`, `IUserSession`, `LoggerInterface`
- [x] 3.2 Implement `getPublicArticles(string $search, string $category, int $limit, int $offset): array`
  - Query OpenRegister via `ObjectService.findObjects($register, $schema, ['status' => 'gepubliceerd', 'visibility' => 'openbaar', '_search' => $search, '_limit' => $limit, '_page' => ...])` — 3 positional args
  - Strip internal fields: `author`, `lastUpdatedBy`, `zaaktypeLinks`
  - Return array with `results`, `total`, `page`, `pages`
- [x] 3.3 Implement `getPublicArticle(string $id): array`
  - Load via `ObjectService.findObject($register, $schema, $id)` — 3 positional args
  - Verify `status === 'gepubliceerd'` AND `visibility === 'openbaar'` — throw `NotFoundException` if not; controller returns 404
  - Strip internal fields from response
- [x] 3.4 Implement `submitFeedback(string $articleId, string $rating, ?string $comment, string $agentUid): array`
  - Validate `$rating` is one of `nuttig`, `niet_nuttig`; throw `InvalidArgumentException` otherwise
  - Create feedback object: `ObjectService.saveObject($register, $feedbackSchema, ['article' => $articleId, 'rating' => $rating, 'comment' => $comment, 'agent' => $agentUid, 'status' => 'nieuw'])` — 3 positional args
  - Call `recalculateScore($articleId)` after creation
  - Return the created feedback object
- [x] 3.5 Implement `recalculateScore(string $articleId): float`
  - Fetch all feedback for article: `ObjectService.findObjects($register, $feedbackSchema, ['article' => $articleId, '_limit' => 9999])` — 3 positional args
  - Count `nuttig` ratings; calculate `nuttig_count / total * 100`; round to 1 decimal
  - Update article: `ObjectService.saveObject($register, $artikelSchema, ['id' => $articleId, 'usefulnessScore' => $score])` — 3 positional args
  - Return the computed score
- [x] 3.6 All `catch (\Throwable $e)` blocks MUST log full exception via `$this->logger->error()` — NEVER expose `$e->getMessage()` to callers

## 4. Backend Controller

- [x] 4.1 Create `lib/Controller/KennisbankController.php`
  - SPDX header: `// SPDX-License-Identifier: EUPL-1.2`
  - Extend `Controller`. Constructor: inject `KennisbankService`, `IRequest`, `IUserSession`, `LoggerInterface`
  - `@spec openspec/changes/2026-03-20-kennisbank/tasks.md`
- [x] 4.2 Implement `index(): JSONResponse`
  - Attribute: `#[PublicPage] #[NoCSRFRequired]`
  - Read `_search`, `category`, `_limit` (default 20, max 100), `_page` (default 1) from `$this->request->getParam()`
  - Call `KennisbankService.getPublicArticles()`
  - Return `new JSONResponse($result, 200)`
  - Wrap in `try/catch`; on error return `new JSONResponse(['message' => 'Failed to load articles'], 500)`
- [x] 4.3 Implement `show(string $id): JSONResponse`
  - Attribute: `#[PublicPage] #[NoCSRFRequired]`
  - Call `KennisbankService.getPublicArticle($id)`
  - On `NotFoundException`: return `new JSONResponse(['message' => 'Not found'], 404)`
  - On error: return `new JSONResponse(['message' => 'Failed to load article'], 500)`
- [x] 4.4 Implement `feedback(): JSONResponse`
  - Attribute: `#[NoAdminRequired]` (authenticated agents only — no `#[PublicPage]`)
  - Read `articleId`, `rating`, `comment` from `$this->request->getParam()`
  - Validate `articleId` and `rating` are present; return `new JSONResponse(['message' => 'articleId and rating are required'], 400)` if missing
  - Validate `rating` is `nuttig` or `niet_nuttig`; return 400 if invalid
  - Get current user UID from `IUserSession`
  - Call `KennisbankService.submitFeedback()`; return `new JSONResponse($result, 201)`
  - On error: `new JSONResponse(['message' => 'Failed to submit feedback'], 500)`
- [x] 4.5 Verify NEVER calls `$e->getMessage()` in any response body

## 5. Routes

- [x] 5.1 Add kennisbank API routes to `appinfo/routes.php`:
  ```php
  ['name' => 'KennisbankController#index',    'url' => '/api/kennisbank/public',      'verb' => 'GET'],
  ['name' => 'KennisbankController#show',     'url' => '/api/kennisbank/public/{id}', 'verb' => 'GET'],
  ['name' => 'KennisbankController#feedback', 'url' => '/api/kennisbank/feedback',    'verb' => 'POST'],
  ```
  Place BEFORE any existing wildcard catch-all routes.
- [x] 5.2 Add OPTIONS pre-flight route for public endpoints if a CORS wildcard is configured

## 6. Frontend Views

- [x] 6.1 Create `src/views/kennisbank/KennisbankHome.vue`
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - Search bar: `NcInputField` with auto-focus; on input ≥ 3 chars trigger `objectStore.searchObjects('kennisartikel', { _search: query })`
  - Category tree sidebar: `NcAppNavigationItem` per category; fetch via `objectStore.fetchCollection('kenniscategorie')` on mount
  - Recently viewed section: read from `localStorage['pipelinq.kb.recentArticles']` (max 5 UUIDs)
  - Popular articles: fetch `objectStore.fetchCollection('kennisartikel', { status: 'gepubliceerd', _sort: 'usefulnessScore', _order: 'desc', _limit: 5 })`
  - ALL `await` calls in `try/catch` with user-facing error via `NcDialog`
  - ALL strings via `this.t('pipelinq', 'key')`. Register all used components in `components: {}`
  - Import ALL components from `@conduction/nextcloud-vue` — NEVER from `@nextcloud/vue`
- [x] 6.2 Create `src/views/kennisbank/ArticleList.vue`
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - `CnIndexPage` with `useListView('kennisartikel', { sidebarState, objectStore })`
  - `CnStatusBadge` for status (concept/gepubliceerd/gearchiveerd) and visibility (intern/openbaar)
  - Filters: status, visibility, category (passed to `_search` params)
  - Columns sortable by `updatedAt`, `title`, `usefulnessScore`
  - Row click → `$router.push({ name: 'ArticleDetail', params: { id: row.id } })`
  - ALL `await` calls in `try/catch`
- [x] 6.3 Create `src/views/kennisbank/ArticleDetail.vue`
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - Props: `articleId` from route; `isNew = articleId === 'new'`
  - Fetch article on mount via `objectStore.fetchObject('kennisartikel', articleId)`
  - Render `article.body` via `marked(article.body)` into `v-html` — sanitize output
  - `CnDetailPage` with `CnDetailCard` sections: metadata (author, version, dates, visibility), categories breadcrumb, tags chips
  - Feedback row: two `NcButton` components ("Nuttig" / "Niet nuttig"); click → `POST /api/kennisbank/feedback` via axios; update local `usefulnessScore` on success
  - Expandable suggestion textarea (shown after "Niet nuttig" click)
  - `CnObjectSidebar` for audit trail access
  - Add article UUID to `localStorage['pipelinq.kb.recentArticles']` on view
  - ALL `await` calls in `try/catch`; NEVER `window.confirm()` — use `CnDeleteDialog` for destructive actions
- [x] 6.4 Create `src/views/kennisbank/ArticleEditor.vue`
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - Markdown textarea (left) + live preview pane (right) using `marked`
  - Fields: title (`NcInputField`), summary (`NcInputField`, 200 char limit), categories (multi-select from kenniscategorie store), tags input, visibility toggle (intern/openbaar)
  - Status controls: "Opslaan als concept", "Publiceren", "Archiveren" buttons
  - On save: set `status` per button clicked, increment `version`, set `publishedAt`/`archivedAt` timestamps as appropriate
  - Call `objectStore.saveObject('kennisartikel', data)` — ALL `await` in `try/catch`
  - ALL strings via `this.t('pipelinq', 'key')`; register all components in `components: {}`
- [x] 6.5 Create `src/views/kennisbank/CategoryManager.vue`
  - SPDX header: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
  - `CnIndexPage` with `useListView('kenniscategorie', { objectStore: kenniscategorieStore })`
  - Tree view showing parent–child hierarchy (group by `parent` field)
  - "Add category" → `CnFormDialog` (schema-driven create); edit → `CnFormDialog`; delete → `CnDeleteDialog`
  - ALL strings via `this.t('pipelinq', 'key')`; import all from `@conduction/nextcloud-vue`

## 7. Navigation and Routing

- [x] 7.1 Add kennisbank routes to `src/router/index.js`:
  ```js
  { path: '/kennisbank', name: 'KennisbankHome', component: KennisbankHome },
  { path: '/kennisbank/articles/new', name: 'ArticleNew', component: ArticleEditor },
  { path: '/kennisbank/articles/:id', name: 'ArticleDetail', component: ArticleDetail, props: r => ({ articleId: r.params.id }) },
  { path: '/kennisbank/articles/:id/edit', name: 'ArticleEdit', component: ArticleEditor, props: r => ({ articleId: r.params.id }) },
  { path: '/kennisbank/categories', name: 'CategoryManager', component: CategoryManager },
  ```
  Use flat route structure (no nesting). Import lazy-loaded components.
- [x] 7.2 Add "Kennisbank" `NcAppNavigationItem` to `src/navigation/MainMenu.vue`:
  - Icon: `BookOpenPageVariant` (imported from `@mdi/js`)
  - `:to="{ name: 'KennisbankHome' }"`
  - Translated label via `this.t('pipelinq', 'Kennisbank')`
  - Import icon and register in `components: {}`

## 8. Store Registration

- [x] 8.1 In `src/store/store.js`:
  ```js
  objectStore.registerObjectType('kennisartikel', 'kennisartikel', 'pipelinq')
  objectStore.registerObjectType('kenniscategorie', 'kenniscategorie', 'pipelinq')
  objectStore.registerObjectType('kennisfeedback', 'kennisfeedback', 'pipelinq')
  ```
  Use kebab-case type names where appropriate. Register each type ONCE.
- [x] 8.2 Add translations to `l10n/en.json` and `l10n/nl.json`:
  - `"Kennisbank"`, `"Articles"`, `"Categories"`, `"New article"`, `"Edit article"`, `"Delete article"`, `"Publish"`, `"Archive"`, `"Save as concept"`, `"Helpful"`, `"Not helpful"`, `"Submit feedback"`, `"Your suggestion (optional)"`, `"No articles found"`, `"Search the knowledge base..."`, `"Recently viewed"`, `"Popular articles"`, `"Internal"`, `"Public"`, `"Status"`, `"Visibility"`, `"Category"`, `"Tags"`, `"Version"`, `"Author"`, `"Published"`, `"Archived"`

## 9. Verification

- [x] 9.1 Run `npm run build` — verify zero build errors
- [x] 9.2 Run `composer check:strict` (or `php -l` on all new PHP files) — verify zero syntax errors
- [x] 9.3 Smoke test `GET /api/kennisbank/public` without auth — verify 200 and `results` array shape; verify only `status=gepubliceerd` AND `visibility=openbaar` articles appear
- [x] 9.4 Smoke test `GET /api/kennisbank/public/{id}` with an `intern` article UUID — verify 404
- [x] 9.5 Smoke test `POST /api/kennisbank/feedback` without auth — verify 401
- [x] 9.6 Smoke test `POST /api/kennisbank/feedback` with auth, missing `rating` — verify 400 with `message` field; no stack trace
- [x] 9.7 Manual browser test: publish an article → verify it appears in KennisbankHome search; archive it → verify it disappears
- [x] 9.8 Manual browser test: submit "Nuttig" feedback → verify `usefulnessScore` updates on article detail
- [x] 9.9 Manual browser test: create subcategory → verify it appears nested under parent in category tree
- [x] 9.10 Run SPDX header check — MUST return zero files without header:
  ```bash
  grep -rL 'SPDX-License-Identifier' \
    lib/Controller/KennisbankController.php \
    lib/Service/KennisbankService.php \
    src/views/kennisbank/
  ```
- [x] 9.11 Verify ObjectService calls use 3 positional args throughout KennisbankService:
  ```bash
  grep -n 'findObjects\|saveObject\|findObject' lib/Service/KennisbankService.php
  ```
  Every call MUST have exactly 3 arguments.
- [x] 9.12 Verify no `$e->getMessage()` in controller response bodies:
  ```bash
  grep -n 'getMessage()' lib/Controller/KennisbankController.php
  ```
  MUST return zero matches.
- [x] 9.13 Verify all Vue components import from `@conduction/nextcloud-vue` only — NEVER `@nextcloud/vue`:
  ```bash
  grep -rn "from '@nextcloud/vue'" src/views/kennisbank/
  ```
  MUST return zero matches.
- [x] 9.14 Verify seed data import is idempotent: trigger `ImportHandler` twice; assert kenniscategorie count does not double.
