---
status: implemented
---

# Entity Notes Specification

## Purpose

Add internal notes/comments to all Pipelinq entities (clients, contacts, leads, requests) using Nextcloud's ICommentsManager, enabling team collaboration and context tracking. Notes serve as the primary collaboration mechanism for CRM users, supporting categorized note types, rich text, @mentions, attachments, and privacy controls to match the workflows of government KCC and commercial sales teams.

**Standards**: Nextcloud Comments API (`OCP\Comments\ICommentsManager`), Nextcloud Activity API, Nextcloud Notifications API, OpenRegister Object Interactions pattern
**Cross-references**: [OpenRegister object-interactions](../../../openregister/openspec/specs/object-interactions/spec.md), [activity-timeline](../activity-timeline/spec.md)

---

## Requirements

### Requirement: Notes CRUD on All Entity Types [MVP]

Users MUST be able to create, view, and delete notes on any Pipelinq entity (client, contact, lead, request). Notes MUST be stored via `ICommentsManager` with entity-specific object types and displayed in reverse chronological order.

#### Scenario: Add a note to an entity
- **GIVEN** the user is viewing a client, contact, lead, or request detail page
- **WHEN** the user types a message in the notes input and clicks "Add note"
- **THEN** a new comment MUST be created via `ICommentsManager::create()` with `actorType: "users"`, `actorId` set to the current user, `objectType` set to the entity type (e.g., `pipelinq_client`), and `objectId` set to the entity ID
- **AND** the comment verb MUST be set to `comment`
- **AND** the message MUST be trimmed of leading/trailing whitespace before storage
- **AND** the note MUST appear at the top of the notes list with the user's display name and timestamp
- **AND** the notes input MUST be cleared

#### Scenario: View notes on an entity
- **GIVEN** an entity has one or more notes
- **WHEN** the user views the entity detail page
- **THEN** all notes MUST be displayed in reverse chronological order (newest first)
- **AND** each note MUST show: author display name (resolved via `IUserManager`), relative timestamp (e.g., "Just now", "5 minutes ago", "2 hours ago"), and message text
- **AND** each note MUST include an `isOwn` flag indicating whether the current user authored it

#### Scenario: Delete own note
- **GIVEN** the user is viewing an entity with notes they authored
- **WHEN** the user clicks delete on their own note
- **THEN** the backend MUST verify `$comment->getActorId() === $currentUserId` before deletion
- **AND** the note MUST be removed via `ICommentsManager::delete()`
- **AND** the note MUST disappear from the list without a full page reload
- **AND** notes authored by other users MUST NOT show a delete button

#### Scenario: Empty notes state
- **GIVEN** an entity has no notes
- **WHEN** the user views the entity detail page
- **THEN** a "No notes yet" message MUST be displayed
- **AND** the notes input MUST still be available for creating the first note

#### Scenario: Notes on all four entity types
- **GIVEN** the `EntityNotes` Vue component receives an `objectType` prop
- **THEN** it MUST work with `pipelinq_client`, `pipelinq_contact`, `pipelinq_lead`, and `pipelinq_request`
- **AND** the backend `NotesService::VALID_TYPES` constant MUST include all four types
- **AND** requests with any other object type MUST be rejected with HTTP 400

#### Scenario: Submit button disabled for empty messages
- **GIVEN** the notes input textarea is empty or contains only whitespace
- **WHEN** the user views the "Add note" button
- **THEN** the button MUST be disabled
- **AND** the button MUST also be disabled while a submission is in progress (showing "Saving..." text)

### Requirement: Note Types and Categorization [V1]

Notes MUST support categorization by type to distinguish between different interaction modalities. Each note type MUST use a distinct `verb` value on the ICommentsManager comment.

#### Scenario: Create a general comment note
- **GIVEN** the user is adding a note to an entity
- **WHEN** the user selects the "Comment" type (or leaves the default)
- **THEN** the comment MUST be created with `verb: "comment"`
- **AND** the note MUST display with a comment icon in the timeline

#### Scenario: Create a call log note
- **GIVEN** the user had a phone conversation with a client
- **WHEN** the user selects "Call log" as the note type and enters details (duration, outcome, summary)
- **THEN** the comment MUST be created with `verb: "call_log"`
- **AND** the note MUST display with a phone icon and show call metadata (duration, outcome) in a structured format above the note body

