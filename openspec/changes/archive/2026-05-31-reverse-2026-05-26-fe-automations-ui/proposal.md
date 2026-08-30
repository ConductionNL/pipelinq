# Reverse-spec — Automation UI

> SUPERSEDED 2026-06-01: The bespoke automation rule editor Vue this reverse-spec
> documented (`src/views/automations/AutomationBuilder.vue` etc.) has been
> removed; automation migrated to the NC **workflowengine (Flow) leaf**. See the
> change `migrate-automation-to-flow-leaf`. This reverse-spec stays archived for
> history; its requirements were un-synced from
> `openspec/specs/crm-workflow-automation/spec.md`. Do NOT resurrect the deleted
> code.

Retroactively specifies the observed behavior of 13 method(s) implementing automation rule editor screens. The code already exists and is exercised in the running app — this change documents its true capabilities as REQs and annotates each method with an `@spec` reference. No code logic changes.

## Affected code units

- `src/views/automations/AutomationBuilder.vue::actionOptions`
- `src/views/automations/AutomationBuilder.vue::addAction`
- `src/views/automations/AutomationBuilder.vue::addCondition`
- `src/views/automations/AutomationBuilder.vue::buildConditions`
- `src/views/automations/AutomationBuilder.vue::canSave`
- `src/views/automations/AutomationBuilder.vue::loadAutomation`
- `src/views/automations/AutomationBuilder.vue::mounted`
- `src/views/automations/AutomationBuilder.vue::operatorOptions`
- `src/views/automations/AutomationBuilder.vue::parseConditions`
- `src/views/automations/AutomationBuilder.vue::removeAction`
- `src/views/automations/AutomationBuilder.vue::removeCondition`
- `src/views/automations/AutomationBuilder.vue::save`
- `src/views/automations/AutomationBuilder.vue::triggerOptions`

## Approach

- Read each implementation; extract observable inputs/outputs and domain meaning.
- Cluster methods with shared observable behavior under a small set of REQs (capability-level, observed-not-aspirational).
- Annotate each method's docblock with `@spec openspec/.../tasks.md#task-N` pointing at its task.

Reverse-spec retrofit (ADR-020). See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
