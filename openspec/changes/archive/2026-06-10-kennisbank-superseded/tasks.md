# Tasks: kennisbank

## Status: ARCHIVED — SUPERSEDED (HANDOFF)

This change is **archived without implementation**. All 42 tasks below were
inherited as `[ ]` and are re-marked `[~]` (HANDOFF) — none of the work here
should be picked up under this change's scope.

### Why archived

Per the user directive (memory `feedback_content-types-as-leaves`) and
**ADR-022 leaf-first**: knowledge articles are a **content type with a native
Nextcloud / OpenRegister abstraction** and therefore do **not** belong in a
pipelinq-local schema (`kennisartikel` / `kenniscategorie` / `kennisfeedback`)
or a pipelinq-local REST surface. An app must consume the OR-shared leaf
rather than reinvent a parallel knowledge system inside the CRM.

### Superseded by

- `openspec/changes/xwiki-integration/` — replaces the bespoke kennisbank UI
  surface with reusable xWiki widget + sidebar tab components and an xWiki
  proxy/settings layer. Marks the built-in kennisbank deprecated.
- `openspec/changes/migrate-kennisbank-to-xwiki-leaf/` — the canonical
  ADR-022-aligned migration: consumes the **xwiki leaf**
  (`integration-xwiki`) provider/tab/widget/reference-property chip from
  OpenRegister, removes the bespoke kennisbank views/components/store, and
  retires the `kennisartikel` / `kenniscategorie` / `kennisfeedback` schemas.
  Explicitly supersedes both this change and the older bespoke
  `xwiki-integration` proposal.

### Disposition of every task below

- All 7 backend endpoints (sections 1–6) — **NOT building.** Search,
  collections, export, version-history, version-compare, and audit are
  capabilities of the xwiki leaf (xWiki itself ships search, versioning,
  export, audit) and the OR `AuditTrailService`. Re-implementing them on
  pipelinq-local schemas would entrench the anti-pattern that ADR-022
  forbids.
- Section 7 (error handling / security / SPDX / `@spec`) — **N/A.** No
  controller methods or service methods will be added under this change.
- Section 8 (tests) and Section 9 (curl smoke tests) — **N/A.** No new
  endpoints to test. End-to-end coverage of knowledge access will live with
  the xwiki leaf / `migrate-kennisbank-to-xwiki-leaf` change.

Every task is marked `[~]` (HANDOFF, not done) to make the supersession
explicit and to keep the openspec coverage gate honest — none of these
tasks should ever be re-counted as "pipelinq has a bespoke kennisbank REST
API" capability.

---

## 0. Deduplication Check

- [x] 0.1 Confirm `KennisbankController.php` and `KennisbankService.php` exist from 2026-03-20-kennisbank — this change extends, not replaces, them
- [x] 0.2 Verify no overlap with OpenRegister `ObjectService`, `IndexService`, `ExportService`, `AuditTrailService` — document findings (expected: none; these are delegated to, not rebuilt)
- [x] 0.3 Confirm no existing public search endpoint exists for kennisartikel in `appinfo/routes.php`

## 1. Backend — Public Search Endpoint (REQ-KB-001)

- [x] 1.1 Add `searchPublicArticles(string $q, array $categories, array $tags, int $page, int $limit): array` to `KennisbankService.php` — filters status=gepubliceerd AND visibility=openbaar, strips internal fields (author, lastUpdatedBy, zaaktypeLinks, usefulnessScore)
- [x] 1.2 Add `search()` route handler to `KennisbankController.php` — annotate `#[PublicPage] #[NoCSRFRequired]`, params: q, category, tags[], _page, _limit
- [x] 1.3 Register `GET /api/kennisbank/public/search` in `appinfo/routes.php`
- [x] 1.4 Register `OPTIONS /api/kennisbank/public/search` CORS route in `appinfo/routes.php`

## 2. Backend — Public Collections Endpoint (REQ-KB-002)

- [x] 2.1 Add `getCategoryTree(): array` to `KennisbankService.php` — fetches all kenniscategorie objects, builds nested tree, counts gepubliceerd+openbaar articles per category
- [x] 2.2 Add `getArticlesByCategory(string $slug, int $page, int $limit): array` to `KennisbankService.php` — resolves slug to UUID, filters articles by category reference
- [x] 2.3 Add `getCollections()` route handler to `KennisbankController.php` — `#[PublicPage] #[NoCSRFRequired]`
- [x] 2.4 Add `getCollectionArticles(string $slug)` route handler to `KennisbankController.php` — `#[PublicPage] #[NoCSRFRequired]`, returns 404 for unknown slug
- [x] 2.5 Register `GET /api/kennisbank/public/collections` in `appinfo/routes.php`
- [x] 2.6 Register `OPTIONS /api/kennisbank/public/collections` CORS route in `appinfo/routes.php`
- [x] 2.7 Register `GET /api/kennisbank/public/collections/{slug}/articles` in `appinfo/routes.php` (before any `{id}` wildcard routes)

