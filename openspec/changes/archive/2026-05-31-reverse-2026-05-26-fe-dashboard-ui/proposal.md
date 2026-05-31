# Reverse-spec — Dashboard UI

Retroactively specifies the observed behavior of 18 method(s) implementing dashboard screens. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/views/dashboard/DashboardHeaderActions.vue::onClientCreated`
- `src/views/dashboard/DashboardHeaderActions.vue::onLeadCreated`
- `src/views/dashboard/DashboardHeaderActions.vue::onRequestCreated`
- `src/views/dashboard/DashboardHeaderActions.vue::refresh`
- `src/views/dashboard/widgets/ClientOverviewWidget.vue::mounted`
- `src/views/dashboard/widgets/ClientOverviewWidget.vue::recent`
- `src/views/dashboard/widgets/ComplaintsWidget.vue::mounted`
- `src/views/dashboard/widgets/MyWorkWidget.vue::allItems`
- `src/views/dashboard/widgets/MyWorkWidget.vue::items`
- `src/views/dashboard/widgets/MyWorkWidget.vue::mounted`
- `src/views/dashboard/widgets/MyWorkWidget.vue::openItem`
- `src/views/dashboard/widgets/MyWorkWidget.vue::total`
- `src/views/dashboard/widgets/OpenLeadsKpiWidget.vue::mounted`
- `src/views/dashboard/widgets/OpenRequestsKpiWidget.vue::mounted`
- `src/views/dashboard/widgets/OverdueKpiWidget.vue::mounted`
- `src/views/dashboard/widgets/PipelineValueKpiWidget.vue::mounted`
- `src/views/dashboard/widgets/RequestsByStatusWidget.vue::mounted`
- `src/views/dashboard/widgets/RequestsByStatusWidget.vue::rows`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
