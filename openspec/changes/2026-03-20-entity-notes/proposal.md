# Proposal: entity-notes inline editing and cleanup hook

## Problem

The entity-notes spec identifies MVP and V1 gaps:
1. No backend cleanup hook — entity deletion via OpenRegister does not trigger `deleteAllNotes()`, leaving orphaned comments
2. No inline editing — users can only create and delete notes, not edit them
3. No keyboard shortcut — Ctrl+Enter does not submit notes
4. No update endpoint — backend has no PUT/PATCH route for note editing

## Proposed Change

1. Add an `ObjectDeletedListener` that calls `NotesService::deleteAllNotes()` when an OpenRegister object with a Pipelinq entity type is deleted
2. Add a `NotesController::update()` endpoint for editing note messages
3. Add `NotesService::updateNote()` method with ownership verification
4. Extend `EntityNotes.vue` with inline editing (edit/save/cancel) and Ctrl+Enter keyboard shortcut
5. Register the update route in `routes.php`

### Out of Scope
- Note types (call log, email log, meeting note) — V1
- Rich text / Markdown rendering — V1
- @mention users — V1
- Note search, file attachments, pinning, templates — V1/Enterprise
- Note visibility (private vs shared) — V1
- Note export — Enterprise

## Impact
- **Files modified**: 5 (NotesService.php, NotesController.php, EntityNotes.vue, routes.php, Application.php)
- **Files created**: 1 (ObjectDeletedListener.php)
- **Risk**: Low — additive changes only
