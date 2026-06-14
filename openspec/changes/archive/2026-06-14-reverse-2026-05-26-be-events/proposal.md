# Reverse-spec — Domain event dispatch

Retroactively specifies the observed behavior of 8 method(s) implementing object lifecycle event dispatch and deep-link registration. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `lib/Listener/DeepLinkRegistrationListener.php::handle`
- `lib/Listener/ObjectEventListener.php::handle`
- `lib/Service/ObjectEventDispatcher.php::dispatchAssigneeChange`
- `lib/Service/ObjectEventDispatcher.php::dispatchCreated`
- `lib/Service/ObjectEventDispatcher.php::dispatchDealLost`
- `lib/Service/ObjectEventDispatcher.php::dispatchDealWon`
- `lib/Service/ObjectEventDispatcher.php::dispatchStageChange`
- `lib/Service/ObjectEventDispatcher.php::dispatchStatusChange`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
