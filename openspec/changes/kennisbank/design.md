# Design: kennisbank

## Architecture

### Data Model

No new OpenRegister schemas are introduced by this change. All endpoints operate on the existing schemas defined in 2026-03-20-kennisbank and recorded in ADR-000:

| Schema | Purpose |
|--------|---------|
| `kennisartikel` | Knowledge base articles (title, body, status, visibility, categories, tags, author, version, usefulnessScore) |
| `kenniscategorie` | Hierarchical categories (name, slug, parent, description, order, icon) |
| `kennisfeedback` | Agent feedback on articles (article, rating, comment, agent, status) |

OpenRegister built-in fields available on all entities (do NOT redefine): id, uuid, uri, version, createdAt, updatedAt, owner, organization, register, schema, relations, files, auditTrail, notes, tasks, tags, status, locked.

---

### Reuse Analysis

This change delegates all heavy lifting to existing OpenRegister platform services:

| OpenRegister Service | Usage |
|----------------------|-------|
| `ObjectService::findObjects()` | Filter articles by status/visibility/category for search and collection endpoints |
| `IndexService` (via `_search` param) | Full-text search with field weighting and snippet highlighting |
| `FacetBuilder` | Faceted article counts per category, status, and visibility |
| `ExportService` | JSON/CSV bulk export of articles — no custom serialization needed |
| `AuditTrailService` | Article version history and before/after snapshots for diff endpoint |
| `AuthorizationService` | Admin check for export and audit endpoints |

No custom search algorithms, query builders, export parsers, or audit controllers are implemented. The app layer only adds filtering logic (status=gepubliceerd AND visibility=openbaar for public endpoints) and strips internal fields before returning public responses.

---

### Backend

#### KennisbankController (`lib/Controller/KennisbankController.php`)

Extend the existing controller from 2026-03-20-kennisbank with the following new route handlers:

| Method | URL | Auth | Description |
|--------|-----|------|-------------|
| GET | `/api/kennisbank/public/search` | `#[PublicPage] #[NoCSRFRequired]` | Full-text search — public articles only |
| GET | `/api/kennisbank/public/collections` | `#[PublicPage] #[NoCSRFRequired]` | Category tree with article counts |
| GET | `/api/kennisbank/public/collections/{slug}/articles` | `#[PublicPage] #[NoCSRFRequired]` | Articles per category (paginated) |
| GET | `/api/kennisbank/articles/export` | `#[NoAdminRequired]` + admin check | Bulk export JSON/CSV |
| GET | `/api/kennisbank/articles/{id}/versions` | `#[NoAdminRequired]` | Article version history |
| GET | `/api/kennisbank/articles/{id}/versions/{from}/{to}` | `#[NoAdminRequired]` | Diff between two versions |
| GET | `/api/kennisbank/audit` | `#[NoAdminRequired]` + admin check | Full audit log for kennisbank objects |
| OPTIONS | `/api/kennisbank/public/search` | `#[PublicPage] #[NoCSRFRequired]` | CORS preflight |
| OPTIONS | `/api/kennisbank/public/collections` | `#[PublicPage] #[NoCSRFRequired]` | CORS preflight |

Route handlers are thin (<10 lines each): validate params, call service, return `JSONResponse`. No business logic in controllers (ADR-003).

#### KennisbankService (`lib/Service/KennisbankService.php`)

Extend the existing service with:

```
searchPublicArticles(string $q, array $categories, array $tags, int $page, int $limit): array
  → Calls ObjectService::findObjects() with _search=$q, status=gepubliceerd, visibility=openbaar
  → Returns { total, page, pages, results: [{ id, title, summary, categories, tags, publishedAt, snippet }] }
  → Strips: author UID, zaaktypeLinks, usefulnessScore, internal fields

getCategoryTree(): array
  → Fetches all kenniscategorie objects, builds nested tree structure
  → For each category, count gepubliceerd+openbaar articles via findObjects
  → Returns [{ id, name, slug, description, icon, order, articleCount, children: [...] }]

getArticlesByCategory(string $slug, int $page, int $limit): array
  → Resolves slug → category UUID, then findObjects with category filter
  → Returns same structure as searchPublicArticles

exportArticles(string $format, array $filters): string
  → Delegates to ExportService::export('kennisartikel', $filters, $format)
  → format: 'json' | 'csv'. Admin only (checked by controller)

getArticleVersions(string $id): array
  → Calls AuditTrailService to fetch audit trail for the article object
  → Returns [{ version, editedAt, editedBy, changeType }]

compareVersions(string $id, int $fromVersion, int $toVersion): array
  → Fetches two audit snapshots, computes field-level diff
  → Returns { from: { version, snapshot }, to: { version, snapshot }, diff: [{ field, before, after }] }

getAuditLog(array $filters, int $page, int $limit): array
  → Fetches audit trail for all kennisartikel + kenniscategorie + kennisfeedback objects
  → Supports filters: schema, action, actor, dateFrom, dateTo
  → Admin only (checked by controller)
```

