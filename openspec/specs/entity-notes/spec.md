# Entity Notes Specification

## Purpose

Add internal notes/comments to all Pipelinq entities (clients, contacts, leads, requests) using Nextcloud's ICommentsManager, enabling team collaboration and context tracking.

## Requirements

### Requirement: Notes CRUD [MVP]

Users MUST be able to create, view, and delete notes on any Pipelinq entity.

#### Scenario: Add a note to an entity
- GIVEN the user is viewing a client, contact, lead, or request detail page
- WHEN the user types a message in the notes input and submits
- THEN a new note MUST be created via ICommentsManager
- AND the note MUST appear in the notes list with the user's name and timestamp
- AND the notes input MUST be cleared

#### Scenario: View notes on an entity
- GIVEN an entity has one or more notes
- WHEN the user views the entity detail page
- THEN all notes MUST be displayed in reverse chronological order (newest first)
- AND each note MUST show: author name, timestamp, message text

#### Scenario: Delete own note
- GIVEN the user is viewing an entity with notes they authored
- WHEN the user clicks delete on their own note
- THEN the note MUST be removed from ICommentsManager
- AND the note MUST disappear from the list
- AND notes authored by other users MUST NOT show a delete button

#### Scenario: Empty notes state
- GIVEN an entity has no notes
- WHEN the user views the entity detail page
- THEN a "No notes yet" message MUST be displayed
- AND the notes input MUST still be available

### Requirement: Notes on All Entity Types [MVP]

The notes component MUST work identically on all four entity types.

#### Scenario: Notes on clients
- GIVEN a client detail view
- THEN a notes section MUST be present using object type `pipelinq_client`

#### Scenario: Notes on contacts
- GIVEN a contact detail view
- THEN a notes section MUST be present using object type `pipelinq_contact`

#### Scenario: Notes on leads
- GIVEN a lead detail view
- THEN a notes section MUST be present using object type `pipelinq_lead`

#### Scenario: Notes on requests
- GIVEN a request detail view
- THEN a notes section MUST be present using object type `pipelinq_request`

### Requirement: Comment Cleanup [MVP]

When a Pipelinq entity is deleted, its associated comments MUST be cleaned up.

#### Scenario: Delete entity removes its notes
- GIVEN an entity with notes exists
- WHEN the entity is deleted via the Pipelinq UI
- THEN all associated comments MUST be removed from ICommentsManager via `deleteCommentsAtObject()`

---

### Current Implementation Status

**Fully implemented.** All MVP requirements are complete and functional.

Implemented:
- **NotesService**: `lib/Service/NotesService.php` -- full CRUD using `ICommentsManager`:
  - `getNotes($objectType, $objectId)` -- returns notes in reverse chronological order with author name, timestamp, `isOwn` flag.
  - `addNote($objectType, $objectId, $message)` -- creates a comment via `ICommentsManager::create()` with verb `comment`.
  - `deleteNote($noteId)` -- deletes a single note, enforcing author-only deletion (`$comment->getActorId() !== $userId`).
  - `deleteAllNotes($objectType, $objectId)` -- removes all comments via `deleteCommentsAtObject()`.
  - Valid types constant: `VALID_TYPES = ['pipelinq_client', 'pipelinq_contact', 'pipelinq_lead', 'pipelinq_request']`.
- **NotesController**: `lib/Controller/NotesController.php` -- REST endpoints:
  - `GET /api/notes/{objectType}/{objectId}` -- list notes (validates object type).
  - `POST /api/notes/{objectType}/{objectId}` -- create note (validates message non-empty, triggers note events).
  - `DELETE /api/notes/{objectType}/{objectId}` -- delete all notes for an entity.
  - `DELETE /api/notes/single/{noteId}` -- delete a single note (own notes only, returns 403 on permission error).
- **NoteEventService**: `lib/Service/NoteEventService.php` -- triggers activity and notification events when a note is added.
- **EntityNotes Vue component**: `src/components/EntityNotes.vue` -- reusable component with:
  - Textarea input with "Add note" button (disabled when empty or submitting).
  - Notes list in reverse chronological order showing author name, relative timestamp ("Just now", "X minutes ago", etc.), and message.
  - Delete button visible only on own notes.
  - Loading state via `NcLoadingIcon`.
  - "No notes yet" empty state.
  - Watches `objectId` prop to re-fetch notes when entity changes.
- **Integration with detail views**: The `CnDetailPage` component (from `@conduction/nextcloud-vue`) is used by `ClientDetail.vue`, `LeadDetail.vue`, `ContactDetail.vue`, and `RequestDetail.vue` with `object-type` prop set to the appropriate type (e.g., `pipelinq_client`, `pipelinq_lead`). The `EntityNotes` component is available for embedding.
- **Activity integration**: `ActivityService::publishNoteAdded()` publishes note events to the Nextcloud activity stream.

NOT implemented:
- Entity deletion does not automatically trigger `deleteAllNotes()` from the frontend -- when a client/lead/request is deleted via the object store, the notes cleanup endpoint is not called. This means orphaned comments may remain in ICommentsManager.
- No inline editing of existing notes (only create and delete).

### Standards & References
- Nextcloud Comments API (`OCP\Comments\ICommentsManager`) -- used for all CRUD operations
- Nextcloud Activity API -- note events are published for visibility in the activity stream

### Specificity Assessment
- The spec is concise, specific, and fully implementable. All scenarios are covered by the current implementation.
- **Minor gap**: The spec does not address inline editing of notes (only create/delete). Should note editing be supported?
- **Minor gap**: The spec does not specify maximum note length. The current implementation trims whitespace but does not enforce length limits.
- **Open question**: Should the automatic cleanup on entity deletion be implemented as a backend hook (OpenRegister event listener) rather than relying on the frontend to call the cleanup endpoint?
