# Tasks: kennisbank

## 0. Deduplication Check

- [x] 0.1 Confirm `KennisbankController.php` and `KennisbankService.php` exist from 2026-03-20-kennisbank — this change extends, not replaces, them
  - NOTE: The 2026-03-20-kennisbank change was archived but only seeded the schemas (`kennisartikel`, `kenniscategorie`, `kennisfeedback` in `lib/Settings/pipelinq_register.json`) — no controller/service was ever built. This change therefore CREATES `KennisbankController.php` + `KennisbankService.php` for the first time, on top of the existing schemas (ADR-037 monolith left untouched).
- [x] 0.2 Verify no overlap with OpenRegister `ObjectService`, `IndexService`, `ExportService`, `AuditTrailService` — documented in design.md Reuse Analysis. The app layer only adds public-visibility filtering and internal-field stripping; all data access delegates to OpenRegister `ObjectService` (`find`/`findAll`/`getLogs`).
- [x] 0.3 Confirm no existing public search endpoint exists for kennisartikel in `appinfo/routes.php` — confirmed none.

## 1. Backend — Public Search Endpoint (REQ-KB-001)

- [x] 1.1 Add `searchPublicArticles(string $q, array $categories, array $tags, int $page, int $limit): array` to `KennisbankService.php` — filters status=gepubliceerd AND visibility=openbaar, strips internal fields (author, lastUpdatedBy, zaaktypeLinks, usefulnessScore)
- [x] 1.2 Add `searchPublic()` route handler to `KennisbankController.php` — annotate `@PublicPage @NoCSRFRequired @CORS`, params: q, category, tags[], _page, _limit
- [x] 1.3 Register `GET /api/kennisbank/public/search` in `appinfo/routes.php`
- [x] 1.4 Register `OPTIONS /api/kennisbank/public/search` CORS route in `appinfo/routes.php`

## 2. Backend — Public Collections Endpoint (REQ-KB-002)

- [x] 2.1 Add `getCategoryTree(): array` to `KennisbankService.php` — fetches all kenniscategorie objects, builds nested tree, counts gepubliceerd+openbaar articles per category
- [x] 2.2 Add `getArticlesByCategory(string $slug, int $page, int $limit): array` to `KennisbankService.php` — resolves slug to UUID, filters articles by category reference (returns null for unknown slug → 404)
- [x] 2.3 Add `getCollections()` route handler to `KennisbankController.php` — `@PublicPage @NoCSRFRequired @CORS`
- [x] 2.4 Add `getCollectionArticles(string $slug)` route handler to `KennisbankController.php` — `@PublicPage @NoCSRFRequired @CORS`, returns 404 for unknown slug
- [x] 2.5 Register `GET /api/kennisbank/public/collections` in `appinfo/routes.php`
- [x] 2.6 Register `OPTIONS /api/kennisbank/public/collections` CORS route in `appinfo/routes.php`
- [x] 2.7 Register `GET /api/kennisbank/public/collections/{slug}/articles` in `appinfo/routes.php` (before any `{id}` wildcard routes)

## 3. Backend — Export Endpoint (REQ-KB-003)

- [x] 3.1 Add `exportArticles(string $format, array $filters): array` to `KennisbankService.php` — serialises articles to JSON or CSV (returns contentType/filename/body)
- [x] 3.2 Add `exportArticles()` route handler to `KennisbankController.php` — `@NoAdminRequired`, call `IGroupManager::isAdmin()` and return 403 if not admin (401 when unauthenticated). Streams via `DataDownloadResponse`.
- [x] 3.3 Register `GET /api/kennisbank/articles/export` in `appinfo/routes.php` (BEFORE `{id}` wildcard)

## 4. Backend — Version History Endpoint (REQ-KB-004)

- [x] 4.1 Add `getArticleVersions(string $id): ?array` to `KennisbankService.php` — fetches audit trail snapshots for the article via `ObjectService::getLogs()`, returns list of { version, editedAt, editedBy, changeType }
- [x] 4.2 Add `getVersions(string $id)` route handler to `KennisbankController.php` — `@NoAdminRequired`, return 404 for unknown article (401 when unauthenticated)
- [x] 4.3 Register `GET /api/kennisbank/articles/{id}/versions` in `appinfo/routes.php`

## 5. Backend — Version Comparison Endpoint (REQ-KB-005)

- [x] 5.1 Add `compareVersions(string $id, int $fromVersion, int $toVersion): ?array` to `KennisbankService.php` — fetches two audit snapshots, computes field-level diff ({ field, before, after }), throws OutOfRangeException (→ 400) if version not found
- [x] 5.2 Add `compareVersions(string $id, int $from, int $to)` route handler to `KennisbankController.php` — `@NoAdminRequired`
- [x] 5.3 Register `GET /api/kennisbank/articles/{id}/versions/{from}/{to}` in `appinfo/routes.php` (before the `{id}/versions` route — more specific first)

