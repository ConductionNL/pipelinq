# Reverse-spec — Application shell

Retroactively specifies the observed behavior of 4 method(s) implementing application shell navigation. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/App.vue::onPipelineSidebarSave`
- `src/App.vue::permissions`
- `src/App.vue::provide`
- `src/App.vue::translateForApp`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
