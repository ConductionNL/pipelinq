# Tasks: adopt-connection-registry

## 1. Declaration

- [x] 1.1 Add `lib/Settings/connections.json` with `cti`, the seven `social-*` rows, `berichtenbox` and `mail-provider`, validated against integriq's `connections.schema.json`; add `id="section-cti"` to `CtiPage.vue` and `id="section-mail-transports"` to `DeliverabilitySettings.vue`.
  - `tests/Unit/Settings/ConnectionsDeclarationTest.php`: parses, names this app, only D2 fields, unique keys equal to `ConnectionReportService::KEYS`, rising order, no em-dash, every link lands on an anchor or route that exists, no `adapter` block, Berichtenbox requires the four keys its code reads.

## 2. Reports

- [x] 2.1 Add `lib/Service/ConnectionReportService.php`: `report()`, `reportCtiCheck()` and `reportSocialReadiness()`, sending `ConnectionStatusReportedEvent` by string class name behind `class_exists`, never throwing.
  - `tests/Stubs/Integriq/Event/ConnectionStatusReportedEvent.php`, loaded by `tests/bootstrap.php` (psalm and phpstan already scan `tests/Stubs`).
  - `tests/Unit/Service/ConnectionReportServiceTest.php`: event and arguments when the class exists, nothing sent or logged when absent, unknown key and status refused, a throwing listener caught, the CTI and readiness mappings.
- [x] 2.2 `CtiController::testConnection` and a successful `updateConfig` report the CTI check; `SocialAccountController::index` reports the readiness.
  - `tests/Unit/Controller/CtiControllerTest.php` and `SocialAccountControllerTest.php`: the report is sent and the response is unchanged.

## 3. Page

- [x] 3.1 `src/manifest.d/97-connection-registry.json`: the `Integrations` page over `integriq/app_connection` with `requiresApp`, `showAdd: false`, the Add integration header action, and the `ConnectionsMenu` entry with `query`, `permission` and `visibleIf.appInstalled`.
- [x] 3.2 `src/services/connectionRegistry.js`: `openIntegriqConnections`, `connectionStatus`, `connectionSettingsLabel`; passed to CnAppRoot from `App.vue`.
  - `tests/vitest/connectionRegistry.spec.js`.
- [x] 3.3 l10n: new strings in `l10n/en.json` and `l10n/nl.json`, then `npm run l10n:build`.
- [x] 3.4 `tests/e2e/integrations-page.spec.ts` reads `integriq/app_connection` filtered on `app=pipelinq`. Not run here: it needs integriq installed and synced.

## 4. After merge

- [ ] 4.1 Run the e2e spec against an instance with both apps, then archive this change into `admin-settings`.
- [ ] 4.2 Follow-up issue: declare SMS, WhatsApp, payment providers and BI export sinks as one row per family that pipelinq reports on, since the contract keeps per-object families out (design D5.4, contract D12).
- [x] 4.3 Raise design D5 amendments 1 to 3 on hydra `connection-registry`. Taken in hydra#673.

## 5. Contract amendments (hydra#673)

- [x] 5.1 `cti` declared `reportedOnly: true`; `ConnectionReportService` maps `preview` to `limited` with `PREVIEW_MESSAGE` and accepts `limited`; `connectionStatus` names Limited (`Beperkt`); `l10n` rebuilt.
  - `ConnectionsDeclarationTest`: `reportedOnly` allowed and boolean, only `cti` carries it. `ConnectionReportServiceTest`: the statuses equal contract D3, a preview network sends `limited` with both halves of the message. `tests/vitest/connectionRegistry.spec.js`: six labels. `tests/e2e/integrations-page.spec.ts` expects `limited` for `preview`, not run.
