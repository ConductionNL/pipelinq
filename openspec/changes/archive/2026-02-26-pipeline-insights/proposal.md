# Proposal: pipeline-insights

## Problem

The pipeline board shows items in stages but lacks temporal and financial context. Users cannot see at a glance:
- How long items have been stuck in a stage
- Which items are overdue across the app
- Total revenue potential per pipeline stage
- Which leads have gone stale (no activity)

These are standard CRM features that help sales teams prioritize and managers spot bottlenecks.

## Solution

Add 4 visual enhancements to the existing pipeline and My Work views — all frontend-only changes, no backend work needed:

1. **Stage revenue summary (#33)** — Show total EUR value in each kanban column header. Already partially implemented (`getStageTotalValue` exists) — needs better formatting and placement.

2. **Stale lead detection (#34)** — Visual badge on leads that haven't changed stage in X days (default 14). Compare `_dateModified` or `stageChangedAt` to current date.

3. **Aging indicator (#35)** — Show "X days" badge on pipeline cards indicating how long the item has been in its current stage.

4. **Overdue item highlighting (#38)** — Red visual treatment for overdue items in My Work, pipeline board, and list view. Enhance existing `isOverdue` logic with consistent styling.

## Scope

- All changes are frontend-only (Vue components, computed properties, CSS)
- No schema changes, no new API endpoints
- Aging calculation uses `_dateModified` field (provided by OpenRegister on every object)
- Stale threshold: 14 days without modification (hardcoded for now, configurable in Enterprise tier)

## Out of scope

- Configurable stale threshold (Enterprise)
- Backend-calculated aging metrics
- Email notifications for stale items
- Stale lead auto-reassignment
