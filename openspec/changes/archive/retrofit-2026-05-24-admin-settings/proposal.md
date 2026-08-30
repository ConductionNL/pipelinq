# Retrofit — admin-settings

Describes observed behavior of the 2 generic config-accessor methods on `SettingsService` as 1 new REQ. The code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Service/SettingsService.php::getConfigValue()`
- `lib/Service/SettingsService.php::setConfigValue()`

## Approach

- Both methods are a paired key/value accessor on top of `IAppConfig` scoped to the Pipelinq `APP_ID`
- Used internally by `SettingsService` itself and externally by `ProspectDiscoveryService` to read register/schema IDs without re-implementing the IAppConfig boilerplate
- A single REQ covers the pair — splitting `get` and `set` into two REQs would inflate without test surface

Source: `openspec/coverage-report.md` generated 2026-05-24. See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
