# Entity Notes V1 Features

## Problem
Basic notes CRUD (create, view, delete) is implemented and functional via ICommentsManager, but V1 features are missing: note type categorization (call log, email log, meeting log), rich text formatting, @mentions with notifications, file attachments, and privacy controls (internal/confidential).

## Current State (Implemented)
- NotesService.php with full CRUD using ICommentsManager
- EntityNotes.vue component working on all 4 entity types
- Reverse-chronological display, own-note deletion, empty state
- Submit button disabled for empty messages

## Proposed Solution
Extend the existing notes system with note type verbs (comment, call_log, email_log, meeting_log), structured metadata for call/email logs, @mention parsing with notification dispatch, file attachment support, and visibility levels.

## Impact
- Extend NotesService.php with type-aware creation
- Extend EntityNotes.vue with type selector, rich text, @mention
- Add attachment upload support
- Add privacy/visibility controls
