# Tasks: kennisbank

## 0. Deduplication Check

- [ ] 0.1 Confirm `KennisbankController.php` and `KennisbankService.php` exist from 2026-03-20-kennisbank — this change extends, not replaces, them
- [ ] 0.2 Verify no overlap with OpenRegister `ObjectService`, `IndexService`, `ExportService`, `AuditTrailService` — document findings (expected: none; these are delegated to, not rebuilt)
- [ ] 0.3 Confirm no existing public search endpoint exists for kennisartikel in `appinfo/routes.php`

## 1. Backend — Public Search Endpoint (REQ-KB-001)

- [ ] 1.1 Add `searchPublicArticles(string $q, array $categories, array $tags, int $page, int $limit): array` to `KennisbankService.php` — filters status=gepubliceerd AND visibility=openbaar, strips internal fields (author, lastUpdatedBy, zaaktypeLinks, usefulnessScore)
- [ ] 1.2 Add `search()` route handler to `KennisbankController.php` — annotate `#[PublicPage] #[NoCSRFRequired]`, params: q, category, tags[], _page, _limit
- [ ] 1.3 Register `GET /api/kennisbank/public/search` in `appinfo/routes.php`
- [ ] 1.4 Register `OPTIONS /api/kennisbank/public/search` CORS route in `appinfo/routes.php`

## 2. Backend — Public Collections Endpoint (REQ-KB-002)

- [ ] 2.1 Add `getCategoryTree(): array` to `KennisbankService.php` — fetches all kenniscategorie objects, builds nested tree, counts gepubliceerd+openbaar articles per category
- [ ] 2.2 Add `getArticlesByCategory(string $slug, int $page, int $limit): array` to `KennisbankService.php` — resolves slug to UUID, filters articles by category reference
- [ ] 2.3 Add `getCollections()` route handler to `KennisbankController.php` — `#[PublicPage] #[NoCSRFRequired]`
- [ ] 2.4 Add `getCollectionArticles(string $slug)` route handler to `KennisbankController.php` — `#[PublicPage] #[NoCSRFRequired]`, returns 404 for unknown slug
- [ ] 2.5 Register `GET /api/kennisbank/public/collections` in `appinfo/routes.php`
- [ ] 2.6 Register `OPTIONS /api/kennisbank/public/collections` CORS route in `appinfo/routes.php`
- [ ] 2.7 Register `GET /api/kennisbank/public/collections/{slug}/articles` in `appinfo/routes.php` (before any `{id}` wildcard routes)

## 3. Backend — Export Endpoint (REQ-KB-003)

- [ ] 3.1 Add `exportArticles(string $format, array $filters): string` to `KennisbankService.php` — delegates to OpenRegister `ExportService`, supports format: json | csv
- [ ] 3.2 Add `export()` route handler to `KennisbankController.php` — `#[NoAdminRequired]`, call `IGroupManager::isAdmin()` and return 403 if not admin
- [ ] 3.3 Register `GET /api/kennisbank/articles/export` in `appinfo/routes.php` (BEFORE `{id}` wildcard)

## 4. Backend — Version History Endpoint (REQ-KB-004)

- [ ] 4.1 Add `getArticleVersions(string $id): array` to `KennisbankService.php` — fetches audit trail snapshots for the article via `AuditTrailService`, returns list of { version, editedAt, editedBy, changeType }
- [ ] 4.2 Add `getVersions(string $id)` route handler to `KennisbankController.php` — `#[NoAdminRequired]`, return 404 for unknown article
- [ ] 4.3 Register `GET /api/kennisbank/articles/{id}/versions` in `appinfo/routes.php`

## 5. Backend — Version Comparison Endpoint (REQ-KB-005)

- [ ] 5.1 Add `compareVersions(string $id, int $fromVersion, int $toVersion): array` to `KennisbankService.php` — fetches two audit snapshots, computes field-level diff ({ field, before, after }), returns 400 if version not found
- [ ] 5.2 Add `compareVersions(string $id, int $from, int $to)` route handler to `KennisbankController.php` — `#[NoAdminRequired]`
- [ ] 5.3 Register `GET /api/kennisbank/articles/{id}/versions/{from}/{to}` in `appinfo/routes.php`

## 6. Backend — Data Audit Endpoint (REQ-KB-006)

- [ ] 6.1 Add `getAuditLog(array $filters, int $page, int $limit): array` to `KennisbankService.php` — fetches audit trail for kennisartikel, kenniscategorie, kennisfeedback via `AuditTrailService`, supports filters: schema, action, actor, dateFrom, dateTo
- [ ] 6.2 Add `getAuditLog()` route handler to `KennisbankController.php` — `#[NoAdminRequired]`, `IGroupManager::isAdmin()` check, return 403 if not admin
- [ ] 6.3 Register `GET /api/kennisbank/audit` in `appinfo/routes.php`

## 7. Error Handling and Security (REQ-KB-007)

- [ ] 7.1 Verify all error responses use static `message` strings — no `$e->getMessage()` in any controller JSONResponse
- [ ] 7.2 Verify all admin checks use `IGroupManager::isAdmin()` on backend (not frontend-sent user claims)
- [ ] 7.3 Add `@spec openspec/changes/kennisbank/tasks.md` PHPDoc tags to all new/modified methods in KennisbankController and KennisbankService (ADR-003 traceability)
- [ ] 7.4 Add EUPL-1.2 SPDX headers to all modified files (ADR-014)

## 8. Tests (ADR-008)

- [ ] 8.1 Create `tests/Unit/Service/KennisbankServiceSearchTest.php` with ≥3 test methods per new service method (searchPublicArticles, getCategoryTree, getArticlesByCategory, exportArticles, getArticleVersions, compareVersions, getAuditLog)
- [ ] 8.2 Create `tests/integration/kennisbank-api.postman_collection.json` covering all 7 new endpoints — happy path (200) + error paths (400, 401, 403, 404)
- [ ] 8.3 Verify `composer check:strict` passes with no PHPUnit failures

## 9. Verification (ADR-008 Smoke Testing)

- [ ] 9.1 `curl -s "/api/kennisbank/public/search?q=paspoort"` — verify 200 response, results array, no author/zaaktypeLinks in output
- [ ] 9.2 `curl -s "/api/kennisbank/public/collections"` — verify 200 response, nested category tree with articleCount
- [ ] 9.3 `curl -s "/api/kennisbank/public/collections/burgerzaken/articles"` — verify 200 with paginated results; test unknown slug → 404
- [ ] 9.4 `curl -u admin:pass "/api/kennisbank/articles/export?format=json"` — verify 200 + JSON; as regular user → 403
- [ ] 9.5 `curl -u admin:pass "/api/kennisbank/articles/{id}/versions"` — verify version list; unknown UUID → 404
- [ ] 9.6 `curl -u admin:pass "/api/kennisbank/articles/{id}/versions/1/2"` — verify diff structure; non-existent version → 400
- [ ] 9.7 `curl -u admin:pass "/api/kennisbank/audit"` — verify audit events; as regular user → 403
- [ ] 9.8 Verify CORS OPTIONS request to `/api/kennisbank/public/search` returns 200 with CORS headers
- [ ] 9.9 Verify concept/intern articles do NOT appear in any public search or collection response
