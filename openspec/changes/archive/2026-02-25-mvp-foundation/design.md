# Design: mvp-foundation

## Architecture Overview

This change touches three layers:

1. **Data layer** — New `pipelinq_register.json` defining the register + 5 schemas in OpenAPI 3.0.0 format
2. **Backend layer** — Simplified repair step using `ConfigurationService::importFromApp()`, admin settings PHP class
3. **Frontend layer** — Extended store initialization for lead/pipeline types, admin settings Vue component

```
pipelinq_register.json (OpenAPI 3.0.0)
    ↓ imported by
ConfigurationService::importFromApp('pipelinq')
    ↓ called from
lib/Repair/InitializeSettings.php (repair step)
    ↓ stores IDs in
IAppConfig (register, *_schema keys)
    ↓ read by
SettingsController → GET /api/settings
    ↓ consumed by
src/store/store.js → initializeStores() → objectStore.registerObjectType()
```

## Key Design Decisions

### 1. Register JSON Format (OpenAPI 3.0.0)

**Decision**: Use the `components.registers` + `components.schemas` structure from the publication_register.json pattern.

**Rationale**: This is the established pattern across opencatalogi, softwarecatalog, and docudesk. The `ConfigurationService::importFromApp()` method looks for `lib/Settings/*_register.json` files and parses this exact structure.

**Structure**:
```json
{
  "openapi": "3.0.0",
  "info": { "title": "...", "version": "..." },
  "x-openregister": { "type": "application", "app": "pipelinq" },
  "components": {
    "registers": {
      "pipelinq": { "slug": "pipelinq", "schemas": [...] }
    },
    "schemas": {
      "client": { "slug": "client", "required": [...], "properties": {...} },
      "contact": { ... },
      "lead": { ... },
      "request": { ... },
      "pipeline": { ... }
    }
  }
}
```

### 2. Repair Step Simplification

**Decision**: Replace the 160-line inline PHP repair step with a ~40-line version that delegates to `ConfigurationService::importFromApp('pipelinq')`.

**Rationale**: The current repair step manually creates registers and schemas via service calls. This is fragile (changes require PHP edits) and inconsistent with other apps. The JSON config pattern is the standard approach.

**Change**: The repair step will:
1. Check if OpenRegister is available
2. Call `ConfigurationService::importFromApp('pipelinq')`
3. Store the resulting register and schema IDs in IAppConfig
4. Log success/failure

**Backward compatibility**: The new repair step will detect existing registers by slug and update rather than duplicate.

### 3. Store Registration for Lead and Pipeline

**Decision**: Extend `initializeStores()` to register `lead` and `pipeline` alongside existing types.

**Rationale**: The generic `useObjectStore` already supports dynamic type registration. We just need to add the new types and ensure the backend settings endpoint returns their schema IDs.

**No new stores needed**: The existing `useObjectStore` with its `registerObjectType()` pattern is sufficient. Specialized stores (e.g., for pipeline kanban logic) will be added in later changes.

### 4. Admin Settings Page

**Decision**: Use Nextcloud's `ISettings` interface with a Vue-rendered admin panel.

**Components**:
- `lib/Sections/PipelinqSection.php` — Registers the settings section under "Administration"
- `lib/Settings/PipelinqAdmin.php` — Renders the admin template with initial state
- `src/settings.js` — Admin settings Vue entry point (separate webpack entry)
- `src/views/admin/AdminSettings.vue` — Shows register status, schema list, re-import button

**Pattern**: Same as used by opencatalogi and softwarecatalog admin settings.

### 5. Settings Endpoint Extension

**Decision**: Extend `SettingsController` to return lead_schema and pipeline_schema IDs alongside existing ones.

**Change**: The `index()` action already returns all IAppConfig keys. The repair step will store `lead_schema` and `pipeline_schema` keys, and the frontend will pick them up automatically.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `lib/Settings/pipelinq_register.json` | CREATE | OpenAPI 3.0.0 register config with 5 schemas |
| `lib/Repair/InitializeSettings.php` | REWRITE | Simplify to use ConfigurationService |
| `src/store/store.js` | MODIFY | Register lead + pipeline types |
| `lib/Sections/PipelinqSection.php` | CREATE | Admin settings section registration |
| `lib/Settings/PipelinqAdmin.php` | CREATE | Admin settings page renderer |
| `templates/admin.php` | CREATE | Admin settings template |
| `src/settings.js` | MODIFY | Admin Vue entry point |
| `src/views/admin/AdminSettings.vue` | CREATE | Admin settings Vue component |
| `appinfo/info.xml` | MODIFY | Add admin settings section reference |
| `webpack.config.js` | MODIFY | Add settings entry point if needed |

## Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| ConfigurationService API changes | Low | Pin to OpenRegister v0.2.x, test in Docker |
| Existing data migration | Low | importFromApp detects existing registers by slug |
| Schema ID mismatch after re-import | Medium | Always read IDs from IAppConfig, not hardcoded |
