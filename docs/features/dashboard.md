# Dashboard

Landing page providing an at-a-glance CRM overview with KPI cards, charts, activity previews, and quick actions.

## Specs

- `openspec/specs/dashboard/spec.md`

## Features

### KPI Cards (MVP)

Top row of metric cards showing key CRM numbers at a glance:

- Open Leads (count of non-terminal leads)
- Open Requests (count of non-terminal requests)
- Total Clients (client count)
- Items Due This Week (leads + requests with upcoming deadlines)

### Requests by Status Chart (MVP)

Visual chart showing the distribution of requests across status values (new, in_progress, completed, rejected, converted).

### My Work Preview (MVP)

Compact preview of the user's assigned items (top 5), linking to the full My Work view. Shows priority, due date, and entity type.

### Quick Actions (MVP)

Shortcut buttons for common operations: create new lead, create new request, create new client.

### Dashboard Data Refresh (MVP)

Dashboard data refreshes on mount and supports manual refresh. Data scoped to user's RBAC permissions.

### Empty State Handling (MVP)

Fresh installations show a welcoming empty state with getting-started guidance instead of empty charts.

### Planned (V1)

- Pipeline funnel visualization (conversion between stages)
- Leads by source chart (marketing attribution)
- Recent activity feed (last 10 CRM events from activity stream)
- Dashboard role-based views (different layouts per role)

### Planned (Enterprise)

- Custom dashboards
- Advanced charts (trends, forecasting)
