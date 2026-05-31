# Kennisbank — Delta Spec

## Purpose

Add a knowledge base module to Pipelinq enabling KCC agents to look up articles, procedures, and FAQs during citizen interactions. Articles are authored in Markdown with a publish/archive lifecycle, organised in a hierarchical category taxonomy, searchable via OpenRegister full-text search, and rated by agents using a thumbs up/down feedback mechanism. A public API exposes published openbaar articles for citizen self-service portals.

**Feature tier**: V1
**Schema.org type**: `schema:Article` (kennisartikel), `schema:DefinedTermSet` (kenniscategorie)
**Entities**: `kennisartikel`, `kenniscategorie`, `kennisfeedback`

---

## Requirements

### REQ-KB-001: Article Schema in Register

The system MUST define `kennisartikel`, `kenniscategorie`, and `kennisfeedback` schemas in `lib/Settings/pipelinq_register.json`.

#### Scenario: kennisartikel schema includes all required properties

- GIVEN the Pipelinq register configuration is loaded
- WHEN the `kennisartikel` schema is inspected
- THEN it MUST include the following required properties:
  - `title` (string, required, `schema:headline`)
  - `body` (string, required, Markdown, `schema:articleBody`)
  - `status` (string, required, enum: `concept` / `gepubliceerd` / `gearchiveerd`; facetable)
  - `visibility` (string, required, enum: `intern` / `openbaar`; facetable)
  - `author` (string, required, Nextcloud user UID)
- AND the following optional properties:
  - `summary` (string, max 200 chars, `schema:abstract`)
  - `categories` (array of UUID strings, references to kenniscategorie)
  - `tags` (array of string)
  - `zaaktypeLinks` (array of string)
  - `lastUpdatedBy` (string)
  - `version` (integer, default 1)
  - `publishedAt` (string, date-time)
  - `archivedAt` (string, date-time)
  - `usefulnessScore` (number, default 0)
- AND MUST NOT redefine OpenRegister built-in fields (`id`, `uuid`, `uri`, `createdAt`, `updatedAt`, `auditTrail`, `tags`, `status`)

#### Scenario: kenniscategorie schema includes required properties

- GIVEN the Pipelinq register configuration is loaded
- WHEN the `kenniscategorie` schema is inspected
- THEN it MUST include `name` (string, required) as the only required property
- AND optional properties: `slug`, `parent` (uuid), `description`, `order` (integer, default 0), `icon`

#### Scenario: kennisfeedback schema includes required properties

- GIVEN the Pipelinq register configuration is loaded
- WHEN the `kennisfeedback` schema is inspected
- THEN it MUST include:
  - `article` (string, uuid, required) — reference to kennisartikel
  - `rating` (string, required, enum: `nuttig` / `niet_nuttig`)
  - `agent` (string, required, Nextcloud user UID)
- AND optional properties: `comment` (string), `status` (enum: `nieuw` / `in_behandeling` / `verwerkt`, default `nieuw`)

#### Scenario: Store initialisation registers all three schemas

- GIVEN the app settings include register and schema slugs for kennisartikel, kenniscategorie, and kennisfeedback
- WHEN `initializeStores()` runs
- THEN the object store MUST register all three types:
  ```js
  objectStore.registerObjectType('kennisartikel', 'kennisartikel', 'pipelinq')
  objectStore.registerObjectType('kenniscategorie', 'kenniscategorie', 'pipelinq')
  objectStore.registerObjectType('kennisfeedback', 'kennisfeedback', 'pipelinq')
  ```
- AND CRUD MUST work via `objectStore.saveObject('kennisartikel', data)` etc.

---

### REQ-KB-002: Article CRUD and Lifecycle

The system MUST support creating, editing, publishing, and archiving knowledge articles.

#### Scenario: Create a new article as concept

- GIVEN a knowledge manager opens `ArticleEditor.vue` in create mode (`/kennisbank/articles/new`)
- WHEN they fill in title, body (Markdown), and visibility
- THEN the form MUST validate:
  - `title` is required
  - `body` is required
  - `visibility` defaults to `intern` if not set
- AND saving MUST call `objectStore.saveObject('kennisartikel', { ...data, status: 'concept', version: 1, author: currentUserId })`
- AND the article MUST appear in `ArticleList.vue` with status badge "Concept"

#### Scenario: Publish an article

- GIVEN a concept article "Paspoort aanvragen"
- WHEN the editor clicks "Publiceren"
- THEN `status` is set to `gepubliceerd`
- AND `publishedAt` is set to the current ISO 8601 timestamp
- AND the article MUST appear in `KennisbankHome.vue` search results

#### Scenario: Archive an article

- GIVEN a gepubliceerd article
- WHEN the editor clicks "Archiveren"
- THEN `status` is set to `gearchiveerd`
- AND `archivedAt` is set to the current timestamp
- AND the article MUST NOT appear in the kennisbank home search results
- AND MUST still be accessible via direct UUID link for audit purposes

#### Scenario: Edit an article increments version

- GIVEN a gepubliceerd article at version 2
- WHEN an editor saves changes to the body
- THEN `version` MUST be incremented to 3
- AND `lastUpdatedBy` MUST be set to the editing agent's UID
- AND the full change is captured in the OpenRegister audit trail

