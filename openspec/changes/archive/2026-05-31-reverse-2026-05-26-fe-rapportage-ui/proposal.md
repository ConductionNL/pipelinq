# Reverse-spec — Reporting UI

Retroactively specifies the observed behavior of 7 method(s) implementing contactmoment reporting screens. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/views/rapportage/AgentPerformance.vue::fetchData`
- `src/views/rapportage/ChannelAnalytics.vue::fetchData`
- `src/views/rapportage/RapportageDashboard.vue::exportCsv`
- `src/views/rapportage/RapportageDashboard.vue::fetchData`
- `src/views/rapportage/RapportageDashboard.vue::mounted`
- `src/views/rapportage/RapportageDashboard.vue::slaStatusClass`
- `src/views/rapportage/RapportageDashboard.vue::trendClass`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
