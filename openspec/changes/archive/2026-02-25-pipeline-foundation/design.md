# Design: pipeline-foundation

## Architecture Overview

This change spans schema definition, PHP backend (repair step), and Vue frontend (admin settings UI).

```
1. Schema Update
   pipelinq_register.json
     └── pipeline.properties.stages.items.properties += isClosed, isWon, color

2. Default Pipeline Creation (PHP)
   SettingsService.php (or new method)
     └── After loadSettings(), check if default pipelines exist
     └── If not, create via OpenRegister API
     └── objectStore.saveObject('pipeline', salesPipelineData)

3. Admin Settings UI (Vue)
   Settings.vue
     └── <PipelineManager /> (new component)
           ├── Lists all pipelines with key info
           ├── <PipelineForm /> (new component)
           │     └── Create/edit pipeline properties
           │     └── Inline stage list management
           └── Delete pipeline with confirmation
```

## Key Design Decisions

### 1. Stages Embedded in Pipeline Object

**Decision**: Keep stages as an embedded array in the pipeline object (matching the existing register schema), not as separate OpenRegister objects.

**Rationale**: The `pipelinq_register.json` already defines `stages` as an array of objects within the pipeline schema. This is simpler — one API call to save a pipeline with all its stages. The spec's separate Stage CRUD (REQ-PIPE-004) is implemented as array manipulation within the pipeline object.

### 2. Default Pipeline Creation in Frontend

**Decision**: Create default pipelines from the Vue admin settings page (via the object store), triggered when the pipeline list is empty and the user clicks a "Create defaults" button — OR create them in the PHP repair step after schema import.

**Chosen approach**: PHP repair step. After `loadSettings()` imports the schemas, check if any pipelines exist. If not, create the defaults via OpenRegister's ObjectService. This ensures pipelines exist on first install without requiring admin to visit settings.

**Implementation**: Add a `createDefaultPipelines()` method to `SettingsService.php`. Call it from `loadSettings()` after successful schema import. The method uses OpenRegister's ObjectService to create pipeline objects.

### 3. Admin Settings Integration

**Decision**: Add a `PipelineManager.vue` component to the existing `Settings.vue` page, below the register status section.

**Rationale**: Pipeline management is admin configuration, not a daily-use feature. Keeping it in the existing settings page is consistent and requires no new routes.

### 4. Pipeline Form with Inline Stage Editor

**Decision**: The pipeline create/edit form includes an inline stage list where admins can add, edit, and remove stages before saving the entire pipeline.

**Rationale**: Since stages are embedded in the pipeline object, they should be edited together. This avoids multiple API calls and gives the admin a clear picture of the full pipeline configuration.

### 5. Stage Reordering

**Decision**: For MVP, stages are reordered by editing the order number or using move up/down buttons. Drag-and-drop is out of scope (V1).

**Rationale**: Drag-and-drop requires additional dependencies (e.g., vuedraggable). Move up/down buttons achieve the same result with simpler implementation.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `lib/Settings/pipelinq_register.json` | MODIFY | Add `isClosed`, `isWon`, `color` to stage item properties |
| `lib/Service/SettingsService.php` | MODIFY | Add `createDefaultPipelines()` method, call from `loadSettings()` |
| `src/views/settings/PipelineManager.vue` | CREATE | Pipeline list + create/delete in admin settings |
| `src/views/settings/PipelineForm.vue` | CREATE | Pipeline form with inline stage editor |
| `src/views/settings/Settings.vue` | MODIFY | Import and render PipelineManager component |
