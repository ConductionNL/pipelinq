# Delta Spec: entity-notes inline editing and cleanup hook

## Newly Implemented

- **Backend cleanup hook via OpenRegister event**: `ObjectDeletedListener` registered for `ObjectDeletedEvent`. Maps schema slugs (client, contact, lead, request) to Pipelinq note object types and calls `NotesService::deleteAllNotes()`. Prevents orphaned comments when entities are deleted via OpenRegister.
- **Note Inline Editing**: Users can edit their own notes inline. "Edit" button shown on own notes opens a textarea with current message. "Save" persists via `PUT /api/notes/single/{noteId}`. "Cancel" reverts without API call. Ownership enforced server-side.
- **Note Update Backend**: `NotesService::updateNote()` fetches comment, verifies author, updates message, saves. `NotesController::update()` validates non-empty message and returns updated note.
- **Keyboard shortcut**: Ctrl+Enter (Cmd+Enter on macOS) submits notes from the textarea.
