## 1. Server

- [x] 1.1 `lib/Listener/RelationshipInverseListener.php` queues the work; `lib/Service/RelationshipInverseService.php` creates, patches and deletes the inverse row in the deferred job; the listener is allow-listed in `DeferredObjectListenerJob`.
- [x] 1.2 `lib/Listener/ContactChannelMirrorListener.php`: fill `email`/`phone` from the changed list before the save.
- [x] 1.3 Register both in `Application::register()`.

## 2. Client

- [x] 2.1 `ContactRelationships.vue`: save only the row, edit with `patchObject`, delete only the row.
- [x] 2.2 `ContactChannelsSection.vue`: drop the client-side mirror.

## 3. Tests

- [x] 3.1 `tests/Unit/Listener/RelationshipInverseListenerTest.php`: create once, existing inverse, notes edit keeps the pair, type change, purge, soft delete, trash edit, other schema, stale entry, settles without re-queuing.
- [x] 3.2 `tests/Unit/Listener/ContactChannelMirrorListenerTest.php`: primary, first entry, emptied list, scalar-only writer, create, merge with other listeners, other schema.
