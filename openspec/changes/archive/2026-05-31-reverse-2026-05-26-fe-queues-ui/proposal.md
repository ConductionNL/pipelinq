# Reverse-spec — Queue UI

Retroactively specifies the observed behavior of 21 method(s) implementing queue management screens. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/views/queues/QueueDetail.vue::agentCount`
- `src/views/queues/QueueDetail.vue::assignToMe`
- `src/views/queues/QueueDetail.vue::bulkAssignToMe`
- `src/views/queues/QueueDetail.vue::items`
- `src/views/queues/QueueDetail.vue::loading`
- `src/views/queues/QueueDetail.vue::mounted`
- `src/views/queues/QueueDetail.vue::nextItem`
- `src/views/queues/QueueDetail.vue::objectStore`
- `src/views/queues/QueueDetail.vue::openItem`
- `src/views/queues/QueueDetail.vue::pickNext`
- `src/views/queues/QueueDetail.vue::queue`
- `src/views/queues/QueueDetail.vue::queuesStore`
- `src/views/queues/QueueDetail.vue::sortedItems`
- `src/views/queues/QueueDetail.vue::toggleSelect`
- `src/views/queues/QueueList.vue::createQueue`
- `src/views/queues/QueueList.vue::loading`
- `src/views/queues/QueueList.vue::openQueue`
- `src/views/queues/QueueList.vue::queues`
- `src/views/queues/QueueList.vue::queuesStore`
- `src/views/queues/QueueList.vue::resetCreateForm`
- `src/views/queues/QueueList.vue::sortedQueues`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
