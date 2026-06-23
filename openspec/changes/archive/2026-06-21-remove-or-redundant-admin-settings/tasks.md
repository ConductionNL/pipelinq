# Tasks — Remove OR-redundant admin settings (§5 Objects API Access, §3 MCP)

## 1. Prove deadness
- [x] 1.1 Grep `ObjectenAccessService` / `isAllowed(` — only caller is `SettingsController::getObjectenAccess`; object data path never consults it.
- [x] 1.2 Grep `mcp_` / `McpConfig` — only settings plumbing; no MCP client/dispatch consumer.

## 2. Backend removal
- [x] 2.1 Delete `lib/Service/ObjectenAccessService.php`.
- [x] 2.2 Delete `ApiAuthService::saveMcpConfig()` + `getMcpConfig()`; keep the class and `SECRET_PLACEHOLDER` (used by OAuth).
- [x] 2.3 Delete `SettingsController::getObjectenAccess()`, `saveObjectenAccess()`, `saveMcp()`.
- [x] 2.4 Remove the `ObjectenAccessService` constructor dependency from `SettingsController` and the `objectenAccess` / `mcpConfig` keys from `index()`.
- [x] 2.5 Remove the `settings#getObjectenAccess`, `settings#saveObjectenAccess`, `settings#saveMcp` routes from `appinfo/routes.php`.

## 3. Frontend removal
- [x] 3.1 Remove the §5 "Objects API Access" and §3 "MCP Server Administration" `<NcSettingsSection>` blocks from `Settings.vue`.
- [x] 3.2 Remove their `data()` fields, the `objectenAccessEntries` computed, the `mounted()` wiring, and the `loadGroupOptions` / `saveSchemaAccess` / `saveMcp` methods; drop the unused `NcSelect` import + component registration.
- [x] 3.3 Remove `objectenAccess` / `mcpConfig` state and hydration from `src/store/modules/settings.js`.

## 4. Tests
- [x] 4.1 Delete `tests/Unit/Service/ObjectenAccessServiceTest.php`.
- [x] 4.2 Remove `testGetMcpConfigExcludesSecrets` from `ApiAuthServiceTest`.
- [x] 4.3 Update `SettingsControllerTest` — drop the `ObjectenAccessService` mock + ctor arg + `getMcpConfig` stubs + `objectenAccess` / `mcpConfig` assertions.

## 5. Quality + verification
- [x] 5.1 `composer lint` green; `phpcs --warning-severity=0` clean on changed `lib/` files.
- [x] 5.2 PHPUnit green for `SettingsControllerTest` + `ApiAuthServiceTest`.
- [x] 5.3 `npm run build` compiles `Settings.vue` + the store after deletions.
- [x] 5.4 Live-verify `/settings/admin/pipelinq`: §5 + §3 gone, other sections render, console clean.

## 6. Spec
- [x] 6.1 Add an `admin-settings` requirement recording that per-schema Objects API access control and MCP server administration are OR-owned and MUST NOT be re-implemented in Pipelinq admin settings.
