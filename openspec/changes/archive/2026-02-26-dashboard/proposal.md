# Proposal: dashboard

## Summary

Replace the placeholder Dashboard view with a real CRM dashboard featuring KPI cards, a requests-by-status chart, a "My Work" preview, quick actions, and proper data refresh — all scoped to MVP tier requirements.

## Motivation

The dashboard is the landing page every user sees when opening Pipelinq. It's currently a placeholder with two navigation buttons. Now that clients, contacts, leads, requests, and pipeline features are all built, the dashboard can aggregate real data into a useful at-a-glance overview. This completes the core CRM experience.

## Affected Projects
- [x] Project: `pipelinq` — Rebuild Dashboard.vue with KPI cards, charts, My Work preview, quick actions

## Scope
### In Scope
- KPI summary cards: open leads, open requests, pipeline value, overdue items (REQ-DB-010 MVP)
- Requests by status chart (REQ-DB-040 MVP)
- My Work preview showing top 5 assigned items (REQ-DB-050 MVP)
- Quick action buttons for creating leads, requests, clients (REQ-DB-070 MVP)
- Data refresh on mount and re-navigation (REQ-DB-080 MVP)
- Empty state handling for fresh installations (REQ-DB-090 MVP)

### Out of Scope
- Pipeline funnel visualization (REQ-DB-020 V1)
- Leads by source chart (REQ-DB-030 V1)
- Recent activity feed (REQ-DB-060 V1)
- Role-based dashboard views (REQ-DB-100 V1)
- Delta indicators on KPI cards (V1 SHOULD, not MVP MUST)

## Approach

Build a single Dashboard.vue component that fetches leads, requests, and pipelines on mount, computes KPI metrics client-side, and renders them using Nextcloud Vue components. No backend changes needed — all data comes from the existing OpenRegister API via the object store.

The requests-by-status chart will be a simple horizontal bar chart built with CSS (no charting library needed for MVP). The My Work preview queries leads and requests filtered by the current user's assignee field.

## Cross-Project Dependencies
None. All data already available via existing OpenRegister schemas and the object store.

## Rollback Strategy
Single file change (Dashboard.vue). Revert to the current placeholder if issues arise.

## Open Questions
None — spec requirements are clear and all data sources exist.
