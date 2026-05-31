# Design: kennisbank

## Architecture

### Data Flow

```
KennisbankHome.vue / ArticleList.vue
    | search, filter, browse
objectStore.fetchCollection('kennisartikel', { _search, status, categories })
    | calls
GET /apps/openregister/api/objects/pipelinq/kennisartikel?_search=...&status=gepubliceerd

ArticleDetail.vue
    | read single article + render Markdown
objectStore.fetchObject('kennisartikel', id)
    | calls
GET /apps/openregister/api/objects/pipelinq/kennisartikel/{id}

ArticleEditor.vue
    | create / update
objectStore.saveObject('kennisartikel', data)
    | calls
POST/PUT /apps/openregister/api/objects/pipelinq/kennisartikel[/{id}]

KennisbankController (public endpoint — citizen self-service)
    | filters status=gepubliceerd AND visibility=openbaar, strips internal fields
GET /index.php/apps/pipelinq/api/kennisbank/public[/{id}]

ArticleDetail.vue (feedback buttons)
    | POST feedback + recalculate score
POST /index.php/apps/pipelinq/api/kennisbank/feedback
    | calls KennisbankService.submitFeedback()
    | creates kennisfeedback object + updates article.usefulnessScore
```

### Data Model (OpenRegister Schemas)

Three new schemas added to `lib/Settings/pipelinq_register.json`.  
OpenRegister built-in fields (`id`, `uuid`, `uri`, `createdAt`, `updatedAt`, `status`, `tags`, `auditTrail`) are NOT redefined.

#### kennisartikel

Schema.org mapping: `@type: schema:Article`

| Property | Type | Required | Description |
|---|---|---|---|
| `title` | string | Yes | Article title (`schema:headline`) |
| `body` | string | Yes | Article content in Markdown format (`schema:articleBody`) |
| `summary` | string | No | Short summary for search result snippets, max 200 chars (`schema:abstract`) |
| `status` | string | Yes | Lifecycle status: `concept`, `gepubliceerd`, `gearchiveerd`; facetable |
| `visibility` | string | Yes | Access level: `intern` (agents only) or `openbaar` (public); facetable |
| `categories` | array of string | No | UUID references to kenniscategorie objects |
| `tags` | array of string | No | Searchable tags for article discovery |
| `zaaktypeLinks` | array of string | No | References to zaaktypen for context-aware suggestions |
| `author` | string | Yes | Nextcloud user UID of the article author |
| `lastUpdatedBy` | string | No | Nextcloud user UID of the last editor |
| `version` | integer | No | Article version number, incremented on each edit; default 1 |
| `publishedAt` | string (date-time) | No | Publication timestamp |
| `archivedAt` | string (date-time) | No | Archive timestamp |
| `usefulnessScore` | number | No | Aggregate usefulness rating (percentage of `nuttig` ratings); default 0 |

#### kenniscategorie

| Property | Type | Required | Description |
|---|---|---|---|
| `name` | string | Yes | Category display name |
| `slug` | string | No | URL-friendly identifier (e.g., `burgerzaken`) |
| `parent` | string (uuid) | No | UUID reference to parent kenniscategorie (supports up to 3 levels) |
| `description` | string | No | Category description shown in browse view |
| `order` | integer | No | Display order within the same parent level; default 0 |
| `icon` | string | No | MDI icon identifier (e.g., `account-group`) |

#### kennisfeedback

| Property | Type | Required | Description |
|---|---|---|---|
| `article` | string (uuid) | Yes | UUID reference to the rated kennisartikel |
| `rating` | string | Yes | Usefulness rating: `nuttig` or `niet_nuttig` |
| `comment` | string | No | Improvement suggestion text (free text) |
| `agent` | string | Yes | Nextcloud user UID of the rating agent |
| `status` | string | No | Feedback processing status: `nieuw`, `in_behandeling`, `verwerkt`; default `nieuw` |

---

### Reuse Analysis

The following existing OpenRegister services and `@conduction/nextcloud-vue` components are leveraged — no custom implementations are needed for these concerns:

| Existing service / component | Usage in this change |
|---|---|
| `ObjectService.findObjects($register, $schema, $params)` | Query kennisartikelen with `status=gepubliceerd`, `visibility=openbaar` filters in `KennisbankService`. 3-arg positional signature throughout. |
| `ObjectService.findObject($register, $schema, $id)` | Load single article in `getPublicArticle()`. |
| `ObjectService.saveObject($register, $schema, $object)` | Create kennisfeedback objects in `submitFeedback()`; update article `usefulnessScore`. |
| `IndexService` / `_search` param | Full-text search and autocomplete — no custom search endpoint needed. |
| `createObjectStore('kennisartikel')` | Pinia store for article CRUD in agent views. |
| `createObjectStore('kenniscategorie')` | Pinia store for category management in `CategoryManager.vue`. |
| `CnIndexPage` + `useListView` | `ArticleList.vue` — list with search/filter/sort/pagination; no custom list logic. |
| `CnDetailPage` + `CnDetailCard` | `ArticleDetail.vue` — detail layout with metadata sections. |
| `CnFormDialog` | `ArticleEditor.vue` for category selection; `CategoryManager.vue` CRUD forms. |
| `CnDeleteDialog` | Confirm article/category deletion without custom dialog. |
| `CnStatusBadge` | Status and visibility badges in `ArticleList.vue`. |
| `CnEmptyState` | Empty state when no articles match search or category filter. |
| `CnObjectSidebar` | Audit trail tab on `ArticleDetail.vue` — shows version history automatically. |
| `NotificationService` | Lifecycle notifications on publish/archive — no custom notification system. |

Explicitly NOT duplicated:
- **Search endpoint** — OpenRegister `_search` query parameter covers full-text search; `KennisbankController` is a field-stripping proxy for the public endpoint only, not a search engine.
- **CRUD API** — agent-facing CRUD goes directly through ObjectService/objectStore; `KennisbankController` covers only the two public read endpoints and the feedback submission.
- **Audit trail / versioning** — OpenRegister's `auditTrail` built-in handles change tracking; `version` field is an application-level display counter only.

---

### Backend

#### KennisbankController (`lib/Controller/KennisbankController.php`)

Public API endpoints for citizen-facing article access.

| Method | URL | Attributes | Action |
|---|---|---|---|
| GET | `/api/kennisbank/public` | `#[PublicPage] #[NoCSRFRequired]` | List published public articles |
| GET | `/api/kennisbank/public/{id}` | `#[PublicPage] #[NoCSRFRequired]` | Get single public article |
| POST | `/api/kennisbank/feedback` | `#[NoAdminRequired]` | Submit article feedback (authenticated agents only) |

The public list and detail endpoints filter by `status=gepubliceerd` AND `visibility=openbaar` and strip internal fields: `author`, `lastUpdatedBy`, `zaaktypeLinks`.

The feedback endpoint requires Nextcloud authentication (`#[NoAdminRequired]`) — citizens do not submit feedback.

#### KennisbankService (`lib/Service/KennisbankService.php`)

| Method | Signature | Description |
|---|---|---|
| `getPublicArticles` | `(string $search, string $category, int $limit, int $offset): array` | Query OpenRegister for published public articles; strip internal fields |
| `getPublicArticle` | `(string $id): array` | Load single article; verify `status=gepubliceerd` AND `visibility=openbaar`; strip internal fields; return 404 if not found or not public |
| `submitFeedback` | `(string $articleId, string $rating, ?string $comment, string $agentUid): array` | Create kennisfeedback object; call `recalculateScore()` |
| `recalculateScore` | `(string $articleId): float` | Fetch all feedback for article; calculate `nuttig / total * 100`; update article's `usefulnessScore` via `ObjectService.saveObject()` |

Error handling: all `catch (\Throwable $e)` blocks log full exception via `$this->logger->error()`. Controllers return static message strings — NEVER `$e->getMessage()` in responses.

---

### Frontend

