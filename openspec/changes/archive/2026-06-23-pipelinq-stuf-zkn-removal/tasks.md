## 1. Remove StUF backend (services, job, controller, routes)

- [x] 1.1 Delete `lib/Service/Stuf/` in full (engine, parser, vault, circuit breaker, register access, dispatcher, mapper, the dead `StufRequestIntegrationService`, six exception classes).
- [x] 1.2 Delete `lib/BackgroundJob/StufRetryJob.php` (self-scheduled; not in `info.xml` `<background-jobs>`).
- [x] 1.3 Delete `lib/Controller/StufController.php`.
- [x] 1.4 Remove the four `stuf#` route entries (`outbound`/`inkomend`/`endpoints`/`messages`) + the StUF comment block from `appinfo/routes.php`.
- [x] 1.5 Confirm `lib/AppInfo/Application.php` registers nothing StUF (it did not — no edit needed).

## 2. Remove StUF register / schemas

- [x] 2.1 Delete `lib/Settings/register.d/85-stuf-zkn-bg-adapter.json` (`stufEndpoint` + `stufMessage` schemas + seeds).

## 3. Remove StUF frontend

- [x] 3.1 Delete `src/views/settings/StufEndpoints.vue`, `src/views/settings/StufAuditLog.vue`, `src/services/stufApi.js`, `src/components/StufLinkedZaakBadge.vue`.
- [x] 3.2 Remove the two StUF imports + the two registry entries (`StufEndpointsView`, `StufAuditLogView`) from `src/registry.js`.
- [x] 3.3 Remove the two StUF menu items + two StUF pages from `src/manifest.json`.
- [x] 3.4 Remove the `StufEndpoints` `settingsSection` entry and trim the StUF/StufAuditLog prose from `src/menu-layout.json`.

## 4. Remove StUF tests + docs

- [x] 4.1 Delete `tests/Unit/Service/Stuf/`.
- [x] 4.2 Delete `docs/Integrations/stuf-zkn-bg-adapter.md` (procest STEP 1 owns procest docs).

## 5. Keep the ZGW bridge untouched

- [x] 5.1 Confirm `lib/Service/Zgw/*` (ZrcClient/ZtcClient/DrcClient/BrcClient/AcClient, NrcSubscriptionService, ZgwNotificationController, NrcNotificationListener) is NOT removed.
- [x] 5.2 Confirm `ZgwCoexistenceValidator` (REQ-ZGW-008) keeps its `stufEndpoint` schema-slug double-write guard; `ZgwRegisterAccess::findAll` returns `[]` for the now-missing schema (catches `Throwable`), so it degrades to "ZGW only" without error.

## 6. Clean up + cache-bust

- [x] 6.1 Grep the whole source tree for dangling refs to any deleted class/route/component/service id (`StufController`, `StufRetryJob`, `Service\Stuf\`, `StufEndpointsView`, `stufApi`, `stuf#`, `stuf/endpoints`, `stuf/audit-log`) — none remain (only ZGW coexistence string refs, intentional).
- [x] 6.2 Bump `appinfo/info.xml` `<version>` `0.5.12` → `0.5.13`.

## 7. Verify

- [x] 7.1 `composer lint` green; `phpcs` on `appinfo/routes.php` introduces zero new violations (21 before / 21 after — pre-existing routes.php phpcs debt unchanged); `phpstan` introduces zero new errors referencing any deleted symbol (the 117 pre-existing errors are in untouched files — Zgw clients, unrelated controllers/services).
- [x] 7.2 `npm run build` compiles (no "Module not found" / "Can't resolve" — the removed imports left no dangle).
- [x] 7.3 Live on `:8080` after `occ upgrade` to 0.5.13 + container restart (fresh APCu): pipelinq loads, dashboard + nav + settings foldout render, zero StUF text/links in the DOM, `api/export/jobs` still 200 JSON, and `api/stuf/endpoints` behaves identically to a non-existent route (200 SPA-shell fallthrough, same as a bogus path) — the `stuf#` handlers are genuinely gone. Only console error is the pre-existing `Invalid currency code: @config.currency` (tracked by `pipelinq-dashboard-config-currency`), unrelated to this removal.