#### Scenario: Live Markdown preview in editor

- GIVEN the editor has the ArticleEditor open
- WHEN they type Markdown in the textarea
- THEN a live preview panel MUST render the formatted output side-by-side using the `marked` library
- AND the preview MUST update without requiring a save

---

### REQ-KB-003: Category Management

The system MUST support hierarchical categories up to 3 levels via `parent` UUID references.

#### Scenario: Create a top-level category

- GIVEN the admin opens `CategoryManager.vue`
- WHEN they add a category "Burgerzaken" with no parent
- THEN the category MUST be created with `parent` set to null
- AND MUST appear as a root-level item in the category tree

#### Scenario: Create a subcategory

- GIVEN "Burgerzaken" exists as a category
- WHEN an admin creates "Paspoort en ID" with parent set to "Burgerzaken"
- THEN the new category MUST appear nested under "Burgerzaken" in the tree
- AND `parent` MUST be set to the UUID of "Burgerzaken"

#### Scenario: Category tree displays article counts

- GIVEN categories with associated articles
- WHEN an agent browses the category sidebar in `KennisbankHome.vue`
- THEN each category MUST display the count of published articles it contains
- AND parent categories MUST show the aggregate count including subcategory articles

#### Scenario: Article can belong to multiple categories

- GIVEN categories "Burgerzaken" and "Dienstverlening"
- WHEN an editor assigns both to article "Balie dienstverlening"
- THEN the article MUST appear in both category filtered views
- AND `categories` on the article MUST contain both UUIDs

---

### REQ-KB-004: Full-Text Search and Autocomplete

The system MUST support full-text search across articles using the OpenRegister `_search` parameter.

#### Scenario: Search returns matching articles

- GIVEN published articles containing the word "paspoort"
- WHEN an agent types "paspoort" in the search bar
- THEN only articles matching "paspoort" in title, body, or tags MUST be returned
- AND results MUST load within 1 second (single-user)

#### Scenario: Autocomplete triggers after 3 characters

- GIVEN the kennisbank search bar is focused
- WHEN the agent types 3 or more characters
- THEN autocomplete suggestions MUST appear showing matching article titles
- AND selecting a suggestion MUST navigate to the article detail

#### Scenario: No results empty state

- GIVEN no articles match the search query
- WHEN results are returned
- THEN a `CnEmptyState` MUST be shown with a message indicating no articles were found
- AND a prompt to clear the search or browse categories MUST be visible

#### Scenario: Category filter narrows search

- GIVEN articles in multiple categories
- WHEN an agent selects "Vergunningen" in the category filter AND enters a search term
- THEN only articles in the "Vergunningen" category tree matching the search MUST appear
- AND the active filter MUST be clearable

---

### REQ-KB-005: Article Detail View

The system MUST render article content and display metadata in the detail view.

#### Scenario: Markdown content is rendered

- GIVEN a gepubliceerd article with Markdown body content
- WHEN an agent navigates to `ArticleDetail.vue`
- THEN the body MUST be rendered as HTML using the `marked` library
- AND headings, code blocks, tables, and lists MUST display correctly

#### Scenario: Category breadcrumb is shown

- GIVEN an article categorized under "Burgerzaken > Paspoort en ID"
- WHEN the article detail is displayed
- THEN a breadcrumb trail "Burgerzaken › Paspoort en ID" MUST appear above the article body
- AND each breadcrumb segment MUST link to the filtered article list for that category

#### Scenario: Article metadata is visible

- GIVEN a gepubliceerd article
- WHEN the detail view loads
- THEN the following metadata MUST be displayed:
  - Author name
  - Last updated timestamp and editor
  - Version number
  - Publication date
  - Visibility badge (`intern` or `openbaar`)
  - Tags (as chips)

#### Scenario: Audit trail accessible via sidebar

- GIVEN an article with a change history (version > 1)
- WHEN the agent opens the sidebar and selects the Audit Trail tab
- THEN the `CnObjectSidebar` audit trail MUST list all edits with actor, timestamp, and changed fields
- AND version numbers MUST be visible in the trail

---

### REQ-KB-006: Agent Feedback

The system MUST allow authenticated agents to rate articles and optionally leave improvement suggestions.

#### Scenario: Submit nuttig rating

- GIVEN an agent reads article "Paspoort aanvragen"
- WHEN they click the "Nuttig" button
- THEN a kennisfeedback object MUST be created with `rating: 'nuttig'` and `agent: currentUserId`
- AND the article's `usefulnessScore` MUST be recalculated immediately
- AND the "Nuttig" button MUST appear selected without a page reload

#### Scenario: Submit niet nuttig rating with comment

- GIVEN an agent finds an article inadequate
- WHEN they click "Niet nuttig" and expand the suggestion form
- AND type an improvement suggestion
- THEN a kennisfeedback object MUST be created with `rating: 'niet_nuttig'` and `comment` set
- AND `status` on the feedback MUST default to `nieuw`

#### Scenario: usefulnessScore recalculated on feedback