#### Routes (added to `src/router/index.js`)

| Path | Name | Component | Notes |
|---|---|---|---|
| `/kennisbank` | `KennisbankHome` | `KennisbankHome.vue` | Search + browse |
| `/kennisbank/articles/:id` | `ArticleDetail` | `ArticleDetail.vue` | Detail view |
| `/kennisbank/articles/new` | `ArticleNew` | `ArticleEditor.vue` | Create mode |
| `/kennisbank/articles/:id/edit` | `ArticleEdit` | `ArticleEditor.vue` | Edit mode |
| `/kennisbank/categories` | `CategoryManager` | `CategoryManager.vue` | Admin view |

#### Views

**KennisbankHome.vue** (`src/views/kennisbank/KennisbankHome.vue`)
- Search bar (auto-focus, autocomplete after 3 chars via `_search` param)
- Category tree sidebar (collapsible `NcAppNavigationItem` list, shows article counts)
- Recently viewed articles (localStorage, max 5 entries)
- Popular articles section (sorted by `usefulnessScore` desc)
- Uses `useListView('kennisartikel', ...)` composable; no custom list logic

**ArticleList.vue** (`src/views/kennisbank/ArticleList.vue`)
- `CnIndexPage` with search, category filter, status filter
- `CnStatusBadge` for status (`concept` / `gepubliceerd` / `gearchiveerd`) and visibility (`intern` / `openbaar`)
- Sortable by `updatedAt`, `title`, `usefulnessScore`
- Row click → `$router.push({ name: 'ArticleDetail', params: { id } })`

**ArticleDetail.vue** (`src/views/kennisbank/ArticleDetail.vue`)
- Rendered Markdown body via `marked` library
- `CnDetailPage` with sections: metadata (author, version, dates), categories breadcrumb, tags
- Feedback row: "Nuttig" / "Niet nuttig" buttons; expandable suggestion textarea
- `CnObjectSidebar` for audit trail (version history)
- Props: `articleId` from route; `isNew = articleId === 'new'`

**ArticleEditor.vue** (`src/views/kennisbank/ArticleEditor.vue`)
- Markdown textarea with live preview (split-pane)
- Title, summary, category multi-select (`CnFormDialog`)
- Tags input, visibility toggle (`intern` / `openbaar`)
- Status controls: save as concept, publish, archive
- All strings via `this.t('pipelinq', 'key')`

**CategoryManager.vue** (`src/views/kennisbank/CategoryManager.vue`)
- Admin view using `CnIndexPage` with `useListView('kenniscategorie', ...)`
- Tree view showing parent–child hierarchy; add/edit/delete via `CnFormDialog` / `CnDeleteDialog`

#### Navigation

Add `NcAppNavigationItem` "Kennisbank" (icon: `BookOpenPageVariant` MDI) to `src/navigation/MainMenu.vue` between existing sections, pointing to `/kennisbank` route.

#### Store Registration

In `src/store/store.js`:
```js
objectStore.registerObjectType('kennisartikel', 'kennisartikel', 'pipelinq')
objectStore.registerObjectType('kenniscategorie', 'kenniscategorie', 'pipelinq')
objectStore.registerObjectType('kennisfeedback', 'kennisfeedback', 'pipelinq')
```

No custom Pinia stores — all state managed via `createObjectStore` plugins (files, auditTrails, relations).

---

### Seed Data

Seed objects for `lib/Settings/pipelinq_register.json` under `components.objects[]`. Using `@self` envelope per ADR-001-data-layer. All values are Dutch and realistic but fictional.

#### kenniscategorie (5 objects)

