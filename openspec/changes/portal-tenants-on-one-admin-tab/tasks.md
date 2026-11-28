# Tasks: portal-tenants-on-one-admin-tab

- [x] **T1**: `portalTenantConfig` 1.1.0 with `status` (live, draft) in a register fragment
- [x] **T2**: `PortalTenantService::listConfigs()` and `GET /portal/api/admin/tenants` (admin only)
  - PHPUnit `PortalAdminControllerTest`
- [x] **T3**: `PortalAuditService::getRecent()`; `GET /portal/api/admin/audit-events?all=1` answers all tenants with `total`
  - PHPUnit `PortalAdminControllerTest`
- [x] **T4**: PortalSettings as the overview: Portalen/Auditlog tabs, the portal list, the selected portal's form, latest actions with CSV download
  - vitest `portalTenantManager.spec.js`, `portalAdminSettings.spec.js`
- [ ] **T5**: Seed the board's three portals in the example set (DQ-DATA) and re-measure PqBeheerPortaal
  - Seeded (28 Nov 2026): the Zuiddrecht set's three portals carry `status`: Mijn Zuiddrecht and Mijn Westervliet `live`, Ondernemersloket Oosterhaven `draft`, as the spec's two live and one draft (`64-admin-integrations.json`). PHPUnit `tests/Unit/Settings/ZuiddrechtPortalTenantStatusTest.php`. Open: re-measure PqBeheerPortaal on a live instance.
- [x] **T6**: `PortalTenantService::resolveTenantId()` resolves the tenant from custom domain, subdomain, then widget header, never from the body or query
- [x] **T7**: PHPUnit `PortalTenantServiceTest` for the resolution order and the ignored query-string tenant id
  - Resolution order: `PortalTenantServiceTest` (custom domain, subdomain, widget header, default). Ignored query/body tenant id: `PortalRequestGuardTest::testATenantIdInTheQueryOrBodyIsIgnored`, at the guard every portal endpoint resolves its tenant through.