## 6. Backend — Data Audit Endpoint (REQ-KB-006)

- [x] 6.1 Add `getAuditLog(array $filters, int $page, int $limit): array` to `KennisbankService.php` — fetches audit trail for kennisartikel, kenniscategorie, kennisfeedback via `ObjectService::getLogs()`, supports filters: schema, action, actor, dateFrom, dateTo
- [x] 6.2 Add `getAuditLog()` route handler to `KennisbankController.php` — `@NoAdminRequired`, `IGroupManager::isAdmin()` check, return 403 if not admin (401 when unauthenticated)
- [x] 6.3 Register `GET /api/kennisbank/audit` in `appinfo/routes.php`

## 7. Error Handling and Security (REQ-KB-007)

- [x] 7.1 Verify all error responses use static `message` strings — no `$e->getMessage()` in any controller JSONResponse (real errors logged server-side only)
- [x] 7.2 Verify all admin checks use `IGroupManager::isAdmin()` on backend (derived from `IUserSession`, not frontend-sent user claims)
- [x] 7.3 Add `@spec openspec/changes/kennisbank/tasks.md` PHPDoc tags to all new/modified methods in KennisbankController and KennisbankService (ADR-003 traceability)
- [x] 7.4 Add EUPL-1.2 SPDX headers to all modified files (ADR-014)

## 8. Tests (ADR-008)

- [x] 8.1 Create `tests/Unit/Service/KennisbankServiceSearchTest.php` with ≥3 test methods per new service method (search, collections, export, versions, compare, audit, config) — 17 test methods asserting real transformation behaviour (field stripping, tree counts, diff, CSV, filtering)
- [x] 8.2 Create `tests/integration/kennisbank-api.postman_collection.json` covering all 7 new endpoints — happy path (200) + error paths (400, 404, CORS). Credentials via env-variable placeholders (no committed defaults; ADR-005).
- [x] 8.3 Verify `composer check:strict` passes with no PHPUnit failures

## 9. Verification (ADR-008 Smoke Testing)

> DEFERRED — tasks 9.1-9.9 require a live Nextcloud instance with OpenRegister installed and kennisbank seed data loaded. They are covered by the Newman collection (`tests/integration/kennisbank-api.postman_collection.json`) which exercises the same happy/error paths and is runnable against a live instance. Unit tests (8.1) assert the equivalent service-level behaviour offline.

- [ ] 9.1 `curl -s "/api/kennisbank/public/search?q=paspoort"` — verify 200 response, results array, no author/zaaktypeLinks in output (DEFERRED — needs live instance; covered by Newman + unit testSearchStripsInternalFields)
- [ ] 9.2 `curl -s "/api/kennisbank/public/collections"` — verify 200 response, nested category tree with articleCount (DEFERRED — needs live instance; covered by Newman + unit testCategoryTreeBuildsNestedCountsAndChildren)
- [ ] 9.3 `curl -s "/api/kennisbank/public/collections/burgerzaken/articles"` — verify 200 with paginated results; test unknown slug → 404 (DEFERRED — needs live instance; covered by Newman + unit testArticlesByCategory*)
- [ ] 9.4 `curl -u admin:pass "/api/kennisbank/articles/export?format=json"` — verify 200 + JSON; as regular user → 403 (DEFERRED — needs live instance; covered by Newman)
- [ ] 9.5 `curl -u admin:pass "/api/kennisbank/articles/{id}/versions"` — verify version list; unknown UUID → 404 (DEFERRED — needs live instance; covered by Newman + unit testGetArticleVersions*)
- [ ] 9.6 `curl -u admin:pass "/api/kennisbank/articles/{id}/versions/1/2"` — verify diff structure; non-existent version → 400 (DEFERRED — needs live instance; covered by Newman + unit testCompareVersions*)
- [ ] 9.7 `curl -u admin:pass "/api/kennisbank/audit"` — verify audit events; as regular user → 403 (DEFERRED — needs live instance; covered by Newman + unit testAuditLog*)
- [ ] 9.8 Verify CORS OPTIONS request to `/api/kennisbank/public/search` returns 200 with CORS headers (DEFERRED — needs live instance; covered by Newman OPTIONS test)
- [ ] 9.9 Verify concept/intern articles do NOT appear in any public search or collection response (DEFERRED — needs live instance; enforced server-side by status=gepubliceerd AND visibility=openbaar filter; asserted offline by unit search tests)