#### Scenario: Create an email log note
- **GIVEN** the user wants to log an email interaction
- **WHEN** the user selects "Email log" as the note type and enters the subject and summary
- **THEN** the comment MUST be created with `verb: "email_log"`
- **AND** the note MUST display with an email icon and show the email subject as a header

#### Scenario: Create a meeting note
- **GIVEN** the user attended a meeting regarding a client or lead
- **WHEN** the user selects "Meeting note" and enters attendees and discussion points
- **THEN** the comment MUST be created with `verb: "meeting_note"`
- **AND** the note MUST display with a calendar icon and list attendees

#### Scenario: Filter notes by type
- **GIVEN** an entity has notes of multiple types (comment, call_log, email_log, meeting_note)
- **WHEN** the user selects a type filter from a dropdown above the notes list
- **THEN** only notes matching the selected type MUST be shown
- **AND** a "All types" option MUST show all notes regardless of type

### Requirement: Note Creation UI with Inline Editor [V1]

The note input MUST provide an inline editing experience that supports structured input for different note types without requiring a modal dialog.

#### Scenario: Default inline textarea
- **GIVEN** no note type is selected or "Comment" is the active type
- **WHEN** the user sees the notes input area
- **THEN** a multi-line textarea MUST be shown with placeholder "Add a note..."
- **AND** the textarea MUST support at least 3 visible rows with vertical resize

#### Scenario: Call log inline form
- **GIVEN** the user selects "Call log" as the note type
- **WHEN** the input area updates
- **THEN** additional fields MUST appear: "Duration" (minutes, numeric input), "Outcome" (dropdown: connected, voicemail, no answer, busy), and "Summary" (textarea)
- **AND** the summary field MUST be required

#### Scenario: Meeting note inline form
- **GIVEN** the user selects "Meeting note" as the note type
- **WHEN** the input area updates
- **THEN** additional fields MUST appear: "Attendees" (comma-separated text input) and "Discussion points" (textarea)

#### Scenario: Keyboard shortcut for submission
- **GIVEN** the user is typing in the notes textarea
- **WHEN** the user presses Ctrl+Enter (or Cmd+Enter on macOS)
- **THEN** the note MUST be submitted (equivalent to clicking "Add note")
- **AND** this MUST NOT submit if the message is empty or a required field is missing

### Requirement: Rich Text Formatting [V1]

Notes MUST support basic rich text formatting using Markdown syntax, rendered in the note display but stored as plain Markdown in ICommentsManager.

#### Scenario: Markdown rendering in note display
- **GIVEN** a note contains Markdown syntax (e.g., `**bold**`, `_italic_`, `- list item`, `[link](url)`)
- **WHEN** the note is displayed in the timeline
- **THEN** the Markdown MUST be rendered as formatted HTML (bold, italic, lists, links)
- **AND** the raw Markdown MUST be stored in `ICommentsManager` as the comment message

#### Scenario: Code and preformatted text
- **GIVEN** a note contains backtick-enclosed text or triple-backtick code blocks
- **WHEN** the note is displayed
- **THEN** the text MUST be rendered in a monospace font with a subtle background color

#### Scenario: Link auto-detection
- **GIVEN** a note contains a bare URL (e.g., `https://example.com`)
- **WHEN** the note is displayed
- **THEN** the URL MUST be rendered as a clickable link that opens in a new tab

#### Scenario: XSS prevention in rendered notes
- **GIVEN** a note contains HTML tags (e.g., `<script>`, `<img onerror>`)
- **WHEN** the Markdown is rendered
- **THEN** all HTML MUST be sanitized to prevent cross-site scripting attacks
- **AND** only safe Markdown-generated HTML elements MUST be allowed

### Requirement: Note Timeline Display [MVP]

Notes MUST be displayed in a timeline format that provides clear chronological context and visual differentiation between note authors.

