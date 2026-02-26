# Proposal: my-work

## Summary

Add a dedicated "My Work" (Werkvoorraad) view to Pipelinq, providing users with a personal workload page that aggregates all leads and requests assigned to them, organized by temporal groups with filtering and sorting.

## Motivation

Users need a single place to see everything assigned to them across leads and requests. The dashboard already has a 5-item "My Work" preview, but there's no full view to see all assigned items. This page becomes the daily productivity hub — showing overdue items first, then what's due this week, upcoming work, and items without deadlines.

## Affected Projects
- [x] Project: `pipelinq` — New MyWork.vue view, navigation entry, App.vue route

## Scope
### In Scope
- Personal workload view with all assigned leads + requests (REQ-MW-010 MVP)
- Sorting by priority then due date within groups (REQ-MW-020 MVP)
- Temporal grouping: Overdue, Due This Week, Upcoming, No Due Date (REQ-MW-030 MVP)
- Entity type filter: All / Leads / Requests (REQ-MW-040 MVP)
- Show completed toggle (REQ-MW-010 MVP)
- Overdue item highlighting with "N days overdue" (REQ-MW-050 MVP)
- Clickable items navigating to detail views (REQ-MW-060 MVP)
- Consistent item card layout (REQ-MW-080 MVP)
- Navigation menu entry with "My Work" item
- Dashboard "View all" link connecting to this view

### Out of Scope
- Cross-app Procest integration (REQ-MW-070 V1)
- Keyboard navigation beyond standard tab/enter (V1 polish)

## Approach

Build a single MyWork.vue component that fetches leads and requests assigned to `OC.currentUser`, computes temporal groups client-side, and renders grouped item cards. Reuse the same `fetchRaw` pattern from Dashboard.vue for independent data fetching. Add navigation entry and route in App.vue.

## Cross-Project Dependencies
None. Data comes from existing OpenRegister schemas.

## Rollback Strategy
Single new file (MyWork.vue) + small additions to MainMenu.vue and App.vue. Easy to revert.

## Open Questions
None.
