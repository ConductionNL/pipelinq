# Design: entity-notes inline editing and cleanup hook

## Backend Cleanup Hook

Register an event listener for `OCA\OpenRegister\Events\ObjectDeletedEvent` in `Application::register()`. The listener maps the deleted object's schema slug to a Pipelinq note object type (e.g., `client` -> `pipelinq_client`) and calls `NotesService::deleteAllNotes()`.

## Note Update Endpoint

Add `PUT /api/notes/single/{noteId}` mapped to `NotesController::update()`. The method:
1. Reads `message` from the request body
2. Validates the message is non-empty
3. Calls `NotesService::updateNote($noteId, $message)` which:
   - Fetches the comment via `ICommentsManager::get()`
   - Verifies the current user is the author (`actorId === currentUserId`)
   - Updates the message via `$comment->setMessage(trim($message))`
   - Saves via `ICommentsManager::save()`
   - Returns the updated note data

## Inline Editing in EntityNotes.vue

- Each note gets an "Edit" button (visible only when `note.isOwn`)
- Clicking "Edit" sets `editingNoteId` and `editMessage` state
- The note message is replaced with a textarea pre-filled with the current message
- "Save" and "Cancel" buttons appear
- Save calls `PUT /api/notes/single/{noteId}` with the new message
- Cancel resets state without API call
- After save, the note list is refreshed

## Keyboard Shortcut

Add `@keydown` handler on the textarea that submits on Ctrl+Enter (or Meta+Enter for macOS).
