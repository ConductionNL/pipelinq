# Design: entity-notes

## Architecture

### Backend

#### NotesService (`lib/Service/NotesService.php`)

Wraps `ICommentsManager` for Pipelinq entity notes.

- **Constructor**: Inject `ICommentsManager`, `IUserSession`, `LoggerInterface`
- **`getNotes(string $objectType, string $objectId): array`** — Fetch all comments for the given object. Returns array of `[id, message, authorId, authorName, timestamp]`. Uses `ICommentsManager::getForObject()`. Resolves author display names via `IUserManager`.
- **`addNote(string $objectType, string $objectId, string $message): array`** — Create comment via `ICommentsManager::create('users', $userId, $objectType, $objectId)`, set message, save. Return the created note.
- **`deleteNote(int $noteId): void`** — Fetch comment, verify current user is the author, then delete via `ICommentsManager::delete()`.
- **`deleteAllNotes(string $objectType, string $objectId): void`** — Call `ICommentsManager::deleteCommentsAtObject()`. Used when entities are deleted.

Valid object types: `pipelinq_client`, `pipelinq_contact`, `pipelinq_lead`, `pipelinq_request`.

#### NotesController (`lib/Controller/NotesController.php`)

REST API endpoints. All `@NoAdminRequired`.

| Method | URL | Action |
|--------|-----|--------|
| GET | `/api/notes/{objectType}/{objectId}` | List notes |
| POST | `/api/notes/{objectType}/{objectId}` | Create note |
| DELETE | `/api/notes/{noteId}` | Delete note |

- Validate `objectType` is one of the 4 valid types
- POST body: `{ "message": "..." }`
- Returns JSON arrays/objects

#### Comment Type Registration (`Application.php`)

In `boot()`, register display name resolvers for the 4 Pipelinq object types so Nextcloud's comment system recognizes them.

```php
$commentsManager->registerDisplayNameResolver('pipelinq_client', function($id) { return 'Client'; });
// ... repeat for contact, lead, request
```

### Frontend

#### EntityNotes.vue (`src/components/EntityNotes.vue`)

Reusable component embedded in all 4 detail views.

**Props**:
- `objectType` (string, required) — e.g. `pipelinq_client`
- `objectId` (string, required) — entity UUID

**Data**:
- `notes: []` — fetched notes array
- `newMessage: ''` — input binding
- `loading: false` — loading state
- `submitting: false` — submit state

**Methods**:
- `fetchNotes()` — GET `/api/notes/{objectType}/{objectId}`
- `addNote()` — POST with message, then re-fetch
- `deleteNote(noteId)` — DELETE, then re-fetch

**Template structure**:
```
<div class="entity-notes">
  <h3>Notes</h3>
  <div class="entity-notes__input">
    <textarea v-model="newMessage" />
    <NcButton @click="addNote">Add note</NcButton>
  </div>
  <div v-if="notes.length === 0" class="entity-notes__empty">No notes yet</div>
  <div v-for="note in notes" class="entity-notes__item">
    <span class="note-author">{{ note.authorName }}</span>
    <span class="note-time">{{ formatTime(note.timestamp) }}</span>
    <p>{{ note.message }}</p>
    <NcButton v-if="note.isOwn" @click="deleteNote(note.id)">Delete</NcButton>
  </div>
</div>
```

#### Detail View Integration

Add `<EntityNotes>` to each detail view, after the existing sections, in the non-editing, non-new, non-loading state:

```vue
<EntityNotes
  v-if="!isNew && !loading && !editing"
  :object-type="'pipelinq_client'"
  :object-id="clientId" />
```

Repeat for contact (`pipelinq_contact`), lead (`pipelinq_lead`), request (`pipelinq_request`).

### Entity Deletion Cleanup

In each detail view's `confirmDelete()` method, call the delete-all endpoint before or after deleting the entity itself. Alternatively, add cleanup in the backend ObjectService layer. Since Pipelinq uses OpenRegister's ObjectService for deletion, the simplest approach is to call cleanup from the frontend's delete handler (fire-and-forget DELETE to `/api/notes/{objectType}/{objectId}/all`), or add a dedicated cleanup route.

**Chosen approach**: Add a `deleteAll` action on NotesController (`DELETE /api/notes/{objectType}/{objectId}`) that calls `deleteCommentsAtObject()`. The frontend calls this before entity deletion.

Updated routes:

| Method | URL | Action |
|--------|-----|--------|
| GET | `/api/notes/{objectType}/{objectId}` | List notes |
| POST | `/api/notes/{objectType}/{objectId}` | Create note |
| DELETE | `/api/notes/{objectType}/{objectId}` | Delete ALL notes for entity |
| DELETE | `/api/notes/single/{noteId}` | Delete single note |

## Files Changed

- `lib/Service/NotesService.php` (new)
- `lib/Controller/NotesController.php` (new)
- `lib/AppInfo/Application.php` (modified — register comment types)
- `appinfo/routes.php` (modified — add 4 routes)
- `src/components/EntityNotes.vue` (new)
- `src/views/clients/ClientDetail.vue` (modified — add EntityNotes)
- `src/views/contacts/ContactDetail.vue` (modified — add EntityNotes)
- `src/views/leads/LeadDetail.vue` (modified — add EntityNotes)
- `src/views/requests/RequestDetail.vue` (modified — add EntityNotes)
