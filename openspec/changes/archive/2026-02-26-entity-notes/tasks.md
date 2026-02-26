# Tasks: entity-notes

## 1. PHP NotesService

- [x] 1.1 Create `lib/Service/NotesService.php` with constructor injecting `ICommentsManager`, `IUserSession`, `IUserManager`, `LoggerInterface`.
- [x] 1.2 Implement `getNotes(string $objectType, string $objectId): array` — fetch comments via `getForObject()`, resolve author display names, return array of `[id, message, authorId, authorName, timestamp, isOwn]`.
- [x] 1.3 Implement `addNote(string $objectType, string $objectId, string $message): array` — create comment, set message and verb, save, return note data.
- [x] 1.4 Implement `deleteNote(int $noteId): void` — verify current user is author, then delete.
- [x] 1.5 Implement `deleteAllNotes(string $objectType, string $objectId): void` — call `deleteCommentsAtObject()`.

## 2. PHP Controller & Routes

- [x] 2.1 Create `lib/Controller/NotesController.php` with `list`, `create`, `deleteAll`, `deleteSingle` actions. All `@NoAdminRequired`. Validate objectType against allowed list.
- [x] 2.2 Add 4 routes to `appinfo/routes.php`: GET list, POST create, DELETE all, DELETE single.

## 3. Comment Type Registration

- [x] 3.1 Register display name resolvers for `pipelinq_client`, `pipelinq_contact`, `pipelinq_lead`, `pipelinq_request` in `Application.php` `boot()`.

## 4. Frontend EntityNotes Component

- [x] 4.1 Create `src/components/EntityNotes.vue` — props `objectType` and `objectId`, fetches notes on mount, displays list with author/time/message, textarea + submit for new notes, delete button on own notes.

## 5. Detail View Integration

- [x] 5.1 Add `EntityNotes` component to `ClientDetail.vue` with `object-type="pipelinq_client"`.
- [x] 5.2 Add `EntityNotes` component to `ContactDetail.vue` with `object-type="pipelinq_contact"`.
- [x] 5.3 Add `EntityNotes` component to `LeadDetail.vue` with `object-type="pipelinq_lead"`.
- [x] 5.4 Add `EntityNotes` component to `RequestDetail.vue` with `object-type="pipelinq_request"`.

## 6. Entity Deletion Cleanup

- [x] 6.1 Add cleanup call in `ClientDetail.vue` `confirmDelete()` — DELETE notes before entity deletion.
- [x] 6.2 Add cleanup call in `ContactDetail.vue` `confirmDelete()`.
- [x] 6.3 Add cleanup call in `LeadDetail.vue` `confirmDelete()`.
- [x] 6.4 Add cleanup call in `RequestDetail.vue` `confirmDelete()`.

## 7. Build and Verify

- [x] 7.1 Run `npm run build` and verify no errors.
- [ ] 7.2 Test notes via browser.

## Verification
- [ ] All tasks checked off
- [ ] Manual testing against acceptance criteria
