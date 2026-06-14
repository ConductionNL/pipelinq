# Reverse-spec — Contact communications and sync

Retroactively specifies the observed behavior of 10 method(s) implementing contact linkage, contactmoment service and email sync. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `lib/Service/ContactLinkedUidsService.php::getLinkedContactsUids`
- `lib/Service/ContactmomentService.php::getObjectService`
- `lib/Service/EmailSyncService.php::buildEmailLinkData`
- `lib/Service/EmailSyncService.php::extractDomain`
- `lib/Service/EmailSyncService.php::getLastSyncTime`
- `lib/Service/EmailSyncService.php::getSyncAccounts`
- `lib/Service/EmailSyncService.php::isSyncEnabled`
- `lib/Service/EmailSyncService.php::setSyncAccounts`
- `lib/Service/EmailSyncService.php::setSyncEnabled`
- `lib/Service/EmailSyncService.php::updateLastSyncTime`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
