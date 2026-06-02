# Proposal: kennisbank

## Problem

Pipelinq has no knowledge base functionality. KCC agents handling citizen phone calls cannot search for articles, procedures, or FAQs to answer questions quickly. This hurts first-call resolution rates (74%+ FCR targets appear in 51/52 tenders). There is no article management, no categorization, no search, and no feedback system.

## Solution

Implement a knowledge base module within Pipelinq that enables:
1. **Article CRUD** with rich text (Markdown), versioning via OpenRegister audit trail, and publish/archive lifecycle
2. **Hierarchical categories** stored as OpenRegister objects for browsable navigation
3. **Full-text search** via OpenRegister `_search` parameter with autocomplete
4. **Agent feedback** (thumbs up/down + improvement suggestions) stored as separate objects
5. **Public vs internal** article visibility with a public API endpoint
6. **Navigation integration** as a dedicated section in Pipelinq sidebar

### Approach

- Add `kennisartikel`, `kenniscategorie`, and `kennisfeedback` schemas to the pipelinq register
- Create Vue views: `KennisbankHome`, `ArticleList`, `ArticleDetail`, `ArticleEditor`
- Create backend: `KennisbankController` for public article API, feedback endpoints
- Integrate with existing `NotificationService` for lifecycle notifications
- Use Markdown rendering via `marked` library for article display

## Scope

- Article CRUD with Markdown body, status lifecycle (concept/gepubliceerd/gearchiveerd)
- Category management (hierarchical, up to 3 levels)
- Full-text search with autocomplete and snippet highlighting
- Agent feedback (thumbs up/down, improvement suggestions)
- Public vs internal visibility
- Kennisbank navigation route and sidebar entry
- Article detail view with feedback buttons

## Out of scope

- AI-assisted search (Enterprise)
- Article analytics dashboard (Enterprise)
- Multi-language article versions (V2)
- Review workflow with scheduled reminders (V2)
- Zaaktype linking UI in Procest (cross-app, separate PR)



## Design

# Design: kennisbank

## Architecture

### Data Model (OpenRegister Schemas)

Three new schemas added to `pipelinq_register.json`:

#### kennisartikel
- `title` (string, required) — Article title
- `body` (string, required) — Markdown content
- `summary` (string) — Short summary for search results (max 200 chars)
- `status` (string, required, enum: concept/gepubliceerd/gearchiveerd, facetable) — Lifecycle status
- `visibility` (string, required, enum: intern/openbaar, facetable) — Access level
- `categories` (array of string) — UUID references to kenniscategorie objects
- `tags` (array of string) — Searchable tags
- `zaaktypeLinks` (array of string) — Zaaktype references for context-aware suggestions
- `author` (string, required) — Nextcloud user UID
- `lastUpdatedBy` (string) — Last editor's UID
- `version` (integer, default: 1) — Version number (incremented on edit)
- `publishedAt` (string, date-time) — Publication timestamp
- `archivedAt` (string, date-time) — Archive timestamp
- `usefulnessScore` (number, default: 0) — Aggregate rating score

#### kenniscategorie
- `name` (string, required) — Category name
- `slug` (string) — URL-friendly name
- `parent` (string, format: uuid) — Parent category reference for hierarchy
- `description` (string) — Category description
- `order` (integer, default: 0) — Display order
- `icon` (string) — Icon identifier

#### kennisfeedback
- `article` (string, required, format: uuid) — Article reference
- `rating` (string, required, enum: nuttig/niet_nuttig) — Usefulness rating
- `comment` (string) — Improvement suggestion text
- `agent` (string, required) — Nextcloud user UID
- `status` (string, enum: nieuw/in_behandeling/verwerkt, default: nieuw) — Feedback status

### Backend

#### KennisbankController (`lib/Controller/KennisbankController.php`)

Public API endpoints for citizen-facing article access. `@NoAdminRequired @NoCSRFRequired @PublicPage`.

| Method | URL | Action |
|--------|-----|--------|
| GET | `/api/kennisbank/public` | List published public articles |
| GET | `/api/kennisbank/public/{id}` | Get single public article |
| POST | `/api/kennisbank/feedback` | Submit article feedback |

The public endpoints filter by `status=gepubliceerd` AND `visibility=openbaar`, excluding internal fields (author UID, feedback data, zaaktype links).

The feedback endpoint is `@NoAdminRequired` (authenticated agents only).

#### KennisbankService (`lib/Service/KennisbankService.php`)

