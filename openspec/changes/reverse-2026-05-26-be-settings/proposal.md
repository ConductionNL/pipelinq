# Reverse-spec — Settings and configuration services

Retroactively specifies the observed behavior of 17 method(s) implementing user settings, preferences and configuration loading. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `lib/Controller/PreferencesController.php::getPreference`
- `lib/Controller/PreferencesController.php::setPreference`
- `lib/Controller/ProspectSettingsController.php::getConfigurationService`
- `lib/Controller/ProspectSettingsController.php::getObjectService`
- `lib/Controller/SettingsController.php::getConfigurationService`
- `lib/Controller/SettingsController.php::getObjectService`
- `lib/Controller/SettingsController.php::getUserSettings`
- `lib/Controller/SettingsController.php::updateUserSettings`
- `lib/Service/ConfigFileLoaderService.php::ensureSourceType`
- `lib/Service/ConfigFileLoaderService.php::loadConfigurationFile`
- `lib/Service/SchemaMapService.php::resolveEntityType`
- `lib/Service/SettingsMapBuilder.php::buildSchemaSlugMap`
- `lib/Service/SettingsMapBuilder.php::findDefaultViewId`
- `lib/Service/SettingsMapBuilder.php::findRegisterIdBySlug`
- `lib/Service/SettingsService.php::getUserSettings`
- `lib/Service/SettingsService.php::loadSettings`
- `lib/Service/SettingsService.php::updateUserSettings`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
