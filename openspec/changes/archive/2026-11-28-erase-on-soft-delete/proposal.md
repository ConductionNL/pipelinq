# Erase a contact in integriq when it is soft deleted

Follows pipelinq#2162 (`opt-out-before-send`, REQ-CII-005). Ruben decided on 2026-10-07: erase on the soft delete.

## Why

`ContactErasedListener` asks integriq to drop a contact's link and evidence while keeping the opt-out. It listens for OpenRegister's `ObjectDeletedEvent`, and OpenRegister fires that only when the trash is purged (`openregister/lib/Db/MagicMapper.php:10001`).

An API delete is a soft delete. `DeleteObject::delete()` sets the deletion metadata (`openregister/lib/Service/Object/DeleteObject.php:361`) and saves through `MagicMapper::update()` (`:374`), which fires `ObjectUpdatedEvent` (`lib/Db/MagicMapper.php:9922`). So a deleted contact kept its link and evidence in integriq for as long as it sat in the trash, which may be forever.

## What changes

- `ContactErasedListener` also listens for `ObjectUpdatedEvent`. It erases when the update moves a live contact or client into the trash.
- The purge still erases. That covers contacts trashed before this change. Both runs are idempotent: integriq clears only rows that still carry the contact ref, and pipelinq's consent history is already gone.
- A restore out of the trash does not bring the link or the evidence back. The opt-out was never removed.

## Impact

- `lib/Listener/ContactErasedListener.php`, `lib/AppInfo/Application.php`
- No schema, no migration, no repair step.
