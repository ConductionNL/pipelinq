# Proposal: entity-notes

## Problem

Pipelinq has no way for users to add internal notes or comments to clients, contacts, leads, or requests. Team members cannot leave context, follow-up reminders, or discussion threads on CRM entities. This is a fundamental CRM capability — every competitor supports it.

## Solution

Integrate Nextcloud's native **ICommentsManager** to add a notes/comments section to all four entity detail views. This leverages Nextcloud's existing comments infrastructure (DAV-based, per-user attribution, timestamps) rather than building a custom solution.

### Approach: PHP API wrapper + custom Vue component

- **Backend**: Create a `NotesController` that wraps `ICommentsManager` with CRUD endpoints. Register Pipelinq-specific object types (`pipelinq_client`, `pipelinq_lead`, etc.) so comments are properly namespaced.
- **Frontend**: Build an `EntityNotes.vue` component that fetches/creates/deletes notes via the Pipelinq API. Embed it in all 4 detail views.
- **Cleanup**: When an entity is deleted, its comments are cleaned up via `deleteCommentsAtObject()`.

### Why ICommentsManager over custom storage

- Already handles user attribution, timestamps, pagination
- Consistent with Nextcloud platform patterns
- Activity integration possible later (V1)
- No schema changes needed in OpenRegister

## Scope

- Notes CRUD on clients, contacts, leads, requests
- User attribution (who wrote the note, when)
- Delete own notes
- Comment cleanup on entity deletion
- No @ mentions, no rich text (keep it simple for MVP)

## Out of scope

- @ mentions / user tagging (V1)
- Rich text editing (V1)
- Activity stream integration (V1)
- File attachments on notes (V1)