#### Search Response Structure

Public search endpoint response:
```json
{
  "total": 42,
  "page": 1,
  "pages": 3,
  "results": [
    {
      "id": "uuid",
      "title": "Paspoort aanvragen",
      "summary": "Hoe u een paspoort aanvraagt bij de gemeente.",
      "categories": ["Burgerzaken"],
      "tags": ["paspoort", "identiteitsbewijs"],
      "publishedAt": "2026-03-15T09:00:00Z",
      "snippet": "...aanvraag indienen bij het <em>gemeentehuis</em>..."
    }
  ]
}
```

Internal fields excluded from public responses: `author`, `lastUpdatedBy`, `zaaktypeLinks`, `usefulnessScore`, `body` (summary only for list; full body only on article detail).

#### Routes (`appinfo/routes.php`)

```php
// Specific routes BEFORE wildcard {id} routes (ADR-003)
['name' => 'Kennisbank#searchPublic',      'url' => '/api/kennisbank/public/search',                         'verb' => 'GET'],
['name' => 'Kennisbank#searchPublicCors',  'url' => '/api/kennisbank/public/search',                         'verb' => 'OPTIONS'],
['name' => 'Kennisbank#getCollections',    'url' => '/api/kennisbank/public/collections',                    'verb' => 'GET'],
['name' => 'Kennisbank#getCollectionsCors','url' => '/api/kennisbank/public/collections',                    'verb' => 'OPTIONS'],
['name' => 'Kennisbank#getCollectionArticles','url' => '/api/kennisbank/public/collections/{slug}/articles', 'verb' => 'GET'],
['name' => 'Kennisbank#exportArticles',    'url' => '/api/kennisbank/articles/export',                       'verb' => 'GET'],
['name' => 'Kennisbank#getVersions',       'url' => '/api/kennisbank/articles/{id}/versions',                'verb' => 'GET'],
['name' => 'Kennisbank#compareVersions',   'url' => '/api/kennisbank/articles/{id}/versions/{from}/{to}',    'verb' => 'GET'],
['name' => 'Kennisbank#getAuditLog',       'url' => '/api/kennisbank/audit',                                 'verb' => 'GET'],
```

---

### Stakeholders (Inferred)

| Stakeholder | Role | Goal |
|-------------|------|------|
| System integrators | Technical implementers building citizen portals | Consume search API without authentication friction |
| KCC agents using CTI clients | Daily knowledge base users via desktop app | Get article suggestions pushed by external app during phone calls |
| Municipality IT managers | Procurement evaluators | Verify open API capability for tender compliance |
| Editors / knowledge managers | Content authors | Compare article versions to review editorial history |
| Compliance officers | Governance auditors | Access full audit log for regulatory accountability |

---

### Seed Data

This change does not introduce or modify OpenRegister schemas. Seed data for `kennisartikel`, `kenniscategorie`, and `kennisfeedback` is defined in the 2026-03-20-kennisbank change as part of `lib/Settings/pipelinq_register.json`. No additional seed data is required for this change (API-only, non-schema backend logic).

For reference, the existing seed data includes realistic Dutch knowledge base articles (Paspoort aanvragen, Rijbewijs verlengen, Parkeervergunning), category hierarchy (Burgerzaken → Reisdocumenten), and agent feedback objects.

---

### Frontend

No new Vue views are required. The public REST API is consumed by external systems. The existing internal kennisbank views (KennisbankHome, ArticleList, ArticleDetail, ArticleEditor) from 2026-03-20-kennisbank are unchanged.

Optional enhancement (in scope): Update `ArticleDetail.vue` to display a version history tab using the new `GET /api/kennisbank/articles/{id}/versions` endpoint, rendered in a `CnObjectSidebar` audit tab or a `CnDetailCard` section. This reuses `CnObjectSidebar` → `CnAuditTrailTab` pattern.

---

## Files Changed

### Modified Files
- `lib/Controller/KennisbankController.php` — Add 9 new route handlers
- `lib/Service/KennisbankService.php` — Add 7 new service methods
- `appinfo/routes.php` — Register 9 new routes (specific before wildcard)

### New Files
- `tests/Unit/Service/KennisbankServiceSearchTest.php` — PHPUnit tests for search, collections, export, versions, audit
- `tests/integration/kennisbank-api.postman_collection.json` — Newman collection for all new endpoints
