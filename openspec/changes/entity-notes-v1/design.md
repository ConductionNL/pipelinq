# Entity Notes V1 - Design

## Approach
1. Extend NotesService to use different ICommentsManager verb values per note type
2. Build note type selector UI in EntityNotes.vue
3. Add @mention parsing and notification dispatch
4. Integrate file attachment via Nextcloud Files
5. Add visibility toggle (internal/confidential)

## Files Affected
- `lib/Service/NotesService.php` - Extend with type-aware creation and metadata
- `src/components/EntityNotes.vue` - Add type selector, rich text, @mention UI
- `src/components/notes/CallLogForm.vue` - Structured call log entry form
- `src/components/notes/EmailLogForm.vue` - Structured email log entry form
- `lib/Service/NotificationService.php` - Add @mention notification dispatch