- GIVEN an article with 8 "nuttig" and 2 "niet nuttig" feedback entries (total: 10)
- WHEN a new "nuttig" feedback is submitted
- THEN `usefulnessScore` MUST be updated to `9/11 * 100 ≈ 81.8`
- AND the update MUST be persisted via `ObjectService.saveObject()`

#### Scenario: Feedback requires authentication

- GIVEN an unauthenticated request
- WHEN `POST /api/kennisbank/feedback` is called
- THEN the response MUST be `401 Unauthorized`
- AND no feedback object MUST be created

---

### REQ-KB-007: Public Article API

The system MUST expose a public read-only API for citizen self-service portals.

#### Scenario: Public list returns only published openbaar articles

- GIVEN articles with mixed status and visibility combinations:
  - Article A: `status=gepubliceerd`, `visibility=openbaar`
  - Article B: `status=gepubliceerd`, `visibility=intern`
  - Article C: `status=concept`, `visibility=openbaar`
  - Article D: `status=gearchiveerd`, `visibility=openbaar`
- WHEN `GET /api/kennisbank/public` is called without authentication
- THEN only Article A MUST appear in results
- AND the response MUST return HTTP 200 with a `results` array and `total`, `page`, `pages` fields

#### Scenario: Internal fields are stripped from public response

- GIVEN Article A with author, lastUpdatedBy, and zaaktypeLinks set
- WHEN `GET /api/kennisbank/public/{id}` is called
- THEN the response MUST NOT include `author`, `lastUpdatedBy`, or `zaaktypeLinks`
- AND MUST include `title`, `body`, `summary`, `categories`, `tags`, `publishedAt`, `usefulnessScore`

#### Scenario: Public single article — 404 for intern or non-published

- GIVEN Article B with `visibility=intern` and `status=gepubliceerd`
- WHEN `GET /api/kennisbank/public/{id}` is called with Article B's UUID
- THEN the response MUST be HTTP 404
- AND MUST NOT reveal that the article exists

#### Scenario: Public endpoint supports search and pagination

- GIVEN 20 published openbaar articles
- WHEN `GET /api/kennisbank/public?_search=vergunning&_page=1&_limit=10` is called
- THEN only matching articles MUST appear
- AND the response MUST include pagination metadata (`total`, `page`, `pages`)

#### Scenario: No stack traces in error responses

- GIVEN a server error occurs while processing a public request
- WHEN the error response is returned
- THEN the body MUST contain only `{ "message": "..." }` with a static message
- AND MUST NOT contain exception messages, stack traces, or internal file paths

---

### REQ-KB-008: Navigation Integration

The system MUST add a Kennisbank section to the Pipelinq sidebar navigation.

#### Scenario: Kennisbank appears in sidebar

- GIVEN the Pipelinq app is loaded
- WHEN the agent views the left-hand navigation (`MainMenu.vue`)
- THEN a "Kennisbank" navigation item MUST be present with the `BookOpenPageVariant` icon
- AND clicking it MUST navigate to `/kennisbank`

#### Scenario: Routes are properly registered

- GIVEN the Vue router is initialised
- WHEN routes are inspected
- THEN the following named routes MUST exist:
  - `KennisbankHome` → `/kennisbank`
  - `ArticleDetail` → `/kennisbank/articles/:id`
  - `ArticleNew` → `/kennisbank/articles/new`
  - `ArticleEdit` → `/kennisbank/articles/:id/edit`
  - `CategoryManager` → `/kennisbank/categories`
- AND PHP routes in `appinfo/routes.php` MUST match the controller actions

---

### REQ-KB-009: Security and Access Control

#### Scenario: Unauthenticated access to public endpoints only

- GIVEN an unauthenticated browser request
- WHEN `GET /api/kennisbank/public` is called
- THEN the response MUST be HTTP 200 (no authentication required)
- WHEN `GET /api/kennisbank/feedback` is called
- THEN the response MUST be HTTP 401 (authentication required)

#### Scenario: SPDX license headers on all new files

- GIVEN all new PHP and Vue files
- WHEN their content is inspected
- THEN every file MUST start with:
  - PHP: `// SPDX-License-Identifier: EUPL-1.2`
  - Vue: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`

#### Scenario: No getMessage() in controller responses

- GIVEN a caught exception in `KennisbankController`
- WHEN the error response is returned to the client
- THEN the response body MUST use a static string message
- AND MUST NOT call or expose `$e->getMessage()`

---

### REQ-KB-010: Accessibility and Internationalisation

#### Scenario: All user-facing strings are translatable

- GIVEN any string visible to the user in kennisbank views
- WHEN the template is inspected
- THEN ALL strings MUST use `this.t('pipelinq', 'key')` (no hardcoded Dutch or English text)
- AND translation keys MUST be present in both `l10n/en.json` and `l10n/nl.json`

#### Scenario: Feedback buttons are keyboard navigable

- GIVEN the ArticleDetail.vue feedback row
- WHEN the user navigates using Tab
- THEN "Nuttig" and "Niet nuttig" buttons MUST be reachable
- AND activatable with Enter or Space
- AND the selected state MUST NOT rely solely on colour (WCAG AA requirement)
