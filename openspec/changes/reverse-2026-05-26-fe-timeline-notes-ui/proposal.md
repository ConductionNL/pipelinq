# Reverse-spec — Timeline and notes UI

Retroactively specifies the observed behavior of 14 method(s) implementing activity timeline and entity notes components. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/components/ActivityTimeline.vue::fetchPage`
- `src/components/ActivityTimeline.vue::filterOptions`
- `src/components/ActivityTimeline.vue::formatDate`
- `src/components/ActivityTimeline.vue::iconFor`
- `src/components/ActivityTimeline.vue::loadMore`
- `src/components/ActivityTimeline.vue::reload`
- `src/components/ActivityTimeline.vue::setFilter`
- `src/components/ActivityTimeline.vue::truncate`
- `src/components/ActivityTimeline.vue::typeLabel`
- `src/components/EntityNotes.vue::addNote`
- `src/components/EntityNotes.vue::deleteNote`
- `src/components/EntityNotes.vue::fetchNotes`
- `src/components/EntityNotes.vue::formatTime`
- `src/components/EntityNotes.vue::objectId`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
