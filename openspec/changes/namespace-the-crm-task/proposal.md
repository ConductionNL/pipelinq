# Namespace the CRM task

## Why

`task` was claimed by three apps: planninq, dossiq and this one. They share
`description`, `priority` and `status` — the attributes any task-shaped record
carries, and nothing that identifies the record.

planninq's project task is the largest of the three and keeps the bare slug.
dossiq's becomes `caseTask`; this one is the CRM task, raised from a lead, a
ticket or the KCC werkplek.

## The decoys, which are a vocabulary rather than stray words

`task` is also an internal type name in two places, and neither is a schema:

- **Activity types.** `ActivityTimelineService` maps a source type to an
  activity type (`'emailLink' => 'email'`, `'task' => 'task'`). Its siblings
  give it away: `interaction` is not a schema either, it queries tickets by
  `ticketType`. The source-type vocabulary stays.
- **Notification subjects.** `NotificationService` branches on
  `$entityType === 'task'` to pick `task_assigned`, and passes
  `objectType: 'task'` to Nextcloud's notification API. That is routing, not a
  schema lookup.

Both were left alone. The schema references — the register lists, the
descriptor, `TASK_SCHEMA_SLUG`, `SchemaMapService`'s entity type, the exportable
schema list and the werkplek dialog's save — all moved.
