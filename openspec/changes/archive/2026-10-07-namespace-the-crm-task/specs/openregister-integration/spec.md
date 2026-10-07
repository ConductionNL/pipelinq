# OpenRegister integration

## ADDED Requirements

### Requirement: The CRM task is namespaced (REQ-ORI-047)

The CRM task schema SHALL be `crmTask` and SHALL NOT be `task`. planninq's
project task keeps the bare slug; dossiq uses `caseTask`.

The three claiming schemas share `description`, `priority` and `status` alone,
so all three are renamed apart rather than folded onto one owner.

The rename SHALL NOT touch `task` where it is an activity type in
`ActivityTimelineService` or a notification entity type or object type in
`NotificationService`. Those are internal vocabularies: `interaction` and
`emailLink` sit beside `task` in the first, and neither is a schema.

A repair step SHALL rename the row IN PLACE before the register import, scoped
to this app's own rows.

The config key SHALL remain `task_schema`.

#### Scenario: The slug is renamed in place

- **GIVEN** an install carrying a pipelinq-owned `task` schema
- **WHEN** the repair step runs
- **THEN** the row keeps its schema id, and so its shard table and objects.

#### Scenario: The activity timeline still classifies a task

- **WHEN** a task-sourced timeline entry is classified
- **THEN** its activity type is still `task`.