#### Scenario: Relative timestamp display
- **GIVEN** a note was created at various times in the past
- **WHEN** the note is displayed
- **THEN** the timestamp MUST use relative formatting:
  - Less than 1 minute ago: "Just now"
  - Less than 1 hour ago: "X minutes ago" (pluralized via `n()`)
  - Less than 24 hours ago: "X hours ago" (pluralized via `n()`)
  - Older: full date with time (e.g., "Mar 15, 2026, 14:30") using the user's locale

#### Scenario: Author differentiation
- **GIVEN** notes from multiple users exist on an entity
- **WHEN** the notes list is displayed
- **THEN** each note MUST show the author's Nextcloud display name in bold
- **AND** the current user's own notes MUST show a delete button (tertiary NcButton)

#### Scenario: Note list performance with many notes
- **GIVEN** an entity has more than 50 notes
- **WHEN** the notes are loaded
- **THEN** the API MUST return at most 200 notes (the current `limit` parameter)
- **AND** the frontend MUST handle the full list without performance degradation (virtual scrolling SHOULD be used for lists exceeding 100 notes)

### Requirement: @Mention Users in Notes [V1]

Users MUST be able to mention other Nextcloud users in notes using `@username` syntax, triggering notifications to the mentioned users.

#### Scenario: Mention autocomplete
- **GIVEN** the user is typing a note and enters `@` followed by characters
- **WHEN** at least 2 characters have been typed after `@`
- **THEN** an autocomplete dropdown MUST appear showing matching Nextcloud users (by display name or user ID)
- **AND** the dropdown MUST query the Nextcloud user list API with the typed prefix
- **AND** selecting a user from the dropdown MUST insert their display name as `@DisplayName`

#### Scenario: Mention notification
- **GIVEN** a note containing `@DisplayName` is submitted
- **WHEN** the note is saved
- **THEN** the backend MUST parse the message for @mentions
- **AND** each mentioned user MUST receive a Nextcloud notification via `NotificationService` with the note content preview and a link to the entity

#### Scenario: Mention rendered as distinct element
- **GIVEN** a saved note contains `@DisplayName`
- **WHEN** the note is displayed
- **THEN** the mention MUST be rendered as a styled chip or badge (with distinct background color)
- **AND** clicking the mention SHOULD navigate to the mentioned user's profile (if accessible)

#### Scenario: Self-mention does not notify
- **GIVEN** the user mentions themselves in a note (`@OwnName`)
- **WHEN** the note is saved
- **THEN** no notification MUST be sent to the authoring user for the self-mention

### Requirement: Note Search [V1]

Users MUST be able to search through notes across all entities within Pipelinq to find specific information quickly.

#### Scenario: Search notes by text content
- **GIVEN** the user navigates to a notes search interface or uses the global search
- **WHEN** the user enters a search query (e.g., "budget approval")
- **THEN** all notes containing the query text MUST be returned
- **AND** each result MUST show: the note snippet with the query highlighted, the entity type and name the note belongs to, the author, and the timestamp

#### Scenario: Search notes within a single entity
- **GIVEN** the user is viewing an entity's notes list with many notes
- **WHEN** the user types in a search/filter field above the notes list
- **THEN** the notes list MUST be filtered client-side to show only notes containing the search text
- **AND** clearing the search MUST restore the full notes list

#### Scenario: No results found
- **GIVEN** a search query that matches no notes
- **WHEN** the search is executed
- **THEN** a "No notes matching your search" message MUST be displayed

### Requirement: Note File Attachments [V1]

Users MUST be able to attach files to individual notes, storing them via Nextcloud's filesystem and linking them to both the note and the parent entity.

#### Scenario: Attach a file to a note
- **GIVEN** the user is composing a new note
- **WHEN** the user clicks an attachment button (paperclip icon) and selects a file from their device or Nextcloud Files
- **THEN** the file MUST be uploaded to Nextcloud's filesystem in a folder structure: `Pipelinq/{entityType}/{entityId}/notes/`
- **AND** the note message MUST include a reference to the attached file
- **AND** the file MUST be displayed as a clickable attachment below the note text

#### Scenario: View note attachment
- **GIVEN** a note has one or more file attachments
- **WHEN** the user views the note in the timeline
- **THEN** each attachment MUST show: filename, file size, and a file type icon
- **AND** clicking the attachment MUST open the file in Nextcloud's file viewer or trigger a download

