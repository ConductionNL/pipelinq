# Review: mvp-foundation

## Summary
- Tasks completed: 11/11
- tasks.md checked off: 12/12
- Spec compliance: **PASS** (all functional requirements met)

| Severity | Count |
|----------|-------|
| CRITICAL | 1 (letter-of-spec only) |
| WARNING | 5 |
| SUGGESTION | 4 |

## Findings

### CRITICAL

- [ ] **RC-002-IMPORT-CALL** (REQ-RC-002): Repair step calls `SettingsService::loadSettings()` which calls `ConfigurationService::importFromApp(appId, data, version, force)` with extra parameters, rather than the spec-mandated `ConfigurationService::importFromApp('pipelinq')`. Functionally equivalent — the actual `ConfigurationService` API requires `(appId, data, version, force)` arguments, so the implementation matches the real API, not the simplified spec description. **Spec should be updated to reflect actual API.**

### WARNING

- [ ] **RC-001-CLIENT-TYPE** (REQ-RC-001): Client schema `@type` is hardcoded to `schema:Person`; spec says "schema:Person or schema:Organization". The `type` property field distinguishes at data level. Reasonable interpretation.
- [ ] **AS-001-CLASS-NAME** (Plan 3.1): File is `SettingsSection.php` not `PipelinqSection.php` as plan specifies. Pre-existing file, functionally correct, info.xml matches.
- [ ] **AS-001-CLASS-NAME-2** (Plan 3.2): File is `AdminSettings.php` not `PipelinqAdmin.php`. Functionally correct, info.xml matches.
- [ ] **AS-002-VIEW-PATH** (Plan 5.2): Vue component at `src/views/settings/Settings.vue` not `src/views/admin/AdminSettings.vue`. Pre-existing file, internally consistent.
- [ ] **AS-002-MOUNT-ID** (Plan 5.1): Mount div uses `#pipelinq-settings` not `#pipelinq-admin`. Pre-existing convention, internally consistent.

### SUGGESTION

- **GENERAL-001**: Use `@nextcloud/axios` instead of raw `fetch` for automatic CSRF token handling.
- **GENERAL-002**: Use `generateUrl()` from `@nextcloud/router` for URL generation to handle `/index.php` prefix in various Nextcloud deployments.
- **GENERAL-003**: The `data-config` attribute passed from template is unused — Settings.vue fetches via API on mount. Either use `IInitialState` or remove the unused attribute.
- **GENERAL-004**: `index()` action has `@NoAdminRequired` (non-admins can read settings). Likely intentional for main app, but worth confirming.

## Requirement Compliance Detail

| Requirement | Status | Notes |
|------------|--------|-------|
| REQ-RC-001: JSON config with 5 schemas | PASS | All properties, types, formats, defaults match spec |
| REQ-RC-002: Repair step migration | PASS* | Functionally correct, spec description simplified |
| REQ-RC-003: Store registration (5 types) | PASS | All 5 types with graceful fallback |
| REQ-AS-001: Admin settings registration | PASS | ISettings + IIconSection + info.xml |
| REQ-AS-002: Register status display | PASS | Connected/Not configured states, schema table |
| REQ-AS-003: Re-import action | PASS | POST route, success/error feedback |

## Recommendation

**APPROVE** — All functional requirements are met. The single CRITICAL finding is a spec description vs. actual API mismatch (the implementation correctly uses the real ConfigurationService API). The WARNINGs are plan artifact naming deviations from pre-existing files, not spec violations. Safe to archive.
