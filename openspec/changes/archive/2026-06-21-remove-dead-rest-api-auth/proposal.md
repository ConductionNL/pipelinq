---
kind: code
---

## Why

Pipelinq's admin settings page (`src/views/settings/Settings.vue` rendered by
`lib/Settings/AdminSettings.php`) carries a §4 "REST API Authentication" section — a tokens tab
and an OAuth 2.0 tab — backed by `ApiAuthService`. The section authenticates **nothing**: it is
write-only configuration with no runtime consumer. Per ADR-022 (*Apps Consume OpenRegister
Abstractions*), a leaf app must not re-implement a capability OpenRegister (OR) already owns.

The deadness is verifiable by grep:

- **REST API tokens** — `ApiAuthService::generateToken()` / `listTokens()` / `revokeToken()`
  persist SHA-256 token hashes under `IAppConfig` keys `api_token_*`. The one method that would
  ever *authenticate* such a token, `ApiAuthService::validateToken()`, has **zero callers** in
  the whole tree. The only `validateToken` callsite — `PortalDocumentController` line 122 —
  invokes `$this->signing->validateToken(...)`, i.e. `Service\Portal\DocumentSigningService::validateToken()`,
  a different method on a different class for customer-portal document signing. No middleware,
  controller, or service ever calls `ApiAuthService::validateToken()`, so a generated token can
  never be presented to authenticate any request.

- **OAuth 2.0** — `ApiAuthService::saveOAuthConfig()` / `getOAuthConfig()` persist `oauth_*`
  config keys, but those values are only ever read back into the settings form
  (`SettingsController::index()` → `oauthConfig` → `Settings.vue` `oauthForm`). No service
  performs an OAuth exchange with them. (The unrelated `LogiusConnector`, `HaalCentraalClient`,
  `BrpAdminController` and `RingCentralAdapter` OAuth code uses its own, separate config keys —
  `brp.oauth_endpoint`, etc. — and never touches the §4 `oauth_*` keys.)

The §3 "MCP Server Administration" methods were already removed from `ApiAuthService` in the
preceding change (`remove-or-redundant-admin-settings`). Removing the REST-token + OAuth methods
here leaves `ApiAuthService` with no public surface at all, so the whole class is deleted.

OR owns the real consumer-authentication mechanism: `Db/Consumer.php` + `AuthorizationService` +
the `/api/consumers` surface. Keeping a write-only token/OAuth UI that authenticates nothing is
worse than having no UI — it tells an admin they have provisioned API credentials when they have
provisioned nothing.

## What Changes

- **DELETE** `lib/Service/ApiAuthService.php` (whole class — MCP-free since the previous change,
  and now token/OAuth-dead).
- **DELETE** `SettingsController` actions `listTokens()`, `generateToken()`, `revokeToken()`,
  `saveOAuth()`; the `ApiAuthService` constructor dependency; and the `apiTokens` / `oauthConfig`
  payload keys from `index()`.
- **DELETE** the matching routes from `appinfo/routes.php` (`settings#listTokens`,
  `settings#generateToken`, `settings#revokeToken`, `settings#saveOAuth`).
- **DELETE** the §4 "REST API Authentication" `<NcSettingsSection>` block (tokens + OAuth tabs)
  from `Settings.vue` plus its `data()` fields, the `onTokenGenerated` / `revokeToken` /
  `saveOAuth` methods, the `mounted()` hydration of `apiTokens` / `oauthConfig`, and the now-unused
  `GenerateTokenDialog` import + component registration and the `NcCheckboxRadioSwitch` import
  (only used by the OAuth tab).
- **DELETE** `src/dialogs/GenerateTokenDialog.vue`.
- **DELETE** the `apiTokens` / `oauthConfig` state + hydration from `src/store/modules/settings.js`.
- **DELETE** `tests/Unit/Service/ApiAuthServiceTest.php`; update
  `tests/Unit/Controller/SettingsControllerTest.php` to drop the `ApiAuthService` mock, the ctor
  arg, the `listTokens` / `getOAuthConfig` stubs, the `testListTokensReturnsTokenList` case, and
  the `apiTokens` / `oauthConfig` assertions in `testIndexIncludesAdminDataForAdmins`.
- The `IAppConfig` values already written under `api_token_*` and `oauth_*` are left in the
  database untouched (harmless inert orphan rows); only the code that read/wrote them is removed.

No new endpoints, services, or nextcloud-vue changes. The remaining admin sections (Register
mapping, Pipelines, Product Categories, Queue, Skills, Agent Profiles, Forecast, Lead Sources,
Request Channels, Prospect Discovery, BI Export, Lead Management, Shillinq, Shillinq AP, xWiki)
are unaffected.

## Impact

- Affected specs: `admin-settings` — adds one requirement recording that REST API token issuance
  and OAuth 2.0 client configuration are OR-owned (Consumers + `AuthorizationService`) and MUST NOT
  be re-implemented in Pipelinq's admin settings; modifies REQ-AS-120 so the settings read payload
  no longer asserts `apiTokens` / `oauthConfig` are present.
- Affected code: `lib/Service/ApiAuthService.php` (removed),
  `lib/Controller/SettingsController.php`, `appinfo/routes.php`,
  `src/views/settings/Settings.vue`, `src/dialogs/GenerateTokenDialog.vue` (removed),
  `src/store/modules/settings.js`, `tests/Unit/Service/ApiAuthServiceTest.php` (removed),
  `tests/Unit/Controller/SettingsControllerTest.php`.
- Behaviour: net removal of dead code; no functional regression. No request was ever
  authenticated by an `api_token_*` value or an `oauth_*` value, so removing the UI and code that
  managed them changes no enforced behaviour. Real consumer authentication continues to be owned
  by OpenRegister (Consumers + `AuthorizationService` + `/api/consumers`).
- ADRs: ADR-022 (Apps Consume OR Abstractions) — the governing rationale. ADR-031
  (Schema-declarative business logic over service classes) — same principle: shared data-layer
  capabilities (auth) are not re-modelled in leaf service classes.