#### Scenario: Delete note with attachments
- **GIVEN** a note has attached files
- **WHEN** the user deletes the note
- **THEN** the note MUST be removed from ICommentsManager
- **AND** the attached files SHOULD remain in the Nextcloud filesystem (they are entity-level files, not ephemeral)

### Requirement: Note Pinning [V1]

Users MUST be able to pin important notes to the top of the notes list so that critical information is always visible regardless of chronological order.

#### Scenario: Pin a note
- **GIVEN** the user is viewing a note on an entity
- **WHEN** the user clicks the pin icon on the note
- **THEN** the note MUST be marked as pinned (stored as a custom property or metadata on the comment)
- **AND** the note MUST appear in a "Pinned" section at the top of the notes list, above the chronological timeline
- **AND** the pinned note MUST display a pin indicator icon

#### Scenario: Unpin a note
- **GIVEN** a note is currently pinned
- **WHEN** the user clicks the pin icon again
- **THEN** the note MUST be unpinned and returned to its chronological position in the timeline

#### Scenario: Multiple pinned notes
- **GIVEN** an entity has 3 pinned notes
- **WHEN** the notes list is displayed
- **THEN** all 3 pinned notes MUST appear in the "Pinned" section, ordered by pin timestamp (most recently pinned first)
- **AND** the remaining notes MUST appear below in reverse chronological order

### Requirement: Note Templates [Enterprise]

The system MUST provide predefined note templates for common CRM interaction patterns, reducing data entry time and ensuring consistent note quality.

#### Scenario: Select a note template
- **GIVEN** the user is composing a new note
- **WHEN** the user clicks a "Templates" button next to the note type selector
- **THEN** a dropdown MUST appear showing available templates (e.g., "Initial Contact", "Follow-up Call", "Complaint Resolution", "Proposal Discussion")
- **AND** selecting a template MUST populate the note textarea with the template text including placeholder markers (e.g., `[client name]`, `[issue description]`)

#### Scenario: Template content varies by entity type
- **GIVEN** the user is on a lead detail page
- **WHEN** the user opens the templates dropdown
- **THEN** lead-specific templates MUST be shown (e.g., "Qualification Call", "Demo Scheduled", "Proposal Sent")
- **AND** generic templates (applicable to all entity types) MUST also be shown

#### Scenario: Custom templates managed by admin
- **GIVEN** an admin user navigates to Pipelinq settings
- **WHEN** the admin opens the "Note Templates" configuration section
- **THEN** the admin MUST be able to create, edit, and delete note templates
- **AND** each template MUST have: name, entity type (or "all"), note type (comment/call_log/email_log/meeting_note), and template body

### Requirement: Note Visibility (Private vs Shared) [V1]

Users MUST be able to mark notes as private (visible only to the author) or shared (visible to all users with access to the entity).

#### Scenario: Create a private note
- **GIVEN** the user is composing a note
- **WHEN** the user toggles the "Private" checkbox before submitting
- **THEN** the note MUST be stored with a visibility indicator (e.g., via the comment verb `private_comment` or a metadata field)
- **AND** the note MUST only be visible to the author in the notes list
- **AND** the note MUST display a lock icon to indicate its private status

#### Scenario: Other users cannot see private notes
- **GIVEN** user A created a private note on a client entity
- **WHEN** user B views the same client's notes list
- **THEN** user A's private note MUST NOT appear in user B's list
- **AND** the API MUST filter private notes by checking `actorId` against the current user

#### Scenario: Default visibility is shared
- **GIVEN** the user is composing a note without toggling visibility
- **WHEN** the note is submitted
- **THEN** the note MUST be visible to all users with access to the entity (shared by default)

### Requirement: Note Activity in Nextcloud Timeline [MVP]

Note creation MUST be published to the Nextcloud Activity stream and trigger notifications for relevant users (e.g., the entity assignee).

#### Scenario: Note creation publishes activity
- **GIVEN** a user creates a note on a lead assigned to another user
- **WHEN** the note is saved and `NoteEventService::triggerNoteEvents()` is called
- **THEN** an activity event MUST be published via `ActivityService::publishNoteAdded()` with the entity type, title, and object ID
- **AND** the affected user (assignee) MUST see the activity in their Nextcloud activity stream

