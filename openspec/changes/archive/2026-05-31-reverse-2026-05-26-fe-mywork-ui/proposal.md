# Reverse-spec — My Work UI

Retroactively specifies the observed behavior of 17 method(s) implementing personal work overview screen. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/views/MyWork.vue::allItems`
- `src/views/MyWork.vue::closedStageNames`
- `src/views/MyWork.vue::computeGroup`
- `src/views/MyWork.vue::currentUser`
- `src/views/MyWork.vue::emptyMessage`
- `src/views/MyWork.vue::fetchAll`
- `src/views/MyWork.vue::fetchRaw`
- `src/views/MyWork.vue::filteredItems`
- `src/views/MyWork.vue::formatDate`
- `src/views/MyWork.vue::groupedItems`
- `src/views/MyWork.vue::leadCount`
- `src/views/MyWork.vue::objectStore`
- `src/views/MyWork.vue::openItem`
- `src/views/MyWork.vue::pipelineMap`
- `src/views/MyWork.vue::requestCount`
- `src/views/MyWork.vue::totalCount`
- `src/views/MyWork.vue::visibleGroups`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