```json
{ "@self": { "register": "pipelinq", "schema": "kenniscategorie", "slug": "kenniscat-burgerzaken" },
  "name": "Burgerzaken", "slug": "burgerzaken", "description": "Paspoorten, rijbewijzen, uittreksels en overige burgerlijke stand zaken", "order": 1, "icon": "account-group" }

{ "@self": { "register": "pipelinq", "schema": "kenniscategorie", "slug": "kenniscat-vergunningen" },
  "name": "Vergunningen", "slug": "vergunningen", "description": "Omgevingsvergunningen, kapvergunningen en evenementenvergunningen", "order": 2, "icon": "file-certificate" }

{ "@self": { "register": "pipelinq", "schema": "kenniscategorie", "slug": "kenniscat-belastingen" },
  "name": "Belastingen en heffingen", "slug": "belastingen", "description": "OZB, afvalstoffenheffing, rioolheffing en kwijtschelding", "order": 3, "icon": "currency-eur" }

{ "@self": { "register": "pipelinq", "schema": "kenniscategorie", "slug": "kenniscat-paspoort-id" },
  "name": "Paspoort en ID-kaart", "slug": "paspoort-id", "parent": "kenniscat-burgerzaken", "description": "Aanvragen, verlengen en ophalen van reisdocumenten", "order": 1, "icon": "card-account-details" }

{ "@self": { "register": "pipelinq", "schema": "kenniscategorie", "slug": "kenniscat-omgevingsvergunning" },
  "name": "Omgevingsvergunning", "slug": "omgevingsvergunning", "parent": "kenniscat-vergunningen", "description": "Bouwen, verbouwen en wijzigen van gebruik", "order": 1, "icon": "home-city" }
```

#### kennisartikel (4 objects)

```json
{ "@self": { "register": "pipelinq", "schema": "kennisartikel", "slug": "artikel-paspoort-aanvragen" },
  "title": "Paspoort aanvragen — procedure en documenten",
  "summary": "Leg uit welke documenten nodig zijn, hoe lang de behandeltijd is, en wat de kosten zijn voor een gewoon paspoort.",
  "body": "## Benodigde documenten\n\n- Geldig identiteitsbewijs (of aangifte verlies/diefstal)\n- 1 recente pasfoto (conform ICAO-normen)\n- Uittreksels indien van toepassing\n\n## Procedure\n\n1. Maak een afspraak via de gemeentelijke website of bel 14 0xx.\n2. Kom op het afgesproken tijdstip langs bij de balie Burgerzaken.\n3. Medewerker neemt vingerafdrukken af en controleert documenten.\n4. Paspoort is klaar binnen 5 werkdagen (spoedprocedure: dezelfde dag, meerkosten).\n\n## Kosten\n\n| Type | Tarief |\n|------|--------|\n| Gewoon paspoort (10 jaar) | € 77,35 |\n| Spoedpaspoort | + € 54,20 |\n| Paspoort voor minderjarigen (5 jaar) | € 56,35 |",
  "status": "gepubliceerd", "visibility": "openbaar",
  "categories": ["kenniscat-paspoort-id"], "tags": ["paspoort", "reisdocument", "burgerzaken"],
  "author": "admin", "version": 2, "publishedAt": "2026-03-01T09:00:00Z", "usefulnessScore": 88 }

{ "@self": { "register": "pipelinq", "schema": "kennisartikel", "slug": "artikel-omgevingsvergunning-aanvragen" },
  "title": "Omgevingsvergunning aanvragen via het Omgevingsloket",
  "summary": "Uitleg over het indienen van een omgevingsvergunning via het Omgevingsloket Online (OLO) en de beoordelingstermijnen.",
  "body": "## Via het Omgevingsloket\n\nAlle aanvragen voor een omgevingsvergunning verlopen via [Omgevingsloket Online](https://www.omgevingsloket.nl).\n\n## Termijnen\n\n- Reguliere procedure: 8 weken (eenmalig te verlengen met 6 weken)\n- Uitgebreide procedure: 26 weken\n\n## Wanneer is een vergunning nodig?\n\n- Nieuwbouw of uitbreiding van een woning\n- Plaatsen van een dakkapel groter dan 0,5 m²\n- Wijzigen van het gebruik van een pand\n\n## Leges\n\nDe hoogte van de leges is afhankelijk van de bouwkosten. Zie de actuele legestabel in de tarievenlijst.",
  "status": "gepubliceerd", "visibility": "openbaar",
  "categories": ["kenniscat-omgevingsvergunning"], "tags": ["vergunning", "bouwen", "omgeving"],
  "author": "admin", "version": 1, "publishedAt": "2026-03-05T10:30:00Z", "usefulnessScore": 75 }

{ "@self": { "register": "pipelinq", "schema": "kennisartikel", "slug": "artikel-kwijtschelding-belasting" },
  "title": "Kwijtschelding gemeentelijke belastingen aanvragen",
  "summary": "Interne procedure voor het beoordelen van kwijtscheldingsverzoeken voor OZB, afvalstoffenheffing en rioolheffing.",
  "body": "## Intern gebruik — KCC\n\nDit artikel beschrijft de interne procedure. Verwijs burgers naar het kwijtscheldingsformulier op de website.\n\n## Voorwaarden\n\n- Inkomen onder bijstandsniveau (norm: 100% voor gezinnen, 90% voor alleenstaanden)\n- Vermogen beneden de vrijstellingsgrens (€ 3.020 voor 2026)\n\n## Behandeling door afdeling Belastingen\n\n1. Verzoek wordt automatisch getoetst via SUWI-koppeling (gemeentelijk systeem).\n2. Bij twijfel wordt contact opgenomen voor aanvullende informatie.\n3. Besluit binnen 8 weken na ontvangst volledig dossier.\n\n## Doorverwijzen\n\nWanneer burger belt over status: doorverwijzen naar afdeling Belastingen (toestel 3400) of registreer terugbelverzoek in Pipelinq.",
  "status": "gepubliceerd", "visibility": "intern",
  "categories": ["kenniscat-belastingen"], "tags": ["kwijtschelding", "belasting", "ozb"],
  "author": "admin", "version": 1, "publishedAt": "2026-02-15T08:00:00Z", "usefulnessScore": 62 }

{ "@self": { "register": "pipelinq", "schema": "kennisartikel", "slug": "artikel-rijbewijs-verlengen" },
  "title": "Rijbewijs verlengen — concept",
  "summary": "Procedure voor het verlengen van een rijbewijs bij de gemeente.",
  "body": "## Concept\n\nDit artikel is nog in bewerking. Neem voor vragen contact op met de redactie van de kennisbank.",
  "status": "concept", "visibility": "intern",
  "categories": ["kenniscat-burgerzaken"], "tags": ["rijbewijs", "verlengen"],
  "author": "admin", "version": 1, "usefulnessScore": 0 }
```