#### Scenario: Assignee receives notification
- **GIVEN** a lead has an `assignee` field set to user `sales-rep-1`
- **WHEN** another user creates a note on that lead
- **THEN** `sales-rep-1` MUST receive a Nextcloud notification via `NotificationService::notifyNoteAdded()` with the entity type, title, assignee user ID, object ID, and note author

#### Scenario: No notification for self-authored notes on own entities
- **GIVEN** user `sales-rep-1` is the assignee of a lead
- **WHEN** `sales-rep-1` creates a note on their own lead
- **THEN** a notification SHOULD NOT be sent to `sales-rep-1` (they are already aware of their own action)

#### Scenario: Note event failure is non-blocking
- **GIVEN** the `NoteEventService` encounters an exception while fetching entity data or publishing events
- **WHEN** a note is created
- **THEN** the exception MUST be caught and logged as a warning
- **AND** the note creation MUST still succeed (event failure does not rollback the note)

### Requirement: Nextcloud Comments API Integration [MVP]

The notes system MUST use Nextcloud's `ICommentsManager` as its storage backend, ensuring compatibility with Nextcloud's native comments ecosystem and future UI components.

#### Scenario: Object type registration for Pipelinq entities
- **GIVEN** Pipelinq uses object types `pipelinq_client`, `pipelinq_contact`, `pipelinq_lead`, and `pipelinq_request`
- **WHEN** notes are created or queried
- **THEN** only these four object types MUST be accepted by the `NotesService`
- **AND** the `NotesController` MUST validate the object type against `NotesService::VALID_TYPES` before any operation

#### Scenario: Comment cleanup on entity deletion
- **GIVEN** an entity with associated notes is deleted
- **WHEN** the deletion is processed
- **THEN** all comments MUST be removed via `ICommentsManager::deleteCommentsAtObject($objectType, $objectId)`
- **AND** orphaned comments MUST NOT remain in the database

#### Scenario: Backend cleanup hook via OpenRegister event
- **GIVEN** an entity is deleted via the OpenRegister ObjectService (not via the frontend)
- **WHEN** the `ObjectDeletedEvent` is dispatched
- **THEN** an event listener MUST call `NotesService::deleteAllNotes()` for the deleted entity
- **AND** this MUST prevent orphaned comments that currently occur when deletion bypasses the frontend cleanup call

### Requirement: Note Export [Enterprise]

Users MUST be able to export notes for an entity in standard formats for reporting, compliance, or handoff purposes.

#### Scenario: Export notes as PDF
- **GIVEN** an entity has multiple notes
- **WHEN** the user clicks "Export notes" and selects "PDF"
- **THEN** a PDF document MUST be generated containing all notes in chronological order
- **AND** each note MUST include: author name, timestamp, note type, and full message text
- **AND** the document MUST include a header with the entity name and type

#### Scenario: Export notes as CSV
- **GIVEN** an entity has multiple notes
- **WHEN** the user clicks "Export notes" and selects "CSV"
- **THEN** a CSV file MUST be generated with columns: Date, Author, Type, Message
- **AND** the CSV MUST use UTF-8 encoding with BOM for Excel compatibility

#### Scenario: Export respects visibility
- **GIVEN** an entity has both shared and private notes
- **WHEN** the user exports notes
- **THEN** only notes visible to the current user MUST be included in the export
- **AND** private notes from other users MUST NOT appear in the export

### Requirement: Note Inline Editing [V1]

Users MUST be able to edit their own previously submitted notes without having to delete and recreate them.

#### Scenario: Edit own note
- **GIVEN** the user is viewing a note they authored
- **WHEN** the user clicks an "Edit" button on the note
- **THEN** the note message MUST become an editable textarea pre-filled with the current message
- **AND** "Save" and "Cancel" buttons MUST replace the "Edit" button

#### Scenario: Save edited note
- **GIVEN** the user has modified a note's text in the inline editor
- **WHEN** the user clicks "Save"
- **THEN** the note MUST be updated via the backend (using `ICommentsManager` to update the comment message)
- **AND** the note MUST return to display mode showing the updated text
- **AND** the note SHOULD show an "edited" indicator with the edit timestamp

