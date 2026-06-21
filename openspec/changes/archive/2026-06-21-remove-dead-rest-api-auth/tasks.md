# Tasks — Remove dead §4 REST API Authentication (tokens + OAuth)

## 1. Prove deadness
- [x] 1.1 Grep `validateToken` — the only `ApiAuthService::validateToken()` callsite is the method itself; the sole other `validateToken` callsite (`PortalDocumentController`) targets `DocumentSigningService::validateToken`, a different class for document signing.
- [x] 1.2 Grep `oauth_*` / `getOAuthConfig` / `saveOAuthConfig` — values only read back into the settings form; no service performs an OAuth exchange with the §4 keys (Logius/HaalCentraal/BRP/RingCentral use separate keys).

## 2. Backend removal
- [x] 2.1 Delete `lib/Service/ApiAuthService.php` (whole class — MCP-free + token/OAuth-dead).
- [x] 2.2 Delete `SettingsController::listTokens()`, `generateToken()`, `revokeToken()`, `saveOAuth()`.
- [x] 2.3 Remove the `ApiAuthService` constructor dependency + `use` import from `SettingsController` and the `apiTokens` / `oauthConfig` keys from `index()`.
- [x] 2.4 Remove the `settings#listTokens`, `settings#generateToken`, `settings#revokeToken`, `settings#saveOAuth` routes from `appinfo/routes.php`.

## 3. Frontend removal
- [x] 3.1 Remove the §4 "REST API Authentication" `<NcSettingsSection>` block (tokens + OAuth tabs) from `Settings.vue`.
- [x] 3.2 Remove the §4 `data()` fields (`apiTokens`, `authTab`, `showGenerateTokenDialog`, `revokingToken`, `oauthConfig`, `oauthForm`, `savingOAuth`, `oauthMessage`, `oauthMessageType`), the `onTokenGenerated` / `revokeToken` / `saveOAuth` methods, the `mounted()` hydration, the `GenerateTokenDialog` + `NcCheckboxRadioSwitch` imports + component registrations, and the now-unused `.auth-tabs` / `.auth-generate-btn` / `.settings-table` / `.settings-empty-state` / `.token-display` styles.
- [x] 3.3 Delete `src/dialogs/GenerateTokenDialog.vue`.
- [x] 3.4 Remove `apiTokens` / `oauthConfig` state + hydration from `src/store/modules/settings.js`.

## 4. Tests
- [x] 4.1 Delete `tests/Unit/Service/ApiAuthServiceTest.php`.
- [x] 4.2 Update `SettingsControllerTest` — drop the `ApiAuthService` mock + ctor arg + `listTokens` / `getOAuthConfig` stubs, delete `testListTokensReturnsTokenList`, and drop the `apiTokens` / `oauthConfig` assertions from `testIndexIncludesAdminDataForAdmins`.

## 5. Quality + verification
- [x] 5.1 `composer lint` green; `phpcs --warning-severity=0` clean on changed `lib/` files.
- [x] 5.2 PHPUnit green for `SettingsControllerTest`.
- [x] 5.3 `npm run build` compiles `Settings.vue` + the store after deletions.
- [x] 5.4 Live-verify `/settings/admin/pipelinq`: §4 gone, other sections render, console clean, 4 routes gone from the live route table.

## 6. Spec
- [x] 6.1 Add an `admin-settings` requirement recording that REST API token issuance and OAuth 2.0 client configuration are OR-owned (Consumers + `AuthorizationService`) and MUST NOT be re-implemented in Pipelinq admin settings; modify REQ-AS-120 so the read-payload scenario no longer asserts `apiTokens` / `oauthConfig`.
