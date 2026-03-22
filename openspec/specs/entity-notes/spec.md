# Entity Notes Specification

## Status: partial

## Purpose

Add internal notes/comments to all Pipelinq entities (clients, contacts, leads, requests) using Nextcloud's ICommentsManager, enabling team collaboration and context tracking.

---

## Requirements

### Requirement: Notes CRUD on All Entity Types

**Status: implemented**

Users MUST be able to create, view, and delete notes on any Pipelinq entity.

#### Scenario: Add a note to an entity
- GIVEN the user is viewing a client, contact, lead, or request detail page
- WHEN the user types a message and clicks "Add note"
- THEN a comment MUST be created via ICommentsManager with correct actorType, actorId, objectType, objectId
- AND the note MUST appear at the top of the notes list

#### Scenario: View notes on an entity
- GIVEN an entity has notes
- WHEN the user views the detail page
- THEN all notes MUST be displayed in reverse chronological order with author, timestamp, and message

#### Scenario: Delete own note
- GIVEN the user authored a note
- WHEN they click delete
- THEN the backend MUST verify ownership before deletion
- AND the note MUST be removed without page reload

#### Scenario: Empty notes state
- GIVEN an entity has no notes
- THEN "No notes yet" MUST be displayed with the input still available

#### Scenario: Notes on all four entity types
- THEN EntityNotes.vue MUST work with pipelinq_client, pipelinq_contact, pipelinq_lead, pipelinq_request
- AND NotesService::VALID_TYPES MUST include all four

#### Scenario: Submit button disabled for empty messages
- GIVEN empty or whitespace-only input
- THEN the "Add note" button MUST be disabled

---

## Unimplemented Requirements

The following requirements are tracked as a change proposal:

**Change:** `openspec/changes/entity-notes-v1/`

- Note type categorization (comment, call_log, email_log, meeting_log verbs)
- Structured call/email log forms with metadata
- @Mention parsing with notification dispatch
- File attachment support via Nextcloud Files
- Note pinning
- Privacy/visibility controls (internal/confidential)
- Rich text formatting

---

### Implementation References

- `lib/Service/NotesService.php` -- full CRUD using ICommentsManager
- `src/components/EntityNotes.vue` -- notes component for all entity types