#### Scenario: Cancel editing
- **GIVEN** the user is editing a note
- **WHEN** the user clicks "Cancel"
- **THEN** the note MUST revert to display mode with the original (unmodified) text
- **AND** no API call MUST be made

#### Scenario: Cannot edit other users' notes
- **GIVEN** the user is viewing a note authored by another user
- **WHEN** the user views the note actions
- **THEN** no "Edit" button MUST be shown (same ownership check as delete: `isOwn` flag)

---

## Current Implementation Status

**Partially implemented.** MVP CRUD requirements are complete and functional. V1 and Enterprise features are not yet implemented.

Implemented:
- **NotesService**: `lib/Service/NotesService.php` -- full CRUD using `ICommentsManager`:
  - `getNotes($objectType, $objectId)` -- returns notes in reverse chronological order with author name, timestamp, `isOwn` flag. Limit hardcoded to 200.
  - `addNote($objectType, $objectId, $message)` -- creates a comment via `ICommentsManager::create()` with verb `comment`.
  - `deleteNote($noteId)` -- deletes a single note, enforcing author-only deletion (`$comment->getActorId() !== $userId`).
  - `deleteAllNotes($objectType, $objectId)` -- removes all comments via `deleteCommentsAtObject()`.
  - Valid types constant: `VALID_TYPES = ['pipelinq_client', 'pipelinq_contact', 'pipelinq_lead', 'pipelinq_request']`.
- **NotesController**: `lib/Controller/NotesController.php` -- REST endpoints:
  - `GET /api/notes/{objectType}/{objectId}` -- list notes (validates object type).
  - `POST /api/notes/{objectType}/{objectId}` -- create note (validates message non-empty, triggers note events).
  - `DELETE /api/notes/{objectType}/{objectId}` -- delete all notes for an entity.
  - `DELETE /api/notes/single/{noteId}` -- delete a single note (own notes only, returns 403 on permission error).
- **NoteEventService**: `lib/Service/NoteEventService.php` -- triggers activity and notification events when a note is added. Fetches entity data from OpenRegister to get entity title and assignee, then calls `ActivityService::publishNoteAdded()` and `NotificationService::notifyNoteAdded()`.
- **EntityNotes Vue component**: `src/components/EntityNotes.vue` -- reusable component with:
  - Textarea input with "Add note" button (disabled when empty or submitting).
  - Notes list in reverse chronological order showing author name, relative timestamp, and message.
  - Delete button visible only on own notes.
  - Loading state via `NcLoadingIcon`.
  - "No notes yet" empty state.
  - Watches `objectId` prop to re-fetch notes when entity changes.
  - Uses plain `fetch()` for API calls with `requesttoken` and `OCS-APIREQUEST` headers.
- **Integration with detail views**: `ClientDetail.vue` shows a static `notes` field from entity data. The `EntityNotes` component is available for embedding via `CnDetailPage` with `object-type` prop.
- **Activity integration**: `ActivityService::publishNoteAdded()` publishes note events to the Nextcloud activity stream.

NOT implemented:
- Note types (call log, email log, meeting note) -- all notes are stored with verb `comment`
- Rich text / Markdown rendering -- notes displayed as plain text with `white-space: pre-wrap`
- @mention users -- no autocomplete or mention parsing
- Note search -- no search/filter UI
- Note file attachments -- no file upload on notes
- Note pinning -- no pin functionality
- Note templates -- no template system
- Note visibility (private vs shared) -- all notes visible to all users
- Note export -- no export functionality
- Inline editing -- only create and delete, no update
- Backend cleanup hook -- entity deletion via OpenRegister does not trigger `deleteAllNotes()`; orphaned comments may remain
- Note pagination -- hardcoded limit of 200, no pagination controls

### Standards & References
- Nextcloud Comments API (`OCP\Comments\ICommentsManager`) -- used for all CRUD operations
- Nextcloud Activity API (`OCP\Activity\IManager`) -- note events published for activity stream visibility
- Nextcloud Notifications API -- assignee notifications on note creation
- OpenRegister Object Interactions pattern -- shared note/comment architecture across Conduction apps