- `getPublicArticles(string $search, string $category, int $limit, int $offset): array` — Query OpenRegister for public articles
- `getPublicArticle(string $id): array` — Get single article, strip internal fields
- `submitFeedback(string $articleId, string $rating, ?string $comment): array` — Create feedback object, update article's usefulnessScore
- `recalculateScore(string $articleId): float` — Count nuttig/niet_nuttig feedback, calculate percentage

### Frontend

#### Routes (added to `src/router/index.js`)

- `/kennisbank` — KennisbankHome (search + browse)
- `/kennisbank/articles/:id` — ArticleDetail
- `/kennisbank/articles/:id/edit` — ArticleEditor
- `/kennisbank/articles/new` — ArticleEditor (create mode)
- `/kennisbank/categories` — CategoryManager (admin)

#### Views

**KennisbankHome.vue** (`src/views/kennisbank/KennisbankHome.vue`)
- Search bar (auto-focus, autocomplete after 3 chars)
- Category tree sidebar (collapsible, shows article counts)
- Recently viewed articles (localStorage)
- Popular articles section

**ArticleList.vue** (`src/views/kennisbank/ArticleList.vue`)
- Filtered list with status/visibility badges
- Search, category filter, status filter
- Sortable by date, title, score

**ArticleDetail.vue** (`src/views/kennisbank/ArticleDetail.vue`)
- Rendered Markdown body
- Category breadcrumb, tags, metadata
- Feedback buttons (Nuttig/Niet nuttig)
- Suggestion form (expandable)
- Version info, author, last updated

**ArticleEditor.vue** (`src/views/kennisbank/ArticleEditor.vue`)
- Markdown textarea with live preview
- Title, summary, category selector (multi-select)
- Tags input
- Visibility toggle (intern/openbaar)
- Status controls (save as concept, publish, archive)

**CategoryManager.vue** (`src/views/kennisbank/CategoryManager.vue`)
- Admin view for managing category hierarchy
- Tree view with drag-and-drop reordering
- CRUD for categories

#### Navigation

Add "Kennisbank" entry to `MainMenu.vue` with book/article icon.

#### Store

Articles and categories are queried directly from OpenRegister via `objectStore` pattern (no custom Pinia store needed).

## Files Changed

### New Files
- `lib/Controller/KennisbankController.php`
- `lib/Service/KennisbankService.php`
- `src/views/kennisbank/KennisbankHome.vue`
- `src/views/kennisbank/ArticleList.vue`
- `src/views/kennisbank/ArticleDetail.vue`
- `src/views/kennisbank/ArticleEditor.vue`
- `src/views/kennisbank/CategoryManager.vue`

### Modified Files
- `lib/Settings/pipelinq_register.json` — Add 3 schemas, update register schema list
- `appinfo/routes.php` — Add kennisbank API routes
- `src/router/index.js` — Add kennisbank routes
- `src/navigation/MainMenu.vue` — Add Kennisbank nav item



## Tasks

# Tasks: kennisbank

## 1. Data Model

- [x] 1.1 Add `kennisartikel` schema to `pipelinq_register.json` with all properties per design
- [x] 1.2 Add `kenniscategorie` schema to `pipelinq_register.json`
- [x] 1.3 Add `kennisfeedback` schema to `pipelinq_register.json`
- [x] 1.4 Update register's schemas list to include the 3 new schemas

## 2. Backend Service

- [x] 2.1 Create `lib/Service/KennisbankService.php` with public article query, feedback submission, and score recalculation
- [x] 2.2 Create `lib/Controller/KennisbankController.php` with public article endpoints and feedback endpoint

## 3. Routes

- [x] 3.1 Add kennisbank API routes to `appinfo/routes.php`

## 4. Frontend Views

- [x] 4.1 Create `src/views/kennisbank/KennisbankHome.vue` — search, category browse, popular articles
- [x] 4.2 Create `src/views/kennisbank/ArticleList.vue` — filterable article list with status badges
- [x] 4.3 Create `src/views/kennisbank/ArticleDetail.vue` — rendered Markdown, feedback buttons, metadata
- [x] 4.4 Create `src/views/kennisbank/ArticleEditor.vue` — create/edit with Markdown preview
- [x] 4.5 Create `src/views/kennisbank/CategoryManager.vue` — admin category CRUD

## 5. Navigation and Routing

- [x] 5.1 Add kennisbank routes to `src/router/index.js`
- [x] 5.2 Add Kennisbank entry to `src/navigation/MainMenu.vue`

## 6. Verification

- [ ] 6.1 Run `npm run build` and verify no errors
- [ ] 6.2 Manual testing via browser