#### kennisfeedback (3 objects)

```json
{ "@self": { "register": "pipelinq", "schema": "kennisfeedback", "slug": "feedback-paspoort-001" },
  "article": "artikel-paspoort-aanvragen", "rating": "nuttig",
  "agent": "jan.devries", "status": "verwerkt" }

{ "@self": { "register": "pipelinq", "schema": "kennisfeedback", "slug": "feedback-omgevingsvergunning-001" },
  "article": "artikel-omgevingsvergunning-aanvragen", "rating": "niet_nuttig",
  "comment": "De informatie over leges ontbreekt — burger vroeg specifiek naar het bedrag voor een dakkapel van 12 m². Graag een tarieventabel toevoegen.",
  "agent": "lisa.vandenberg", "status": "nieuw" }

{ "@self": { "register": "pipelinq", "schema": "kennisfeedback", "slug": "feedback-kwijtschelding-001" },
  "article": "artikel-kwijtschelding-belasting", "rating": "nuttig",
  "comment": "Goed artikel, maar het doorkiesnummer voor afdeling Belastingen klopt niet meer (is nu 3401).",
  "agent": "pieter.bakker", "status": "in_behandeling" }
```

---

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
- `lib/Settings/pipelinq_register.json` — Add 3 schemas + seed objects; update register's schemas list
- `appinfo/routes.php` — Add kennisbank API routes
- `src/router/index.js` — Add kennisbank routes
- `src/navigation/MainMenu.vue` — Add Kennisbank nav item
- `src/store/store.js` — Register 3 new object types
