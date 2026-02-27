# My Work (Werkvoorraad)

Personal productivity hub aggregating all work items assigned to the current user into a single prioritized view. Answers: "What do I need to work on next?"

## Specs

- `openspec/specs/my-work/spec.md`

## Features

### Personal Workload View (MVP)

Unified list of all leads and requests assigned to the current user, combining both entity types into a single sorted view.

- Item cards showing title, type badge, priority, and due date
- Click-through navigation to entity detail views

### Temporal Grouping (MVP)

Items are organized into urgency-based groups:

- **Overdue**: Past due date, highlighted with red indicators
- **Due This Week**: Due within the current week
- **Upcoming**: Due in the future beyond this week
- **No Due Date**: Items without a deadline

### Sorting (MVP)

Items sorted by priority first (urgent → high → normal → low), then by due date within each priority level.

### Filtering (MVP)

Filter tabs to focus on specific entity types:

- All (leads + requests combined)
- Leads only
- Requests only

### Overdue Item Highlighting (MVP)

Items past their due date are visually distinct with red color indicators and overdue badges, ensuring nothing falls through the cracks.

### Item Navigation (MVP)

Clicking any item navigates to its detail view (lead-detail or request-detail) for full context and actions.

### Planned (V1)

- Cross-app workload: include Procest tasks alongside Pipelinq items
- Additional sort options

### Planned (Enterprise)

- Workload analytics (items per user for management visibility)
