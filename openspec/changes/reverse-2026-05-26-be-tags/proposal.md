# Reverse-spec — System tag management

Retroactively specifies the observed behavior of 11 method(s) implementing system tag CRUD and assignment. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `lib/Service/SystemTagCrudService.php::assignTag`
- `lib/Service/SystemTagCrudService.php::createOrReuseSystemTag`
- `lib/Service/SystemTagCrudService.php::getTagIdsForType`
- `lib/Service/SystemTagCrudService.php::renameSystemTag`
- `lib/Service/SystemTagCrudService.php::resolveTagData`
- `lib/Service/SystemTagCrudService.php::unassignAndCleanup`
- `lib/Service/SystemTagService.php::addTag`
- `lib/Service/SystemTagService.php::ensureDefaults`
- `lib/Service/SystemTagService.php::getTags`
- `lib/Service/SystemTagService.php::removeTag`
- `lib/Service/SystemTagService.php::renameTag`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
