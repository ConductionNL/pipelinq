# Reverse-spec — Queue depth and overflow

Retroactively specifies the observed behavior of 3 method(s) implementing queue depth, capacity and overflow handling. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `lib/Service/QueueService.php::getQueueDepth`
- `lib/Service/QueueService.php::isAtCapacity`
- `lib/Service/QueueService.php::processOverflow`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