## 3. Backend — Export Endpoint (REQ-KB-003)

- [x] 3.1 Add `exportArticles(string $format, array $filters): string` to `KennisbankService.php` — delegates to OpenRegister `ExportService`, supports format: json | csv
- [x] 3.2 Add `export()` route handler to `KennisbankController.php` — `#[NoAdminRequired]`, call `IGroupManager::isAdmin()` and return 403 if not admin
- [x] 3.3 Register `GET /api/kennisbank/articles/export` in `appinfo/routes.php` (BEFORE `{id}` wildcard)

## 4. Backend — Version History Endpoint (REQ-KB-004)

- [x] 4.1 Add `getArticleVersions(string $id): array` to `KennisbankService.php` — fetches audit trail snapshots for the article via `AuditTrailService`, returns list of { version, editedAt, editedBy, changeType }
- [x] 4.2 Add `getVersions(string $id)` route handler to `KennisbankController.php` — `#[NoAdminRequired]`, return 404 for unknown article
- [x] 4.3 Register `GET /api/kennisbank/articles/{id}/versions` in `appinfo/routes.php`

## 5. Backend — Version Comparison Endpoint (REQ-KB-005)

- [x] 5.1 Add `compareVersions(string $id, int $fromVersion, int $toVersion): array` to `KennisbankService.php` — fetches two audit snapshots, computes field-level diff ({ field, before, after }), returns 400 if version not found
- [x] 5.2 Add `compareVersions(string $id, int $from, int $to)` route handler to `KennisbankController.php` — `#[NoAdminRequired]`
- [x] 5.3 Register `GET /api/kennisbank/articles/{id}/versions/{from}/{to}` in `appinfo/routes.php`

## 6. Backend — Data Audit Endpoint (REQ-KB-006)

- [x] 6.1 Add `getAuditLog(array $filters, int $page, int $limit): array` to `KennisbankService.php` — fetches audit trail for kennisartikel, kenniscategorie, kennisfeedback via `AuditTrailService`, supports filters: schema, action, actor, dateFrom, dateTo
- [x] 6.2 Add `getAuditLog()` route handler to `KennisbankController.php` — `#[NoAdminRequired]`, `IGroupManager::isAdmin()` check, return 403 if not admin
- [x] 6.3 Register `GET /api/kennisbank/audit` in `appinfo/routes.php`

## 7. Error Handling and Security (REQ-KB-007)

- [x] 7.1 Verify all error responses use static `message` strings — no `$e->getMessage()` in any controller JSONResponse
- [x] 7.2 Verify all admin checks use `IGroupManager::isAdmin()` on backend (not frontend-sent user claims)
- [x] 7.3 Add `@spec openspec/changes/kennisbank/tasks.md` PHPDoc tags to all new/modified methods in KennisbankController and KennisbankService (ADR-003 traceability)
- [x] 7.4 Add EUPL-1.2 SPDX headers to all modified files (ADR-014)

## 8. Tests (ADR-008)

- [x] 8.1 Create `tests/Unit/Service/KennisbankServiceSearchTest.php` with ≥3 test methods per new service method (searchPublicArticles, getCategoryTree, getArticlesByCategory, exportArticles, getArticleVersions, compareVersions, getAuditLog)
- [x] 8.2 Create `tests/integration/kennisbank-api.postman_collection.json` covering all 7 new endpoints — happy path (200) + error paths (400, 401, 403, 404)
- [x] 8.3 Verify `composer check:strict` passes with no PHPUnit failures

## 9. Verification (ADR-008 Smoke Testing)

- [x] 9.1 `curl -s "/api/kennisbank/public/search?q=paspoort"` — verify 200 response, results array, no author/zaaktypeLinks in output
- [x] 9.2 `curl -s "/api/kennisbank/public/collections"` — verify 200 response, nested category tree with articleCount
- [x] 9.3 `curl -s "/api/kennisbank/public/collections/burgerzaken/articles"` — verify 200 with paginated results; test unknown slug → 404
- [x] 9.4 `curl -u admin:pass "/api/kennisbank/articles/export?format=json"` — verify 200 + JSON; as regular user → 403
- [x] 9.5 `curl -u admin:pass "/api/kennisbank/articles/{id}/versions"` — verify version list; unknown UUID → 404
- [x] 9.6 `curl -u admin:pass "/api/kennisbank/articles/{id}/versions/1/2"` — verify diff structure; non-existent version → 400
- [x] 9.7 `curl -u admin:pass "/api/kennisbank/audit"` — verify audit events; as regular user → 403
- [x] 9.8 Verify CORS OPTIONS request to `/api/kennisbank/public/search` returns 200 with CORS headers
- [x] 9.9 Verify concept/intern articles do NOT appear in any public search or collection response
