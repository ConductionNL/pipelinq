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

### When a follow-up is planned (V1)

A follow-up row says when it is planned. A follow-up planned today reads "planned 14:00" and is marked "Follow-up today". One still to come reads "Tomorrow" or "In 3 days". One past its deadline reads "2 days overdue" in red and, on the phone, sits in the Overdue group.

### Recent activity (V1)

The side column lists the ten latest changes to the work assigned to you, from OpenRegister's audit trail: what kind of item it is, its title, how long ago, who made the change and which fields changed. Click an entry to open the item. You only see changes to items you may read.

### The work of a team (V1)

An administrator names the team manager groups under Pipelinq settings, My work, "Team manager groups" (group IDs, separated by commas). A member of such a group can ask for the open work of everyone in that group, or of one member of it. A Nextcloud administrator can ask for everyone's work. Every item is still read with your own permissions, so a wider scope never shows an item you may not open. On My work, "Whose work" at the top of the side column switches between My items, Team and Organisation; it only shows when you have more than your own work. On Team, "Your team" lists every member with their open items: pick one to see only theirs, and "Back to team" to see the whole team again. The lists, the download and the "Your week so far" figures follow the switch. The worklist API takes the same choice as `?scope=team` or `?scope=organisation`, with `&member=` for one colleague.

### Item Navigation (MVP)

Clicking any item navigates to its detail view (lead-detail or request-detail) for full context and actions.

### Planned (V1)

- Cross-app workload: include Procest tasks alongside Pipelinq items
- Additional sort options

### Queue-Based Work Section (Enterprise)

A "My Queues" tab alongside the existing "My Items" temporal view, showing items from queues the user is assigned to.

- Tab navigation: My Items (default) / My Queues
- Items grouped by queue name with priority-based ordering within each queue
- "Pick" action on unassigned items to self-assign from queue
- Empty state when user is not assigned to any queues

### Planned (Enterprise)

- Workload analytics (items per user for management visibility)
