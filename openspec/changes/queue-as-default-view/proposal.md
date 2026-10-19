# Proposal: queue-as-default-view

## Why

The queue is one list with a filter: every open ticket nobody has picked up. A page of its own repeats the ticket list beside All tickets. Ruben decided on 19 October 2026 that such pages become default views on their parent list, as dossiq does for Woo requests.

## What changes

- The All tickets page ships a default view `queue` with the queue's filter (`assignee: "IS NULL"`, `status_notIn` the closed statuses) and its sort (oldest first). Every user sees it under the page's views and can add it to the menu under "My views".
- The Queue page, its menu entry, its simple-profile menu entry and its tour step go.
- `/queue` redirects to `/tickets?savedView=default:queue`, so bookmarks keep working.
- Links to the queue on the simple dashboard open the view.
- All tickets offers Take it on on every unassigned row, the queue page's row action.

## What users lose

The page's own presentation: the Take the oldest header button, the Take on row action in the simple profile (its ticket list has no row menu), the Unassigned and Past the deadline tiles, the Team today and Agreements cards, the Waiting column, and the queue entry in everyone's menu. A user who wants the queue in the menu adds the view there.

## Impact

Depends on `@conduction/nextcloud-vue` library batch 17 (`defaultSavedViews`, `?savedView=`, the "My views" menu section).
