# Tasks: entity-notes inline editing and cleanup hook

## 1. Backend cleanup hook for entity deletion
- [ ] 1.1 Create `ObjectDeletedListener` that cleans up notes on entity deletion
  - **spec_ref**: `specs/entity-notes/spec.md#Backend cleanup hook via OpenRegister event`
  - **files**: `pipelinq/lib/Listener/ObjectDeletedListener.php`
- [ ] 1.2 Register the event listener in Application.php
  - **spec_ref**: `specs/entity-notes/spec.md#Backend cleanup hook via OpenRegister event`
  - **files**: `pipelinq/lib/AppInfo/Application.php`

## 2. Note update (inline editing) backend
- [ ] 2.1 Add `updateNote()` method to NotesService with ownership verification
  - **spec_ref**: `specs/entity-notes/spec.md#Save edited note`
  - **files**: `pipelinq/lib/Service/NotesService.php`
- [ ] 2.2 Add `update()` action to NotesController
  - **spec_ref**: `specs/entity-notes/spec.md#Edit own note`
  - **files**: `pipelinq/lib/Controller/NotesController.php`
- [ ] 2.3 Register PUT route for note update
  - **spec_ref**: `specs/entity-notes/spec.md#Edit own note`
  - **files**: `pipelinq/appinfo/routes.php`

## 3. Inline editing and keyboard shortcut in frontend
- [ ] 3.1 Add inline editing UI (edit/save/cancel) and Ctrl+Enter shortcut to EntityNotes.vue
  - **spec_ref**: `specs/entity-notes/spec.md#Edit own note`, `specs/entity-notes/spec.md#Keyboard shortcut for submission`
  - **files**: `pipelinq/src/components/EntityNotes.vue`
