# Pipelinq Features

Feature documentation organized by functional group. Each file describes implemented and planned features based on the OpenSpec specifications.

## Feature Groups

| Feature | File | Specs |
|---------|------|-------|
| Client Management | [client-management.md](client-management.md) | client-management, contacts-sync |
| Lead Management | [lead-management.md](lead-management.md) | lead-management |
| Request Management | [request-management.md](request-management.md) | request-management |
| Pipeline & Kanban | [pipeline-kanban.md](pipeline-kanban.md) | pipeline, pipeline-insights |
| Dashboard | [dashboard.md](dashboard.md) | dashboard |
| My Work | [my-work.md](my-work.md) | my-work |
| Collaboration | [collaboration.md](collaboration.md) | entity-notes, notifications-activity |
| Administration | [administration.md](administration.md) | admin-settings, openregister-integration |

## Spec-to-Feature Mapping

Used by the `/opsx:archive` skill to update the correct feature doc when archiving a change.

```
client-management → client-management.md
contacts-sync → client-management.md
lead-management → lead-management.md
request-management → request-management.md
pipeline → pipeline-kanban.md
pipeline-insights → pipeline-kanban.md
dashboard → dashboard.md
my-work → my-work.md
entity-notes → collaboration.md
notifications-activity → collaboration.md
admin-settings → administration.md
openregister-integration → administration.md
```
